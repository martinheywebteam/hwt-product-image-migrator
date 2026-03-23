<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class HWT_Exporter {

    private $logger;

    public function __construct( HWT_Logger $logger ) {
        $this->logger = $logger;
    }

    /**
     * Stream a CSV download of product images.
     *
     * @param array $sku_filter Optional list of SKUs to limit the export.
     */
    public function export_csv( $sku_filter = array() ) {
        // Normalize SKU filter — handle multiple delimiter types.
        $sku_filter = array_filter( array_map( 'trim', $sku_filter ) );
        $sku_filter = array_map( 'strtolower', $sku_filter );
        $sku_filter = array_values( array_unique( $sku_filter ) );

        $filename = 'hwt-product-images-' . gmdate( 'Y-m-d-His' ) . '.csv';

        // Headers.
        header( 'Content-Type: text/csv; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename=' . $filename );
        header( 'Pragma: no-cache' );
        header( 'Expires: 0' );

        $output = fopen( 'php://output', 'w' );
        fputcsv( $output, array( 'sku', 'product_id', 'product_title', 'featured_image_url', 'gallery_image_urls' ) );

        $page = 1;
        $exported = 0;

        while ( true ) {
            $args = array(
                'limit'    => 100,
                'page'     => $page,
                'status'   => array( 'publish', 'draft', 'private', 'pending' ),
                'orderby'  => 'ID',
                'order'    => 'ASC',
                'return'   => 'objects',
            );

            $products = wc_get_products( $args );

            if ( empty( $products ) ) {
                break;
            }

            foreach ( $products as $product ) {
                $sku        = $product->get_sku();
                $product_id = $product->get_id();

                // If product has no SKU, try matching by product ID.
                $match_key = ! empty( $sku ) ? strtolower( $sku ) : '';

                // Apply SKU filter if provided.
                if ( ! empty( $sku_filter ) ) {
                    $id_str = strval( $product_id );

                    // Match by SKU OR by product ID.
                    if (
                        ( ! empty( $match_key ) && in_array( $match_key, $sku_filter, true ) ) ||
                        in_array( $id_str, $sku_filter, true )
                    ) {
                        // Matched — continue to export.
                    } else {
                        continue; // Not in filter, skip.
                    }
                } else {
                    // No filter — skip products without SKUs.
                    if ( empty( $sku ) ) {
                        continue;
                    }
                }

                // Featured image.
                $featured_url = '';
                $thumbnail_id = get_post_thumbnail_id( $product_id );
                if ( $thumbnail_id ) {
                    $featured_url = wp_get_attachment_url( $thumbnail_id );
                }

                // Gallery images — pipe-delimited.
                $gallery_urls = array();
                $gallery_ids  = $product->get_gallery_image_ids();
                if ( ! empty( $gallery_ids ) ) {
                    foreach ( $gallery_ids as $gid ) {
                        $url = wp_get_attachment_url( $gid );
                        if ( $url ) {
                            $gallery_urls[] = $url;
                        }
                    }
                }

                // Skip products with no images at all.
                if ( empty( $featured_url ) && empty( $gallery_urls ) ) {
                    continue;
                }

                // Use SKU if available, otherwise product ID.
                $export_sku = ! empty( $sku ) ? $sku : $product_id;

                fputcsv( $output, array(
                    $export_sku,
                    $product_id,
                    $product->get_name(),
                    $featured_url ?: '',
                    implode( '|', $gallery_urls ),
                ) );

                $exported++;

                // Export variation images for variable products.
                if ( $product->is_type( 'variable' ) ) {
                    $variation_ids = $product->get_children();
                    foreach ( $variation_ids as $var_id ) {
                        $variation = wc_get_product( $var_id );
                        if ( ! $variation ) continue;

                        $var_sku = $variation->get_sku();
                        if ( empty( $var_sku ) ) continue;

                        // Apply SKU filter to variations too.
                        if ( ! empty( $sku_filter ) && ! in_array( strtolower( $var_sku ), $sku_filter, true ) ) {
                            continue;
                        }

                        $var_thumb_url = '';
                        $var_thumb_id  = get_post_thumbnail_id( $var_id );
                        if ( $var_thumb_id ) {
                            $var_thumb_url = wp_get_attachment_url( $var_thumb_id );
                        }

                        if ( empty( $var_thumb_url ) ) continue;

                        fputcsv( $output, array(
                            $var_sku,
                            $var_id,
                            $variation->get_name(),
                            $var_thumb_url,
                            '', // Variations don't have gallery images.
                        ) );

                        $exported++;
                    }
                }
            }

            $page++;
        }

        fclose( $output );
        exit;
    }
}
