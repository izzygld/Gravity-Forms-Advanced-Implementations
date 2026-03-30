<?php
/**
 * Export Handler for GF External Entry Export.
 *
 * Handles CSV generation and entry retrieval for exports.
 *
 * @package GF_External_Entry_Export
 */

defined( 'ABSPATH' ) || exit;

/**
 * GF_EEE_Export_Handler class.
 *
 * Generates CSV exports from Gravity Forms entries using GFAPI.
 */
class GF_EEE_Export_Handler {

    /**
     * Generate CSV export for given parameters.
     *
     * @param int   $form_id Form ID.
     * @param array $fields  Field IDs to include.
     * @param array $filters Search filters (start_date, end_date, status).
     * @return array|WP_Error Array with 'content', 'filename', 'count' or error.
     */
    public function generate_export( $form_id, $fields = array(), $filters = array() ) {
        // Get form
        $form = GFAPI::get_form( $form_id );
        if ( ! $form ) {
            return new WP_Error( 'invalid_form', __( 'Form not found.', 'gf-external-entry-export' ) );
        }

        // Build search criteria
        $search_criteria = $this->build_search_criteria( $filters );

        // Get sorting
        $sorting = array(
            'key'        => 'date_created',
            'direction'  => 'DESC',
            'is_numeric' => false,
        );

        // Fetch entries using GFAPI (following GF docs pattern)
        $entries = GFAPI::get_entries( $form_id, $search_criteria, $sorting );

        if ( is_wp_error( $entries ) ) {
            return $entries;
        }

        // Build field map for export
        $field_map = $this->build_field_map( $form, $fields );

        // Generate CSV content
        $csv_content = $this->generate_csv( $entries, $field_map, $form, $filters );

        // Generate filename
        $filename = $this->generate_filename( $form );

        return array(
            'content'  => $csv_content,
            'filename' => $filename,
            'count'    => count( $entries ),
            'form'     => $form,
        );
    }

    /**
     * Build search criteria from filters.
     *
     * @param array $filters Filter parameters.
     * @return array Search criteria array for GFAPI::get_entries().
     */
    private function build_search_criteria( $filters ) {
        $search_criteria = array();

        // Status filter
        $status = $filters['status'] ?? 'active';
        if ( 'all' !== $status ) {
            $search_criteria['status'] = $status;
        }

        // Date filters
        if ( ! empty( $filters['start_date'] ) ) {
            $search_criteria['start_date'] = $filters['start_date'];
        }

        if ( ! empty( $filters['end_date'] ) ) {
            // Add one day to end date to include entries from that day
            $end_date = strtotime( $filters['end_date'] );
            if ( $end_date ) {
                $search_criteria['end_date'] = gmdate( 'Y-m-d', $end_date + DAY_IN_SECONDS );
            }
        }

        /**
         * Filter the search criteria before fetching entries.
         *
         * @param array $search_criteria Search criteria array.
         * @param array $filters         Original filters.
         */
        return apply_filters( 'gf_eee_search_criteria', $search_criteria, $filters );
    }

