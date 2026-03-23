<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class HWT_Importer {

    private $logger;
    private $upload_profile = null;

    public function __construct( HWT_Logger $logger ) {
        $this->logger = $logger;
    }

    /**
     * Auto-detect how this site stores attachments by examining an existing working one.
     * Called once per import session, result is cached.
     *
     * Detects:
     *   - Whether _wp_attached_file stores a full URL or relative path
     *   - Whether files live in /sites/{blog_id}/ or the main uploads dir
     *   - Whether URLs include the subsite path (e.g. /uk/)
     *   - The base URL used for uploads
     */
    private function detect_upload_profile() {
        if ( $this->upload_profile !== null ) {
            return $this->upload_profile;
        }

        global $wpdb;

        $profile = array(
            'uses_full_url'       => false,
            'files_in_sites_dir'  => true,
            'url_has_subsite'     => true,
            'base_url'            => '',
        );

        // Find a recent image attachment NOT created by our plugin.
        $sample_id = $wpdb->get_var(
            "SELECT p.ID FROM {$wpdb->posts} p
             LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_hwt_source_url'
             WHERE p.post_type = 'attachment'
               AND p.post_mime_type LIKE 'image/%'
               AND pm.meta_id IS NULL
             ORDER BY p.ID DESC
             LIMIT 1"
        );

        if ( ! $sample_id ) {
            // No sample found — use defaults (standard WP behavior).
            $this->logger->info( 'Upload profile: no sample attachment found, using defaults.' );
            $this->upload_profile = $profile;
            return $profile;
        }

        $attached_file = get_post_meta( intval( $sample_id ), '_wp_attached_file', true );
        $file_path     = get_attached_file( intval( $sample_id ) );

        // Check if _wp_attached_file is a full URL.
        if ( $attached_file && strpos( $attached_file, 'http' ) === 0 ) {
            $profile['uses_full_url'] = true;

            $parsed = wp_parse_url( $attached_file );
            $path   = isset( $parsed['path'] ) ? $parsed['path'] : '';
            $blog_id = get_current_blog_id();

            // Does the URL contain /sites/{blog_id}/?
            $profile['url_has_subsite'] = false;
            if ( is_multisite() && $blog_id > 1 ) {
                $blog_details = get_blog_details();
                $subsite_path = $blog_details ? trim( $blog_details->path, '/' ) : '';
                $profile['url_has_subsite'] = ( ! empty( $subsite_path ) && strpos( $path, '/' . $subsite_path . '/' ) !== false );
            }

            // Extract base URL for later use.
            if ( preg_match( '#^(https?://.+/uploads)/?(sites/\d+/)?#', $attached_file, $m ) ) {
                $profile['base_url'] = rtrim( $m[0], '/' );
            }
        }

        // Check if files physically live in /sites/{blog_id}/.
        if ( $file_path ) {
            $blog_id = get_current_blog_id();
            $profile['files_in_sites_dir'] = ( is_multisite() && $blog_id > 1 && strpos( $file_path, '/sites/' . $blog_id . '/' ) !== false );
        }

        $this->logger->info( "Upload profile detected (sample ID {$sample_id}):" );
        $this->logger->info( "  uses_full_url: " . ( $profile['uses_full_url'] ? 'YES' : 'NO' ) );
        $this->logger->info( "  files_in_sites_dir: " . ( $profile['files_in_sites_dir'] ? 'YES' : 'NO' ) );
        $this->logger->info( "  url_has_subsite: " . ( $profile['url_has_subsite'] ? 'YES' : 'NO' ) );
        $this->logger->info( "  base_url: " . ( $profile['base_url'] ?: '(default)' ) );
        $this->logger->info( "  sample _wp_attached_file: {$attached_file}" );

        $this->upload_profile = $profile;
        return $profile;
    }

    /**
     * Handle the CSV upload, validate it, and store job data in a transient.
     */
    public function setup_import( $file_tmp, $file_name, $sku_filter = array(), $overwrite = false, $batch_size = 5 ) {
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
            'log_file'   => $log_file,
        );

        set_transient( 'hwt_import_job', $job, HOUR_IN_SECONDS );

        $this->logger->info( "Import job started. Total rows: {$total}. Batch size: {$job['batch_size']}. Overwrite: " . ( $overwrite ? 'YES' : 'NO' ) );
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

            // --- Featured image ---
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

            // --- Gallery images ---
            if ( ! empty( $gallery_raw ) ) {
                $gallery_urls = array_filter( array_map( 'trim', explode( '|', $gallery_raw ) ) );
                $existing_ids = $product->get_gallery_image_ids();

                if ( ! empty( $existing_ids ) && ! $job['overwrite'] ) {
                    $images_skipped += count( $gallery_urls );
                    $new_gallery_ids = $existing_ids;
                    $this->logger->info( "SKU={$sku} | Gallery exists (" . count( $existing_ids ) . "), skipping." );
                } else {
                    $new_gallery_ids = $job['overwrite'] ? array() : $existing_ids;

                    foreach ( $gallery_urls as $i => $g_url ) {
                        $att_id = $this->sideload_image( $g_url, $product_id, $job['overwrite'] );
                        if ( is_wp_error( $att_id ) ) {
                            $errors[] = 'Gallery ' . ( $i + 1 ) . ': ' . $att_id->get_error_message();
                            $this->logger->error( "SKU={$sku} | Gallery " . ( $i + 1 ) . " failed: {$att_id->get_error_message()}" );
                        } else {
                            $new_gallery_ids[] = $att_id;
                            $images_imported++;
                            $this->logger->info( "SKU={$sku} | Gallery " . ( $i + 1 ) . " imported (ID {$att_id})." );
                        }
                    }
                }
            }

            // --- Save to product via WooCommerce API (HPOS-compatible) ---
            $changed = false;
            if ( $featured_att_id !== null ) {
                $product->set_image_id( $featured_att_id );
                $this->logger->info( "SKU={$sku} | set_image_id({$featured_att_id})" );
                $changed = true;
            }
            if ( ! empty( $new_gallery_ids ) ) {
                $product->set_gallery_image_ids( $new_gallery_ids );
                $this->logger->info( "SKU={$sku} | set_gallery_image_ids(" . implode( ',', $new_gallery_ids ) . ")" );
                $changed = true;
            }
            if ( $changed ) {
                $product->save();

                // Verify it stuck.
                $verify_product = wc_get_product( $product_id );
                $verify_img     = $verify_product ? $verify_product->get_image_id() : 'N/A';
                $verify_gallery = $verify_product ? $verify_product->get_gallery_image_ids() : array();
                $this->logger->info( "SKU={$sku} | VERIFY after save: image_id={$verify_img}, gallery=" . implode( ',', $verify_gallery ) );
            }

            // Build result.
            $status = 'success';
            if ( ! empty( $errors ) && 0 === $images_imported ) {
                $status = 'error';
            } elseif ( ! empty( $errors ) ) {
                $status = 'partial';
            } elseif ( $images_imported === 0 && $images_skipped > 0 ) {
                $status = 'skipped';
            }

            $msg = "{$images_imported} imported";
            if ( $images_skipped > 0 ) $msg .= ", {$images_skipped} skipped";
            if ( ! empty( $errors ) )  $msg .= ', errors: ' . implode( '; ', $errors );

            $results[] = array(
                'sku'             => $sku,
                'product_id'      => $product_id,
                'product_title'   => $product->get_name(),
                'status'          => $status,
                'images_imported' => $images_imported,
                'images_skipped'  => $images_skipped,
                'message'         => $msg,
            );
        }

        fclose( $handle );
        wp_cache_flush();

        $new_offset = $offset + $processed;
        $done       = $new_offset >= $job['total'];

        // Accumulate results in the import history option.
        $history = get_option( 'hwt_import_history', array() );
        if ( empty( $history ) || $offset === 0 ) {
            // First batch — start fresh history.
            $history = array(
                'date'      => current_time( 'mysql' ),
                'total'     => $job['total'],
                'overwrite' => $job['overwrite'],
                'products'  => array(),
                'stats'     => array( 'imported' => 0, 'skipped' => 0, 'failed' => 0 ),
            );
        }

        foreach ( $results as $r ) {
            $history['products'][] = $r;
            if ( $r['status'] === 'success' || $r['status'] === 'partial' ) {
                $history['stats']['imported'] += ( isset( $r['images_imported'] ) ? $r['images_imported'] : 0 );
                $history['stats']['skipped']  += ( isset( $r['images_skipped'] ) ? $r['images_skipped'] : 0 );
                if ( $r['status'] === 'partial' ) $history['stats']['failed']++;
            } elseif ( $r['status'] === 'error' ) {
                $history['stats']['failed']++;
            } elseif ( $r['status'] === 'skipped' ) {
                $history['stats']['skipped']++;
            }
        }

        update_option( 'hwt_import_history', $history, false );

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

        // Auto-detect upload format from existing working attachments.
        $profile = $this->detect_upload_profile();

        // Encode URL for special characters (e.g. Hermès).
        $encoded_url = $this->encode_url( $url );

        // If the site stores files outside /sites/{blog_id}/, override upload dir.
        $needs_dir_fix = ( is_multisite() && get_current_blog_id() > 1 && ! $profile['files_in_sites_dir'] );
        if ( $needs_dir_fix ) {
            add_filter( 'upload_dir', array( $this, 'adapt_upload_dir' ) );
        }

        $attachment_id = media_sideload_image( $encoded_url, $product_id, '', 'id' );

        if ( is_wp_error( $attachment_id ) ) {
            $attachment_id = media_sideload_image( $url, $product_id, '', 'id' );
            if ( is_wp_error( $attachment_id ) ) {
                if ( $needs_dir_fix ) {
                    remove_filter( 'upload_dir', array( $this, 'adapt_upload_dir' ) );
                }
                return $attachment_id;
            }
        }

        if ( $needs_dir_fix ) {
            remove_filter( 'upload_dir', array( $this, 'adapt_upload_dir' ) );
        }

        // If the site stores full URLs in _wp_attached_file, convert relative to full URL.
        if ( $profile['uses_full_url'] ) {
            $current_file = get_post_meta( $attachment_id, '_wp_attached_file', true );
            if ( $current_file && strpos( $current_file, 'http' ) !== 0 ) {
                if ( ! empty( $profile['base_url'] ) ) {
                    $full_url = $profile['base_url'] . '/' . $current_file;
                } else {
                    $parsed   = wp_parse_url( site_url() );
                    $base_url = $parsed['scheme'] . '://' . $parsed['host'];
                    $full_url = $base_url . '/wp-content/uploads/' . $current_file;
                }

                update_post_meta( $attachment_id, '_wp_attached_file', $full_url );

                global $wpdb;
                $wpdb->update(
                    $wpdb->posts,
                    array( 'guid' => $full_url ),
                    array( 'ID' => $attachment_id )
                );
                clean_post_cache( $attachment_id );
            }
        }

        // Store source URL for duplicate prevention.
        update_post_meta( $attachment_id, '_hwt_source_url', $url );

        // Log details.
        $this->logger->info( "  -> att_id: {$attachment_id}" );
        $this->logger->info( "  -> _wp_attached_file: " . get_post_meta( $attachment_id, '_wp_attached_file', true ) );
        $this->logger->info( "  -> wp_get_attachment_url: " . wp_get_attachment_url( $attachment_id ) );

        return $attachment_id;
    }

    /**
     * Filter: Adapt upload dir based on detected profile.
     * Strips /sites/{blog_id}/ and subsite path when the site doesn't use them.
     */
    public function adapt_upload_dir( $uploads ) {
        if ( ! is_multisite() || get_current_blog_id() <= 1 ) {
            return $uploads;
        }

        $blog_id       = get_current_blog_id();
        $sites_segment = '/sites/' . $blog_id;

        // Remove /sites/{blog_id} from paths and URLs.
        $uploads['basedir'] = str_replace( $sites_segment, '', $uploads['basedir'] );
        $uploads['path']    = str_replace( $sites_segment, '', $uploads['path'] );
        $uploads['baseurl'] = str_replace( $sites_segment, '', $uploads['baseurl'] );
        $uploads['url']     = str_replace( $sites_segment, '', $uploads['url'] );

        // Remove subsite path (e.g. /uk/) from URLs if detected profile shows it's not used.
        $blog_details = get_blog_details();
        if ( $blog_details ) {
            $subsite_path = trim( $blog_details->path, '/' );
            if ( ! empty( $subsite_path ) ) {
                $uploads['baseurl'] = str_replace( '/' . $subsite_path . '/wp-content', '/wp-content', $uploads['baseurl'] );
                $uploads['url']     = str_replace( '/' . $subsite_path . '/wp-content', '/wp-content', $uploads['url'] );
            }
        }

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
