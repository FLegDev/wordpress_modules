<?php
/**
 * Plugin Name: Campaigns Redirector
 * Description: Redirecteur intelligent pour newsletters avec tracking Matomo, anti-spam et optimisation performance
 * Version: 1.6.7
 * Author: FLegDevFr
 * License: GPL-2.0+
 * Text Domain: campaigns-redirector
 */

if (!defined('ABSPATH')) exit;

// Constants
define('CRMS_VERSION', '1.6.7');
define('CRMS_PATH', plugin_dir_path(__FILE__));
define('CRMS_URL', plugin_dir_url(__FILE__));
define('CRMS_PAGE_SLUG', 'campaigns');

/**
 * Autoloader
 */
spl_autoload_register(function($class) {
	if (strpos($class, 'CRMS_') === 0) {
		$file = CRMS_PATH . 'includes/class-' . strtolower(str_replace('_', '-', substr($class, 5))) . '.php';
		if (file_exists($file)) {
			require_once $file;
		}
	}
});

/**
 * Activation hook
 */
register_activation_hook(__FILE__, function() {
	CRMS_Core::activate();
	CRMS_Logger::activate();
	CRMS_Security::activate();
	CRMS_Performance::activate();
});

/**
 * Initialize plugin
 */
add_action('plugins_loaded', function() {
	CRMS_Core::maybe_migrate_options();

	CRMS_Core::instance();
	CRMS_Logger::instance();
	CRMS_Security::instance();
	CRMS_Performance::instance();
	CRMS_Admin::instance();
}, 5);
