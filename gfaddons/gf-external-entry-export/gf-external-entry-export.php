<?php
/**
 * Plugin Name: GF External Entry Export
 * Plugin URI: https://github.com/izzygld/gf-external-entry-export
 * Description: Generate secure, expiring download links for Gravity Forms entries - no WordPress admin access required for external users.
 * Version: 1.0.1
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

define( 'GF_EXTERNAL_ENTRY_EXPORT_VERSION', '1.0.1' );
define( 'GF_EXTERNAL_ENTRY_EXPORT_MIN_GF_VERSION', '2.5' );
define( 'GF_EXTERNAL_ENTRY_EXPORT_PLUGIN_PATH', plugin_dir_path( __FILE__ ) );
define( 'GF_EXTERNAL_ENTRY_EXPORT_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

/**
 * Check if Gravity Forms is active.
 *
 * Shows admin notice and deactivates plugin if GF is not available.
 */
function gf_eee_check_gravity_forms_dependency() {
    // Check if Gravity Forms is active
    if ( ! class_exists( 'GFForms' ) ) {
        add_action( 'admin_notices', 'gf_eee_missing_gravity_forms_notice' );
        add_action( 'admin_init', 'gf_eee_deactivate_self' );
        return false;
    }
    return true;
}

/**
 * Display admin notice when Gravity Forms is missing.
 */
function gf_eee_missing_gravity_forms_notice() {
    ?>
    <div class="notice notice-error is-dismissible">
        <p>
            <strong><?php esc_html_e( 'GF External Entry Export', 'gf-external-entry-export' ); ?>:</strong>
            <?php
            printf(
                /* translators: %s: Gravity Forms plugin name */
                esc_html__( 'This plugin requires %s to be installed and activated. The plugin has been deactivated.', 'gf-external-entry-export' ),
                '<strong>Gravity Forms</strong>'
            );
            ?>
        </p>
    </div>
    <?php
}

/**
 * Deactivate this plugin if Gravity Forms is not active.
 */
function gf_eee_deactivate_self() {
    // Only deactivate if we're in admin and the plugin is active
    if ( is_admin() && current_user_can( 'activate_plugins' ) ) {
        deactivate_plugins( plugin_basename( __FILE__ ) );

        // Remove the "Plugin activated" notice
        if ( isset( $_GET['activate'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            unset( $_GET['activate'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        }
    }
}

// Run dependency check on plugins_loaded (before gform_loaded)
add_action( 'plugins_loaded', 'gf_eee_check_gravity_forms_dependency', 1 );

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
        require_once GF_EXTERNAL_ENTRY_EXPORT_PLUGIN_PATH . 'includes/class-token-handler.php';
        require_once GF_EXTERNAL_ENTRY_EXPORT_PLUGIN_PATH . 'includes/class-export-handler.php';
        require_once GF_EXTERNAL_ENTRY_EXPORT_PLUGIN_PATH . 'includes/class-rest-controller.php';
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
