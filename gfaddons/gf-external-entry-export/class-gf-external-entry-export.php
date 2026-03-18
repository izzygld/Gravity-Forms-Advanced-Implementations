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

        // Register AJAX hooks early so they're available during admin-ajax.php requests.
        // GFAddOn::init_ajax() is gated behind is_gravityforms_supported() which
        // may not pass in the AJAX context, so we register unconditionally here.
        if ( defined( 'DOING_AJAX' ) && DOING_AJAX ) {
            $this->register_ajax_handlers();
        }
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
    }

    /**
     * Init admin - runs in WP admin only.
     *
     * @return void
     */
    public function init_admin() {
        parent::init_admin();

        // Add "External Export" link to form list hover actions and toolbar.
        add_filter( 'gform_toolbar_menu', array( $this, 'add_toolbar_menu_item' ), 10, 2 );
        add_filter( 'gform_form_actions', array( $this, 'add_form_action' ), 10, 2 );

        // Register AJAX handlers (also registered in init_ajax for AJAX context).
        $this->register_ajax_handlers();
    }

    /**
     * Init AJAX — runs when RG_CURRENT_PAGE is admin-ajax.php.
     *
     * GFAddOn calls init_ajax() instead of init_admin() for AJAX requests,
     * so AJAX action hooks must be registered here.
     *
     * @return void
     */
    public function init_ajax() {
        parent::init_ajax();

        $this->register_ajax_handlers();
    }

    /**
     * Register AJAX action hooks for link management.
     *
     * @return void
     */
    private function register_ajax_handlers() {
        add_action( 'wp_ajax_gf_eee_generate_link', array( $this, 'ajax_generate_link' ) );
        add_action( 'wp_ajax_gf_eee_revoke_link', array( $this, 'ajax_revoke_link' ) );
        add_action( 'wp_ajax_gf_eee_get_links', array( $this, 'ajax_get_links' ) );
        add_action( 'wp_ajax_gf_eee_regenerate_creds', array( $this, 'ajax_regenerate_creds' ) );
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
     * Add "External Export" to the form toolbar menu.
     *
     * @param array $menu_items Existing menu items.
     * @param int   $form_id    Current form ID.
     * @return array
     */
    public function add_toolbar_menu_item( $menu_items, $form_id ) {
        $menu_items['external_export'] = array(
            'label'        => esc_html__( 'External Export', 'gf-external-entry-export' ),
            'short_label'  => esc_html__( 'Export', 'gf-external-entry-export' ),
            'icon'         => '<i class="gform-icon gform-icon--circle-arrow-down"></i>',
            'url'          => admin_url( 'admin.php?page=gf_edit_forms&view=settings&subview=' . $this->get_slug() . '&id=' . absint( $form_id ) ),
            'menu_class'   => 'gf_form_toolbar_external_export',
            'link_class'   => GFForms::get_page() === 'form_settings' && rgget( 'subview' ) === $this->get_slug() ? 'gf_toolbar_active' : '',
            'capabilities' => array( 'gf_external_entry_export_form_settings', 'gravityforms_edit_forms', 'manage_options' ),
            'priority'     => 699,
        );

        return $menu_items;
    }

    /**
     * Add "External Export" to form list row actions (hover links).
     *
     * @param array $actions Existing row actions.
     * @param int   $form_id Current form ID.
     * @return array
     */
    public function add_form_action( $actions, $form_id ) {
        $actions['external_export'] = array(
            'label'        => esc_html__( 'External Export', 'gf-external-entry-export' ),
            'url'          => admin_url( 'admin.php?page=gf_edit_forms&view=settings&subview=' . $this->get_slug() . '&id=' . absint( $form_id ) ),
            'menu_class'   => 'gf_form_toolbar_external_export',
            'capabilities' => array( 'gf_external_entry_export_form_settings', 'gravityforms_edit_forms', 'manage_options' ),
            'priority'     => 699,
        );

        return $actions;
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

        // Auto-generate credentials if not set yet.
        $form_settings     = $this->get_form_settings( $form );
        if ( ! is_array( $form_settings ) ) {
            $form_settings = array();
        }
        $export_username   = rgar( $form_settings, 'export_username' );
        $export_password   = rgar( $form_settings, 'export_password' );

        if ( empty( $export_username ) || empty( $export_password ) ) {
            $export_username = 'export_' . bin2hex( random_bytes( 6 ) );
            $export_password = bin2hex( random_bytes( 16 ) );

            $form_settings['export_username'] = $export_username;
            $form_settings['export_password'] = $export_password;
            $this->save_form_settings( $form, $form_settings );
        }

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
            array(
                'title'       => esc_html__( 'Credentials', 'gf-external-entry-export' ),
                'description' => esc_html__( 'The external client must provide these credentials (HTTP Basic Auth) to download entries from this form.', 'gf-external-entry-export' ),
                'dependency'  => array(
                    'live'   => true,
                    'fields' => array(
                        array(
                            'field' => 'enable_export',
                        ),
                    ),
                ),
                'fields'      => array(
                    array(
                        'name'       => 'export_username',
                        'label'      => esc_html__( 'Username', 'gf-external-entry-export' ),
                        'type'       => 'html',
                        'html'       => '<code id="gf-eee-cred-username" style="font-size:14px;user-select:all;">'
                            . esc_html( rgar( $form_settings, 'export_username', '' ) )
                            . '</code> '
                            . '<button type="button" class="button button-small gf-eee-copy-field" data-target="gf-eee-cred-username">'
                            . esc_html__( 'Copy', 'gf-external-entry-export' )
                            . '</button>',
                        'tooltip'    => esc_html__( 'The username the external client enters to authenticate.', 'gf-external-entry-export' ),
                    ),
                    array(
                        'name'        => 'export_password_display',
                        'label'       => esc_html__( 'Password', 'gf-external-entry-export' ),
                        'type'        => 'html',
                        'html'        => '<code id="gf-eee-cred-password" style="font-size:14px;user-select:all;">'
                            . esc_html( rgar( $form_settings, 'export_password', '' ) )
                            . '</code> '
                            . '<button type="button" class="button button-small gf-eee-copy-field" data-target="gf-eee-cred-password">'
                            . esc_html__( 'Copy', 'gf-external-entry-export' )
                            . '</button>'
                            . '<br><br>'
                            . '<button type="button" class="button" id="gf-eee-regenerate-creds">'
                            . esc_html__( 'Regenerate Credentials', 'gf-external-entry-export' )
                            . '</button>',
                        'tooltip'     => esc_html__( 'The password the external client enters to authenticate.', 'gf-external-entry-export' ),
                    ),
                ),
            ),
        );
    }

    /**
     * Custom form settings page.
     *
     * Renders the standard settings fields via the GFAddOn renderer,
     * then appends the per-form link management UI below.
     *
     * @param array $form Current form object.
     * @return void
     */
    public function form_settings( $form ) {
        // Render the standard settings (enable/disable, field selection, filters).
        $renderer = $this->get_settings_renderer();
        if ( $renderer ) {
            $renderer->render();
        }

        // Render the link management UI scoped to this form.
        $this->render_form_link_management( $form );
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

            // Field types that store a single combined value despite having sub-inputs.
            $single_value_types = array( 'time', 'date', 'password' );

            // Handle multi-input fields
            if ( is_array( $field->inputs ) && ! empty( $field->inputs ) && ! in_array( $field->type, $single_value_types, true ) ) {
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

    /**
     * Add form settings menu item.
     *
     * @param array $menu_items Existing menu items.
     * @param int   $form_id    Form ID.
     * @return array
     */
    public function add_form_settings_menu( $menu_items, $form_id ) {
        $menu_items[] = array(
            'name'         => $this->_slug,
            'label'        => esc_html__( 'External Downloads', 'gf-external-entry-export' ),
            'icon'         => $this->get_menu_icon(),
            'capabilities' => array( $this->_capabilities_form_settings ),
        );
        return $menu_items;
    }

    /**
     * Get menu icon.
     *
     * @return string
     */
    public function get_menu_icon() {
        return 'gform-icon--circle-arrow-down';
    }

    /**
     * Render per-form link management UI.
     *
     * Displayed below the form settings on the External Downloads tab.
     *
     * @param array $form Current form object.
     * @return void
     */
    private function render_form_link_management( $form ) {
        $form_id       = absint( $form['id'] );
        $form_settings = $this->get_form_settings( $form );
        $is_enabled    = ! empty( $form_settings['enable_export'] );
        ?>
        <div class="gf-eee-form-management" data-form-id="<?php echo esc_attr( $form_id ); ?>">
            <hr style="margin: 30px 0;">
            <h3><?php esc_html_e( 'Export Link Management', 'gf-external-entry-export' ); ?></h3>

            <?php if ( ! $is_enabled ) : ?>
                <div class="gform-alert gform-alert--warning" style="padding: 12px 16px; margin-bottom: 16px;">
                    <p><?php esc_html_e( 'Enable "Allow generating external export links for this form" above, then save settings to manage export links.', 'gf-external-entry-export' ); ?></p>
                </div>
            <?php else : ?>

                <div class="gf-eee-generate-section">
                    <h4><?php esc_html_e( 'Generate New Export Link', 'gf-external-entry-export' ); ?></h4>

                    <input type="hidden" id="gf-eee-form-id" value="<?php echo esc_attr( $form_id ); ?>">

                    <table class="form-table gf-eee-generate-table">
                        <tr>
                            <th scope="row">
                                <label for="gf-eee-description"><?php esc_html_e( 'Description', 'gf-external-entry-export' ); ?></label>
                            </th>
                            <td>
                                <input type="text" id="gf-eee-description" name="description" class="regular-text"
                                       placeholder="<?php esc_attr_e( 'e.g., Export for Vendor ABC', 'gf-external-entry-export' ); ?>">
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label><?php esc_html_e( 'Fields to Export', 'gf-external-entry-export' ); ?></label>
                            </th>
                            <td>
                                <div id="gf-eee-fields-container">
                                    <p class="description"><?php esc_html_e( 'Loading fields...', 'gf-external-entry-export' ); ?></p>
                                </div>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="gf-eee-expiration"><?php esc_html_e( 'Link Expiration', 'gf-external-entry-export' ); ?></label>
                            </th>
                            <td>
                                <select id="gf-eee-expiration" name="expiration">
                                    <option value="1"><?php esc_html_e( '1 Hour', 'gf-external-entry-export' ); ?></option>
                                    <option value="6"><?php esc_html_e( '6 Hours', 'gf-external-entry-export' ); ?></option>
                                    <option value="24" selected><?php esc_html_e( '24 Hours', 'gf-external-entry-export' ); ?></option>
                                    <option value="168"><?php esc_html_e( '7 Days', 'gf-external-entry-export' ); ?></option>
                                    <option value="720"><?php esc_html_e( '30 Days', 'gf-external-entry-export' ); ?></option>
                                    <option value="0"><?php esc_html_e( 'Never Expires', 'gf-external-entry-export' ); ?></option>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label><?php esc_html_e( 'Date Range (Optional)', 'gf-external-entry-export' ); ?></label>
                            </th>
                            <td>
                                <input type="date" id="gf-eee-start-date" name="start_date">
                                <span><?php esc_html_e( 'to', 'gf-external-entry-export' ); ?></span>
                                <input type="date" id="gf-eee-end-date" name="end_date">
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="gf-eee-status"><?php esc_html_e( 'Entry Status', 'gf-external-entry-export' ); ?></label>
                            </th>
                            <td>
                                <select id="gf-eee-status" name="status">
                                    <option value="active"><?php esc_html_e( 'Active Only', 'gf-external-entry-export' ); ?></option>
                                    <option value="all"><?php esc_html_e( 'All Entries', 'gf-external-entry-export' ); ?></option>
                                </select>
                            </td>
                        </tr>
                    </table>

                    <p class="submit">
                        <button type="button" class="button button-primary" id="gf-eee-generate-btn">
                            <?php esc_html_e( 'Generate Export Link', 'gf-external-entry-export' ); ?>
                        </button>
                    </p>
                </div>

                <div id="gf-eee-result" class="gf-eee-result hidden">
                    <h4><?php esc_html_e( 'Export Link Generated', 'gf-external-entry-export' ); ?></h4>

                    <div class="gf-eee-credentials-section" style="background:#fff8e1;border-left:4px solid #f0c33c;padding:12px 16px;margin-bottom:16px;">
                        <p style="margin:0 0 8px;"><strong><?php esc_html_e( 'Save these credentials now — they will not be shown again.', 'gf-external-entry-export' ); ?></strong></p>
                        <table class="form-table" style="margin:0;">
                            <tr>
                                <th style="padding:4px 10px 4px 0;width:100px;"><?php esc_html_e( 'Username', 'gf-external-entry-export' ); ?></th>
                                <td style="padding:4px 0;">
                                    <code id="gf-eee-client-username" style="font-size:14px;"></code>
                                    <button type="button" class="button button-small gf-eee-copy-field" data-target="gf-eee-client-username"><?php esc_html_e( 'Copy', 'gf-external-entry-export' ); ?></button>
                                </td>
                            </tr>
                            <tr>
                                <th style="padding:4px 10px 4px 0;width:100px;"><?php esc_html_e( 'Password', 'gf-external-entry-export' ); ?></th>
                                <td style="padding:4px 0;">
                                    <code id="gf-eee-client-password" style="font-size:14px;"></code>
                                    <button type="button" class="button button-small gf-eee-copy-field" data-target="gf-eee-client-password"><?php esc_html_e( 'Copy', 'gf-external-entry-export' ); ?></button>
                                </td>
                            </tr>
                        </table>
                    </div>

                    <div class="gf-eee-url-container">
                        <label for="gf-eee-url"><strong><?php esc_html_e( 'Export URL', 'gf-external-entry-export' ); ?></strong></label>
                        <input type="text" id="gf-eee-url" readonly class="large-text" style="margin-top:4px;">
                        <button type="button" class="button" id="gf-eee-copy-btn">
                            <?php esc_html_e( 'Copy URL', 'gf-external-entry-export' ); ?>
                        </button>
                    </div>
                    <p class="description" id="gf-eee-expiry-info"></p>
                    <p class="description">
                        <?php esc_html_e( 'The external client must provide the username and password via HTTP Basic Auth to download.', 'gf-external-entry-export' ); ?>
                    </p>
                </div>

                <hr style="margin: 30px 0;">

                <div class="gf-eee-links-section">
                    <h4><?php esc_html_e( 'Active Export Links', 'gf-external-entry-export' ); ?></h4>
                    <table class="wp-list-table widefat fixed striped" id="gf-eee-links-table">
                        <thead>
                            <tr>
                                <th><?php esc_html_e( 'Description', 'gf-external-entry-export' ); ?></th>
                                <th><?php esc_html_e( 'Client Username', 'gf-external-entry-export' ); ?></th>
                                <th><?php esc_html_e( 'Created', 'gf-external-entry-export' ); ?></th>
                                <th><?php esc_html_e( 'Expires', 'gf-external-entry-export' ); ?></th>
                                <th><?php esc_html_e( 'Downloads', 'gf-external-entry-export' ); ?></th>
                                <th><?php esc_html_e( 'Actions', 'gf-external-entry-export' ); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td colspan="6"><?php esc_html_e( 'Loading...', 'gf-external-entry-export' ); ?></td>
                            </tr>
                        </tbody>
                    </table>
                </div>

            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Scripts to enqueue.
     *
     * @return array
     */
    public function scripts() {
        $scripts = array(
            array(
                'handle'  => 'gf_eee_admin',
                'src'     => $this->get_base_url() . '/assets/js/admin.js',
                'version' => $this->_version,
                'deps'    => array( 'jquery', 'wp-util', 'wp-api-request' ),
                'enqueue' => array(
                    array(
                        'admin_page' => array( 'form_settings', 'plugin_settings', 'entry_list' ),
                    ),
                ),
                'strings' => array(
                    'nonce'            => wp_create_nonce( 'gf_eee_admin' ),
                    'generating'       => esc_html__( 'Generating...', 'gf-external-entry-export' ),
                    'copied'           => esc_html__( 'Copied!', 'gf-external-entry-export' ),
                    'copy_failed'      => esc_html__( 'Copy failed', 'gf-external-entry-export' ),
                    'confirm_revoke'   => esc_html__( 'Are you sure you want to revoke this export link?', 'gf-external-entry-export' ),
                    'link_revoked'     => esc_html__( 'Link revoked', 'gf-external-entry-export' ),
                    'error'            => esc_html__( 'An error occurred', 'gf-external-entry-export' ),
                ),
            ),
        );

        return array_merge( parent::scripts(), $scripts );
    }

    /**
     * Styles to enqueue.
     *
     * @return array
     */
    public function styles() {
        $styles = array(
            array(
                'handle'  => 'gf_eee_admin',
                'src'     => $this->get_base_url() . '/assets/css/admin.css',
                'version' => $this->_version,
                'enqueue' => array(
                    array(
                        'admin_page' => array( 'form_settings', 'plugin_settings', 'entry_list' ),
                    ),
                ),
            ),
        );

        return array_merge( parent::styles(), $styles );
    }

    /**
     * AJAX: Generate export link.
     *
     * @return void
     */
    public function ajax_generate_link() {
        check_ajax_referer( 'gf_eee_admin', 'nonce' );

        if ( ! $this->current_user_can_any( array( 'gf_external_entry_export_manage_links', 'gravityforms_edit_entries', 'manage_options' ) ) ) {
            wp_send_json_error( array( 'message' => esc_html__( 'Permission denied.', 'gf-external-entry-export' ) ) );
        }

        $form_id     = absint( rgpost( 'form_id' ) );
        $fields      = array_map( 'sanitize_text_field', (array) rgpost( 'fields' ) );
        $expiration  = absint( rgpost( 'expiration' ) );
        $start_date  = sanitize_text_field( rgpost( 'start_date' ) );
        $end_date    = sanitize_text_field( rgpost( 'end_date' ) );
        $status      = sanitize_text_field( rgpost( 'status' ) );
        $description = sanitize_text_field( rgpost( 'description' ) );

        if ( ! $form_id ) {
            wp_send_json_error( array( 'message' => esc_html__( 'Invalid form ID.', 'gf-external-entry-export' ) ) );
        }

        // Validate form export is enabled
        $form_settings = $this->get_form_settings( GFAPI::get_form( $form_id ) );
        if ( empty( $form_settings['enable_export'] ) ) {
            wp_send_json_error( array( 'message' => esc_html__( 'External export is not enabled for this form.', 'gf-external-entry-export' ) ) );
        }

        $token_data = array(
            'form_id'     => $form_id,
            'fields'      => $fields,
            'start_date'  => $start_date,
            'end_date'    => $end_date,
            'status'      => $status ?: 'active',
            'created_by'  => get_current_user_id(),
            'description' => $description,
        );

        $result = $this->token_handler->generate_token( $token_data, $expiration );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( array( 'message' => $result->get_error_message() ) );
        }

        // Return the form-level credentials.
        $result['client_username'] = rgar( $form_settings, 'export_username', '' );
        $result['client_password'] = rgar( $form_settings, 'export_password', '' );

        wp_send_json_success( $result );
    }

    /**
     * AJAX: Revoke export link.
     *
     * @return void
     */
    public function ajax_revoke_link() {
        check_ajax_referer( 'gf_eee_admin', 'nonce' );

        if ( ! $this->current_user_can_any( array( 'gf_external_entry_export_manage_links', 'gravityforms_edit_entries', 'manage_options' ) ) ) {
            wp_send_json_error( array( 'message' => esc_html__( 'Permission denied.', 'gf-external-entry-export' ) ) );
        }

        $token_id = sanitize_text_field( rgpost( 'token_id' ) );

        if ( ! $token_id ) {
            wp_send_json_error( array( 'message' => esc_html__( 'Invalid token ID.', 'gf-external-entry-export' ) ) );
        }

        $result = $this->token_handler->revoke_token( $token_id );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( array( 'message' => $result->get_error_message() ) );
        }

        wp_send_json_success( array( 'message' => esc_html__( 'Link revoked successfully.', 'gf-external-entry-export' ) ) );
    }

    /**
     * AJAX: Regenerate form-level export credentials.
     *
     * Generates a new username/password pair, hashes the password, persists
     * settings, and returns the raw password to show once.
     *
     * @return void
     */
    public function ajax_regenerate_creds() {
        check_ajax_referer( 'gf_eee_admin', 'nonce' );

        if ( ! $this->current_user_can_any( array( 'gf_external_entry_export_form_settings', 'gravityforms_edit_forms', 'manage_options' ) ) ) {
            wp_send_json_error( array( 'message' => esc_html__( 'Permission denied.', 'gf-external-entry-export' ) ) );
        }

        $form_id = absint( rgpost( 'form_id' ) );
        if ( ! $form_id ) {
            wp_send_json_error( array( 'message' => esc_html__( 'Invalid form ID.', 'gf-external-entry-export' ) ) );
        }

        $form          = GFAPI::get_form( $form_id );
        $form_settings = $this->get_form_settings( $form );
        if ( ! is_array( $form_settings ) ) {
            $form_settings = array();
        }

        $new_username    = 'export_' . bin2hex( random_bytes( 6 ) );
        $raw_password    = bin2hex( random_bytes( 16 ) );

        $form_settings['export_username'] = $new_username;
        $form_settings['export_password'] = $raw_password;
        $this->save_form_settings( $form, $form_settings );

        wp_send_json_success( array(
            'username' => $new_username,
            'password' => $raw_password,
        ) );
    }

    /**
     * AJAX: Get export links for a form.
     *
     * @return void
     */
    public function ajax_get_links() {
        check_ajax_referer( 'gf_eee_admin', 'nonce' );

        if ( ! $this->current_user_can_any( array( 'gf_external_entry_export_manage_links', 'gravityforms_edit_entries', 'manage_options' ) ) ) {
            wp_send_json_error( array( 'message' => esc_html__( 'Permission denied.', 'gf-external-entry-export' ) ) );
        }

        $form_id = absint( rgpost( 'form_id' ) );

        if ( ! $form_id ) {
            wp_send_json_error( array( 'message' => esc_html__( 'Invalid form ID.', 'gf-external-entry-export' ) ) );
        }

        $links = $this->token_handler->get_tokens_for_form( $form_id );

        wp_send_json_success( array( 'links' => $links ) );
    }

    /**
     * Get base URL for this addon.
     *
     * @param string $full_path Optional full path to plugin file.
     * @return string
     */
    public function get_base_url( $full_path = '' ) {
        if ( empty( $full_path ) ) {
            $full_path = $this->_full_path;
        }
        return plugins_url( '', $full_path );
    }

    /**
     * Get base path for this addon.
     *
     * @param string $full_path Optional full path to plugin file.
     * @return string
     */
    public function get_base_path( $full_path = '' ) {
        if ( empty( $full_path ) ) {
            $full_path = $this->_full_path;
        }
        return plugin_dir_path( $full_path );
    }

    /**
     * Create custom plugin page for managing export links.
     *
     * @return void
     */
    public function plugin_page() {
        $this->render_link_management_page();
    }

    /**
     * Render the link management page.
     *
     * @return void
     */
    private function render_link_management_page() {
        $forms = GFAPI::get_forms();
        ?>
        <div class="wrap gf-eee-management">
            <h1><?php esc_html_e( 'External Downloads Overview', 'gf-external-entry-export' ); ?></h1>
            <p class="description" style="margin-bottom: 20px;">
                <?php esc_html_e( 'Manage export links from each form\'s settings. Go to a form below and click the "External Downloads" tab.', 'gf-external-entry-export' ); ?>
            </p>

            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'Form', 'gf-external-entry-export' ); ?></th>
                        <th><?php esc_html_e( 'Export Enabled', 'gf-external-entry-export' ); ?></th>
                        <th><?php esc_html_e( 'Active Links', 'gf-external-entry-export' ); ?></th>
                        <th><?php esc_html_e( 'Actions', 'gf-external-entry-export' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ( empty( $forms ) ) : ?>
                        <tr><td colspan="4"><?php esc_html_e( 'No forms found.', 'gf-external-entry-export' ); ?></td></tr>
                    <?php else : ?>
                        <?php foreach ( $forms as $form ) :
                            $form_settings = $this->get_form_settings( $form );
                            $is_enabled    = ! empty( $form_settings['enable_export'] );
                            $active_links  = $is_enabled ? $this->token_handler->get_tokens_for_form( $form['id'] ) : array();
                            $settings_url  = admin_url( 'admin.php?page=gf_edit_forms&view=settings&subview=' . $this->_slug . '&id=' . $form['id'] );
                        ?>
                            <tr>
                                <td><strong><?php echo esc_html( $form['title'] ); ?></strong></td>
                                <td>
                                    <?php if ( $is_enabled ) : ?>
                                        <span style="color:#46b450;">&#10003; <?php esc_html_e( 'Enabled', 'gf-external-entry-export' ); ?></span>
                                    <?php else : ?>
                                        <span style="color:#999;">&#10007; <?php esc_html_e( 'Disabled', 'gf-external-entry-export' ); ?></span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo count( $active_links ); ?></td>
                                <td>
                                    <a href="<?php echo esc_url( $settings_url ); ?>" class="button button-small">
                                        <?php esc_html_e( 'Manage Downloads', 'gf-external-entry-export' ); ?>
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    /**
     * Plugin page title.
     *
     * @return string
     */
    public function plugin_page_title() {
        return esc_html__( 'External Export Links', 'gf-external-entry-export' );
    }

    /**
     * Uninstall cleanup.
     *
     * @return void
     */
    public function uninstall() {
        global $wpdb;

        // Remove tokens table
        $table_name = $wpdb->prefix . 'gf_eee_tokens';
        $wpdb->query( "DROP TABLE IF EXISTS {$table_name}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

        // Remove logs table
        $logs_table = $wpdb->prefix . 'gf_eee_access_logs';
        $wpdb->query( "DROP TABLE IF EXISTS {$logs_table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

        // Remove options
        delete_option( 'gf_eee_db_version' );
    }
}
