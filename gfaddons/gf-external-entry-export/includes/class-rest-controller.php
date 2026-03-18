<?php
/**
 * REST Controller for GF External Entry Export.
 *
 * Handles the public-facing export endpoint that external users access.
 *
 * @package GF_External_Entry_Export
 */

defined( 'ABSPATH' ) || exit;

/**
 * GF_EEE_REST_Controller class.
 *
 * Implements the secure export endpoint following WordPress REST API patterns.
 */
class GF_EEE_REST_Controller {

    /**
     * REST namespace.
     *
     * @var string
     */
    const NAMESPACE = 'gf-eee/v1';

    /**
     * Parent addon instance.
     *
     * @var GF_External_Entry_Export
     */
    private $addon;

    /**
     * Constructor.
     *
     * @param GF_External_Entry_Export $addon Parent addon instance.
     */
    public function __construct( $addon ) {
        $this->addon = $addon;
    }

    /**
     * Register REST API routes.
     *
     * @return void
     */
    public function register_routes() {
        // Main export endpoint - no authentication required (uses token)
        register_rest_route(
            self::NAMESPACE,
            '/export',
            array(
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => array( $this, 'handle_export' ),
                'permission_callback' => '__return_true', // Public endpoint, validated by token
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

        // Preview endpoint (admin only) - shows entry count
        register_rest_route(
            self::NAMESPACE,
            '/preview',
            array(
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => array( $this, 'handle_preview' ),
                'permission_callback' => array( $this, 'check_admin_permission' ),
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

        // Get form fields endpoint (admin only)
        register_rest_route(
            self::NAMESPACE,
            '/form-fields/(?P<form_id>\d+)',
            array(
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => array( $this, 'get_form_fields' ),
                'permission_callback' => array( $this, 'check_admin_permission' ),
                'args'                => array(
                    'form_id' => array(
                        'required'          => true,
                        'type'              => 'integer',
                        'sanitize_callback' => 'absint',
                    ),
                ),
            )
        );

        // Get active links endpoint (admin only)
        register_rest_route(
            self::NAMESPACE,
            '/links',
            array(
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => array( $this, 'get_active_links' ),
                'permission_callback' => array( $this, 'check_admin_permission' ),
            )
        );
    }

    /**
     * Check if user has admin permission.
     *
     * @return bool
     */
    public function check_admin_permission() {
        return current_user_can( 'gf_external_entry_export_manage_links' ) ||
               current_user_can( 'gravityforms_edit_entries' ) ||
               current_user_can( 'manage_options' );
    }

    /**
     * Extract HTTP Basic Auth credentials from the request.
     *
     * @return array{username: string, password: string}|null Credentials or null.
     */
    private function get_basic_auth_credentials() {
        $username = null;
        $password = null;

        // Standard PHP approach
        if ( isset( $_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW'] ) ) {
            $username = sanitize_text_field( wp_unslash( $_SERVER['PHP_AUTH_USER'] ) );
            $password = sanitize_text_field( wp_unslash( $_SERVER['PHP_AUTH_PW'] ) );
        }
        // Fallback: parse Authorization header (CGI / FastCGI environments)
        elseif ( isset( $_SERVER['HTTP_AUTHORIZATION'] ) ) {
            $auth = sanitize_text_field( wp_unslash( $_SERVER['HTTP_AUTHORIZATION'] ) );
            if ( 0 === stripos( $auth, 'basic ' ) ) {
                $decoded = base64_decode( substr( $auth, 6 ), true );
                if ( false !== $decoded && strpos( $decoded, ':' ) !== false ) {
                    list( $username, $password ) = explode( ':', $decoded, 2 );
                }
            }
        }
        // Fallback: REDIRECT_HTTP_AUTHORIZATION (some Apache configs)
        elseif ( isset( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ) ) {
            $auth = sanitize_text_field( wp_unslash( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ) );
            if ( 0 === stripos( $auth, 'basic ' ) ) {
                $decoded = base64_decode( substr( $auth, 6 ), true );
                if ( false !== $decoded && strpos( $decoded, ':' ) !== false ) {
                    list( $username, $password ) = explode( ':', $decoded, 2 );
                }
            }
        }

        if ( ! empty( $username ) && ! empty( $password ) ) {
            return array(
                'username' => $username,
                'password' => $password,
            );
        }

        return null;
    }

    /**
     * Handle export request.
     *
     * This is the main public endpoint that external users access.
     * Requires both a valid signed token AND HTTP Basic Auth credentials.
     *
     * @param WP_REST_Request $request Request object.
     * @return WP_REST_Response|WP_Error Response or error.
     */
    public function handle_export( $request ) {
        // ── Strict authentication: HTTP Basic Auth is REQUIRED ──
        $credentials = $this->get_basic_auth_credentials();

        if ( null === $credentials ) {
            header( 'WWW-Authenticate: Basic realm="GF Export"' );
            return new WP_Error(
                'authentication_required',
                __( 'Username and password are required to download this export.', 'gf-external-entry-export' ),
                array( 'status' => 401 )
            );
        }

        $token_id = $request->get_param( 'gf_eee_export' );
        $token    = rawurldecode( $request->get_param( 'token' ) );

        // Check user agent requirement
        if ( $this->addon->get_plugin_setting( 'require_user_agent' ) ) {
            if ( empty( $_SERVER['HTTP_USER_AGENT'] ) ) {
                return new WP_Error(
                    'missing_user_agent',
                    __( 'User agent required.', 'gf-external-entry-export' ),
                    array( 'status' => 400 )
                );
            }
        }

        // Validate token (checks signature, expiration, revocation, download limit, IP allowlist)
        $token_data = $this->addon->token_handler->validate_for_export( $token_id, $token );

        if ( is_wp_error( $token_data ) ) {
            return new WP_Error(
                $token_data->get_error_code(),
                $token_data->get_error_message(),
                array( 'status' => 403 )
            );
        }

        // ── Rate-limit: block after repeated auth failures ──
        $rate_key   = 'gf_eee_fail_' . md5( $token_id . '|' . $_SERVER['REMOTE_ADDR'] );
        $fail_count = (int) get_transient( $rate_key );
        if ( $fail_count >= 5 ) {
            $this->addon->token_handler->log_access( $token_id, 0, 'rate_limited' );
            return new WP_Error(
                'rate_limited',
                __( 'Too many failed attempts. Please try again later.', 'gf-external-entry-export' ),
                array( 'status' => 429 )
            );
        }

        // Validate credentials against form-level username / hashed password
        $form          = GFAPI::get_form( $token_data['form_id'] );
        $form_settings = $this->addon->get_form_settings( $form );
        $expected_user = rgar( $form_settings, 'export_username', '' );
        $password_hash = rgar( $form_settings, 'export_password_hash', '' );

        // Legacy fallback: plain-text password that hasn't been migrated yet.
        if ( empty( $password_hash ) ) {
            $password_hash = rgar( $form_settings, 'export_password', '' );
        }

        if ( empty( $expected_user ) || empty( $password_hash ) ) {
            $this->addon->token_handler->log_access( $token_id, $token_data['form_id'], 'auth_failed', 0, 'No form credentials configured' );
            return new WP_Error(
                'credentials_not_configured',
                __( 'Export credentials have not been configured for this form.', 'gf-external-entry-export' ),
                array( 'status' => 403 )
            );
        }

        // Constant-time username check + password hash verification.
        $user_ok = hash_equals( $expected_user, $credentials['username'] );
        $pass_ok = wp_check_password( $credentials['password'], $password_hash );

        if ( ! $user_ok || ! $pass_ok ) {
            set_transient( $rate_key, $fail_count + 1, 15 * MINUTE_IN_SECONDS );
            $this->addon->token_handler->log_access( $token_id, $token_data['form_id'], 'auth_failed', 0, 'Bad credentials' );
            header( 'WWW-Authenticate: Basic realm="GF Export"' );
            return new WP_Error(
                'invalid_credentials',
                __( 'Invalid username or password.', 'gf-external-entry-export' ),
                array( 'status' => 401 )
            );
        }

        // Clear rate-limit counter on success.
        delete_transient( $rate_key );

        // Generate export
        $result = $this->addon->export_handler->generate_export(
            $token_data['form_id'],
            $token_data['fields'],
            $token_data['filters']
        );

        if ( is_wp_error( $result ) ) {
            $this->addon->token_handler->log_access(
                $token_id,
                $token_data['form_id'],
                'export_failed',
                0,
                $result->get_error_message()
            );

            return new WP_Error(
                $result->get_error_code(),
                $result->get_error_message(),
                array( 'status' => 500 )
            );
        }

        // Record successful download
        $this->addon->token_handler->record_download( $token_id, $result['count'] );

        // Send CSV response
        $this->send_csv_response( $result['content'], $result['filename'] );
    }

    /**
     * Send CSV file response.
     *
     * @param string $content  CSV content.
     * @param string $filename Filename.
     * @return void
     */
    private function send_csv_response( $content, $filename ) {
        // Clear any output buffers
        while ( ob_get_level() > 0 ) {
            ob_end_clean();
        }

        // Sanitize filename for Content-Disposition header (prevent header injection).
        $safe_filename = preg_replace( '/[\r\n"\\]/', '_', $filename );

        // Set headers for CSV download
        nocache_headers();
        header( 'Content-Type: text/csv; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename="' . $safe_filename . '"' );
        header( 'Content-Length: ' . strlen( $content ) );
        header( 'X-Content-Type-Options: nosniff' );
        header( 'X-Robots-Tag: noindex, nofollow' );

        // Output content and exit
        echo $content; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        exit;
    }

    /**
     * Handle preview request (entry count).
     *
     * @param WP_REST_Request $request Request object.
     * @return WP_REST_Response|WP_Error Response or error.
     */
    public function handle_preview( $request ) {
        $form_id = $request->get_param( 'form_id' );
        $filters = array(
            'start_date' => $request->get_param( 'start_date' ),
            'end_date'   => $request->get_param( 'end_date' ),
            'status'     => $request->get_param( 'status' ),
        );

        $count = $this->addon->export_handler->get_entry_count( $form_id, $filters );

        if ( is_wp_error( $count ) ) {
            return $count;
        }

        return rest_ensure_response(
            array(
                'count'   => $count,
                'form_id' => $form_id,
                'filters' => $filters,
            )
        );
    }

    /**
     * Get form fields for export configuration.
     *
     * @param WP_REST_Request $request Request object.
     * @return WP_REST_Response|WP_Error Response or error.
     */
    public function get_form_fields( $request ) {
        $form_id = $request->get_param( 'form_id' );
        $form    = GFAPI::get_form( $form_id );

        if ( ! $form ) {
            return new WP_Error(
                'form_not_found',
                __( 'Form not found.', 'gf-external-entry-export' ),
                array( 'status' => 404 )
            );
        }

        // Check if export is enabled for this form
        $form_settings = $this->addon->get_form_settings( $form );
        $export_enabled = ! empty( $form_settings['enable_export'] );

        $fields = array();

        if ( ! empty( $form['fields'] ) ) {
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

                        $input_label   = ! empty( $input['label'] ) ? $input['label'] : '';
                        $setting_name  = 'field_' . str_replace( '.', '_', $input['id'] );
                        $is_allowed    = ! empty( $form_settings[ $setting_name ] );

                        $fields[] = array(
                            'id'         => $input['id'],
                            'setting'    => $setting_name,
                            'label'      => $input_label ? "{$field_label} - {$input_label}" : $field_label,
                            'type'       => $field->type,
                            'is_allowed' => $is_allowed,
                        );
                    }
                } else {
                    $setting_name = 'field_' . $field->id;
                    $is_allowed   = ! empty( $form_settings[ $setting_name ] );

                    $fields[] = array(
                        'id'         => $field->id,
                        'setting'    => $setting_name,
                        'label'      => $field_label,
                        'type'       => $field->type,
                        'is_allowed' => $is_allowed,
                    );
                }
            }
        }

        return rest_ensure_response(
            array(
                'form_id'        => $form_id,
                'form_title'     => $form['title'],
                'export_enabled' => $export_enabled,
                'fields'         => $fields,
            )
        );
    }

    /**
     * Get all active export links.
     *
     * @param WP_REST_Request $request Request object.
     * @return WP_REST_Response Response.
     */
    public function get_active_links( $request ) {
        $links = $this->addon->token_handler->get_all_active_tokens();

        // Format for display
        foreach ( $links as &$link ) {
            // Format dates
            $link['created_at_formatted'] = get_date_from_gmt( $link['created_at'], get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) );

            if ( ! empty( $link['expires_at'] ) ) {
                $link['expires_at_formatted'] = get_date_from_gmt( $link['expires_at'], get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) );

                // Calculate time remaining
                $expires_timestamp = strtotime( $link['expires_at'] );
                $remaining         = $expires_timestamp - time();

                if ( $remaining > 0 ) {
                    $link['time_remaining'] = human_time_diff( time(), $expires_timestamp );
                } else {
                    $link['time_remaining'] = __( 'Expired', 'gf-external-entry-export' );
                }
            } else {
                $link['expires_at_formatted'] = __( 'Never', 'gf-external-entry-export' );
                $link['time_remaining']       = __( 'Never', 'gf-external-entry-export' );
            }

            // Download info
            if ( $link['max_downloads'] > 0 ) {
                $link['downloads_display'] = sprintf(
                    '%d / %d',
                    $link['download_count'],
                    $link['max_downloads']
                );
            } else {
                $link['downloads_display'] = sprintf(
                    '%d / ∞',
                    $link['download_count']
                );
            }
        }

        return rest_ensure_response(
            array(
                'links' => $links,
            )
        );
    }
}
