<?php
/**
 * Plugin Name: Global Digital WP Sync
 * Description: Collects WordPress-only Global Digital metrics and pushes them to the Global Digital API.
 * Version: 0.1.0
 * Author: Global Digital
 * License: GPL-2.0-or-later
 * Text Domain: global-digital-wp-sync
 */

if (!defined('ABSPATH')) {
    exit;
}

define('GD_WP_SYNC_VERSION', '0.1.0');
define('GD_WP_SYNC_FILE', __FILE__);
define('GD_WP_SYNC_DIR', plugin_dir_path(__FILE__));
define('GD_WP_SYNC_URL', plugin_dir_url(__FILE__));

require_once GD_WP_SYNC_DIR . 'includes/class-gd-wp-sync.php';
require_once GD_WP_SYNC_DIR . 'includes/class-gd-wp-sync-collector.php';
require_once GD_WP_SYNC_DIR . 'includes/class-gd-wp-sync-api.php';
require_once GD_WP_SYNC_DIR . 'includes/class-gd-wp-sync-admin.php';

register_activation_hook(__FILE__, array('GD_WP_Sync', 'activate'));
register_deactivation_hook(__FILE__, array('GD_WP_Sync', 'deactivate'));

function gd_wp_sync()
{
    static $instance = null;

    if (null === $instance) {
        $instance = new GD_WP_Sync();
    }

    return $instance;
}

add_action('plugins_loaded', array(gd_wp_sync(), 'init'));
