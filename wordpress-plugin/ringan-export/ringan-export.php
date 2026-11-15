<?php
/**
 * Plugin Name:       Ringan Export
 * Plugin URI:        https://github.com/ringan-cms/ringan-export
 * Description:       Ekspor post/CPT WordPress beserta seluruh field ACF dan media ke satu file ZIP sesuai profil JSON dari Ringan CMS.
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      8.0
 * Author:            Ringan CMS
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       ringan-export
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

define('RINGAN_EXPORT_VERSION', '1.0.0');
define('RINGAN_EXPORT_FILE', __FILE__);
define('RINGAN_EXPORT_DIR', plugin_dir_path(__FILE__));
define('RINGAN_EXPORT_URL', plugin_dir_url(__FILE__));

require_once RINGAN_EXPORT_DIR . 'includes/profile.php';
require_once RINGAN_EXPORT_DIR . 'includes/acf.php';
require_once RINGAN_EXPORT_DIR . 'includes/exporter.php';
require_once RINGAN_EXPORT_DIR . 'includes/admin.php';

add_action('admin_menu', 'ringan_export_register_menu');
add_action('admin_enqueue_scripts', 'ringan_export_enqueue_assets');

add_action('admin_post_ringan_export_save_profile', 'ringan_export_handle_save_profile');
add_action('admin_post_ringan_export_fetch_profile', 'ringan_export_handle_fetch_profile');
add_action('admin_post_ringan_export_download', 'ringan_export_handle_download');

add_action('wp_ajax_ringan_export_prepare', 'ringan_export_ajax_prepare');
add_action('wp_ajax_ringan_export_batch', 'ringan_export_ajax_batch');
add_action('wp_ajax_ringan_export_finalize', 'ringan_export_ajax_finalize');
add_action('wp_ajax_ringan_export_cancel', 'ringan_export_ajax_cancel');