<?php
/**
 * Main Izzygld Entry Export for Gravity Forms Addon Class
 *
 * this is where all the addon magic happens
 * extends GFAddOn to give us all that secure external export stuff
 *
 * @package Izzygld_Entry_Export
 */

// dont let anyone access this directly
defined( 'ABSPATH' ) || exit;

/**
 * Izzygld_EEE_Addon class
 *
 * followin the GFAddOn pattern from the gf docs
 * this handles all the settings, ajax stuff, and ui rendering
 */
class Izzygld_EEE_Addon extends GFAddOn {

    /**
     * holds an instance of this class, if we got one
     *
     * @var Izzygld_EEE_Addon|null
     */
    private static $_instance = null;

    /**
     * addon version number
     *
     * @var string
     */
    protected $_version = IZZYGLD_EEE_VERSION;

    /**
     * minimum gf version we need to work
     *
     * @var string
     */
    protected $_min_gravityforms_version = IZZYGLD_EEE_MIN_GF_VERSION;

    /**
     * url-safe addon slug, gotta be max 33 chars
     *
     * @var string
     */
    protected $_slug = 'izzygld-entry-export-for-gravity-forms';

    /**
     * path to plugin from the plugins folder
     *
     * @var string
     */
    protected $_path = 'izzygld-entry-export-for-gravity-forms/izzygld-entry-export-for-gravity-forms.php';

    /**
     * full path to the main plugin file
     *
     * @var string
     */
    protected $_full_path = __FILE__;

    /**
     * the full title of our addon
     *
     * @var string
     */
    protected $_title = 'Izzygld Entry Export for Gravity Forms';

    /**
     * shorter title for menus n stuff
     *
     * @var string
     */
    protected $_short_title = 'Izzygld Entry Export';

    /**
     * capabilites our addon uses for permissions
     *
     * @var array
     */
    protected $_capabilities = array(
        'izzygld_entry_export_settings',
        'izzygld_entry_export_form_settings',
        'izzygld_entry_export_manage_links',
    );

    /**
     * capability needed for setings page
     *
     * @var string
     */
    protected $_capabilities_settings_page = 'izzygld_entry_export_settings';

    /**
     * capability needed for form setings
     *
     * @var string
     */
    protected $_capabilities_form_settings = 'izzygld_entry_export_form_settings';

    /**
     * our token controller instance for handlin tokens
     *
     * @var Izzygld_EEE_Token_Handler
     */
    public $token_controller;

    /**
     * our export maker instance for generatin csv stuff
     *
     * @var Izzygld_EEE_Export_Handler
     */
    public $export_maker;

    /**
     * our api handler instance for rest endpoints
     *
     * @var Izzygld_EEE_REST_Controller
     */
    public $api_handler;

    /**
     * gets the singleton instance of this class
     * creates it if it dont exist yet
     *
     * @return Izzygld_EEE_Addon
     */
    public static function get_instance() {
        if ( null === self::$_instance ) {
            self::$_instance = new self();
        }
        return self::$_instance;
    }

    /**
     * runs before wordpress init kicks off
     * settin up our handler instances early
     *
     * @return void
     */
    public function pre_init() {
        parent::pre_init();

        // spinnin up our handler classes
        $this->token_controller = new Izzygld_EEE_Token_Handler();
        $this->export_maker     = new Izzygld_EEE_Export_Handler();

        // gotta hook up ajax early so its available during admin-ajax.php requests
        // cuz GFAddOn::init_ajax() has some gatekeeping that might not pass
        if ( defined( 'DOING_AJAX' ) && DOING_AJAX ) {
            $this->hookup_ajax_stuff();
        }
    }

    /**
     * init method that runs on all pages
     * settin up our rest routes here
     *
     * @return void
     */
    public function init() {
        parent::init();

        // registerin our rest api routes
        add_action( 'rest_api_init', array( $this, 'setup_rest_routes' ) );
    }

    /**
     * init admin runs only in wp admin area
     * settin up admin-specific hooks here
     *
     * @return void
     */
    public function init_admin() {
        parent::init_admin();

        // addin "External Export" link to form list toolbar and hover actions
        add_filter( 'gform_toolbar_menu', array( $this, 'add_toolbar_menu_item' ), 10, 2 );
        add_filter( 'gform_form_actions', array( $this, 'add_form_action' ), 10, 2 );

        // registerin ajax handlers here too
        $this->hookup_ajax_stuff();
    }

    /**
     * init ajax runs when we're in an ajax request
     * GFAddOn calls this instead of init_admin for ajax stuff
     *
     * @return void
     */
    public function init_ajax() {
        parent::init_ajax();

        $this->hookup_ajax_stuff();
    }

    /**
     * hookin up all our ajax action handlers
     * these handle link generation, revoking, n stuff
     *
     * @return void
     */
    private function hookup_ajax_stuff() {
        add_action( 'wp_ajax_izzygld_eee_generate_link', array( $this, 'ajax_make_da_link' ) );
        add_action( 'wp_ajax_izzygld_eee_revoke_link', array( $this, 'ajax_kill_da_link' ) );
        add_action( 'wp_ajax_izzygld_eee_get_links', array( $this, 'ajax_grab_links' ) );
        add_action( 'wp_ajax_izzygld_eee_regenerate_creds', array( $this, 'ajax_remake_creds' ) );
    }

