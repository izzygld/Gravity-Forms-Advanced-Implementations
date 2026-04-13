<?php
/**
 * Token Controller for Izzygld Entry Export for Gravity Forms
 *
 * handles all the token generaton, validation, and lifecycle managment
 * basically the brains of the secure link system
 *
 * @package Izzygld_Entry_Export
 */

// dont let ppl access directly
defined( 'ABSPATH' ) || exit;

/**
 * Izzygld_EEE_Token_Handler class
 *
 * manages cryptographically secure export tokens with expiraton and revocation
 * uses custom database tables for token storage - direct querys are intentional
 *
 * @phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
 * @phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
 */
class Izzygld_EEE_Token_Handler {

    /**
     * database version number
     *
     * @var string
     */
    const DB_VERSION = '1.1.0';

    /**
     * tokens table name without the prefix
     *
     * @var string
     */
    const TOKENS_TABLE = 'izzygld_eee_tokens';

    /**
     * access logs table name without the prefix
     *
     * @var string
     */
    const LOGS_TABLE = 'izzygld_eee_access_logs';

    /**
     * constructor - sets up da tables if needed
     */
    public function __construct() {
        $this->maybe_setup_tables();
    }

    /**
     * checkin if we need to create the databse tables
     * if the version dont match we run the setup
     *
     * @return void
     */
    public function maybe_setup_tables() {
        $installed_ver = get_option( 'izzygld_eee_db_version' );

        if ( $installed_ver !== self::DB_VERSION ) {
            $this->setup_da_tables();
            update_option( 'izzygld_eee_db_version', self::DB_VERSION );
        }
    }

    /**
     * creatin our custom database tables
     * this runs the sql to set up tokens and logs tables
     *
     * @return void
     */
    private function setup_da_tables() {
        global $wpdb;

        $da_charset_collate = $wpdb->get_charset_collate();

        $da_tokens_table = $wpdb->prefix . self::TOKENS_TABLE;
        $da_logs_table   = $wpdb->prefix . self::LOGS_TABLE;

        $da_sql = "CREATE TABLE {$da_tokens_table} (
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
        ) {$da_charset_collate};