    /**
     * Build field map from form and allowed fields.
     *
     * @param array $form   Form object.
     * @param array $fields Allowed field IDs.
     * @return array Field map with id => label pairs.
     */
    private function build_field_map( $form, $fields ) {
        $field_map = array();

        // Get addon settings for this form
        $addon         = gf_external_entry_export();
        $form_settings = $addon ? $addon->get_form_settings( $form ) : array();

        // Include metadata fields if configured
        $meta_fields = array(
            'id'           => array(
                'label'   => __( 'Entry ID', 'gf-external-entry-export' ),
                'setting' => 'include_entry_id',
            ),
            'date_created' => array(
                'label'   => __( 'Date Created', 'gf-external-entry-export' ),
                'setting' => 'include_date_created',
            ),
            'status'       => array(
                'label'   => __( 'Status', 'gf-external-entry-export' ),
                'setting' => 'include_status',
            ),
            'source_url'   => array(
                'label'   => __( 'Source URL', 'gf-external-entry-export' ),
                'setting' => 'include_source_url',
            ),
            'ip'           => array(
                'label'   => __( 'IP Address', 'gf-external-entry-export' ),
                'setting' => 'include_ip',
            ),
        );

        foreach ( $meta_fields as $key => $config ) {
            if ( ! empty( $form_settings[ $config['setting'] ] ) ) {
                $field_map[ $key ] = array(
                    'label'   => $config['label'],
                    'type'    => 'meta',
                    'id'      => $key,
                );
            }
        }

        // Add form fields
        if ( ! empty( $form['fields'] ) ) {
            foreach ( $form['fields'] as $field ) {
                // Skip non-data fields
                if ( in_array( $field->type, array( 'html', 'section', 'page', 'captcha' ), true ) ) {
                    continue;
                }

                // Some multi-input field types store a single combined value at the
                // parent ID (e.g. Time → "11:12 am", Date, Password). Treat them
                // as single-value fields so the export gets one column with the
                // combined value instead of separate empty sub-columns.
                $single_value_types = array( 'time', 'date', 'password' );

                // Handle multi-input fields (name, address, etc.)
                if ( is_array( $field->inputs ) && ! empty( $field->inputs ) && ! in_array( $field->type, $single_value_types, true ) ) {
                    foreach ( $field->inputs as $input ) {
                        if ( ! empty( $input['isHidden'] ) ) {
                            continue;
                        }

                        $input_id     = $input['id'];
                        $setting_name = 'field_' . str_replace( '.', '_', $input_id );

                        // Check if field is allowed
                        if ( ! empty( $fields ) && ! in_array( $setting_name, $fields, true ) ) {
                            continue;
                        }

                        // Check form settings
                        if ( empty( $fields ) && empty( $form_settings[ $setting_name ] ) ) {
                            continue;
                        }

                        $field_label = ! empty( $field->adminLabel ) ? $field->adminLabel : $field->label;
                        $input_label = ! empty( $input['label'] ) ? $input['label'] : '';

                        $field_map[ (string) $input_id ] = array(
                            'label' => $input_label ? "{$field_label} - {$input_label}" : $field_label,
                            'type'  => 'field',
                            'id'    => $input_id,
                            'field' => $field,
                        );
                    }
                } else {
                    $setting_name = 'field_' . $field->id;

                    // Check if field is allowed
                    if ( ! empty( $fields ) && ! in_array( $setting_name, $fields, true ) ) {
                        continue;
                    }

                    // Check form settings
                    if ( empty( $fields ) && empty( $form_settings[ $setting_name ] ) ) {
                        continue;
                    }

                    $field_label = ! empty( $field->adminLabel ) ? $field->adminLabel : $field->label;

                    $field_map[ (string) $field->id ] = array(
                        'label' => $field_label,
                        'type'  => 'field',
                        'id'    => $field->id,
                        'field' => $field,
                    );
                }
            }
        }

        /**
         * Filter the field map before export.
         *
         * @param array $field_map Field map array.
         * @param array $form      Form object.
         * @param array $fields    Requested fields.
         */
        return apply_filters( 'gf_eee_field_map', $field_map, $form, $fields );
    }

    /**
     * Generate CSV content from entries.
     *
     * @param array $entries   Entry objects.
     * @param array $field_map Field map.
     * @param array $form      Form object.
     * @param array $filters   Applied filters.
     * @return string CSV content.
     */
    private function generate_csv( $entries, $field_map, $form, $filters ) {
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Using php://temp memory stream, not filesystem.
        $output = fopen( 'php://temp', 'r+' );

        // BOM for Excel UTF-8 compatibility
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Writing to memory stream.
        fwrite( $output, "\xEF\xBB\xBF" );

        // Header row
        $headers = array();
        foreach ( $field_map as $field_data ) {
            $headers[] = $field_data['label'];
        }
        fputcsv( $output, $headers );

        // Data rows
        foreach ( $entries as $entry ) {
            $row = array();

            foreach ( $field_map as $field_key => $field_data ) {
                if ( 'meta' === $field_data['type'] ) {
                    // Metadata field
                    $value = rgar( $entry, $field_key );

                    // Format date
                    if ( 'date_created' === $field_key && ! empty( $value ) ) {
                        $value = get_date_from_gmt( $value, 'Y-m-d H:i:s' );
                    }
                } else {
                    // Form field
                    $value = $this->get_field_value( $entry, $field_data, $form );
                }

                $row[] = $value;
            }

            fputcsv( $output, $row );
        }

        // Get content
        rewind( $output );
        $csv_content = stream_get_contents( $output );
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closing memory stream.
        fclose( $output );

        /**
         * Filter the CSV content before output.
         *
         * @param string $csv_content CSV content.
         * @param array  $entries     Entries array.
         * @param array  $form        Form object.
         */
        return apply_filters( 'gf_eee_csv_content', $csv_content, $entries, $form );
    }

