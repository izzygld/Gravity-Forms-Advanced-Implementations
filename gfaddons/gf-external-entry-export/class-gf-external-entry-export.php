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

    /**
     * Plugin settings fields.
     *
     * @return array
     */
    public function plugin_settings_fields() {
        return array(
            array(
                'title'       => esc_html__( 'External Entry Export Settings', 'gf-external-entry-export' ),
                'description' => esc_html__( 'Configure global settings for external entry export links.', 'gf-external-entry-export' ),
                'fields'      => array(
                    array(
                        'name'          => 'default_expiration',
                        'label'         => esc_html__( 'Default Link Expiration', 'gf-external-entry-export' ),
                        'type'          => 'select',
                        'default_value' => '24',
                        'choices'       => array(
                            array( 'label' => esc_html__( '1 Hour', 'gf-external-entry-export' ), 'value' => '1' ),
                            array( 'label' => esc_html__( '6 Hours', 'gf-external-entry-export' ), 'value' => '6' ),
                            array( 'label' => esc_html__( '24 Hours', 'gf-external-entry-export' ), 'value' => '24' ),
                            array( 'label' => esc_html__( '7 Days', 'gf-external-entry-export' ), 'value' => '168' ),
                            array( 'label' => esc_html__( '30 Days', 'gf-external-entry-export' ), 'value' => '720' ),
                            array( 'label' => esc_html__( 'Never', 'gf-external-entry-export' ), 'value' => '0' ),
                        ),
                        'tooltip'       => esc_html__( 'How long export links remain valid by default.', 'gf-external-entry-export' ),
                    ),
                    array(
                        'name'          => 'max_downloads',
                        'label'         => esc_html__( 'Max Downloads Per Link', 'gf-external-entry-export' ),
                        'type'          => 'text',
                        'input_type'    => 'number',
                        'default_value' => '10',
                        'tooltip'       => esc_html__( 'Maximum number of times a link can be used. Set to 0 for unlimited.', 'gf-external-entry-export' ),
                    ),
                    array(
                        'name'          => 'enable_logging',
                        'label'         => esc_html__( 'Enable Access Logging', 'gf-external-entry-export' ),
                        'type'          => 'checkbox',
                        'choices'       => array(
                            array(
                                'label'         => esc_html__( 'Log all export link access attempts', 'gf-external-entry-export' ),
                                'name'          => 'enable_logging',
                                'default_value' => 1,
                            ),
                        ),
                        'tooltip'       => esc_html__( 'Track when export links are accessed, including IP address and timestamp.', 'gf-external-entry-export' ),
                    ),
                    array(
                        'name'    => 'secret_key',
                        'label'   => esc_html__( 'Token Secret Key', 'gf-external-entry-export' ),
                        'type'    => 'text',
                        'class'   => 'medium code',
                        'tooltip' => esc_html__( 'Secret key used to sign export tokens. Auto-generated if empty.', 'gf-external-entry-export' ),
                        'after_input' => '<button type="button" class="button" onclick="gfEEEGenerateKey();">' . esc_html__( 'Generate', 'gf-external-entry-export' ) . '</button>',
                    ),
                ),
            ),
            array(
                'title'  => esc_html__( 'Security', 'gf-external-entry-export' ),
                'fields' => array(
                    array(
                        'name'    => 'allowed_ips',
                        'label'   => esc_html__( 'IP Allowlist', 'gf-external-entry-export' ),
                        'type'    => 'textarea',
                        'class'   => 'medium',
                        'tooltip' => esc_html__( 'Restrict export access to specific IP addresses (one per line). Leave empty to allow all.', 'gf-external-entry-export' ),
                    ),
                    array(
                        'name'    => 'require_user_agent',
                        'label'   => esc_html__( 'Require User Agent', 'gf-external-entry-export' ),
                        'type'    => 'checkbox',
                        'choices' => array(
                            array(
                                'label' => esc_html__( 'Block requests without a valid user agent', 'gf-external-entry-export' ),
                                'name'  => 'require_user_agent',
                            ),
                        ),
                    ),
                ),
            ),
        );
    }

    /**
     * Form settings fields.
     *
     * @param array $form Current form.
     * @return array
     */
    public function form_settings_fields( $form ) {
        $field_choices = $this->get_field_choices( $form );

        return array(
            array(
                'title'       => esc_html__( 'External Export Settings', 'gf-external-entry-export' ),
                'description' => esc_html__( 'Configure which fields are available for external export.', 'gf-external-entry-export' ),
                'fields'      => array(
                    array(
                        'name'    => 'enable_export',
                        'label'   => esc_html__( 'Enable External Export', 'gf-external-entry-export' ),
                        'type'    => 'checkbox',
                        'choices' => array(
                            array(
                                'label'         => esc_html__( 'Allow generating external export links for this form', 'gf-external-entry-export' ),
                                'name'          => 'enable_export',
                                'default_value' => 0,
                            ),
                        ),
                    ),
                    array(
                        'name'       => 'allowed_fields',
                        'label'      => esc_html__( 'Exportable Fields', 'gf-external-entry-export' ),
                        'type'       => 'checkbox',
                        'choices'    => $field_choices,
                        'tooltip'    => esc_html__( 'Select which fields can be included in external exports.', 'gf-external-entry-export' ),
                        'dependency' => array(
                            'live'   => true,
                            'fields' => array(
                                array(
                                    'field' => 'enable_export',
                                ),
                            ),
                        ),
                    ),
                    array(
                        'name'       => 'include_meta',
                        'label'      => esc_html__( 'Include Entry Metadata', 'gf-external-entry-export' ),
                        'type'       => 'checkbox',
                        'choices'    => array(
                            array(
                                'label' => esc_html__( 'Entry ID', 'gf-external-entry-export' ),
                                'name'  => 'include_entry_id',
                            ),
                            array(
                                'label' => esc_html__( 'Date Created', 'gf-external-entry-export' ),
                                'name'  => 'include_date_created',
                            ),
                            array(
                                'label' => esc_html__( 'Entry Status', 'gf-external-entry-export' ),
                                'name'  => 'include_status',
                            ),
                            array(
                                'label' => esc_html__( 'Source URL', 'gf-external-entry-export' ),
                                'name'  => 'include_source_url',
                            ),
                            array(
                                'label' => esc_html__( 'IP Address', 'gf-external-entry-export' ),
                                'name'  => 'include_ip',
                            ),
                        ),
                        'dependency' => array(
                            'live'   => true,
                            'fields' => array(
                                array(
                                    'field' => 'enable_export',
                                ),
                            ),
                        ),
                    ),
                ),
            ),
            array(
                'title'  => esc_html__( 'Filter Settings', 'gf-external-entry-export' ),
                'fields' => array(
                    array(
                        'name'       => 'default_status_filter',
                        'label'      => esc_html__( 'Default Entry Status', 'gf-external-entry-export' ),
                        'type'       => 'select',
                        'choices'    => array(
                            array( 'label' => esc_html__( 'Active Only', 'gf-external-entry-export' ), 'value' => 'active' ),
                            array( 'label' => esc_html__( 'All Entries', 'gf-external-entry-export' ), 'value' => 'all' ),
                            array( 'label' => esc_html__( 'Spam', 'gf-external-entry-export' ), 'value' => 'spam' ),
                            array( 'label' => esc_html__( 'Trash', 'gf-external-entry-export' ), 'value' => 'trash' ),
                        ),
                        'dependency' => array(
                            'live'   => true,
                            'fields' => array(
                                array(
                                    'field' => 'enable_export',
                                ),
                            ),
                        ),
                    ),
                    array(
                        'name'       => 'allow_date_filter',
                        'label'      => esc_html__( 'Allow Date Filtering', 'gf-external-entry-export' ),
                        'type'       => 'checkbox',
                        'choices'    => array(
                            array(
                                'label'         => esc_html__( 'Allow admins to specify date range when generating links', 'gf-external-entry-export' ),
                                'name'          => 'allow_date_filter',
                                'default_value' => 1,
                            ),
                        ),
                        'dependency' => array(
                            'live'   => true,
                            'fields' => array(
                                array(
                                    'field' => 'enable_export',
                                ),
                            ),
                        ),
                    ),
                ),
            ),
        );
    }

    /**
     * Get field choices for form settings.
     *
     * @param array $form Form object.
     * @return array
     */
    private function get_field_choices( $form ) {
        $choices = array();

        if ( empty( $form['fields'] ) ) {
            return $choices;
        }

        foreach ( $form['fields'] as $field ) {
            // Skip non-data fields
            if ( in_array( $field->type, array( 'html', 'section', 'page', 'captcha' ), true ) ) {
                continue;
            }

            $field_label = ! empty( $field->adminLabel ) ? $field->adminLabel : $field->label;

            // Handle multi-input fields
            if ( is_array( $field->inputs ) && ! empty( $field->inputs ) ) {
                foreach ( $field->inputs as $input ) {
                    if ( ! empty( $input['isHidden'] ) ) {
                        continue;
                    }
                    $input_label = ! empty( $input['label'] ) ? $input['label'] : $field_label;
                    $choices[]   = array(
                        'label' => sprintf( '%s (%s)', $field_label, $input_label ),
                        'name'  => 'field_' . str_replace( '.', '_', $input['id'] ),
                    );
                }
            } else {
                $choices[] = array(
                    'label' => $field_label,
                    'name'  => 'field_' . $field->id,
                );
            }
        }

        return $choices;
    }
}
