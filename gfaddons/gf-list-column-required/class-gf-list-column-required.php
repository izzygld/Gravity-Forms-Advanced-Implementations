<?php
/**
 * Main GF List Column Required Addon Class
 *
 * this is where all the addon magic happens
 * extends GFAddOn to hook into the form editor and validation
 *
 * @package GF_List_Column_Required
 */

// dont let anyone access this directly
defined( 'ABSPATH' ) || exit;

/**
 * GF_LCR_MAIN_ADDON class
 *
 * followin the GFAddOn pattern from the gf docs
 * this handles script enqueuing, validation hookup, and frontend rendering
 */
class GF_LCR_MAIN_ADDON extends GFAddOn {

    /**
     * holds an instance of this class, if we got one
     *
     * @var GF_LCR_MAIN_ADDON|null
     */
    private static $_instance = null;

    /**
     * addon version number
     *
     * @var string
     */
    protected $_version = GF_LIST_COLUMN_REQUIRED_VERSION;

    /**
     * minimum gf version we need to work
     *
     * @var string
     */
    protected $_min_gravityforms_version = GF_LIST_COLUMN_REQUIRED_MIN_GF_VERSION;

    /**
     * url-safe addon slug, gotta be max 33 chars
     *
     * @var string
     */
    protected $_slug = 'gf-list-column-required';

    /**
     * path to plugin from the plugins folder
     *
     * @var string
     */
    protected $_path = 'gf-list-column-required/gf-list-column-required.php';

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
    protected $_title = 'Gravity Forms List Column Required';

    /**
     * shorter title for menus n stuff
     *
     * @var string
     */
    protected $_short_title = 'List Column Required';

    /**
     * our validator instance for checkin required columns
     *
     * @var GF_LCR_VALIDATOR
     */
    public $validator;

    /**
     * our frontend handler for renderin required indicators
     *
     * @var GF_LCR_FRONTEND
     */
    public $frontend;

    /**
     * gets the singleton instance of this class
     * creates it if it dont exist yet
     *
     * @return GF_LCR_MAIN_ADDON
     */
    public static function get_instance() {
        if ( null === self::$_instance ) {
            self::$_instance = new self();
        }
        return self::$_instance;
    }

    /**
     * minimum requirements to run this addon
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
     * runs before wordpress init kicks off
     * settin up our handler instances early
     *
     * @return void
     */
    public function pre_init() {
        parent::pre_init();

        // spinnin up our handler classes
        $this->validator = new GF_LCR_VALIDATOR();
        $this->frontend  = new GF_LCR_FRONTEND();
    }

    /**
     * init method that runs on all pages
     * hookin up our validation filter here
     *
     * @return void
     */
    public function init() {
        parent::init();

        // hookin up the server-side validation for list column required checks
        $this->validator->hookup();
    }

    /**
     * init frontend runs only on the public-facing site
     * hookin up the required indicators and attributes here
     *
     * @return void
     */
    public function init_frontend() {
        parent::init_frontend();

        // addin required indicators to column headers and inputs
        $this->frontend->hookup();
    }

    /**
     * scripts we need to load up
     * enqueuin our admin js for the form editor
     *
     * @return array
     */
    public function scripts() {
        $da_scripts = array(
            array(
                'handle'  => 'gf_lcr_admin',
                'src'     => $this->get_base_url() . '/assets/js/admin.js',
                'version' => $this->_version,
                'deps'    => array( 'jquery' ),
                'enqueue' => array(
                    array(
                        'admin_page' => array( 'form_editor' ),
                    ),
                ),
            ),
        );

        return array_merge( parent::scripts(), $da_scripts );
    }

    /**
     * styles we need to load up
     * enqueuin our admin css for the form editor
     *
     * @return array
     */
    public function styles() {
        $da_styles = array(
            array(
                'handle'  => 'gf_lcr_admin',
                'src'     => $this->get_base_url() . '/assets/css/admin.css',
                'version' => $this->_version,
                'enqueue' => array(
                    array(
                        'admin_page' => array( 'form_editor' ),
                    ),
                ),
            ),
        );

        return array_merge( parent::styles(), $da_styles );
    }
}