    /**
     * settin up our rest api routes
     * creates the api handler and registers everything
     *
     * @return void
     */
    public function setup_rest_routes() {
        $this->api_handler = new Izzygld_EEE_REST_Controller( $this );
        $this->api_handler->setup_da_routes();
    }

    /**
     * addin "External Export" to the form toolbar menu
     * shows up at the top when editin a form
     *
     * @param array $da_menu_items existin menu items
     * @param int   $da_form_id    current form id
     * @return array
     */
    public function add_toolbar_menu_item( $da_menu_items, $da_form_id ) {
        $da_menu_items['external_export'] = array(
            'label'        => esc_html__( 'External Export', 'izzygld-entry-export-for-gravity-forms' ),
            'short_label'  => esc_html__( 'Export', 'izzygld-entry-export-for-gravity-forms' ),
            'icon'         => '<i class="gform-icon gform-icon--circle-arrow-down"></i>',
            'url'          => admin_url( 'admin.php?page=gf_edit_forms&view=settings&subview=' . $this->get_slug() . '&id=' . absint( $da_form_id ) ),
            'menu_class'   => 'gf_form_toolbar_external_export',
            'link_class'   => GFForms::get_page() === 'form_settings' && rgget( 'subview' ) === $this->get_slug() ? 'gf_toolbar_active' : '',
            'capabilities' => array( 'izzygld_entry_export_form_settings', 'gravityforms_edit_forms', 'manage_options' ),
            'priority'     => 699,
        );

        return $da_menu_items;
    }

    /**
     * addin "External Export" to form list row actions
     * shows up when you hover over a form in the list
     *
     * @param array $da_actions existin row actions
     * @param int   $da_form_id current form id
     * @return array
     */
    public function add_form_action( $da_actions, $da_form_id ) {
        $da_actions['external_export'] = array(
            'label'        => esc_html__( 'External Export', 'izzygld-entry-export-for-gravity-forms' ),
            'url'          => admin_url( 'admin.php?page=gf_edit_forms&view=settings&subview=' . $this->get_slug() . '&id=' . absint( $da_form_id ) ),
            'menu_class'   => 'gf_form_toolbar_external_export',
            'capabilities' => array( 'izzygld_entry_export_form_settings', 'gravityforms_edit_forms', 'manage_options' ),
            'priority'     => 699,
        );

        return $da_actions;
    }

    /**
     * minimum requirments to run this addon
     * checkin php version and gf version
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
     * plugin setings fields for the global config page
     * these are the main settings before per-form stuff
     *
     * @return array
     */
    public function plugin_settings_fields() {
        return array(
            array(
                'title'       => esc_html__( 'External Entry Export Settings', 'izzygld-entry-export-for-gravity-forms' ),
                'description' => esc_html__( 'Configure global settings for external entry export links.', 'izzygld-entry-export-for-gravity-forms' ),
                'fields'      => array(
                    array(
                        'name'          => 'default_expiration',
                        'label'         => esc_html__( 'Default Link Expiration', 'izzygld-entry-export-for-gravity-forms' ),
                        'type'          => 'select',
                        'default_value' => '24',
                        'choices'       => array(
                            array( 'label' => esc_html__( '1 Hour', 'izzygld-entry-export-for-gravity-forms' ), 'value' => '1' ),
                            array( 'label' => esc_html__( '6 Hours', 'izzygld-entry-export-for-gravity-forms' ), 'value' => '6' ),
                            array( 'label' => esc_html__( '24 Hours', 'izzygld-entry-export-for-gravity-forms' ), 'value' => '24' ),
                            array( 'label' => esc_html__( '7 Days', 'izzygld-entry-export-for-gravity-forms' ), 'value' => '168' ),
                            array( 'label' => esc_html__( '30 Days', 'izzygld-entry-export-for-gravity-forms' ), 'value' => '720' ),
                            array( 'label' => esc_html__( 'Never', 'izzygld-entry-export-for-gravity-forms' ), 'value' => '0' ),
                        ),
                        'tooltip'       => esc_html__( 'How long export links remain valid by default.', 'izzygld-entry-export-for-gravity-forms' ),
                    ),
                    array(
                        'name'          => 'max_downloads',
                        'label'         => esc_html__( 'Max Downloads Per Link', 'izzygld-entry-export-for-gravity-forms' ),
                        'type'          => 'text',
                        'input_type'    => 'number',
                        'default_value' => '10',
                        'tooltip'       => esc_html__( 'Maximum number of times a link can be used. Set to 0 for unlimited.', 'izzygld-entry-export-for-gravity-forms' ),
                    ),
                    array(
                        'name'          => 'enable_logging',
                        'label'         => esc_html__( 'Enable Access Logging', 'izzygld-entry-export-for-gravity-forms' ),
                        'type'          => 'checkbox',
                        'choices'       => array(
                            array(
                                'label'         => esc_html__( 'Log all export link access attempts', 'izzygld-entry-export-for-gravity-forms' ),
                                'name'          => 'enable_logging',
                                'default_value' => 1,
                            ),
                        ),
                        'tooltip'       => esc_html__( 'Track when export links are accessed, including IP address and timestamp.', 'izzygld-entry-export-for-gravity-forms' ),
                    ),
                    array(
                        'name'    => 'secret_key',
                        'label'   => esc_html__( 'Token Secret Key', 'izzygld-entry-export-for-gravity-forms' ),
                        'type'    => 'text',
                        'class'   => 'medium code',
                        'tooltip' => esc_html__( 'Secret key used to sign export tokens. Auto-generated if empty.', 'izzygld-entry-export-for-gravity-forms' ),
                        'after_input' => '<button type="button" class="button" onclick="gfEEEGenerateKey();">' . esc_html__( 'Generate', 'izzygld-entry-export-for-gravity-forms' ) . '</button>',
                    ),
                ),
            ),
            array(
                'title'  => esc_html__( 'Security', 'izzygld-entry-export-for-gravity-forms' ),
                'fields' => array(
                    array(
                        'name'    => 'allowed_ips',
                        'label'   => esc_html__( 'IP Allowlist', 'izzygld-entry-export-for-gravity-forms' ),
                        'type'    => 'textarea',
                        'class'   => 'medium',
                        'tooltip' => esc_html__( 'Restrict export access to specific IP addresses (one per line). Leave empty to allow all.', 'izzygld-entry-export-for-gravity-forms' ),
                    ),
                    array(
                        'name'    => 'require_user_agent',
                        'label'   => esc_html__( 'Require User Agent', 'izzygld-entry-export-for-gravity-forms' ),
                        'type'    => 'checkbox',
                        'choices' => array(
                            array(
                                'label' => esc_html__( 'Block requests without a valid user agent', 'izzygld-entry-export-for-gravity-forms' ),
                                'name'  => 'require_user_agent',
                            ),
                        ),
                    ),
                ),
            ),
        );
    }

