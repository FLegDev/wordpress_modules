<?php
/**
 * Plugin Name:       Media Slider Blocks
 * Plugin URI:        https://github.com/your-repo/media-slider-blocks
 * Description:       Blocs Gutenberg : catégories de médias + slider d'images filtrable.
 * Version:           1.2.4
 * Author:            Your Name
 * License:           GPL-2.0+
 * Text Domain:       media-slider-blocks
 * Requires at least: 6.3
 * Requires PHP:      8.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'MSB_VERSION',  '1.2.4' );
define( 'MSB_DIR',      plugin_dir_path( __FILE__ ) );
define( 'MSB_URL',      plugin_dir_url( __FILE__ ) );

// ── Core ──────────────────────────────────────────────────────────────────────
require_once MSB_DIR . 'includes/class-media-taxonomy.php';
require_once MSB_DIR . 'includes/class-media-ajax.php';

add_action( 'plugins_loaded', function () {
    MSB_Media_Taxonomy::init();
    MSB_Media_Ajax::init();
} );

// ── Enregistrement des blocs ──────────────────────────────────────────────────
add_action( 'init', function () {
    register_block_type( MSB_DIR . 'blocks/media-slider' );
    register_block_type( MSB_DIR . 'blocks/class-card' );
    register_block_type( MSB_DIR . 'blocks/diagonal-image' );
    register_block_type( MSB_DIR . 'blocks/recent-news' );
} );

// ── Données injectées dans l'éditeur (ajaxUrl + nonce) ───────────────────────
// On enqueue un script dédié et on le localise — méthode la plus fiable.
// window.msbEditor sera disponible avant que les editor.js des blocs s'exécutent.
add_action( 'enqueue_block_editor_assets', function () {
    wp_enqueue_script(
        'msb-editor-data',
        MSB_URL . 'assets/js/editor-data.js',
        [],          // pas de dépendances
        MSB_VERSION,
        false        // dans le <head> pour être disponible avant les blocs
    );
    wp_localize_script( 'msb-editor-data', 'msbEditor', [
        'ajaxUrl' => admin_url( 'admin-ajax.php' ),
        'nonce'   => wp_create_nonce( 'msb_nonce' ),
    ] );

} );

// ── Assets frontend (slider JS + CSS) ────────────────────────────────────────
add_action( 'wp_enqueue_scripts', function () {
    wp_enqueue_style(
        'msb-slider',
        MSB_URL . 'assets/css/slider.css',
        [],
        MSB_VERSION
    );
    wp_enqueue_script(
        'msb-slider',
        MSB_URL . 'assets/js/slider.js',
        [],
        MSB_VERSION,
        true
    );
} );

// ── Admin assets (médiathèque) ────────────────────────────────────────────────
add_action( 'admin_enqueue_scripts', function ( string $hook ) {
    if ( ! in_array( $hook, [ 'upload.php', 'post.php', 'post-new.php' ], true ) ) {
        return;
    }
    wp_enqueue_style( 'msb-admin', MSB_URL . 'assets/css/admin.css', [], MSB_VERSION );
} );

// ── Lightbox automatique pour le bloc Image natif Gutenberg ─────────────────
add_filter( 'render_block_core/image', function ( string $block_content, array $block ): string {
    if ( stripos( $block_content, '<a ' ) !== false ) {
        return $block_content;
    }

    if ( ! class_exists( 'WP_HTML_Tag_Processor' ) ) {
        return $block_content;
    }

    $tags = new WP_HTML_Tag_Processor( $block_content );
    if ( ! $tags->next_tag( 'img' ) ) {
        return $block_content;
    }

    $image_url = $tags->get_attribute( 'src' );
    if ( ! is_string( $image_url ) || $image_url === '' ) {
        return $block_content;
    }

    $alt = $tags->get_attribute( 'alt' );
    $tags->add_class( 'msb-lightbox-trigger' );
    $tags->add_class( 'msb-core-image-lightbox-trigger' );
    $tags->set_attribute( 'data-msb-full', esc_url( $image_url ) );
    $tags->set_attribute( 'data-msb-title', is_string( $alt ) ? esc_attr( $alt ) : '' );
    $tags->set_attribute( 'data-msb-caption', '' );
    $tags->set_attribute( 'tabindex', '0' );
    $tags->set_attribute( 'role', 'button' );

    return $tags->get_updated_html();
}, 10, 2 );
