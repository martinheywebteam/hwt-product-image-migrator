<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class HWT_Importer {

    private $logger;

    public function __construct( HWT_Logger $logger ) {
        $this->logger = $logger;
    }

    /**
     * Handle the CSV upload, validate it, and store job data in a transient.
     */
    public function setup_import( $file_tmp, $file_name, $sku_filter = array(), $overwrite = false, $batch_size = 5, $dry_run = false ) {
        $ext = strtolower( pathinfo( $file_name, PATHINFO_EXTENSION ) );
        if ( 'csv' !== $ext ) {
            return new WP_Error( 'invalid_file', 'Please upload a valid CSV file.' );
        }

        if ( filesize( $file_tmp ) > 10 * 1024 * 1024 ) {
            return new WP_Error( 'file_too_large', 'CSV file must be under 10 MB.' );
        }

        if ( ! is_dir( HWT_PIM_LOG_DIR ) ) {
            wp_mkdir_p( HWT_PIM_LOG_DIR );
            @file_put_contents( HWT_PIM_LOG_DIR . '/.htaccess', "Deny from all\n" );
        }

        $dest = HWT_PIM_LOG_DIR . '/import-' . gmdate( 'YmdHis' ) . '.csv';
        if ( ! move_uploaded_file( $file_tmp, $dest ) ) {
            if ( ! @copy( $file_tmp, $dest ) ) {
                $upload_dir = wp_upload_dir();
                $dest = $upload_dir['basedir'] . '/hwt-import-' . gmdate( 'YmdHis' ) . '.csv';
                if ( ! move_uploaded_file( $file_tmp, $dest ) && ! @copy( $file_tmp, $dest ) ) {
                    return new WP_Error( 'upload_failed', 'Could not save uploaded file.' );
                }
            }
        }

        $handle = fopen( $dest, 'r' );
        if ( ! $handle ) {
            return new WP_Error( 'read_failed', 'Could not read uploaded CSV.' );
        }

        $header = fgetcsv( $handle );
        if ( ! $header ) {
            fclose( $handle );
            return new WP_Error( 'empty_csv', 'CSV file is empty.' );
        }

        $header   = array_map( 'trim', array_map( 'strtolower', $header ) );
        $required = array( 'sku', 'featured_image_url', 'gallery_image_urls' );
        $missing  = array_diff( $required, $header );

        if ( ! empty( $missing ) ) {
            fclose( $handle );
            return new WP_Error( 'invalid_header', 'CSV missing columns: ' . implode( ', ', $missing ) );
        }

        $total = 0;
        while ( fgetcsv( $handle ) !== false ) {
            $total++;
        }
        fclose( $handle );

        if ( 0 === $total ) {
            return new WP_Error( 'no_rows', 'CSV contains no data rows.' );
        }

        $sku_filter = array_filter( array_map( 'trim', $sku_filter ) );
        $sku_filter = array_map( 'strtolower', $sku_filter );
        $log_file   = $this->logger->start_session();

        $job = array(
            'csv_path'   => $dest,
            'total'      => $total,
            'sku_filter' => $sku_filter,
            'overwrite'  => $overwrite,
            'batch_size' => max( 1, min( 20, intval( $batch_size ) ) ),
            'dry_run'    => $dry_run,
            'log_file'   => $log_file,
        );

        set_transient( 'hwt_import_job', $job, HOUR_IN_SECONDS );

        $this->logger->info( "Import job started. Total rows: {$total}. Batch size: {$job['batch_size']}. Overwrite: " . ( $overwrite ? 'YES' : 'NO' ) . '. Dry run: ' . ( $dry_run ? 'YES' : 'NO' ) );
        $this->logger->info( "Blog ID: " . get_current_blog_id() . ". DB prefix: " . $GLOBALS['wpdb']->prefix );
        $upload_dir = wp_upload_dir();
        $this->logger->info( "Upload basedir: " . $upload_dir['basedir'] );
        $this->logger->info( "Upload baseurl: " . $upload_dir['baseurl'] );

        return $job;
    }

    /**
     * Process a batch of CSV rows.
     */
    public function process_batch( $offset ) {
        $job = get_transient( 'hwt_import_job' );
        if ( ! $job ) {
            return array( 'error' => 'No active import job found. Please upload a CSV first.' );
        }

        $this->logger->set_log_file( $job['log_file'] );

        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $handle = fopen( $job['csv_path'], 'r' );
        if ( ! $handle ) {
            return array( 'error' => 'Could not read CSV file.' );
        }

        $header = fgetcsv( $handle );
        $header = array_map( 'trim', array_map( 'strtolower', $header ) );
        $col    = array_flip( $header );

        $current = 0;
        while ( $current < $offset && fgetcsv( $handle ) !== false ) {
            $current++;
        }

        $results   = array();
        $processed = 0;

        while ( $processed < $job['batch_size'] ) {
            $row = fgetcsv( $handle );
            if ( false === $row ) {
                break;
            }

            $processed++;
            $sku = isset( $row[ $col['sku'] ] ) ? trim( $row[ $col['sku'] ] ) : '';

            if ( empty( $sku ) ) {
                $results[] = array( 'sku' => '(empty)', 'status' => 'skipped', 'message' => 'Empty SKU.' );
                continue;
            }

            if ( ! empty( $job['sku_filter'] ) && ! in_array( strtolower( $sku ), $job['sku_filter'], true ) ) {
                $results[] = array( 'sku' => $sku, 'status' => 'skipped', 'message' => 'Not in SKU filter.' );
                continue;
            }

            // Look up product by SKU.
            $product_id = wc_get_product_id_by_sku( $sku );
            if ( ! $product_id ) {
                $results[] = array( 'sku' => $sku, 'status' => 'error', 'message' => 'Product not found.' );
                $this->logger->warn( "SKU={$sku} | Product not found." );
                continue;
            }

            $product = wc_get_product( $product_id );
            if ( ! $product ) {
                $results[] = array( 'sku' => $sku, 'status' => 'error', 'message' => 'Could not load product.' );
                continue;
            }

            $featured_url    = isset( $row[ $col['featured_image_url'] ] ) ? trim( $row[ $col['featured_image_url'] ] ) : '';
            $gallery_raw     = isset( $row[ $col['gallery_image_urls'] ] ) ? trim( $row[ $col['gallery_image_urls'] ] ) : '';
            $images_imported = 0;
            $images_skipped  = 0;
            $errors          = array();
            $featured_att_id = null;
            $new_gallery_ids = array();
            $is_dry_run      = ! empty( $job['dry_run'] );

            $gallery_urls = ! empty( $gallery_raw ) ? array_filter( array_map( 'trim', explode( '|', $gallery_raw ) ) ) : array();

            if ( $is_dry_run ) {
                // --- DRY RUN: simulate without downloading ---
                $has_featured = (bool) $product->get_image_id();
                $has_gallery  = ! empty( $product->get_gallery_image_ids() );

                if ( ! empty( $featured_url ) ) {
                    if ( $has_featured && ! $job['overwrite'] ) { $images_skipped++; } else { $images_imported++; }
                }
                if ( ! empty( $gallery_urls ) ) {
                    if ( $has_gallery && ! $job['overwrite'] ) { $images_skipped += count( $gallery_urls ); } else { $images_imported += count( $gallery_urls ); }
                }

                $status = ( $images_imported === 0 && $images_skipped > 0 ) ? 'skipped' : 'success';
                $msg = "DRY RUN: {$images_imported} would import, {$images_skipped} would skip";
                $this->logger->info( "SKU={$sku} | {$msg}" );

            } else {
                // --- REAL IMPORT ---
                if ( ! empty( $featured_url ) ) {
                    if ( $product->get_image_id() && ! $job['overwrite'] ) {
                        $images_skipped++;
                        $this->logger->info( "SKU={$sku} | Featured image exists, skipping." );
                    } else {
                        $att_id = $this->sideload_image( $featured_url, $product_id, $job['overwrite'] );
                        if ( is_wp_error( $att_id ) ) {
                            $errors[] = 'Featured: ' . $att_id->get_error_message();
                            $this->logger->error( "SKU={$sku} | Featured failed: {$att_id->get_error_message()}" );
                        } else {
                            $featured_att_id = $att_id;
                            $images_imported++;
                            $this->logger->info( "SKU={$sku} | Featured image imported (ID {$att_id})." );
                        }
                    }
                }

                if ( ! empty( $gallery_urls ) ) {
                    $existing_ids = $product->get_gallery_image_ids();
                    if ( ! empty( $existing_ids ) && ! $job['overwrite'] ) {
                        $images_skipped += count( $gallery_urls );
                        $new_gallery_ids = $existing_ids;
                    } else {
                        $new_gallery_ids = $job['overwrite'] ? array() : $existing_ids;
                        foreach ( $gallery_urls as $i => $g_url ) {
                            $att_id = $this->sideload_image( $g_url, $product_id, $job['overwrite'] );
                            if ( is_wp_error( $att_id ) ) {
                                $errors[] = 'Gallery ' . ( $i + 1 ) . ': ' . $att_id->get_error_message();
                            } else {
                                $new_gallery_ids[] = $att_id;
                                $images_imported++;
                                $this->logger->info( "SKU={$sku} | Gallery " . ( $i + 1 ) . " imported (ID {$att_id})." );
                            }
                        }
                    }
                }

                // Save via WooCommerce API.
                $changed = false;
                if ( $featured_att_id !== null ) { $product->set_image_id( $featured_att_id ); $changed = true; }
                if ( ! empty( $new_gallery_ids ) ) { $product->set_gallery_image_ids( $new_gallery_ids ); $changed = true; }
                if ( $changed ) {
                    $product->save();
                    $this->logger->info( "SKU={$sku} | Product saved." );
                }

                $status = 'success';
                if ( ! empty( $errors ) && 0 === $images_imported ) { $status = 'error'; }
                elseif ( ! empty( $errors ) ) { $status = 'partial'; }
                elseif ( $images_imported === 0 && $images_skipped > 0 ) { $status = 'skipped'; }

                $msg = "{$images_imported} imported";
                if ( $images_skipped > 0 ) $msg .= ", {$images_skipped} skipped";
                if ( ! empty( $errors ) )  $msg .= ', errors: ' . implode( '; ', $errors );
            }

            $results[] = array(
                'sku'             => $sku,
                'product_id'      => $product_id,
                'product_title'   => $product->get_name(),
                'status'          => $status,
                'images_imported' => $images_imported,
                'images_skipped'  => $images_skipped,
                'message'         => $msg,
                'dry_run'         => $is_dry_run,
            );
        }

        fclose( $handle );
        wp_cache_flush();

        $new_offset = $offset + $processed;
        $done       = $new_offset >= $job['total'];

        // Save cumulative history (keeps last 10 runs).
        $all_runs    = get_option( 'hwt_import_history_runs', array() );
        $current_key = 'run_' . md5( $job['csv_path'] . $job['log_file'] );

        if ( ! isset( $all_runs[ $current_key ] ) || $offset === 0 ) {
            $all_runs[ $current_key ] = array(
                'date' => current_time( 'mysql' ), 'total' => $job['total'],
                'overwrite' => $job['overwrite'], 'dry_run' => ! empty( $job['dry_run'] ),
                'products' => array(), 'stats' => array( 'imported' => 0, 'skipped' => 0, 'failed' => 0 ),
            );
        }
        foreach ( $results as $r ) {
            $all_runs[ $current_key ]['products'][] = $r;
            if ( $r['status'] === 'success' || $r['status'] === 'partial' ) {
                $all_runs[ $current_key ]['stats']['imported'] += ( isset( $r['images_imported'] ) ? $r['images_imported'] : 0 );
                $all_runs[ $current_key ]['stats']['skipped']  += ( isset( $r['images_skipped'] ) ? $r['images_skipped'] : 0 );
                if ( $r['status'] === 'partial' ) $all_runs[ $current_key ]['stats']['failed']++;
            } elseif ( $r['status'] === 'error' ) { $all_runs[ $current_key ]['stats']['failed']++;
            } elseif ( $r['status'] === 'skipped' ) { $all_runs[ $current_key ]['stats']['skipped']++; }
        }
        if ( count( $all_runs ) > 10 ) { $all_runs = array_slice( $all_runs, -10, 10, true ); }
        update_option( 'hwt_import_history_runs', $all_runs, false );
        update_option( 'hwt_import_history', $all_runs[ $current_key ], false );

        if ( $done ) {
            $this->logger->info( 'Import complete.' );
            delete_transient( 'hwt_import_job' );
        }

        return array(
            'processed' => $processed,
            'offset'    => $new_offset,
            'total'     => $job['total'],
            'results'   => $results,
            'done'      => $done,
            'log_file'  => basename( $job['log_file'] ),
        );
    }

    /**
     * Download and sideload an image, matching the site's actual working upload format.
     *
     * Diagnostics revealed this site stores:
     *   _wp_attached_file = FULL URL (e.g. https://domain/wp-content/uploads/YYYY/MM/file.jpg)
     *   Files at: /httpdocs/wp-content/uploads/YYYY/MM/ (main uploads, no /sites/2/)
     *
     * So we override wp_upload_dir() during sideload to use the main uploads dir,
     * then fix _wp_attached_file to store the full URL (matching manual uploads).
     *
     * @param  string $url        Remote image URL.
     * @param  int    $product_id Product post ID to attach to.
     * @param  bool   $force      Delete existing and re-download.
     * @return int|WP_Error       Attachment ID on success.
     */
    private function sideload_image( $url, $product_id, $force = false ) {
        // Check for existing download.
        $existing = $this->find_attachment_by_source_url( $url, $product_id );
        if ( $existing ) {
            if ( $force ) {
                wp_delete_attachment( $existing, true );
            } else {
                $file = get_attached_file( $existing );
                if ( $file && file_exists( $file ) && filesize( $file ) > 0 ) {
                    return $existing;
                }
                wp_delete_attachment( $existing, true );
            }
        }

        // Encode URL for special characters (e.g. Hermès).
        $encoded_url = $this->encode_url( $url );

        // Override upload dir to use MAIN uploads (no /sites/2/) — matching how
        // manually uploaded images work on this multisite.
        add_filter( 'upload_dir', array( $this, 'use_main_upload_dir' ) );

        $attachment_id = media_sideload_image( $encoded_url, $product_id, '', 'id' );

        if ( is_wp_error( $attachment_id ) ) {
            $attachment_id = media_sideload_image( $url, $product_id, '', 'id' );
            if ( is_wp_error( $attachment_id ) ) {
                remove_filter( 'upload_dir', array( $this, 'use_main_upload_dir' ) );
                return $attachment_id;
            }
        }

        remove_filter( 'upload_dir', array( $this, 'use_main_upload_dir' ) );

        // Fix _wp_attached_file to store FULL URL (matching working attachments on this site).
        $current_file = get_post_meta( $attachment_id, '_wp_attached_file', true );
        if ( $current_file && strpos( $current_file, 'http' ) !== 0 ) {
            // It's a relative path — convert to full URL matching the working format.
            $site_url = site_url();
            // Strip /uk or any subsite path from the domain for the uploads URL.
            $parsed   = wp_parse_url( $site_url );
            $base_url = $parsed['scheme'] . '://' . $parsed['host'];
            $full_url = $base_url . '/wp-content/uploads/' . $current_file;
            update_post_meta( $attachment_id, '_wp_attached_file', $full_url );

            // Also update the guid to match.
            global $wpdb;
            $wpdb->update(
                $wpdb->posts,
                array( 'guid' => $full_url ),
                array( 'ID' => $attachment_id )
            );
            clean_post_cache( $attachment_id );
        }

        // Store source URL for duplicate prevention.
        update_post_meta( $attachment_id, '_hwt_source_url', $url );

        // Log details.
        $file_url  = wp_get_attachment_url( $attachment_id );
        $file_path = get_attached_file( $attachment_id );
        $this->logger->info( "  -> att_id: {$attachment_id}" );
        $this->logger->info( "  -> _wp_attached_file: " . get_post_meta( $attachment_id, '_wp_attached_file', true ) );
        $this->logger->info( "  -> get_attached_file: {$file_path}" );
        $this->logger->info( "  -> file_exists: " . ( file_exists( $file_path ) ? 'YES' : 'NO' ) );
        $this->logger->info( "  -> wp_get_attachment_url: {$file_url}" );

        return $attachment_id;
    }

    /**
     * Encode URL path segments for special characters.
     */
    /**
     * Override wp_upload_dir to use the MAIN uploads directory (no /sites/X/).
     * This matches how manually uploaded images are stored on this multisite.
     */
    public function use_main_upload_dir( $uploads ) {
        if ( ! is_multisite() || get_current_blog_id() <= 1 ) {
            return $uploads;
        }

        $blog_id = get_current_blog_id();
        $sites_segment = '/sites/' . $blog_id;

        // Remove /sites/{blog_id} from all paths and URLs.
        $uploads['basedir'] = str_replace( $sites_segment, '', $uploads['basedir'] );
        $uploads['path']    = str_replace( $sites_segment, '', $uploads['path'] );
        $uploads['baseurl'] = str_replace( $sites_segment, '', $uploads['baseurl'] );
        $uploads['url']     = str_replace( $sites_segment, '', $uploads['url'] );

        // Also remove /uk/ (subsite path) from URLs to match working format.
        $uploads['baseurl'] = str_replace( '/uk/wp-content', '/wp-content', $uploads['baseurl'] );
        $uploads['url']     = str_replace( '/uk/wp-content', '/wp-content', $uploads['url'] );

        return $uploads;
    }

    private function encode_url( $url ) {
        $parts = wp_parse_url( $url );
        if ( ! isset( $parts['path'] ) ) {
            return $url;
        }

        $path     = urldecode( $parts['path'] );
        $segments = explode( '/', $path );
        $encoded  = array_map( 'rawurlencode', $segments );
        $parts['path'] = implode( '/', $encoded );

        $result = $parts['scheme'] . '://' . $parts['host'];
        if ( isset( $parts['port'] ) ) $result .= ':' . $parts['port'];
        $result .= $parts['path'];
        if ( isset( $parts['query'] ) ) $result .= '?' . $parts['query'];

        return $result;
    }

    /**
     * Find attachment by source URL meta.
     */
    private function find_attachment_by_source_url( $url, $product_id ) {
        global $wpdb;

        $id = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT p.ID FROM {$wpdb->posts} p
                 INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
                 WHERE p.post_type = 'attachment'
                   AND p.post_parent = %d
                   AND pm.meta_key = '_hwt_source_url'
                   AND pm.meta_value = %s
                 LIMIT 1",
                $product_id,
                $url
            )
        );

        return $id ? intval( $id ) : false;
    }
}