    /**
     * form settings fields for per-form configuration
     * this is where admins pick which fields to export n stuff
     *
     * @param array $da_form current form object
     * @return array
     */
    public function form_settings_fields( $da_form ) {
        $da_field_choices = $this->grab_field_choices( $da_form );

        // auto-generate creds if they aint set yet
        $da_form_settings   = $this->get_form_settings( $da_form );
        if ( ! is_array( $da_form_settings ) ) {
            $da_form_settings = array();
        }
        $da_export_username = rgar( $da_form_settings, 'export_username' );
        $da_export_password = rgar( $da_form_settings, 'export_password' );

        if ( empty( $da_export_username ) || empty( $da_export_password ) ) {
            $da_export_username = 'export_' . bin2hex( random_bytes( 6 ) );
            $da_export_password = bin2hex( random_bytes( 16 ) );

            $da_form_settings['export_username'] = $da_export_username;
            $da_form_settings['export_password'] = $da_export_password;
            $this->save_form_settings( $da_form, $da_form_settings );
        }

        return array(
            array(
                'title'       => esc_html__( 'External Export Settings', 'izzygld-entry-export-for-gravity-forms' ),
                'description' => esc_html__( 'Configure which fields are available for external export.', 'izzygld-entry-export-for-gravity-forms' ),
                'fields'      => array(
                    array(
                        'name'    => 'enable_export',
                        'label'   => esc_html__( 'Enable External Export', 'izzygld-entry-export-for-gravity-forms' ),
                        'type'    => 'checkbox',
                        'choices' => array(
                            array(
                                'label'         => esc_html__( 'Allow generating external export links for this form', 'izzygld-entry-export-for-gravity-forms' ),
                                'name'          => 'enable_export',
                                'default_value' => 0,
                            ),
                        ),
                    ),
                    array(
                        'name'       => 'allowed_fields',
                        'label'      => esc_html__( 'Exportable Fields', 'izzygld-entry-export-for-gravity-forms' ),
                        'type'       => 'checkbox',
                        'choices'    => $da_field_choices,
                        'tooltip'    => esc_html__( 'Select which fields can be included in external exports.', 'izzygld-entry-export-for-gravity-forms' ),
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
                        'label'      => esc_html__( 'Include Entry Metadata', 'izzygld-entry-export-for-gravity-forms' ),
                        'type'       => 'checkbox',
                        'choices'    => array(
                            array(
                                'label' => esc_html__( 'Entry ID', 'izzygld-entry-export-for-gravity-forms' ),
                                'name'  => 'include_entry_id',
                            ),
                            array(
                                'label' => esc_html__( 'Date Created', 'izzygld-entry-export-for-gravity-forms' ),
                                'name'  => 'include_date_created',
                            ),
                            array(
                                'label' => esc_html__( 'Entry Status', 'izzygld-entry-export-for-gravity-forms' ),
                                'name'  => 'include_status',
                            ),
                            array(
                                'label' => esc_html__( 'Source URL', 'izzygld-entry-export-for-gravity-forms' ),
                                'name'  => 'include_source_url',
                            ),
                            array(
                                'label' => esc_html__( 'IP Address', 'izzygld-entry-export-for-gravity-forms' ),
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
                'title'  => esc_html__( 'Filter Settings', 'izzygld-entry-export-for-gravity-forms' ),
                'fields' => array(
                    array(
                        'name'       => 'default_status_filter',
                        'label'      => esc_html__( 'Default Entry Status', 'izzygld-entry-export-for-gravity-forms' ),
                        'type'       => 'select',
                        'choices'    => array(
                            array( 'label' => esc_html__( 'Active Only', 'izzygld-entry-export-for-gravity-forms' ), 'value' => 'active' ),
                            array( 'label' => esc_html__( 'All Entries', 'izzygld-entry-export-for-gravity-forms' ), 'value' => 'all' ),
                            array( 'label' => esc_html__( 'Spam', 'izzygld-entry-export-for-gravity-forms' ), 'value' => 'spam' ),
                            array( 'label' => esc_html__( 'Trash', 'izzygld-entry-export-for-gravity-forms' ), 'value' => 'trash' ),
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
                        'label'      => esc_html__( 'Allow Date Filtering', 'izzygld-entry-export-for-gravity-forms' ),
                        'type'       => 'checkbox',
                        'choices'    => array(
                            array(
                                'label'         => esc_html__( 'Allow admins to specify date range when generating links', 'izzygld-entry-export-for-gravity-forms' ),
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
                'title'       => esc_html__( 'Credentials', 'izzygld-entry-export-for-gravity-forms' ),
                'description' => esc_html__( 'The external client must provide these credentials (HTTP Basic Auth) to download entries from this form.', 'izzygld-entry-export-for-gravity-forms' ),
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
                        'label'      => esc_html__( 'Username', 'izzygld-entry-export-for-gravity-forms' ),
                        'type'       => 'html',
                        'html'       => '<code id="izzygld-eee-cred-username" style="font-size:14px;user-select:all;">'
                            . esc_html( rgar( $da_form_settings, 'export_username', '' ) )
                            . '</code> '
                            . '<button type="button" class="button button-small izzygld-eee-copy-field" data-target="izzygld-eee-cred-username">'
                            . esc_html__( 'Copy', 'izzygld-entry-export-for-gravity-forms' )
                            . '</button>',
                        'tooltip'    => esc_html__( 'The username the external client enters to authenticate.', 'izzygld-entry-export-for-gravity-forms' ),
                    ),
                    array(
                        'name'        => 'export_password_display',
                        'label'       => esc_html__( 'Password', 'izzygld-entry-export-for-gravity-forms' ),
                        'type'        => 'html',
                        'html'        => '<code id="izzygld-eee-cred-password" style="font-size:14px;user-select:all;">'
                            . esc_html( rgar( $da_form_settings, 'export_password', '' ) )
                            . '</code> '
                            . '<button type="button" class="button button-small izzygld-eee-copy-field" data-target="izzygld-eee-cred-password">'
                            . esc_html__( 'Copy', 'izzygld-entry-export-for-gravity-forms' )
                            . '</button>'
                            . '<br><br>'
                            . '<button type="button" class="button" id="izzygld-eee-regenerate-creds">'
                            . esc_html__( 'Regenerate Credentials', 'izzygld-entry-export-for-gravity-forms' )
                            . '</button>',
                        'tooltip'     => esc_html__( 'The password the external client enters to authenticate.', 'izzygld-entry-export-for-gravity-forms' ),
                    ),
                ),
            ),
        );
    }

    /**
     * custom form settings page renderer
     * renders the standard settings then adds our link managment ui below
     *
     * @param array $da_form current form object
     * @return void
     */
    public function form_settings( $da_form ) {
        // renderin the standard settings first (enable/disable, field selection, filters)
        $da_renderer = $this->get_settings_renderer();
        if ( $da_renderer ) {
            $da_renderer->render();
        }

        // now render our link managment ui for this form
        $this->render_form_link_ui( $da_form );
    }

    /**
     * grabbin field choices for the form settings checkboxes
     * goes thru all the form fields and makes checkbox options
     *
     * @param array $da_form form object
     * @return array
     */
    private function grab_field_choices( $da_form ) {
        $da_choices = array();

        if ( empty( $da_form['fields'] ) ) {
            return $da_choices;
        }

        foreach ( $da_form['fields'] as $da_field ) {
            // skippin non-data fields like html sections etc
            if ( in_array( $da_field->type, array( 'html', 'section', 'page', 'captcha' ), true ) ) {
                continue;
            }

            $da_field_label = ! empty( $da_field->adminLabel ) ? $da_field->adminLabel : $da_field->label;

            // field types that store one combined value even tho they got sub-inputs
            $single_val_types = array( 'time', 'date', 'password' );

            // handlin multi-input fields like name, address, etc
            if ( is_array( $da_field->inputs ) && ! empty( $da_field->inputs ) && ! in_array( $da_field->type, $single_val_types, true ) ) {
                foreach ( $da_field->inputs as $da_input ) {
                    if ( ! empty( $da_input['isHidden'] ) ) {
                        continue;
                    }
                    $da_input_label = ! empty( $da_input['label'] ) ? $da_input['label'] : $da_field_label;
                    $da_choices[]   = array(
                        'label' => sprintf( '%s (%s)', $da_field_label, $da_input_label ),
                        'name'  => 'field_' . str_replace( '.', '_', $da_input['id'] ),
                    );
                }
            } else {
                $da_choices[] = array(
                    'label' => $da_field_label,
                    'name'  => 'field_' . $da_field->id,
                );
            }
        }

        return $da_choices;
    }

    /**
     * addin our form settings menu item
     * shows up in the form settings sidebar
     *
     * @param array $da_menu_items existin menu items
     * @param int   $da_form_id    form id
     * @return array
     */
    public function add_form_settings_menu( $da_menu_items, $da_form_id ) {
        $da_menu_items[] = array(
            'name'         => $this->_slug,
            'label'        => esc_html__( 'External Downloads', 'izzygld-entry-export-for-gravity-forms' ),
            'icon'         => $this->get_menu_icon(),
            'capabilities' => array( $this->_capabilities_form_settings ),
        );
        return $da_menu_items;
    }

    /**
     * gettin the menu icon for our addon
     *
     * @return string
     */
    public function get_menu_icon() {
        return 'gform-icon--circle-arrow-down';
    }

    /**
     * renderin the per-form link managment ui
     * this shows up below the form settings stuff
     *
     * @param array $da_form current form object
     * @return void
     */
    private function render_form_link_ui( $da_form ) {
        $da_form_id       = absint( $da_form['id'] );
        $da_form_settings = $this->get_form_settings( $da_form );
        $is_turned_on     = ! empty( $da_form_settings['enable_export'] );
        ?>
        <div class="izzygld-eee-form-management" data-form-id="<?php echo esc_attr( $da_form_id ); ?>">
            <hr style="margin: 30px 0;">
            <h3><?php esc_html_e( 'Export Link Management', 'izzygld-entry-export-for-gravity-forms' ); ?></h3>

            <?php if ( ! $is_turned_on ) : ?>
                <div class="gform-alert gform-alert--warning" style="padding: 12px 16px; margin-bottom: 16px;">
                    <p><?php esc_html_e( 'Enable "Allow generating external export links for this form" above, then save settings to manage export links.', 'izzygld-entry-export-for-gravity-forms' ); ?></p>
                </div>
            <?php else : ?>

                <div class="izzygld-eee-generate-section">
                    <h4><?php esc_html_e( 'Generate New Export Link', 'izzygld-entry-export-for-gravity-forms' ); ?></h4>

                    <input type="hidden" id="izzygld-eee-form-id" value="<?php echo esc_attr( $da_form_id ); ?>">

                    <table class="form-table izzygld-eee-generate-table">
                        <tr>
                            <th scope="row">
                                <label for="izzygld-eee-description"><?php esc_html_e( 'Description', 'izzygld-entry-export-for-gravity-forms' ); ?></label>
                            </th>
                            <td>
                                <input type="text" id="izzygld-eee-description" name="description" class="regular-text"
                                       placeholder="<?php esc_attr_e( 'e.g., Export for Vendor ABC', 'izzygld-entry-export-for-gravity-forms' ); ?>">
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label><?php esc_html_e( 'Fields to Export', 'izzygld-entry-export-for-gravity-forms' ); ?></label>
                            </th>
                            <td>
                                <div id="izzygld-eee-fields-container">
                                    <p class="description"><?php esc_html_e( 'Loading fields...', 'izzygld-entry-export-for-gravity-forms' ); ?></p>
                                </div>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="izzygld-eee-expiration"><?php esc_html_e( 'Link Expiration', 'izzygld-entry-export-for-gravity-forms' ); ?></label>
                            </th>
                            <td>
                                <select id="izzygld-eee-expiration" name="expiration">
                                    <option value="1"><?php esc_html_e( '1 Hour', 'izzygld-entry-export-for-gravity-forms' ); ?></option>
                                    <option value="6"><?php esc_html_e( '6 Hours', 'izzygld-entry-export-for-gravity-forms' ); ?></option>
                                    <option value="24" selected><?php esc_html_e( '24 Hours', 'izzygld-entry-export-for-gravity-forms' ); ?></option>
                                    <option value="168"><?php esc_html_e( '7 Days', 'izzygld-entry-export-for-gravity-forms' ); ?></option>
                                    <option value="720"><?php esc_html_e( '30 Days', 'izzygld-entry-export-for-gravity-forms' ); ?></option>
                                    <option value="0"><?php esc_html_e( 'Never Expires', 'izzygld-entry-export-for-gravity-forms' ); ?></option>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label><?php esc_html_e( 'Date Range (Optional)', 'izzygld-entry-export-for-gravity-forms' ); ?></label>
                            </th>
                            <td>
                                <input type="date" id="izzygld-eee-start-date" name="start_date">
                                <span><?php esc_html_e( 'to', 'izzygld-entry-export-for-gravity-forms' ); ?></span>
                                <input type="date" id="izzygld-eee-end-date" name="end_date">
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="izzygld-eee-status"><?php esc_html_e( 'Entry Status', 'izzygld-entry-export-for-gravity-forms' ); ?></label>
                            </th>
                            <td>
                                <select id="izzygld-eee-status" name="status">
                                    <option value="active"><?php esc_html_e( 'Active Only', 'izzygld-entry-export-for-gravity-forms' ); ?></option>
                                    <option value="all"><?php esc_html_e( 'All Entries', 'izzygld-entry-export-for-gravity-forms' ); ?></option>
                                </select>
                            </td>
                        </tr>
                    </table>

                    <p class="submit">
                        <button type="button" class="button button-primary" id="izzygld-eee-generate-btn">
                            <?php esc_html_e( 'Generate Export Link', 'izzygld-entry-export-for-gravity-forms' ); ?>
                        </button>
                    </p>
                </div>

                <div id="izzygld-eee-result" class="izzygld-eee-result hidden">
                    <h4><?php esc_html_e( 'Export Link Generated', 'izzygld-entry-export-for-gravity-forms' ); ?></h4>

                    <div class="izzygld-eee-credentials-section" style="background:#fff8e1;border-left:4px solid #f0c33c;padding:12px 16px;margin-bottom:16px;">
                        <p style="margin:0 0 8px;"><strong><?php esc_html_e( 'Save these credentials now — they will not be shown again.', 'izzygld-entry-export-for-gravity-forms' ); ?></strong></p>
                        <table class="form-table" style="margin:0;">
                            <tr>
                                <th style="padding:4px 10px 4px 0;width:100px;"><?php esc_html_e( 'Username', 'izzygld-entry-export-for-gravity-forms' ); ?></th>
                                <td style="padding:4px 0;">
                                    <code id="izzygld-eee-client-username" style="font-size:14px;"></code>
                                    <button type="button" class="button button-small izzygld-eee-copy-field" data-target="izzygld-eee-client-username"><?php esc_html_e( 'Copy', 'izzygld-entry-export-for-gravity-forms' ); ?></button>
                                </td>
                            </tr>
                            <tr>
                                <th style="padding:4px 10px 4px 0;width:100px;"><?php esc_html_e( 'Password', 'izzygld-entry-export-for-gravity-forms' ); ?></th>
                                <td style="padding:4px 0;">
                                    <code id="izzygld-eee-client-password" style="font-size:14px;"></code>
                                    <button type="button" class="button button-small izzygld-eee-copy-field" data-target="izzygld-eee-client-password"><?php esc_html_e( 'Copy', 'izzygld-entry-export-for-gravity-forms' ); ?></button>
                                </td>
                            </tr>
                        </table>
                    </div>

                    <div class="izzygld-eee-url-container">
                        <label for="izzygld-eee-url"><strong><?php esc_html_e( 'Export URL', 'izzygld-entry-export-for-gravity-forms' ); ?></strong></label>
                        <input type="text" id="izzygld-eee-url" readonly class="large-text" style="margin-top:4px;">
                        <button type="button" class="button" id="izzygld-eee-copy-btn">
                            <?php esc_html_e( 'Copy URL', 'izzygld-entry-export-for-gravity-forms' ); ?>
                        </button>
                    </div>
                    <p class="description" id="izzygld-eee-expiry-info"></p>
                    <p class="description">
                        <?php esc_html_e( 'The external client must provide the username and password via HTTP Basic Auth to download.', 'izzygld-entry-export-for-gravity-forms' ); ?>
                    </p>
                </div>

                <hr style="margin: 30px 0;">

                <div class="izzygld-eee-links-section">
                    <h4><?php esc_html_e( 'Active Export Links', 'izzygld-entry-export-for-gravity-forms' ); ?></h4>
                    <table class="wp-list-table widefat fixed striped" id="izzygld-eee-links-table">
                        <thead>
                            <tr>
                                <th><?php esc_html_e( 'Description', 'izzygld-entry-export-for-gravity-forms' ); ?></th>
                                <th><?php esc_html_e( 'Client Username', 'izzygld-entry-export-for-gravity-forms' ); ?></th>
                                <th><?php esc_html_e( 'Created', 'izzygld-entry-export-for-gravity-forms' ); ?></th>
                                <th><?php esc_html_e( 'Expires', 'izzygld-entry-export-for-gravity-forms' ); ?></th>
                                <th><?php esc_html_e( 'Downloads', 'izzygld-entry-export-for-gravity-forms' ); ?></th>
                                <th><?php esc_html_e( 'Actions', 'izzygld-entry-export-for-gravity-forms' ); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td colspan="6"><?php esc_html_e( 'Loading...', 'izzygld-entry-export-for-gravity-forms' ); ?></td>
                            </tr>
                        </tbody>
                    </table>
                </div>

            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * scripts we need to load up
     * enqueuin our admin js file
     *
     * @return array
     */
    public function scripts() {
        $da_scripts = array(
            array(
                'handle'  => 'izzygld_eee_admin',
                'src'     => $this->get_base_url() . '/assets/js/admin.js',
                'version' => $this->_version,
                'deps'    => array( 'jquery', 'wp-util', 'wp-api-request' ),
                'enqueue' => array(
                    array(
                        'admin_page' => array( 'form_settings', 'plugin_settings', 'entry_list' ),
                    ),
                ),
                'strings' => array(
                    'nonce'            => wp_create_nonce( 'izzygld_eee_admin' ),
                    'generating'       => esc_html__( 'Generating...', 'izzygld-entry-export-for-gravity-forms' ),
                    'copied'           => esc_html__( 'Copied!', 'izzygld-entry-export-for-gravity-forms' ),
                    'copy_failed'      => esc_html__( 'Copy failed', 'izzygld-entry-export-for-gravity-forms' ),
                    'confirm_revoke'   => esc_html__( 'Are you sure you want to revoke this export link?', 'izzygld-entry-export-for-gravity-forms' ),
                    'link_revoked'     => esc_html__( 'Link revoked', 'izzygld-entry-export-for-gravity-forms' ),
                    'error'            => esc_html__( 'An error occurred', 'izzygld-entry-export-for-gravity-forms' ),
                ),
            ),
        );

        return array_merge( parent::scripts(), $da_scripts );
    }

    /**
     * styles we need to load up
     * enqueuin our admin css
     *
     * @return array
     */
    public function styles() {
        $da_styles = array(
            array(
                'handle'  => 'izzygld_eee_admin',
                'src'     => $this->get_base_url() . '/assets/css/admin.css',
                'version' => $this->_version,
                'enqueue' => array(
                    array(
                        'admin_page' => array( 'form_settings', 'plugin_settings', 'entry_list' ),
                    ),
                ),
            ),
        );

        return array_merge( parent::styles(), $da_styles );
    }

    /**
     * ajax handler for makin da export link
     * this is called when admin clicks generate button
     *
     * @return void
     */
    public function ajax_make_da_link() {
        check_ajax_referer( 'izzygld_eee_admin', 'nonce' );

        if ( ! $this->current_user_can_any( array( 'izzygld_entry_export_manage_links', 'gravityforms_edit_entries', 'manage_options' ) ) ) {
            wp_send_json_error( array( 'message' => esc_html__( 'Permission denied.', 'izzygld-entry-export-for-gravity-forms' ) ) );
        }

        $da_form_id     = absint( rgpost( 'form_id' ) );
        $da_fields      = array_map( 'sanitize_text_field', (array) rgpost( 'fields' ) );
        $da_expiration  = absint( rgpost( 'expiration' ) );
        $da_start_date  = sanitize_text_field( rgpost( 'start_date' ) );
        $da_end_date    = sanitize_text_field( rgpost( 'end_date' ) );
        $da_status      = sanitize_text_field( rgpost( 'status' ) );
        $da_description = sanitize_text_field( rgpost( 'description' ) );

        if ( ! $da_form_id ) {
            wp_send_json_error( array( 'message' => esc_html__( 'Invalid form ID.', 'izzygld-entry-export-for-gravity-forms' ) ) );
        }

        // makin sure export is enabled for this form
        $da_form_settings = $this->get_form_settings( GFAPI::get_form( $da_form_id ) );
        if ( empty( $da_form_settings['enable_export'] ) ) {
            wp_send_json_error( array( 'message' => esc_html__( 'External export is not enabled for this form.', 'izzygld-entry-export-for-gravity-forms' ) ) );
        }

        $da_token_data = array(
            'form_id'     => $da_form_id,
            'fields'      => $da_fields,
            'start_date'  => $da_start_date,
            'end_date'    => $da_end_date,
            'status'      => $da_status ?: 'active',
            'created_by'  => get_current_user_id(),
            'description' => $da_description,
        );

        $da_result = $this->token_controller->make_da_token( $da_token_data, $da_expiration );

        if ( is_wp_error( $da_result ) ) {
            wp_send_json_error( array( 'message' => $da_result->get_error_message() ) );
        }

        // sendin back the form-level credentials too
        $da_result['client_username'] = rgar( $da_form_settings, 'export_username', '' );
        $da_result['client_password'] = rgar( $da_form_settings, 'export_password', '' );

        wp_send_json_success( $da_result );
    }

    /**
     * ajax handler for killin da export link
     * revokes a link so it cant be used no more
     *
     * @return void
     */
    public function ajax_kill_da_link() {
        check_ajax_referer( 'izzygld_eee_admin', 'nonce' );

        if ( ! $this->current_user_can_any( array( 'izzygld_entry_export_manage_links', 'gravityforms_edit_entries', 'manage_options' ) ) ) {
            wp_send_json_error( array( 'message' => esc_html__( 'Permission denied.', 'izzygld-entry-export-for-gravity-forms' ) ) );
        }

        $da_token_id = sanitize_text_field( rgpost( 'token_id' ) );

        if ( ! $da_token_id ) {
            wp_send_json_error( array( 'message' => esc_html__( 'Invalid token ID.', 'izzygld-entry-export-for-gravity-forms' ) ) );
        }

        $da_result = $this->token_controller->kill_da_token( $da_token_id );

        if ( is_wp_error( $da_result ) ) {
            wp_send_json_error( array( 'message' => $da_result->get_error_message() ) );
        }

        wp_send_json_success( array( 'message' => esc_html__( 'Link revoked successfully.', 'izzygld-entry-export-for-gravity-forms' ) ) );
    }

    /**
     * ajax handler for remaking form credentials
     * generates new username/password combo for a form
     *
     * @return void
     */
    public function ajax_remake_creds() {
        check_ajax_referer( 'izzygld_eee_admin', 'nonce' );

        if ( ! $this->current_user_can_any( array( 'izzygld_entry_export_form_settings', 'gravityforms_edit_forms', 'manage_options' ) ) ) {
            wp_send_json_error( array( 'message' => esc_html__( 'Permission denied.', 'izzygld-entry-export-for-gravity-forms' ) ) );
        }

        $da_form_id = absint( rgpost( 'form_id' ) );
        if ( ! $da_form_id ) {
            wp_send_json_error( array( 'message' => esc_html__( 'Invalid form ID.', 'izzygld-entry-export-for-gravity-forms' ) ) );
        }

        $da_form          = GFAPI::get_form( $da_form_id );
        $da_form_settings = $this->get_form_settings( $da_form );
        if ( ! is_array( $da_form_settings ) ) {
            $da_form_settings = array();
        }

        $da_new_username = 'export_' . bin2hex( random_bytes( 6 ) );
        $da_raw_password = bin2hex( random_bytes( 16 ) );

        $da_form_settings['export_username'] = $da_new_username;
        $da_form_settings['export_password'] = $da_raw_password;
        $this->save_form_settings( $da_form, $da_form_settings );

        wp_send_json_success( array(
            'username' => $da_new_username,
            'password' => $da_raw_password,
        ) );
    }

    /**
     * ajax handler for grabbin export links for a form
     * returns all active links for the admin ui
     *
     * @return void
     */
    public function ajax_grab_links() {
        check_ajax_referer( 'izzygld_eee_admin', 'nonce' );

        if ( ! $this->current_user_can_any( array( 'izzygld_entry_export_manage_links', 'gravityforms_edit_entries', 'manage_options' ) ) ) {
            wp_send_json_error( array( 'message' => esc_html__( 'Permission denied.', 'izzygld-entry-export-for-gravity-forms' ) ) );
        }

        $da_form_id = absint( rgpost( 'form_id' ) );

        if ( ! $da_form_id ) {
            wp_send_json_error( array( 'message' => esc_html__( 'Invalid form ID.', 'izzygld-entry-export-for-gravity-forms' ) ) );
        }

        $da_links = $this->token_controller->grab_tokens_for_form( $da_form_id );

        wp_send_json_success( array( 'links' => $da_links ) );
    }

    /**
     * gettin the base url for this addon
     * used for asset loading n stuff
     *
     * @param string $da_full_path optional full path to plugin file
     * @return string
     */
    public function get_base_url( $da_full_path = '' ) {
        if ( empty( $da_full_path ) ) {
            $da_full_path = $this->_full_path;
        }
        return plugins_url( '', $da_full_path );
    }

    /**
     * gettin the base path for this addon
     * used for file includes n stuff
     *
     * @param string $da_full_path optional full path to plugin file
     * @return string
     */
    public function get_base_path( $da_full_path = '' ) {
        if ( empty( $da_full_path ) ) {
            $da_full_path = $this->_full_path;
        }
        return plugin_dir_path( $da_full_path );
    }

    /**
     * creatin our custom plugin page
     * this is the main overview page for all forms
     *
     * @return void
     */
    public function plugin_page() {
        $this->render_overview_page();
    }

    /**
     * renderin the link managment overview page
     * shows all forms and their export status
     *
     * @return void
     */
    private function render_overview_page() {
        $da_forms = GFAPI::get_forms();
        ?>
        <div class="wrap izzygld-eee-management">
            <h1><?php esc_html_e( 'External Downloads Overview', 'izzygld-entry-export-for-gravity-forms' ); ?></h1>
            <p class="description" style="margin-bottom: 20px;">
                <?php esc_html_e( 'Manage export links from each form\'s settings. Go to a form below and click the "External Downloads" tab.', 'izzygld-entry-export-for-gravity-forms' ); ?>
            </p>

            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'Form', 'izzygld-entry-export-for-gravity-forms' ); ?></th>
                        <th><?php esc_html_e( 'Export Enabled', 'izzygld-entry-export-for-gravity-forms' ); ?></th>
                        <th><?php esc_html_e( 'Active Links', 'izzygld-entry-export-for-gravity-forms' ); ?></th>
                        <th><?php esc_html_e( 'Actions', 'izzygld-entry-export-for-gravity-forms' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ( empty( $da_forms ) ) : ?>
                        <tr><td colspan="4"><?php esc_html_e( 'No forms found.', 'izzygld-entry-export-for-gravity-forms' ); ?></td></tr>
                    <?php else : ?>
                        <?php foreach ( $da_forms as $da_form ) :
                            $da_form_settings = $this->get_form_settings( $da_form );
                            $is_turned_on     = ! empty( $da_form_settings['enable_export'] );
                            $da_active_links  = $is_turned_on ? $this->token_controller->grab_tokens_for_form( $da_form['id'] ) : array();
                            $da_settings_url  = admin_url( 'admin.php?page=gf_edit_forms&view=settings&subview=' . $this->_slug . '&id=' . $da_form['id'] );
                        ?>
                            <tr>
                                <td><strong><?php echo esc_html( $da_form['title'] ); ?></strong></td>
                                <td>
                                    <?php if ( $is_turned_on ) : ?>
                                        <span style="color:#46b450;">&#10003; <?php esc_html_e( 'Enabled', 'izzygld-entry-export-for-gravity-forms' ); ?></span>
                                    <?php else : ?>
                                        <span style="color:#999;">&#10007; <?php esc_html_e( 'Disabled', 'izzygld-entry-export-for-gravity-forms' ); ?></span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo (int) count( $da_active_links ); ?></td>
                                <td>
                                    <a href="<?php echo esc_url( $da_settings_url ); ?>" class="button button-small">
                                        <?php esc_html_e( 'Manage Downloads', 'izzygld-entry-export-for-gravity-forms' ); ?>
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
     * plugin page title for the admin menu
     *
     * @return string
     */
    public function plugin_page_title() {
        return esc_html__( 'External Export Links', 'izzygld-entry-export-for-gravity-forms' );
    }

    /**
     * cleanup when plugin is uninstalled
     * droppin our custom tables and options
     *
     * @return void
     */
    public function uninstall() {
        global $wpdb;

        // gettin rid of the tokens table
        $da_table_name = $wpdb->prefix . 'izzygld_eee_tokens';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Uninstall cleanup of plugin's custom table; table name is wpdb->prefix + hardcoded string.
        $wpdb->query( "DROP TABLE IF EXISTS {$da_table_name}" );

        // gettin rid of the logs table too
        $da_logs_table = $wpdb->prefix . 'izzygld_eee_access_logs';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Uninstall cleanup of plugin's custom table; table name is wpdb->prefix + hardcoded string.
        $wpdb->query( "DROP TABLE IF EXISTS {$da_logs_table}" );

        // cleanin up our options
        delete_option( 'izzygld_eee_db_version' );
    }
}
