<?php
/**
 * API Handler for GF External Entry Export
 *
 * handles the public-facin export endpoint that external users access
 * this is the main entry point for downloadin exports
 *
 * @package GF_External_Entry_Export
 */

// dont let ppl access directly
defined( 'ABSPATH' ) || exit;

/**
 * GF_EEE_API_HANDLER class
 *
 * implements the secure export endpoint followin wordpress rest api patterns
 * handles authenticaton, validation, and servin up the csv files
 */
class GF_EEE_API_HANDLER {

    /**
     * rest namespace for our endpoints
     *
     * @var string
     */
    const NAMESPACE = 'gf-eee/v1';

    /**
     * parent addon instance
     *
     * @var GF_EEE_MAIN_ADDON
     */
    private $da_addon;

    /**
     * constructor - stores the addon instance
     *
     * @param GF_EEE_MAIN_ADDON $da_addon parent addon instance
     */
    public function __construct( $da_addon ) {
        $this->da_addon = $da_addon;
    }

    /**
     * settin up our rest api routes
     * registers all the endpoints we need
     *
     * @return void
     */
    public function setup_da_routes() {
        // main export endpoint - no authenticaton required (uses token)
        register_rest_route(
            self::NAMESPACE,
            '/export',
            array(
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => array( $this, 'handle_da_export' ),
                'permission_callback' => '__return_true', // public endpoint, validated by token
                'args'                => array(
                    'gf_eee_export' => array(
                        'required'          => true,
                        'type'              => 'string',
                        'sanitize_callback' => 'sanitize_text_field',
                        'description'       => __( 'Export token ID', 'gf-external-entry-export' ),
                    ),
                    'token'         => array(
                        'required'          => true,
                        'type'              => 'string',
                        'sanitize_callback' => 'sanitize_text_field',
                        'description'       => __( 'Signed export token', 'gf-external-entry-export' ),
                    ),
                ),
            )
        );

        // preview endpoint (admin only) - shows entry count
        register_rest_route(
            self::NAMESPACE,
            '/preview',
            array(
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => array( $this, 'handle_da_preview' ),
                'permission_callback' => array( $this, 'can_user_do_admin_stuff' ),
                'args'                => array(
                    'form_id'    => array(
                        'required'          => true,
                        'type'              => 'integer',
                        'sanitize_callback' => 'absint',
                    ),
                    'start_date' => array(
                        'type'              => 'string',
                        'sanitize_callback' => 'sanitize_text_field',
                    ),
                    'end_date'   => array(
                        'type'              => 'string',
                        'sanitize_callback' => 'sanitize_text_field',
                    ),
                    'status'     => array(
                        'type'              => 'string',
                        'sanitize_callback' => 'sanitize_text_field',
                        'default'           => 'active',
                    ),
                ),
            )
        );

        // get form fields endpoint (admin only)
        register_rest_route(
            self::NAMESPACE,
            '/form-fields/(?P<form_id>\d+)',
            array(
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => array( $this, 'grab_form_fields' ),
                'permission_callback' => array( $this, 'can_user_do_admin_stuff' ),
                'args'                => array(
                    'form_id' => array(
                        'required'          => true,
                        'type'              => 'integer',
                        'sanitize_callback' => 'absint',
                    ),
                ),
            )
        );

        // get active links endpoint (admin only)
        register_rest_route(
            self::NAMESPACE,
            '/links',
            array(
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => array( $this, 'grab_active_links' ),
                'permission_callback' => array( $this, 'can_user_do_admin_stuff' ),
            )
        );
    }

    /**
     * checkin if user can do admin stuff
     * needs one of the right capabilites
     *
     * @return bool
     */
    public function can_user_do_admin_stuff() {
        return current_user_can( 'gf_external_entry_export_manage_links' ) ||
               current_user_can( 'gravityforms_edit_entries' ) ||
               current_user_can( 'manage_options' );
    }

