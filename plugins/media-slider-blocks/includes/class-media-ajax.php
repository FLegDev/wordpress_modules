<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class MSB_Media_Ajax {

    public static function init(): void {
        add_action( 'wp_ajax_msb_get_images',        [ __CLASS__, 'get_images' ] );
        add_action( 'wp_ajax_nopriv_msb_get_images', [ __CLASS__, 'get_images' ] );
    }

    public static function get_images(): void {
        check_ajax_referer( 'msb_nonce', 'nonce' );
        $ids    = isset( $_POST['categories'] ) ? array_map( 'intval', (array) $_POST['categories'] ) : [];
        $number = isset( $_POST['number'] )     ? absint( $_POST['number'] ) : 12;
        $ob     = isset( $_POST['order_by'] )   ? sanitize_text_field( $_POST['order_by'] ) : 'date';
        wp_send_json_success( self::query_images( $ids, $number, $ob ) );
    }

    public static function query_images( array $ids = [], int $number = 12, string $order_by = 'date' ): array {
        $ob_map = [
            'date'  => [ 'orderby' => 'date',  'order' => 'DESC' ],
            'title' => [ 'orderby' => 'title', 'order' => 'ASC'  ],
            'rand'  => [ 'orderby' => 'rand',  'order' => 'DESC' ],
        ];
        $ob = $ob_map[ $order_by ] ?? $ob_map['date'];

        $args = [
            'post_type'      => 'attachment',
            'post_mime_type' => 'image',
            'post_status'    => 'inherit',
            'posts_per_page' => $number,
            'orderby'        => $ob['orderby'],
            'order'          => $ob['order'],
        ];

        if ( ! empty( $ids ) ) {
            $args['tax_query'] = [ [
                'taxonomy' => MSB_Media_Taxonomy::TAXONOMY,
                'field'    => 'term_id',
                'terms'    => $ids,
                'operator' => 'IN',
            ] ];
        }

        $query   = new WP_Query( $args );
        $results = [];

        foreach ( $query->posts as $post ) {
            $full  = wp_get_attachment_image_src( $post->ID, 'full' );
            $large = wp_get_attachment_image_src( $post->ID, 'large' );
            $thumb = wp_get_attachment_image_src( $post->ID, 'medium_large' );

            $results[] = [
                'id'      => $post->ID,
                'title'   => get_the_title( $post->ID ),
                'caption' => wp_get_attachment_caption( $post->ID ),
                'alt'     => get_post_meta( $post->ID, '_wp_attachment_image_alt', true ),
                'url'     => get_attachment_link( $post->ID ),
                'src'     => [
                    'full'  => $full  ? $full[0]  : '',
                    'large' => $large ? $large[0] : '',
                    'thumb' => $thumb ? $thumb[0] : '',
                ],
            ];
        }

        wp_reset_postdata();
        return $results;
    }
}
