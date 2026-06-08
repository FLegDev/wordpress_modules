<?php
/**
 * Plugin Name: OMR Word Importer
 * Description: Imports a .docx file into WordPress as editable Gutenberg blocks with images, terminal blocks, and a web table of contents.
 * Version: 0.5.1
 * Author: OMR
 * Requires at least: 6.0
 * Requires PHP: 8.0
 * Text Domain: omr-word-importer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'OMR_WORD_IMPORTER_VERSION', '0.5.1' );
define( 'OMR_WORD_IMPORTER_FILE', __FILE__ );
define( 'OMR_WORD_IMPORTER_DIR', plugin_dir_path( __FILE__ ) );
define( 'OMR_WORD_IMPORTER_URL', plugin_dir_url( __FILE__ ) );

require_once OMR_WORD_IMPORTER_DIR . 'includes/class-omr-docx-converter.php';
require_once OMR_WORD_IMPORTER_DIR . 'includes/class-omr-word-importer.php';

add_action(
	'plugins_loaded',
	static function () {
		$plugin = new OMR_Word_Importer();
		$plugin->hooks();
	}
);