    /**
     * Get formatted field value for export.
     *
     * @param array $entry      Entry object.
     * @param array $field_data Field data from map.
     * @param array $form       Form object.
     * @return string Formatted value.
     */
    private function get_field_value( $entry, $field_data, $form ) {
        $field_id = $field_data['id'];
        $field    = $field_data['field'] ?? null;
        $value    = rgar( $entry, (string) $field_id );

        // Handle special field types
        if ( $field ) {
            switch ( $field->type ) {
                case 'checkbox':
                    // Get comma-separated checked values
                    if ( is_array( $field->inputs ) ) {
                        $checked = array();
                        foreach ( $field->inputs as $input ) {
                            $input_value = rgar( $entry, (string) $input['id'] );
                            if ( ! empty( $input_value ) ) {
                                $checked[] = $input_value;
                            }
                        }
                        $value = implode( ', ', $checked );
                    }
                    break;

                case 'multiselect':
                    // Decode JSON array
                    if ( ! empty( $value ) ) {
                        $decoded = json_decode( $value, true );
                        if ( is_array( $decoded ) ) {
                            $value = implode( ', ', $decoded );
                        }
                    }
                    break;

                case 'list':
                    // Unserialize list field
                    $unserialized = maybe_unserialize( $value );
                    if ( is_array( $unserialized ) ) {
                        $formatted = array();
                        foreach ( $unserialized as $row ) {
                            if ( is_array( $row ) ) {
                                $formatted[] = implode( ' | ', $row );
                            } else {
                                $formatted[] = $row;
                            }
                        }
                        $value = implode( '; ', $formatted );
                    }
                    break;

                case 'fileupload':
                    // Handle file URLs
                    if ( ! empty( $value ) ) {
                        $decoded = json_decode( $value, true );
                        if ( is_array( $decoded ) ) {
                            $value = implode( ', ', $decoded );
                        }
                    }
                    break;

                case 'date':
                    // Format date based on field settings
                    if ( ! empty( $value ) && ! empty( $field->dateFormat ) ) {
                        $timestamp = strtotime( $value );
                        if ( $timestamp ) {
                            $value = GFCommon::date_display( $value, $field->dateFormat );
                        }
                    }
                    break;

                case 'time':
                    // Format time
                    if ( ! empty( $value ) && is_array( $value ) ) {
                        $hour   = rgar( $value, 0 );
                        $minute = rgar( $value, 1 );
                        $ampm   = rgar( $value, 2 );
                        $value  = "{$hour}:{$minute}" . ( $ampm ? " {$ampm}" : '' );
                    }
                    break;

                case 'consent':
                    // Format consent field
                    $value = $value ? __( 'Yes', 'gf-external-entry-export' ) : __( 'No', 'gf-external-entry-export' );
                    break;
            }

            // Use GF's export method if available
            if ( method_exists( $field, 'get_value_export' ) ) {
                $export_value = $field->get_value_export( $entry, (string) $field_id, false, true );
                if ( ! empty( $export_value ) ) {
                    $value = $export_value;
                }
            }
        }

        // Sanitize for CSV
        $value = $this->sanitize_for_csv( $value );

        /**
         * Filter individual field value before export.
         *
         * @param string $value    Field value.
         * @param array  $entry    Entry object.
         * @param mixed  $field    Field object.
         * @param array  $form     Form object.
         */
        return apply_filters( 'gf_eee_field_value', $value, $entry, $field, $form );
    }

    /**
     * Sanitize value for CSV output.
     *
     * @param mixed $value Value to sanitize.
     * @return string Sanitized value.
     */
    private function sanitize_for_csv( $value ) {
        if ( is_array( $value ) ) {
            $value = implode( ', ', array_filter( $value ) );
        }

        // Convert to string
        $value = (string) $value;

        // Remove null bytes
        $value = str_replace( "\0", '', $value );

        // Prevent formula injection (CSV injection protection)
        $first_char = substr( $value, 0, 1 );
        if ( in_array( $first_char, array( '=', '+', '-', '@', "\t", "\r" ), true ) ) {
            $value = "'" . $value;
        }

        return $value;
    }

    /**
     * Generate export filename.
     *
     * @param array $form Form object.
     * @return string Filename.
     */
    private function generate_filename( $form ) {
        $form_title = sanitize_file_name( $form['title'] );
        $date       = gmdate( 'Y-m-d-His' );

        return sprintf( 'gf-export-%s-%s.csv', $form_title, $date );
    }

    /**
     * Get entry count for preview.
     *
     * @param int   $form_id Form ID.
     * @param array $filters Search filters.
     * @return int|WP_Error Entry count or error.
     */
    public function get_entry_count( $form_id, $filters = array() ) {
        $search_criteria = $this->build_search_criteria( $filters );
        return GFAPI::count_entries( $form_id, $search_criteria );
    }
}
