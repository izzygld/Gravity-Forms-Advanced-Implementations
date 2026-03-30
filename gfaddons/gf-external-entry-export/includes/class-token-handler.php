<?php
/**
 * Token Handler for GF External Entry Export.
 *
 * Handles secure token generation, validation, and lifecycle management.
 *
 * @package GF_External_Entry_Export
 */

defined( 'ABSPATH' ) || exit;

/**
 * GF_EEE_Token_Handler class.
 *
 * Manages cryptographically secure export tokens with expiration and revocation.
 * Uses custom database tables for token storage - direct queries are intentional and necessary.
 *
 * @phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
 * @phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
 */
class GF_EEE_Token_Handler {

    /**
     * Database version.
     *
     * @var string
     */
    const DB_VERSION = '1.1.0';

    /**
     * Tokens table name (without prefix).
     *
     * @var string
     */
    const TOKENS_TABLE = 'gf_eee_tokens';

    /**
     * Access logs table name (without prefix).
     *
     * @var string
     */
    const LOGS_TABLE = 'gf_eee_access_logs';

    /**
     * Constructor.
     */
    public function __construct() {
        $this->maybe_create_tables();
    }

    /**
     * Create database tables if needed.
     *
     * @return void
     */
    public function maybe_create_tables() {
        $installed_version = get_option( 'gf_eee_db_version' );

        if ( $installed_version !== self::DB_VERSION ) {
            $this->create_tables();
            update_option( 'gf_eee_db_version', self::DB_VERSION );
        }
    }

    /**
     * Create required database tables.
     *
     * @return void
     */
    private function create_tables() {
        global $wpdb;

        $charset_collate = $wpdb->get_charset_collate();

        $tokens_table = $wpdb->prefix . self::TOKENS_TABLE;
        $logs_table   = $wpdb->prefix . self::LOGS_TABLE;

        $sql = "CREATE TABLE {$tokens_table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            token_id varchar(64) NOT NULL,
            token_hash varchar(128) NOT NULL,
            form_id bigint(20) unsigned NOT NULL,
            fields longtext NOT NULL,
            filters longtext DEFAULT NULL,
            description varchar(255) DEFAULT '',
            created_by bigint(20) unsigned NOT NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            expires_at datetime DEFAULT NULL,
            max_downloads int(11) DEFAULT 10,
            download_count int(11) DEFAULT 0,
            last_download_at datetime DEFAULT NULL,
            client_username varchar(64) NOT NULL,
            client_password_hash varchar(255) NOT NULL,
            is_revoked tinyint(1) DEFAULT 0,
            revoked_at datetime DEFAULT NULL,
            revoked_by bigint(20) unsigned DEFAULT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY token_id (token_id),
            KEY form_id (form_id),
            KEY created_by (created_by),
            KEY expires_at (expires_at),
            KEY is_revoked (is_revoked)
        ) {$charset_collate};

