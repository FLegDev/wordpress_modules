<?php
/**
 * Plugin Name: Parresia Annonces
 * Description: Module de petites annonces 100 % maison pour WordPress.
 * Version: 1.8.1
 * Author: OpenAI
 * Text Domain: parresia-annonces
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( ! defined( 'PA_PLUGIN_VERSION' ) ) {
    define( 'PA_PLUGIN_VERSION', '1.8.1' );
}
if ( ! defined( 'PA_PLUGIN_FILE' ) ) {
    define( 'PA_PLUGIN_FILE', __FILE__ );
}
if ( ! defined( 'PA_PLUGIN_PATH' ) ) {
    define( 'PA_PLUGIN_PATH', plugin_dir_path( __FILE__ ) );
}
if ( ! defined( 'PA_PLUGIN_URL' ) ) {
    define( 'PA_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
}

require_once PA_PLUGIN_PATH . 'includes/class-pa-main.php';

register_activation_hook( __FILE__, array( 'PA_Main', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'PA_Main', 'deactivate' ) );

PA_Main::init();
