<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( ! class_exists( 'PA_Ad_Details_Widget' ) ) {
    class PA_Ad_Details_Widget extends WP_Widget {
        public function __construct() {
            parent::__construct(
                'pa_ad_details_widget',
                __( 'Parresia Annonces — Détails annonce', 'parresia-annonces' )
            );
        }

        public function widget( $args, $instance ) {
            if ( ! is_singular( 'classified_ad' ) ) {
                return;
            }
            PA_Main::mark_widget_rendered();
            echo $args['before_widget'];
            echo PA_Main::render_details_html( get_queried_object_id(), false );
            echo $args['after_widget'];
        }
    }
}