        CREATE TABLE {$logs_table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            token_id varchar(64) NOT NULL,
            form_id bigint(20) unsigned NOT NULL,
            accessed_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            ip_address varchar(45) DEFAULT NULL,
            user_agent text DEFAULT NULL,
            status varchar(20) NOT NULL,
            entries_exported int(11) DEFAULT 0,
            error_message text DEFAULT NULL,
            PRIMARY KEY (id),
            KEY token_id (token_id),
            KEY form_id (form_id),
            KEY accessed_at (accessed_at),
            KEY status (status)
        ) {$charset_collate};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );
    }

    /**
     * Generate a secure export token.
     *
     * @param array $data       Token data (form_id, fields, filters, etc.).
     * @param int   $expiration Expiration in hours (0 for never).
     * @return array|WP_Error Token info or error.
     */
    public function generate_token( $data, $expiration = 24 ) {
        global $wpdb;

        // Validate required data
        if ( empty( $data['form_id'] ) ) {
            return new WP_Error( 'missing_form_id', __( 'Form ID is required.', 'gf-external-entry-export' ) );
        }

        // Generate secure token
        $token_id = $this->generate_secure_token_id();
        $secret   = $this->get_secret_key();
        $token    = $this->sign_token( $token_id, $data['form_id'], $secret );

        // Prepare filters
        $filters = array(
            'start_date' => ! empty( $data['start_date'] ) ? sanitize_text_field( $data['start_date'] ) : null,
            'end_date'   => ! empty( $data['end_date'] ) ? sanitize_text_field( $data['end_date'] ) : null,
            'status'     => ! empty( $data['status'] ) ? sanitize_text_field( $data['status'] ) : 'active',
        );

        // Calculate expiration
        $expires_at = null;
        if ( $expiration > 0 ) {
            $expires_at = gmdate( 'Y-m-d H:i:s', time() + ( $expiration * HOUR_IN_SECONDS ) );
        }

        // Get max downloads from settings
        $addon        = gf_external_entry_export();
        $max_downloads = $addon ? absint( $addon->get_plugin_setting( 'max_downloads' ) ) : 10;

        // Per-link client credentials (legacy columns, kept for DB compat).
        $client_username = 'link_' . $token_id;
        $client_password = bin2hex( random_bytes( 16 ) );
        $password_hash   = wp_hash_password( $client_password );

        // Insert token record
        $table  = $wpdb->prefix . self::TOKENS_TABLE;
        $result = $wpdb->insert(
            $table,
            array(
                'token_id'             => $token_id,
                'token_hash'           => hash( 'sha256', $token ),
                'form_id'              => absint( $data['form_id'] ),
                'fields'               => wp_json_encode( $data['fields'] ?? array() ),
                'filters'              => wp_json_encode( $filters ),
                'description'          => sanitize_text_field( $data['description'] ?? '' ),
                'created_by'           => absint( $data['created_by'] ?? get_current_user_id() ),
                'created_at'           => current_time( 'mysql', true ),
                'expires_at'           => $expires_at,
                'max_downloads'        => $max_downloads,
                'client_username'      => $client_username,
                'client_password_hash' => $password_hash,
            ),
            array( '%s', '%s', '%d', '%s', '%s', '%s', '%d', '%s', '%s', '%d', '%s', '%s' )
        );

        if ( false === $result ) {
            return new WP_Error( 'db_error', __( 'Failed to create export token.', 'gf-external-entry-export' ) );
        }

        // Build export URL
        $export_url = $this->get_export_url( $token_id, $token );

        return array(
            'token_id'   => $token_id,
            'url'        => $export_url,
            'expires_at' => $expires_at,
            'form_id'    => $data['form_id'],
        );
    }

    /**
     * Generate a cryptographically secure token ID.
     *
     * @return string
     */
    private function generate_secure_token_id() {
        return bin2hex( random_bytes( 16 ) );
    }

    /**
     * Sign a token with HMAC.
     *
     * @param string $token_id Token ID.
     * @param int    $form_id  Form ID.
     * @param string $secret   Secret key.
     * @return string Signed token.
     */
    private function sign_token( $token_id, $form_id, $secret ) {
        $payload   = $token_id . '|' . $form_id;
        $signature = hash_hmac( 'sha256', $payload, $secret );
        return base64_encode( $payload . '|' . $signature );
    }

    /**
     * Verify and decode a signed token.
     *
     * @param string $token Signed token.
     * @return array|false Token parts or false if invalid.
     */
    public function verify_token( $token ) {
        $decoded = base64_decode( $token, true );
        if ( false === $decoded ) {
            return false;
        }

        $parts = explode( '|', $decoded );
        if ( count( $parts ) !== 3 ) {
            return false;
        }

        list( $token_id, $form_id, $signature ) = $parts;

        // Verify signature
        $secret            = $this->get_secret_key();
        $expected_signature = hash_hmac( 'sha256', $token_id . '|' . $form_id, $secret );

        if ( ! hash_equals( $expected_signature, $signature ) ) {
            return false;
        }

        return array(
            'token_id' => $token_id,
            'form_id'  => absint( $form_id ),
        );
    }

    /**
     * Validate a token for export access.
     *
     * @param string $token_id Token ID.
     * @param string $token    Full signed token.
     * @return array|WP_Error Token data or error.
     */
    public function validate_for_export( $token_id, $token ) {
        global $wpdb;

        // Verify token signature
        $verified = $this->verify_token( $token );
        if ( false === $verified || $verified['token_id'] !== $token_id ) {
            $this->log_access( $token_id, 0, 'invalid_signature' );
            return new WP_Error( 'invalid_token', __( 'Invalid export token.', 'gf-external-entry-export' ) );
        }

        // Get token record
        $table  = $wpdb->prefix . self::TOKENS_TABLE;
        $record = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE token_id = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                $token_id
            ),
            ARRAY_A
        );

        if ( ! $record ) {
            $this->log_access( $token_id, 0, 'not_found' );
            return new WP_Error( 'token_not_found', __( 'Export token not found.', 'gf-external-entry-export' ) );
        }

        // Check if revoked
        if ( ! empty( $record['is_revoked'] ) ) {
            $this->log_access( $token_id, $record['form_id'], 'revoked' );
            return new WP_Error( 'token_revoked', __( 'This export link has been revoked.', 'gf-external-entry-export' ) );
        }

        // Check expiration
        if ( ! empty( $record['expires_at'] ) ) {
            $expires = strtotime( $record['expires_at'] );
            if ( time() > $expires ) {
                $this->log_access( $token_id, $record['form_id'], 'expired' );
                return new WP_Error( 'token_expired', __( 'This export link has expired.', 'gf-external-entry-export' ) );
            }
        }

        // Check download limit
        if ( ! empty( $record['max_downloads'] ) && $record['download_count'] >= $record['max_downloads'] ) {
            $this->log_access( $token_id, $record['form_id'], 'limit_exceeded' );
            return new WP_Error( 'download_limit', __( 'Download limit exceeded for this link.', 'gf-external-entry-export' ) );
        }

        // Check IP allowlist
        $addon = gf_external_entry_export();
        if ( $addon ) {
            $allowed_ips = $addon->get_plugin_setting( 'allowed_ips' );
            if ( ! empty( $allowed_ips ) ) {
                $ip_list    = array_filter( array_map( 'trim', explode( "\n", $allowed_ips ) ) );
                $visitor_ip = $this->get_visitor_ip();
                if ( ! in_array( $visitor_ip, $ip_list, true ) ) {
                    $this->log_access( $token_id, $record['form_id'], 'ip_blocked', 0, __( 'IP not in allowlist', 'gf-external-entry-export' ) );
                    return new WP_Error( 'ip_blocked', __( 'Access denied.', 'gf-external-entry-export' ) );
                }
            }
        }

        // Decode stored data
        $record['fields']  = json_decode( $record['fields'], true ) ?: array();
        $record['filters'] = json_decode( $record['filters'], true ) ?: array();

        return $record;
    }

    /**
     * Validate client credentials for export access.
     *
     * @param string $token_id Token ID.
     * @param string $username Client username.
     * @param string $password Client password (plain text).
     * @return true|WP_Error True on success or error.
     */
    public function validate_credentials( $token_id, $username, $password ) {
        global $wpdb;

        $table  = $wpdb->prefix . self::TOKENS_TABLE;
        $record = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT client_username, client_password_hash FROM {$table} WHERE token_id = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                $token_id
            ),
            ARRAY_A
        );

        if ( ! $record ) {
            return new WP_Error( 'invalid_credentials', __( 'Invalid credentials.', 'gf-external-entry-export' ) );
        }

        // Constant-time username comparison
        if ( ! hash_equals( $record['client_username'], $username ) ) {
            $this->log_access( $token_id, 0, 'auth_failed', 0, 'Invalid username' );
            return new WP_Error( 'invalid_credentials', __( 'Invalid credentials.', 'gf-external-entry-export' ) );
        }

        // Verify password hash
        if ( ! wp_check_password( $password, $record['client_password_hash'] ) ) {
            $this->log_access( $token_id, 0, 'auth_failed', 0, 'Invalid password' );
            return new WP_Error( 'invalid_credentials', __( 'Invalid credentials.', 'gf-external-entry-export' ) );
        }

        return true;
    }

    /**
     * Update download count after successful export.
     *
     * @param string $token_id       Token ID.
     * @param int    $entries_count  Number of entries exported.
     * @return void
     */
    public function record_download( $token_id, $entries_count = 0 ) {
        global $wpdb;

        $table = $wpdb->prefix . self::TOKENS_TABLE;

        $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$table} SET download_count = download_count + 1, last_download_at = %s WHERE token_id = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                current_time( 'mysql', true ),
                $token_id
            )
        );

        // Get form_id for logging
        $record = $wpdb->get_row(
            $wpdb->prepare( "SELECT form_id FROM {$table} WHERE token_id = %s", $token_id ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            ARRAY_A
        );

        $this->log_access( $token_id, $record['form_id'] ?? 0, 'success', $entries_count );
    }

    /**
     * Revoke a token.
     *
     * @param string $token_id Token ID to revoke.
     * @return bool|WP_Error True on success or error.
     */
    public function revoke_token( $token_id ) {
        global $wpdb;

        $table  = $wpdb->prefix . self::TOKENS_TABLE;
        $result = $wpdb->update(
            $table,
            array(
                'is_revoked' => 1,
                'revoked_at' => current_time( 'mysql', true ),
                'revoked_by' => get_current_user_id(),
            ),
            array( 'token_id' => $token_id ),
            array( '%d', '%s', '%d' ),
            array( '%s' )
        );

        if ( false === $result ) {
            return new WP_Error( 'revoke_failed', __( 'Failed to revoke token.', 'gf-external-entry-export' ) );
        }

        return true;
    }

    /**
     * Get all tokens for a form.
     *
     * @param int  $form_id      Form ID.
     * @param bool $active_only  Whether to return only active tokens.
     * @return array
     */
    public function get_tokens_for_form( $form_id, $active_only = true ) {
        global $wpdb;

        $table = $wpdb->prefix . self::TOKENS_TABLE;

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is wpdb->prefix + constant.
        if ( $active_only ) {
            $tokens = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT token_id, description, created_at, expires_at, max_downloads, download_count, last_download_at, is_revoked, client_username
                     FROM {$table}
                     WHERE form_id = %d AND is_revoked = 0 AND (expires_at IS NULL OR expires_at > NOW())
                     ORDER BY created_at DESC",
                    $form_id
                ),
                ARRAY_A
            );
        } else {
            $tokens = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT token_id, description, created_at, expires_at, max_downloads, download_count, last_download_at, is_revoked, client_username
                     FROM {$table}
                     WHERE form_id = %d
                     ORDER BY created_at DESC",
                    $form_id
                ),
                ARRAY_A
            );
        }
        // phpcs:enable

        // Add form title and user info
        $form = GFAPI::get_form( $form_id );
        foreach ( $tokens as &$token ) {
            $token['form_title'] = $form ? $form['title'] : __( 'Unknown', 'gf-external-entry-export' );
        }

        return $tokens;
    }

    /**
     * Get all active tokens across all forms.
     *
     * @return array
     */
    public function get_all_active_tokens() {
        global $wpdb;

        $table = $wpdb->prefix . self::TOKENS_TABLE;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table for token management; caching not appropriate for token validation.
        $tokens = $wpdb->get_results(
            $wpdb->prepare(
                // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                // Table names are wpdb->prefix + constant or wpdb property.
                "SELECT t.id, t.token_id, t.form_id, t.fields, t.filters, t.description,
                        t.created_by, t.created_at, t.expires_at, t.max_downloads,
                        t.download_count, t.last_download_at, t.is_revoked,
                        t.client_username, u.display_name as created_by_name
                 FROM {$table} t
                 LEFT JOIN {$wpdb->users} u ON t.created_by = u.ID
                 WHERE t.is_revoked = %d
                   AND (t.expires_at IS NULL OR t.expires_at > %s)
                   AND (t.max_downloads = 0 OR t.download_count < t.max_downloads)
                 ORDER BY t.created_at DESC",
                // phpcs:enable
                0,
                current_time( 'mysql', true )
            ),
            ARRAY_A
        );

        // Add form titles
        foreach ( $tokens as &$token ) {
            $form = GFAPI::get_form( $token['form_id'] );
            $token['form_title'] = $form ? $form['title'] : __( 'Unknown', 'gf-external-entry-export' );
        }

        return $tokens;
    }

    /**
     * Log access attempt.
     *
     * @param string      $token_id        Token ID.
     * @param int         $form_id         Form ID.
     * @param string      $status          Status (success, expired, revoked, etc.).
     * @param int         $entries_count   Number of entries exported.
     * @param string|null $error_message   Error message if failed.
     * @return void
     */
    public function log_access( $token_id, $form_id, $status, $entries_count = 0, $error_message = null ) {
        global $wpdb;

        $addon = gf_external_entry_export();
        if ( $addon && ! $addon->get_plugin_setting( 'enable_logging' ) ) {
            return;
        }

        $table = $wpdb->prefix . self::LOGS_TABLE;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom logging table; write-only operation.
        $wpdb->insert(
            $table,
            array(
                'token_id'         => $token_id,
                'form_id'          => $form_id,
                'accessed_at'      => current_time( 'mysql', true ),
                'ip_address'       => $this->get_visitor_ip(),
                'user_agent'       => isset( $_SERVER['HTTP_USER_AGENT'] ) ? substr( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ), 0, 500 ) : null,
                'status'           => $status,
                'entries_exported' => $entries_count,
                'error_message'    => $error_message,
            ),
            array( '%s', '%d', '%s', '%s', '%s', '%s', '%d', '%s' )
        );
    }

    /**
     * Get visitor IP address.
     *
     * Only trusts proxy headers (X-Forwarded-For, CF-Connecting-IP) when the
     * request comes from a known trusted proxy. Falls back to REMOTE_ADDR.
     *
     * @return string
     */
    private function get_visitor_ip() {
        // REMOTE_ADDR is always the direct connection and cannot be spoofed.
        $remote_addr = isset( $_SERVER['REMOTE_ADDR'] )
            ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) )
            : '0.0.0.0';

        /**
         * Filter the list of trusted proxy IPs/CIDRs.
         *
         * If the direct connection (REMOTE_ADDR) is from a trusted proxy,
         * we read the real client IP from the forwarding headers.
         *
         * @param array $trusted_proxies Trusted proxy IP addresses.
         */
        $trusted_proxies = apply_filters( 'gf_eee_trusted_proxies', array() );

        if ( empty( $trusted_proxies ) || ! in_array( $remote_addr, $trusted_proxies, true ) ) {
            // Not behind a trusted proxy — REMOTE_ADDR is the client IP.
            return filter_var( $remote_addr, FILTER_VALIDATE_IP ) ? $remote_addr : '0.0.0.0';
        }

        // Trusted proxy — read forwarding headers in priority order.
        $proxy_keys = array(
            'HTTP_CF_CONNECTING_IP', // Cloudflare
            'HTTP_X_FORWARDED_FOR',
            'HTTP_X_REAL_IP',
        );

        foreach ( $proxy_keys as $key ) {
            if ( ! empty( $_SERVER[ $key ] ) ) {
                $ip = sanitize_text_field( wp_unslash( $_SERVER[ $key ] ) );
                // Handle comma-separated IPs (X-Forwarded-For)
                if ( strpos( $ip, ',' ) !== false ) {
                    $ip = trim( explode( ',', $ip )[0] );
                }
                if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
                    return $ip;
                }
            }
        }

        return filter_var( $remote_addr, FILTER_VALIDATE_IP ) ? $remote_addr : '0.0.0.0';
    }

    /**
     * Get or generate secret key.
     *
     * @return string
     */
    private function get_secret_key() {
        $addon = gf_external_entry_export();
        $key   = $addon ? $addon->get_plugin_setting( 'secret_key' ) : '';

        if ( empty( $key ) ) {
            // Fallback: derive a stable key from WP auth constants.
            if ( defined( 'AUTH_KEY' ) && AUTH_KEY !== 'put your unique phrase here' ) {
                $key = AUTH_KEY;
            } elseif ( defined( 'SECURE_AUTH_KEY' ) && SECURE_AUTH_KEY !== 'put your unique phrase here' ) {
                $key = SECURE_AUTH_KEY;
            } else {
                // Last resort: generate a persistent key and store it.
                $key = get_option( 'gf_eee_fallback_secret' );
                if ( empty( $key ) ) {
                    $key = bin2hex( random_bytes( 32 ) );
                    update_option( 'gf_eee_fallback_secret', $key, false );
                }
            }
        }

        return $key;
    }

    /**
     * Build export URL.
     *
     * @param string $token_id Token ID.
     * @param string $token    Full signed token.
     * @return string
     */
    private function get_export_url( $token_id, $token ) {
        return add_query_arg(
            array(
                'gf_eee_export' => $token_id,
                'token'         => rawurlencode( $token ),
            ),
            rest_url( 'gf-eee/v1/export' )
        );
    }

    /**
     * Clean up expired tokens.
     *
     * @param int $days_old Delete tokens expired more than this many days ago.
     * @return int Number of tokens deleted.
     */
    public function cleanup_expired_tokens( $days_old = 30 ) {
        global $wpdb;

        $table    = $wpdb->prefix . self::TOKENS_TABLE;
        $cutoff   = gmdate( 'Y-m-d H:i:s', time() - ( $days_old * DAY_IN_SECONDS ) );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Cleanup operation on custom table.
        $deleted  = $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$table} WHERE expires_at IS NOT NULL AND expires_at < %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                $cutoff
            )
        );

        return $deleted;
    }
}