        CREATE TABLE {$da_logs_table} (
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
        ) {$da_charset_collate};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $da_sql );
    }

    /**
     * makin da token - generates a secure export token
     * this is the main function for creatin new download links
     *
     * @param array $da_data       token data (form_id, fields, filters, etc)
     * @param int   $da_expiration expiration in hours (0 for never)
     * @return array|WP_Error token info or error
     */
    public function make_da_token( $da_data, $da_expiration = 24 ) {
        global $wpdb;

        // gotta have a form id
        if ( empty( $da_data['form_id'] ) ) {
            return new WP_Error( 'missing_form_id', __( 'Form ID is required.', 'izzygld-entry-export-for-gravity-forms' ) );
        }

        // generatin the secure token stuff
        $da_token_id = $this->make_secure_token_id();
        $da_secret   = $this->grab_secret_key();
        $da_token    = $this->sign_da_token( $da_token_id, $da_data['form_id'], $da_secret );

        // settin up the filters
        $da_filters = array(
            'start_date' => ! empty( $da_data['start_date'] ) ? sanitize_text_field( $da_data['start_date'] ) : null,
            'end_date'   => ! empty( $da_data['end_date'] ) ? sanitize_text_field( $da_data['end_date'] ) : null,
            'status'     => ! empty( $da_data['status'] ) ? sanitize_text_field( $da_data['status'] ) : 'active',
        );

        // figurin out when it expires
        $expiring_duedate = null;
        if ( $da_expiration > 0 ) {
            $expiring_duedate = gmdate( 'Y-m-d H:i:s', time() + ( $da_expiration * HOUR_IN_SECONDS ) );
        }

        // gettin max downloads from settings
        $da_addon        = izzygld_eee_get_da_addon();
        $da_max_downloads = $da_addon ? absint( $da_addon->get_plugin_setting( 'max_downloads' ) ) : 10;

        // per-link client credentials (legacy columns, kept for DB compat)
        $da_client_username = 'link_' . $da_token_id;
        $da_client_password = bin2hex( random_bytes( 16 ) );
        $da_password_hash   = wp_hash_password( $da_client_password );

        // insertin the token record into the db
        $da_table  = $wpdb->prefix . self::TOKENS_TABLE;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table insert; no WP cache for custom token table.
        $da_result = $wpdb->insert(
            $da_table,
            array(
                'token_id'             => $da_token_id,
                'token_hash'           => hash( 'sha256', $da_token ),
                'form_id'              => absint( $da_data['form_id'] ),
                'fields'               => wp_json_encode( $da_data['fields'] ?? array() ),
                'filters'              => wp_json_encode( $da_filters ),
                'description'          => sanitize_text_field( $da_data['description'] ?? '' ),
                'created_by'           => absint( $da_data['created_by'] ?? get_current_user_id() ),
                'created_at'           => current_time( 'mysql', true ),
                'expires_at'           => $expiring_duedate,
                'max_downloads'        => $da_max_downloads,
                'client_username'      => $da_client_username,
                'client_password_hash' => $da_password_hash,
            ),
            array( '%s', '%s', '%d', '%s', '%s', '%s', '%d', '%s', '%s', '%d', '%s', '%s' )
        );

        if ( false === $da_result ) {
            return new WP_Error( 'db_error', __( 'Failed to create export token.', 'izzygld-entry-export-for-gravity-forms' ) );
        }

        // buildin the export url
        $da_export_url = $this->make_export_url( $da_token_id, $da_token );

        return array(
            'token_id'   => $da_token_id,
            'url'        => $da_export_url,
            'expires_at' => $expiring_duedate,
            'form_id'    => $da_data['form_id'],
        );
    }

    /**
     * generatin a cryptographically secure token id
     * just random bytes converted to hex
     *
     * @return string
     */
    private function make_secure_token_id() {
        return bin2hex( random_bytes( 16 ) );
    }

    /**
     * signin da token with HMAC
     * makes it so we can verify the token later
     *
     * @param string $da_token_id token id
     * @param int    $da_form_id  form id
     * @param string $da_secret   secret key
     * @return string signed token
     */
    private function sign_da_token( $da_token_id, $da_form_id, $da_secret ) {
        $da_payload   = $da_token_id . '|' . $da_form_id;
        $da_signature = hash_hmac( 'sha256', $da_payload, $da_secret );
        return base64_encode( $da_payload . '|' . $da_signature );
    }

    /**
     * verifyin and decodin a signed token
     * checks if the signature matches
     *
     * @param string $da_token signed token
     * @return array|false token parts or false if invalid
     */
    public function token_validation( $da_token ) {
        $da_decoded = base64_decode( $da_token, true );
        if ( false === $da_decoded ) {
            return false;
        }

        $da_parts = explode( '|', $da_decoded );
        if ( count( $da_parts ) !== 3 ) {
            return false;
        }

        list( $da_token_id, $da_form_id, $da_signature ) = $da_parts;

        // verifyin the signature matches
        $da_secret            = $this->grab_secret_key();
        $da_expected_signature = hash_hmac( 'sha256', $da_token_id . '|' . $da_form_id, $da_secret );

        if ( ! hash_equals( $da_expected_signature, $da_signature ) ) {
            return false;
        }

        return array(
            'token_id' => $da_token_id,
            'form_id'  => absint( $da_form_id ),
        );
    }

    /**
     * validatin a token for export access
     * checks all the things like expirton, revocation, download limits
     *
     * @param string $da_token_id token id
     * @param string $da_token    full signed token
     * @return array|WP_Error token data or error
     */
    public function check_export_allowed( $da_token_id, $da_token ) {
        global $wpdb;

        // verifyin token signature first
        $da_verified = $this->token_validation( $da_token );
        if ( false === $da_verified || $da_verified['token_id'] !== $da_token_id ) {
            $this->log_da_access( $da_token_id, 0, 'invalid_signature' );
            return new WP_Error( 'invalid_token', __( 'Invalid export token.', 'izzygld-entry-export-for-gravity-forms' ) );
        }

        // gettin the token record from db
        $da_table  = $wpdb->prefix . self::TOKENS_TABLE;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table lookup; caching not appropriate for token validation.
        $da_record = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$da_table} WHERE token_id = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                $da_token_id
            ),
            ARRAY_A
        );

        if ( ! $da_record ) {
            $this->log_da_access( $da_token_id, 0, 'not_found' );
            return new WP_Error( 'token_not_found', __( 'Export token not found.', 'izzygld-entry-export-for-gravity-forms' ) );
        }

        // checkin if its been revoked
        if ( ! empty( $da_record['is_revoked'] ) ) {
            $this->log_da_access( $da_token_id, $da_record['form_id'], 'revoked' );
            return new WP_Error( 'token_revoked', __( 'This export link has been revoked.', 'izzygld-entry-export-for-gravity-forms' ) );
        }

        // checkin if its expired
        if ( ! empty( $da_record['expires_at'] ) ) {
            $da_expires = strtotime( $da_record['expires_at'] );
            if ( time() > $da_expires ) {
                $this->log_da_access( $da_token_id, $da_record['form_id'], 'expired' );
                return new WP_Error( 'token_expired', __( 'This export link has expired.', 'izzygld-entry-export-for-gravity-forms' ) );
            }
        }

        // checkin download limit
        if ( ! empty( $da_record['max_downloads'] ) && $da_record['download_count'] >= $da_record['max_downloads'] ) {
            $this->log_da_access( $da_token_id, $da_record['form_id'], 'limit_exceeded' );
            return new WP_Error( 'download_limit', __( 'Download limit exceeded for this link.', 'izzygld-entry-export-for-gravity-forms' ) );
        }

        // checkin ip allowlist
        $da_addon = izzygld_eee_get_da_addon();
        if ( $da_addon ) {
            $da_allowed_ips = $da_addon->get_plugin_setting( 'allowed_ips' );
            if ( ! empty( $da_allowed_ips ) ) {
                $da_ip_list    = array_filter( array_map( 'trim', explode( "\n", $da_allowed_ips ) ) );
                $da_visitor_ip = $this->grab_user_ip();
                if ( ! in_array( $da_visitor_ip, $da_ip_list, true ) ) {
                    $this->log_da_access( $da_token_id, $da_record['form_id'], 'ip_blocked', 0, __( 'IP not in allowlist', 'izzygld-entry-export-for-gravity-forms' ) );
                    return new WP_Error( 'ip_blocked', __( 'Access denied.', 'izzygld-entry-export-for-gravity-forms' ) );
                }
            }
        }

        // decodin stored data
        $da_record['fields']  = json_decode( $da_record['fields'], true ) ?: array();
        $da_record['filters'] = json_decode( $da_record['filters'], true ) ?: array();

        return $da_record;
    }

    /**
     * validatin client credentials for export access
     * checks username and password against whats in the db
     *
     * @param string $da_token_id token id
     * @param string $da_username client username
     * @param string $da_password client password (plain text)
     * @return true|WP_Error true on success or error
     */
    public function check_creds_valid( $da_token_id, $da_username, $da_password ) {
        global $wpdb;

        $da_table  = $wpdb->prefix . self::TOKENS_TABLE;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table lookup; caching not appropriate for credential validation.
        $da_record = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT client_username, client_password_hash FROM {$da_table} WHERE token_id = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                $da_token_id
            ),
            ARRAY_A
        );

        if ( ! $da_record ) {
            return new WP_Error( 'invalid_credentials', __( 'Invalid credentials.', 'izzygld-entry-export-for-gravity-forms' ) );
        }

        // constant-time username comparison for security
        if ( ! hash_equals( $da_record['client_username'], $da_username ) ) {
            $this->log_da_access( $da_token_id, 0, 'auth_failed', 0, 'Invalid username' );
            return new WP_Error( 'invalid_credentials', __( 'Invalid credentials.', 'izzygld-entry-export-for-gravity-forms' ) );
        }

        // verifyin password hash
        if ( ! wp_check_password( $da_password, $da_record['client_password_hash'] ) ) {
            $this->log_da_access( $da_token_id, 0, 'auth_failed', 0, 'Invalid password' );
            return new WP_Error( 'invalid_credentials', __( 'Invalid credentials.', 'izzygld-entry-export-for-gravity-forms' ) );
        }

        return true;
    }

    /**
     * loggin da download after successful export
     * updates the download count and last download time
     *
     * @param string $da_token_id      token id
     * @param int    $da_entries_count number of entries exported
     * @return void
     */
    public function log_da_download( $da_token_id, $da_entries_count = 0 ) {
        global $wpdb;

        $da_table = $wpdb->prefix . self::TOKENS_TABLE;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table update; write-only operation.
        $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$da_table} SET download_count = download_count + 1, last_download_at = %s WHERE token_id = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                current_time( 'mysql', true ),
                $da_token_id
            )
        );

        // gettin form_id for loggin
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table lookup after download.
        $da_record = $wpdb->get_row(
            $wpdb->prepare( "SELECT form_id FROM {$da_table} WHERE token_id = %s", $da_token_id ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            ARRAY_A
        );

        $this->log_da_access( $da_token_id, $da_record['form_id'] ?? 0, 'success', $da_entries_count );
    }

    /**
     * killin da token - revokes it so it cant be used
     * marks it as revoked in the database
     *
     * @param string $da_token_id token id to revoke
     * @return bool|WP_Error true on success or error
     */
    public function kill_da_token( $da_token_id ) {
        global $wpdb;

        $da_table  = $wpdb->prefix . self::TOKENS_TABLE;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table update for token revocation.
        $da_result = $wpdb->update(
            $da_table,
            array(
                'is_revoked' => 1,
                'revoked_at' => current_time( 'mysql', true ),
                'revoked_by' => get_current_user_id(),
            ),
            array( 'token_id' => $da_token_id ),
            array( '%d', '%s', '%d' ),
            array( '%s' )
        );

        if ( false === $da_result ) {
            return new WP_Error( 'revoke_failed', __( 'Failed to revoke token.', 'izzygld-entry-export-for-gravity-forms' ) );
        }

        return true;
    }

    /**
     * grabbin all tokens for a specific form
     * can get just active ones or all of em
     *
     * @param int  $da_form_id    form id
     * @param bool $active_only   whether to get only active tokens
     * @return array
     */
    public function grab_tokens_for_form( $da_form_id, $active_only = true ) {
        global $wpdb;

        $da_table = $wpdb->prefix . self::TOKENS_TABLE;

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Table name is wpdb->prefix + constant; custom table, no WP cache.
        if ( $active_only ) {
            $da_tokens = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT token_id, description, created_at, expires_at, max_downloads, download_count, last_download_at, is_revoked, client_username
                     FROM {$da_table}
                     WHERE form_id = %d AND is_revoked = 0 AND (expires_at IS NULL OR expires_at > NOW())
                     ORDER BY created_at DESC",
                    $da_form_id
                ),
                ARRAY_A
            );
        } else {
            $da_tokens = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT token_id, description, created_at, expires_at, max_downloads, download_count, last_download_at, is_revoked, client_username
                     FROM {$da_table}
                     WHERE form_id = %d
                     ORDER BY created_at DESC",
                    $da_form_id
                ),
                ARRAY_A
            );
        }
        // phpcs:enable

        // addin form title and user info
        $da_form = GFAPI::get_form( $da_form_id );
        foreach ( $da_tokens as &$da_token ) {
            $da_token['form_title'] = $da_form ? $da_form['title'] : __( 'Unknown', 'izzygld-entry-export-for-gravity-forms' );
        }

        return $da_tokens;
    }

    /**
     * grabbin all active tokens across all forms
     * for the overview page
     *
     * @return array
     */
    public function grab_all_active_tokens() {
        global $wpdb;

        $da_table = $wpdb->prefix . self::TOKENS_TABLE;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table for token management; caching not appropriate for token validation.
        $da_tokens = $wpdb->get_results(
            $wpdb->prepare(
                // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                // Table names are wpdb->prefix + constant or wpdb property.
                "SELECT t.id, t.token_id, t.form_id, t.fields, t.filters, t.description,
                        t.created_by, t.created_at, t.expires_at, t.max_downloads,
                        t.download_count, t.last_download_at, t.is_revoked,
                        t.client_username, u.display_name as created_by_name
                 FROM {$da_table} t
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

        // addin form titles
        foreach ( $da_tokens as &$da_token ) {
            $da_form = GFAPI::get_form( $da_token['form_id'] );
            $da_token['form_title'] = $da_form ? $da_form['title'] : __( 'Unknown', 'izzygld-entry-export-for-gravity-forms' );
        }

        return $da_tokens;
    }

    /**
     * loggin da access attempt
     * records who tried to access what and if it worked
     *
     * @param string      $da_token_id      token id
     * @param int         $da_form_id       form id
     * @param string      $da_status        status (success, expired, revoked, etc)
     * @param int         $da_entries_count number of entries exported
     * @param string|null $da_error_msg     error message if failed
     * @return void
     */
    public function log_da_access( $da_token_id, $da_form_id, $da_status, $da_entries_count = 0, $da_error_msg = null ) {
        global $wpdb;

        $da_addon = izzygld_eee_get_da_addon();
        if ( $da_addon && ! $da_addon->get_plugin_setting( 'enable_logging' ) ) {
            return;
        }

        $da_table = $wpdb->prefix . self::LOGS_TABLE;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom logging table; write-only operation.
        $wpdb->insert(
            $da_table,
            array(
                'token_id'         => $da_token_id,
                'form_id'          => $da_form_id,
                'accessed_at'      => current_time( 'mysql', true ),
                'ip_address'       => $this->grab_user_ip(),
                'user_agent'       => isset( $_SERVER['HTTP_USER_AGENT'] ) ? substr( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ), 0, 500 ) : null,
                'status'           => $da_status,
                'entries_exported' => $da_entries_count,
                'error_message'    => $da_error_msg,
            ),
            array( '%s', '%d', '%s', '%s', '%s', '%s', '%d', '%s' )
        );
    }

    /**
     * grabbin the user's ip address
     * only trusts proxy headers when comin from a known trusted proxy
     *
     * @return string
     */
    private function grab_user_ip() {
        // REMOTE_ADDR is always the direct connecton and cant be spoofed
        $da_remote_addr = isset( $_SERVER['REMOTE_ADDR'] )
            ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) )
            : '0.0.0.0';

        /**
         * Filter the list of trusted proxy IPs/CIDRs
         *
         * if the direct connecton is from a trusted proxy
         * we read the real client ip from the forwading headers
         *
         * @param array $da_trusted_proxies trusted proxy ip addresses
         */
        $da_trusted_proxies = apply_filters( 'izzygld_eee_trusted_proxies', array() );

        if ( empty( $da_trusted_proxies ) || ! in_array( $da_remote_addr, $da_trusted_proxies, true ) ) {
            // not behind a trusted proxy so REMOTE_ADDR is the client ip
            return filter_var( $da_remote_addr, FILTER_VALIDATE_IP ) ? $da_remote_addr : '0.0.0.0';
        }

        // trusted proxy so read forwading headers in priority order
        $da_proxy_keys = array(
            'HTTP_CF_CONNECTING_IP', // cloudflare
            'HTTP_X_FORWARDED_FOR',
            'HTTP_X_REAL_IP',
        );

        foreach ( $da_proxy_keys as $da_key ) {
            if ( ! empty( $_SERVER[ $da_key ] ) ) {
                $da_ip = sanitize_text_field( wp_unslash( $_SERVER[ $da_key ] ) );
                // handlin comma-separated ips (x-forwarded-for)
                if ( strpos( $da_ip, ',' ) !== false ) {
                    $da_ip = trim( explode( ',', $da_ip )[0] );
                }
                if ( filter_var( $da_ip, FILTER_VALIDATE_IP ) ) {
                    return $da_ip;
                }
            }
        }

        return filter_var( $da_remote_addr, FILTER_VALIDATE_IP ) ? $da_remote_addr : '0.0.0.0';
    }

    /**
     * gettin or generatin the secret key
     * used for signin tokens
     *
     * @return string
     */
    private function grab_secret_key() {
        $da_addon = izzygld_eee_get_da_addon();
        $da_key   = $da_addon ? $da_addon->get_plugin_setting( 'secret_key' ) : '';

        if ( empty( $da_key ) ) {
            // fallback: derive a stable key from wp auth constants
            if ( defined( 'AUTH_KEY' ) && AUTH_KEY !== 'put your unique phrase here' ) {
                $da_key = AUTH_KEY;
            } elseif ( defined( 'SECURE_AUTH_KEY' ) && SECURE_AUTH_KEY !== 'put your unique phrase here' ) {
                $da_key = SECURE_AUTH_KEY;
            } else {
                // last resort: generate a persistant key and store it
                $da_key = get_option( 'izzygld_eee_fallback_secret' );
                if ( empty( $da_key ) ) {
                    $da_key = bin2hex( random_bytes( 32 ) );
                    update_option( 'izzygld_eee_fallback_secret', $da_key, false );
                }
            }
        }

        return $da_key;
    }

    /**
     * buildin the export url
     * adds the token params to the rest endpoint
     *
     * @param string $da_token_id token id
     * @param string $da_token    full signed token
     * @return string
     */
    private function make_export_url( $da_token_id, $da_token ) {
        return add_query_arg(
            array(
                'izzygld_eee_export' => $da_token_id,
                'token'         => rawurlencode( $da_token ),
            ),
            rest_url( 'izzygld-eee/v1/export' )
        );
    }

    /**
     * cleanin up expired tokens
     * deletes tokens that have been expired for a while
     *
     * @param int $days_old delete tokens expired more than this many days ago
     * @return int number of tokens deleted
     */
    public function cleanup_gone( $days_old = 30 ) {
        global $wpdb;

        $da_table    = $wpdb->prefix . self::TOKENS_TABLE;
        $da_cutoff   = gmdate( 'Y-m-d H:i:s', time() - ( $days_old * DAY_IN_SECONDS ) );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Cleanup operation on custom table.
        $da_deleted  = $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$da_table} WHERE expires_at IS NOT NULL AND expires_at < %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                $da_cutoff
            )
        );

        return $da_deleted;
    }
}
