<?php
/**
 * Plugin Name: GF External Entry Export
 * Plugin URI: https://github.com/izzygld/gf-external-entry-export
 * Description: Generate secure, expiring download links for Gravity Forms entries - no WordPress admin access required for external users.
 * Version: 1.0.0
 * Author: izzygld
 * Author URI: https://github.com/izzygld
 * Text Domain: gf-external-entry-export
 * Domain Path: /languages
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

defined( 'ABSPATH' ) || exit;

define( 'GF_EXTERNAL_ENTRY_EXPORT_VERSION', '1.0.0' );
define( 'GF_EXTERNAL_ENTRY_EXPORT_MIN_GF_VERSION', '2.5' );
define( 'GF_EXTERNAL_ENTRY_EXPORT_PLUGIN_PATH', plugin_dir_path( __FILE__ ) );
define( 'GF_EXTERNAL_ENTRY_EXPORT_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

/**
 * Bootstrap class for GF External Entry Export addon.
 *
 * Handles proper initialization after Gravity Forms loads.
 * Following GF addon framework best practices from docs.gravityforms.com
 */
class GF_External_Entry_Export_Bootstrap {

    /**
     * Load the addon when Gravity Forms is ready.
     *
     * @return void
     */
    public static function load() {
        // Ensure GF addon framework is available
        if ( ! method_exists( 'GFForms', 'include_addon_framework' ) ) {
            return;
        }

        // Include addon framework
        GFForms::include_addon_framework();

        // Load dependencies
        require_once GF_EXTERNAL_ENTRY_EXPORT_PLUGIN_PATH . 'class-gf-external-entry-export.php';

        // Register the addon
        GFAddOn::register( 'GF_External_Entry_Export' );
    }

    /**
     * Get the addon instance.
     *
     * @return GF_External_Entry_Export|null
     */
    public static function get_instance() {
        return class_exists( 'GF_External_Entry_Export' ) ? GF_External_Entry_Export::get_instance() : null;
    }
}

// Initialize on gform_loaded hook (priority 5 per GF docs)
add_action( 'gform_loaded', array( 'GF_External_Entry_Export_Bootstrap', 'load' ), 5 );

/**
 * Helper function to get addon instance.
 *
 * @return GF_External_Entry_Export|null
 */
function gf_external_entry_export() {
    return GF_External_Entry_Export_Bootstrap::get_instance();
}
