<?php
/**
 * Plugin Name: GF External Entry Export
 * Plugin URI: https://github.com/izzygld/gf-external-entry-export
 * Description: Generate secure, expiring download links for Gravity Forms entries - no WordPress admin access required for external users.
 * Version: 1.0.2
 * Author: izzygld
 * Author URI: https://github.com/izzygld
 * Text Domain: gf-external-entry-export
 * Domain Path: /languages
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

// dont let ppl access this file directly thats bad
defined( 'ABSPATH' ) || exit;

// settin up all our constant values for the plugin
define( 'GF_EXTERNAL_ENTRY_EXPORT_VERSION', '1.0.2' );
define( 'GF_EXTERNAL_ENTRY_EXPORT_MIN_GF_VERSION', '2.5' );
define( 'GF_EXTERNAL_ENTRY_EXPORT_PLUGIN_PATH', plugin_dir_path( __FILE__ ) );
define( 'GF_EXTERNAL_ENTRY_EXPORT_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

/**
 * checkin if gravity forms is installed on the site
 * if its not there we gotta show an eror and turn off the plugin
 */
function gf_eee_check_gf_installed() {
    // lookin for gravity forms class to see if its active
    if ( ! class_exists( 'GFForms' ) ) {
        add_action( 'admin_notices', 'gf_eee_show_missing_gf_error' );
        add_action( 'admin_init', 'gf_eee_turn_off_plugin' );
        return false;
    }
    return true;
}

/**
 * showin the admin error when gravity forms aint there
 * this pops up at the top of the admin area
 */
function gf_eee_show_missing_gf_error() {
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
 * turnin off the plugin if gravity forms isnt there
 * we dont want it runnin without its dependency ya know
 */
function gf_eee_turn_off_plugin() {
    // only do this if were in admin and user can activate plugins
    if ( is_admin() && current_user_can( 'activate_plugins' ) ) {
        deactivate_plugins( plugin_basename( __FILE__ ) );

        // get rid of the "Plugin activated" message cuz its misleading
        if ( isset( $_GET['activate'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            unset( $_GET['activate'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        }
    }
}

// runnin our dependency check early before gf loads
add_action( 'plugins_loaded', 'gf_eee_check_gf_installed', 1 );

/**
 * this class handles loadin up the whole plugin
 * its like the main entry point that gets everyting started
 * followin the gf addon framework patterns from their docs
 */
class GF_EEE_PLUGIN_LOADER {

    /**
     * loads up da addon when gravity forms is ready to go
     * this is where all the magic starts happenin
     *
     * @return void
     */
    public static function fire_it_up() {
        // makin sure the gf addon framework stuff is availalbe
        if ( ! method_exists( 'GFForms', 'include_addon_framework' ) ) {
            return;
        }

        // bringin in the addon framework
        GFForms::include_addon_framework();

        // loadin up all our helper classes and stuff
        require_once GF_EXTERNAL_ENTRY_EXPORT_PLUGIN_PATH . 'includes/class-token-handler.php';
        require_once GF_EXTERNAL_ENTRY_EXPORT_PLUGIN_PATH . 'includes/class-export-handler.php';
        require_once GF_EXTERNAL_ENTRY_EXPORT_PLUGIN_PATH . 'includes/class-rest-controller.php';
        require_once GF_EXTERNAL_ENTRY_EXPORT_PLUGIN_PATH . 'class-gf-external-entry-export.php';

        // registerring the addon with gravity forms
        GFAddOn::register( 'GF_EEE_MAIN_ADDON' );
    }

    /**
     * grabbin da addon instance so we can use it elsewhere
     *
     * @return GF_EEE_MAIN_ADDON|null
     */
    public static function grab_da_instance() {
        return class_exists( 'GF_EEE_MAIN_ADDON' ) ? GF_EEE_MAIN_ADDON::get_instance() : null;
    }
}

// startin everything up when gform_loaded fires (priority 5 like gf docs say)
add_action( 'gform_loaded', array( 'GF_EEE_PLUGIN_LOADER', 'fire_it_up' ), 5 );

/**
 * helper function to quickly get da addon instance
 * makes it easier to access from other places in the code
 *
 * @return GF_EEE_MAIN_ADDON|null
 */
function gf_eee_get_da_addon() {
    return GF_EEE_PLUGIN_LOADER::grab_da_instance();
}