    /**
     * grabbin http basic auth credentials from the request
     * checks a few different places cuz servers are weird
     *
     * @return array{username: string, password: string}|null credentials or null
     */
    private function grab_basic_auth_creds() {
        $da_username = null;
        $da_password = null;

        // standard php approach
        if ( isset( $_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW'] ) ) {
            $da_username = sanitize_text_field( wp_unslash( $_SERVER['PHP_AUTH_USER'] ) );
            $da_password = sanitize_text_field( wp_unslash( $_SERVER['PHP_AUTH_PW'] ) );
        }
        // fallback: parse authorization header (cgi / fastcgi environments)
        elseif ( isset( $_SERVER['HTTP_AUTHORIZATION'] ) ) {
            $da_auth = sanitize_text_field( wp_unslash( $_SERVER['HTTP_AUTHORIZATION'] ) );
            if ( 0 === stripos( $da_auth, 'basic ' ) ) {
                $da_decoded = base64_decode( substr( $da_auth, 6 ), true );
                if ( false !== $da_decoded && strpos( $da_decoded, ':' ) !== false ) {
                    list( $da_username, $da_password ) = explode( ':', $da_decoded, 2 );
                }
            }
        }
        // fallback: redirect_http_authorization (some apache configs)
        elseif ( isset( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ) ) {
            $da_auth = sanitize_text_field( wp_unslash( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ) );
            if ( 0 === stripos( $da_auth, 'basic ' ) ) {
                $da_decoded = base64_decode( substr( $da_auth, 6 ), true );
                if ( false !== $da_decoded && strpos( $da_decoded, ':' ) !== false ) {
                    list( $da_username, $da_password ) = explode( ':', $da_decoded, 2 );
                }
            }
        }

        if ( ! empty( $da_username ) && ! empty( $da_password ) ) {
            return array(
                'username' => $da_username,
                'password' => $da_password,
            );
        }

        return null;
    }

    /**
     * handlin da export request
     * this is the main public endpoint that external users access
     * requires both a valid signed token AND http basic auth creds
     *
     * @param WP_REST_Request $da_request request object
     * @return WP_REST_Response|WP_Error response or error
     */
    public function handle_da_export( $da_request ) {
        // strict authenticaton: http basic auth is REQUIRED
        $da_creds = $this->grab_basic_auth_creds();

        if ( null === $da_creds ) {
            header( 'WWW-Authenticate: Basic realm="GF Export"' );
            return new WP_Error(
                'authentication_required',
                __( 'Username and password are required to download this export.', 'gf-external-entry-export' ),
                array( 'status' => 401 )
            );
        }

        $da_token_id = $da_request->get_param( 'gf_eee_export' );
        $da_token    = rawurldecode( $da_request->get_param( 'token' ) );

        // checkin user agent requirement
        if ( $this->da_addon->get_plugin_setting( 'require_user_agent' ) ) {
            if ( empty( $_SERVER['HTTP_USER_AGENT'] ) ) {
                return new WP_Error(
                    'missing_user_agent',
                    __( 'User agent required.', 'gf-external-entry-export' ),
                    array( 'status' => 400 )
                );
            }
        }

        // validatin token (checks signature, expiraton, revocation, download limit, ip allowlist)
        $da_token_data = $this->da_addon->token_controller->check_export_allowed( $da_token_id, $da_token );

        if ( is_wp_error( $da_token_data ) ) {
            return new WP_Error(
                $da_token_data->get_error_code(),
                $da_token_data->get_error_message(),
                array( 'status' => 403 )
            );
        }

        // rate-limit: blockin after repeated auth failures
        $da_remote_addr = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
        $da_rate_key    = 'gf_eee_fail_' . md5( $da_token_id . '|' . $da_remote_addr );
        $da_fail_count  = (int) get_transient( $da_rate_key );
        if ( $da_fail_count >= 5 ) {
            $this->da_addon->token_controller->log_da_access( $da_token_id, 0, 'rate_limited' );
            return new WP_Error(
                'rate_limited',
                __( 'Too many failed attempts. Please try again later.', 'gf-external-entry-export' ),
                array( 'status' => 429 )
            );
        }

        // validatin credentials against form-level username / password
        $da_form          = GFAPI::get_form( $da_token_data['form_id'] );
        $da_form_settings = $this->da_addon->get_form_settings( $da_form );
        $da_expected_user = rgar( $da_form_settings, 'export_username', '' );
        $da_expected_pass = rgar( $da_form_settings, 'export_password', '' );

        if ( empty( $da_expected_user ) || empty( $da_expected_pass ) ) {
            $this->da_addon->token_controller->log_da_access( $da_token_id, $da_token_data['form_id'], 'auth_failed', 0, 'No form credentials configured' );
            return new WP_Error(
                'credentials_not_configured',
                __( 'Export credentials have not been configured for this form.', 'gf-external-entry-export' ),
                array( 'status' => 403 )
            );
        }

        // constant-time username + password comparson for security
        $da_user_ok = hash_equals( $da_expected_user, $da_creds['username'] );
        $da_pass_ok = hash_equals( $da_expected_pass, $da_creds['password'] );

        if ( ! $da_user_ok || ! $da_pass_ok ) {
            set_transient( $da_rate_key, $da_fail_count + 1, 15 * MINUTE_IN_SECONDS );
            $this->da_addon->token_controller->log_da_access( $da_token_id, $da_token_data['form_id'], 'auth_failed', 0, 'Bad credentials' );
            header( 'WWW-Authenticate: Basic realm="GF Export"' );
            return new WP_Error(
                'invalid_credentials',
                __( 'Invalid username or password.', 'gf-external-entry-export' ),
                array( 'status' => 401 )
            );
        }

        // clearin rate-limit counter on success
        delete_transient( $da_rate_key );

        // generatin the export
        $da_result = $this->da_addon->export_maker->export_generated(
            $da_token_data['form_id'],
            $da_token_data['fields'],
            $da_token_data['filters']
        );

        if ( is_wp_error( $da_result ) ) {
            $this->da_addon->token_controller->log_da_access(
                $da_token_id,
                $da_token_data['form_id'],
                'export_failed',
                0,
                $da_result->get_error_message()
            );

            return new WP_Error(
                $da_result->get_error_code(),
                $da_result->get_error_message(),
                array( 'status' => 500 )
            );
        }

        // recordin successful download
        $this->da_addon->token_controller->log_da_download( $da_token_id, $da_result['count'] );

        // sendin csv response
        $this->send_da_csv( $da_result['content'], $da_result['filename'] );
    }

    /**
     * sendin the csv file response
     * sets all the right headers for downloadin
     *
     * @param string $da_content  csv content
     * @param string $da_filename filename
     * @return void
     */
    private function send_da_csv( $da_content, $da_filename ) {
        // clearin any output buffers
        while ( ob_get_level() > 0 ) {
            ob_end_clean();
        }

        // sanitizin filename for content-disposition header (prevent header injecton)
        $da_safe_filename = preg_replace( '/[\r\n"\\\\]/', '_', $da_filename );

        // settin headers for csv download
        nocache_headers();
        header( 'Content-Type: text/csv; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename="' . $da_safe_filename . '"' );
        header( 'Content-Length: ' . strlen( $da_content ) );
        header( 'X-Content-Type-Options: nosniff' );
        header( 'X-Robots-Tag: noindex, nofollow' );

        // outputtin content and exitin
        echo $da_content; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        exit;
    }

    /**
     * handlin da preview request (entry count)
     * lets admins see how many entries would be exported
     *
     * @param WP_REST_Request $da_request request object
     * @return WP_REST_Response|WP_Error response or error
     */
    public function handle_da_preview( $da_request ) {
        $da_form_id = $da_request->get_param( 'form_id' );
        $da_filters = array(
            'start_date' => $da_request->get_param( 'start_date' ),
            'end_date'   => $da_request->get_param( 'end_date' ),
            'status'     => $da_request->get_param( 'status' ),
        );

        $da_count = $this->da_addon->export_maker->grab_entry_count( $da_form_id, $da_filters );

        if ( is_wp_error( $da_count ) ) {
            return $da_count;
        }

        return rest_ensure_response(
            array(
                'count'   => $da_count,
                'form_id' => $da_form_id,
                'filters' => $da_filters,
            )
        );
    }

    /**
     * grabbin form fields for export configuraton
     * returns all the fields in a form for the admin ui
     *
     * @param WP_REST_Request $da_request request object
     * @return WP_REST_Response|WP_Error response or error
     */
    public function grab_form_fields( $da_request ) {
        $da_form_id = $da_request->get_param( 'form_id' );
        $da_form    = GFAPI::get_form( $da_form_id );

        if ( ! $da_form ) {
            return new WP_Error(
                'form_not_found',
                __( 'Form not found.', 'gf-external-entry-export' ),
                array( 'status' => 404 )
            );
        }

        // checkin if export is enabled for this form
        $da_form_settings  = $this->da_addon->get_form_settings( $da_form );
        $da_export_enabled = ! empty( $da_form_settings['enable_export'] );

        $da_fields = array();

        if ( ! empty( $da_form['fields'] ) ) {
            foreach ( $da_form['fields'] as $da_field ) {
                // skippin non-data fields
                if ( in_array( $da_field->type, array( 'html', 'section', 'page', 'captcha' ), true ) ) {
                    continue;
                }

                $da_field_label = ! empty( $da_field->adminLabel ) ? $da_field->adminLabel : $da_field->label;

                // handlin multi-input fields
                if ( is_array( $da_field->inputs ) && ! empty( $da_field->inputs ) ) {
                    foreach ( $da_field->inputs as $da_input ) {
                        if ( ! empty( $da_input['isHidden'] ) ) {
                            continue;
                        }

                        $da_input_label   = ! empty( $da_input['label'] ) ? $da_input['label'] : '';
                        $da_setting_name  = 'field_' . str_replace( '.', '_', $da_input['id'] );
                        $da_is_allowed    = ! empty( $da_form_settings[ $da_setting_name ] );

                        $da_fields[] = array(
                            'id'         => $da_input['id'],
                            'setting'    => $da_setting_name,
                            'label'      => $da_input_label ? "{$da_field_label} - {$da_input_label}" : $da_field_label,
                            'type'       => $da_field->type,
                            'is_allowed' => $da_is_allowed,
                        );
                    }
                } else {
                    $da_setting_name = 'field_' . $da_field->id;
                    $da_is_allowed   = ! empty( $da_form_settings[ $da_setting_name ] );

                    $da_fields[] = array(
                        'id'         => $da_field->id,
                        'setting'    => $da_setting_name,
                        'label'      => $da_field_label,
                        'type'       => $da_field->type,
                        'is_allowed' => $da_is_allowed,
                    );
                }
            }
        }

        return rest_ensure_response(
            array(
                'form_id'        => $da_form_id,
                'form_title'     => $da_form['title'],
                'export_enabled' => $da_export_enabled,
                'fields'         => $da_fields,
            )
        );
    }

    /**
     * grabbin all active export links
     * for the main overview page
     *
     * @param WP_REST_Request $da_request request object
     * @return WP_REST_Response response
     */
    public function grab_active_links( $da_request ) {
        $da_links = $this->da_addon->token_controller->grab_all_active_tokens();

        // formatin for display
        foreach ( $da_links as &$da_link ) {
            // formatin dates
            $da_link['created_at_formatted'] = get_date_from_gmt( $da_link['created_at'], get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) );

            if ( ! empty( $da_link['expires_at'] ) ) {
                $da_link['expires_at_formatted'] = get_date_from_gmt( $da_link['expires_at'], get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) );

                // calculatin time remaining
                $da_expires_timestamp = strtotime( $da_link['expires_at'] );
                $da_remaining         = $da_expires_timestamp - time();

                if ( $da_remaining > 0 ) {
                    $da_link['time_remaining'] = human_time_diff( time(), $da_expires_timestamp );
                } else {
                    $da_link['time_remaining'] = __( 'Expired', 'gf-external-entry-export' );
                }
            } else {
                $da_link['expires_at_formatted'] = __( 'Never', 'gf-external-entry-export' );
                $da_link['time_remaining']       = __( 'Never', 'gf-external-entry-export' );
            }

            // download info
            if ( $da_link['max_downloads'] > 0 ) {
                $da_link['downloads_display'] = sprintf(
                    '%d / %d',
                    $da_link['download_count'],
                    $da_link['max_downloads']
                );
            } else {
                $da_link['downloads_display'] = sprintf(
                    '%d / ∞',
                    $da_link['download_count']
                );
            }
        }

        return rest_ensure_response(
            array(
                'links' => $da_links,
            )
        );
    }
}
