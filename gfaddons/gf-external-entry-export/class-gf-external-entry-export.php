<?php
/**
 * Main GF External Entry Export Addon Class.
 *
 * Extends GFAddOn to provide secure external entry export functionality.
 *
 * @package GF_External_Entry_Export
 */

defined( 'ABSPATH' ) || exit;

/**
 * GF_External_Entry_Export class.
 *
 * Following the GFAddOn pattern from docs.gravityforms.com/gfaddon/
 */
class GF_External_Entry_Export extends GFAddOn {

    /**
     * Contains an instance of this class, if available.
     *
     * @var GF_External_Entry_Export|null
     */
    private static $_instance = null;

    /**
     * Addon version.
     *
     * @var string
     */
    protected $_version = GF_EXTERNAL_ENTRY_EXPORT_VERSION;

    /**
     * Minimum required Gravity Forms version.
     *
     * @var string
     */
    protected $_min_gravityforms_version = GF_EXTERNAL_ENTRY_EXPORT_MIN_GF_VERSION;

    /**
     * URL-safe addon slug (max 33 chars).
     *
     * @var string
     */
    protected $_slug = 'gf-external-entry-export';

    /**
     * Relative path to plugin from plugins folder.
     *
     * @var string
     */
    protected $_path = 'gf-external-entry-export/gf-external-entry-export.php';

    /**
     * Full path to main plugin file.
     *
     * @var string
     */
    protected $_full_path = __FILE__;

    /**
     * Addon title.
     *
     * @var string
     */
    protected $_title = 'Gravity Forms External Entry Export';

    /**
     * Short addon title.
     *
     * @var string
     */
    protected $_short_title = 'External Export';

    /**
     * Addon capabilities.
     *
     * @var array
     */
    protected $_capabilities = array(
        'gf_external_entry_export_settings',
        'gf_external_entry_export_form_settings',
        'gf_external_entry_export_manage_links',
    );

    /**
     * Settings capability.
     *
     * @var string
     */
    protected $_capabilities_settings_page = 'gf_external_entry_export_settings';

    /**
     * Form settings capability.
     *
     * @var string
     */
    protected $_capabilities_form_settings = 'gf_external_entry_export_form_settings';

    /**
     * Token handler instance.
     *
     * @var GF_EEE_Token_Handler
     */
    public $token_handler;

    /**
     * Export handler instance.
     *
     * @var GF_EEE_Export_Handler
     */
    public $export_handler;

    /**
     * REST controller instance.
     *
     * @var GF_EEE_REST_Controller
     */
    public $rest_controller;

    /**
     * Returns an instance of this class.
     *
     * @return GF_External_Entry_Export
     */
    public static function get_instance() {
        if ( null === self::$_instance ) {
            self::$_instance = new self();
        }
        return self::$_instance;
    }

    /**
     * Run before WordPress init.
     *
     * @return void
     */
    public function pre_init() {
        parent::pre_init();

        // Initialize handlers
        $this->token_handler  = new GF_EEE_Token_Handler();
        $this->export_handler = new GF_EEE_Export_Handler();
    }

    /**
     * Init method - runs on all pages.
     *
     * @return void
     */
    public function init() {
        parent::init();

        // Register REST routes
        add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );

        // Add form settings tab
        add_filter( 'gform_form_settings_menu', array( $this, 'add_form_settings_menu' ), 10, 2 );
    }

    /**
     * Init admin - runs in WP admin only.
     *
     * @return void
     */
    public function init_admin() {
        parent::init_admin();

        // Add export links column to entries list
        add_filter( 'gform_entry_list_columns', array( $this, 'add_entries_column' ), 10, 2 );

        // AJAX handlers for link management
        add_action( 'wp_ajax_gf_eee_generate_link', array( $this, 'ajax_generate_link' ) );
        add_action( 'wp_ajax_gf_eee_revoke_link', array( $this, 'ajax_revoke_link' ) );
        add_action( 'wp_ajax_gf_eee_get_links', array( $this, 'ajax_get_links' ) );
    }

    /**
     * Register REST API routes.
     *
     * @return void
     */
    public function register_rest_routes() {
        $this->rest_controller = new GF_EEE_REST_Controller( $this );
        $this->rest_controller->register_routes();
    }

    /**
     * Minimum requirements for this addon.
     *
     * @return array
     */
    public function minimum_requirements() {
        return array(
            'gravityforms' => array(
                'version' => $this->_min_gravityforms_version,
            ),
            'php'          => array(
                'version' => '7.4',
            ),
        );
    }


}
