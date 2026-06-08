<?php
/**
 * Plugin Name: Parresia Blocks
 * Description: Gutenberg blocks and sidebar widgets for Parresia editorial templates.
 * Version: 0.1.0
 * Author: Parresia
 * Requires at least: 6.2
 * Requires PHP: 7.4
 * Text Domain: parresia-blocks
 */

if (!defined('ABSPATH')) {
    exit;
}

define('PARRESIA_BLOCKS_VERSION', '0.1.0');
define('PARRESIA_BLOCKS_FILE', __FILE__);
define('PARRESIA_BLOCKS_DIR', plugin_dir_path(__FILE__));
define('PARRESIA_BLOCKS_URL', plugin_dir_url(__FILE__));

require_once PARRESIA_BLOCKS_DIR . 'includes/class-parresia-blocks.php';

add_action('plugins_loaded', static function () {
    Parresia_Blocks::instance();
});

