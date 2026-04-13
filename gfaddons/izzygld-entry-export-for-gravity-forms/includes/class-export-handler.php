<?php
/**
 * Export Maker for Izzygld Entry Export for Gravity Forms
 *
 * handles csv generaton and entry retrieval for exports
 * basically does all the heavy liftin for gettin data out
 *
 * @package Izzygld_Entry_Export
 */

// dont let ppl access directly
defined( 'ABSPATH' ) || exit;

/**
 * Izzygld_EEE_Export_Handler class
 *
 * generates csv exports from gravity forms entrys using GFAPI
 * this is where the actual export magic happens
 */
class Izzygld_EEE_Export_Handler {

    /**
     * generatin the csv export for given parameters
     * this is the main function that does the export
     *
     * @param int   $da_form_id form id
     * @param array $da_fields  field ids to include
     * @param array $da_filters search filters (start_date, end_date, status)
     * @return array|WP_Error array with 'content', 'filename', 'count' or error
     */
    public function export_generated( $da_form_id, $da_fields = array(), $da_filters = array() ) {
        // gettin the form first
        $da_form = GFAPI::get_form( $da_form_id );
        if ( ! $da_form ) {
            return new WP_Error( 'invalid_form', __( 'Form not found.', 'izzygld-entry-export-for-gravity-forms' ) );
        }

        // buildin the search criteria
        $da_search_stuff = $this->make_da_search_stuff( $da_filters );

        // gettin sorting setup
        $da_sorting = array(
            'key'        => 'date_created',
            'direction'  => 'DESC',
            'is_numeric' => false,
        );

        // fetchin entries using GFAPI (followin gf docs pattern)
        $da_entries = GFAPI::get_entries( $da_form_id, $da_search_stuff, $da_sorting );

        if ( is_wp_error( $da_entries ) ) {
            return $da_entries;
        }

        // buildin field map for export
        $da_field_mapping = $this->make_field_mapping( $da_form, $da_fields );

        // generatin csv content
        $da_csv_output = $this->make_da_csv( $da_entries, $da_field_mapping, $da_form, $da_filters );

        // generatin filename
        $da_filename = $this->make_da_filename( $da_form );

        return array(
            'content'  => $da_csv_output,
            'filename' => $da_filename,
            'count'    => count( $da_entries ),
            'form'     => $da_form,
        );
    }

    /**
     * buildin search criteria from filters
     * converts our filter array to what gfapi needs
     *
     * @param array $da_filters filter parameters
     * @return array search criteria array for GFAPI::get_entries()
     */
    private function make_da_search_stuff( $da_filters ) {
        $da_search_stuff = array();

        // status filter
        $da_status = $da_filters['status'] ?? 'active';
        if ( 'all' !== $da_status ) {
            $da_search_stuff['status'] = $da_status;
        }

        // date filters
        if ( ! empty( $da_filters['start_date'] ) ) {
            $da_search_stuff['start_date'] = $da_filters['start_date'];
        }

        if ( ! empty( $da_filters['end_date'] ) ) {
            // addin one day to end date to include entrys from that day
            $da_end_date = strtotime( $da_filters['end_date'] );
            if ( $da_end_date ) {
                $da_search_stuff['end_date'] = gmdate( 'Y-m-d', $da_end_date + DAY_IN_SECONDS );
            }
        }

        /**
         * Filter the search criterya before fetchin entries
         *
         * @param array $da_search_stuff search criteria array
         * @param array $da_filters      original filters
         */
        return apply_filters( 'izzygld_eee_search_criteria', $da_search_stuff, $da_filters );
    }

    /**
     * buildin field map from form and allowed fields
     * figures out which fields go in the export and what to call em
     *
     * @param array $da_form   form object
     * @param array $da_fields allowed field ids
     * @return array field map with id => label pairs
     */
    private function make_field_mapping( $da_form, $da_fields ) {
        $da_field_mapping = array();

        // gettin addon settings for this form
        $da_addon         = izzygld_eee_get_da_addon();
        $da_form_settings = $da_addon ? $da_addon->get_form_settings( $da_form ) : array();

        // includin metadata fields if configured
        $da_meta_fields = array(
            'id'           => array(
                'label'   => __( 'Entry ID', 'izzygld-entry-export-for-gravity-forms' ),
                'setting' => 'include_entry_id',
            ),
            'date_created' => array(
                'label'   => __( 'Date Created', 'izzygld-entry-export-for-gravity-forms' ),
                'setting' => 'include_date_created',
            ),
            'status'       => array(
                'label'   => __( 'Status', 'izzygld-entry-export-for-gravity-forms' ),
                'setting' => 'include_status',
            ),
            'source_url'   => array(
                'label'   => __( 'Source URL', 'izzygld-entry-export-for-gravity-forms' ),
                'setting' => 'include_source_url',
            ),
            'ip'           => array(
                'label'   => __( 'IP Address', 'izzygld-entry-export-for-gravity-forms' ),
                'setting' => 'include_ip',
            ),
        );

        foreach ( $da_meta_fields as $da_key => $da_config ) {
            if ( ! empty( $da_form_settings[ $da_config['setting'] ] ) ) {
                $da_field_mapping[ $da_key ] = array(
                    'label'   => $da_config['label'],
                    'type'    => 'meta',
                    'id'      => $da_key,
                );
            }
        }

        // addin form fields
        if ( ! empty( $da_form['fields'] ) ) {
            foreach ( $da_form['fields'] as $da_field ) {
                // skippin non-data fields
                if ( in_array( $da_field->type, array( 'html', 'section', 'page', 'captcha' ), true ) ) {
                    continue;
                }

                // some multi-input field types store a single combined value at the
                // parent id (eg time -> "11:12 am", date, password). treat them
                // as single-value fields so the export gets one column with the
                // combined value instead of seperate empty sub-columns
                $single_val_types = array( 'time', 'date', 'password' );

                // handlin multi-input fields (name, address, etc)
                if ( is_array( $da_field->inputs ) && ! empty( $da_field->inputs ) && ! in_array( $da_field->type, $single_val_types, true ) ) {
                    foreach ( $da_field->inputs as $da_input ) {
                        if ( ! empty( $da_input['isHidden'] ) ) {
                            continue;
                        }

                        $da_input_id     = $da_input['id'];
                        $da_setting_name = 'field_' . str_replace( '.', '_', $da_input_id );

                        // checkin if field is allowed
                        if ( ! empty( $da_fields ) && ! in_array( $da_setting_name, $da_fields, true ) ) {
                            continue;
                        }

                        // checkin form settings
                        if ( empty( $da_fields ) && empty( $da_form_settings[ $da_setting_name ] ) ) {
                            continue;
                        }

                        $da_field_label = ! empty( $da_field->adminLabel ) ? $da_field->adminLabel : $da_field->label;
                        $da_input_label = ! empty( $da_input['label'] ) ? $da_input['label'] : '';

                        $da_field_mapping[ (string) $da_input_id ] = array(
                            'label' => $da_input_label ? "{$da_field_label} - {$da_input_label}" : $da_field_label,
                            'type'  => 'field',
                            'id'    => $da_input_id,
                            'field' => $da_field,
                        );
                    }
                } else {
                    $da_setting_name = 'field_' . $da_field->id;

                    // checkin if field is allowed
                    if ( ! empty( $da_fields ) && ! in_array( $da_setting_name, $da_fields, true ) ) {
                        continue;
                    }

                    // checkin form settings
                    if ( empty( $da_fields ) && empty( $da_form_settings[ $da_setting_name ] ) ) {
                        continue;
                    }

                    $da_field_label = ! empty( $da_field->adminLabel ) ? $da_field->adminLabel : $da_field->label;

                    $da_field_mapping[ (string) $da_field->id ] = array(
                        'label' => $da_field_label,
                        'type'  => 'field',
                        'id'    => $da_field->id,
                        'field' => $da_field,
                    );
                }
            }
        }

        /**
         * Filter the field map before export
         *
         * @param array $da_field_mapping field map array
         * @param array $da_form          form object
         * @param array $da_fields        requested fields
         */
        return apply_filters( 'izzygld_eee_field_map', $da_field_mapping, $da_form, $da_fields );
    }

    /**
     * makin da csv content from entries
     * builds the actual csv string with headers and data
     *
     * @param array $da_entries      entry objects
     * @param array $da_field_mapping field map
     * @param array $da_form         form object
     * @param array $da_filters      applied filters
     * @return string csv content
     */
    private function make_da_csv( $da_entries, $da_field_mapping, $da_form, $da_filters ) {
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Using php://temp memory stream, not filesystem.
        $da_output = fopen( 'php://temp', 'r+' );

        // BOM for excel utf-8 compatability
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Writing to memory stream.
        fwrite( $da_output, "\xEF\xBB\xBF" );

        // header row
        $da_headers = array();
        foreach ( $da_field_mapping as $da_field_data ) {
            $da_headers[] = $da_field_data['label'];
        }
        fputcsv( $da_output, $da_headers );

        // data rows - loopn thru each entry
        foreach ( $da_entries as $da_entry ) {
            $da_row = array();

            foreach ( $da_field_mapping as $da_field_key => $da_field_data ) {
                if ( 'meta' === $da_field_data['type'] ) {
                    // metadata field
                    $da_value = rgar( $da_entry, $da_field_key );

                    // formatin date
                    if ( 'date_created' === $da_field_key && ! empty( $da_value ) ) {
                        $da_value = get_date_from_gmt( $da_value, 'Y-m-d H:i:s' );
                    }
                } else {
                    // form field
                    $da_value = $this->grab_field_value( $da_entry, $da_field_data, $da_form );
                }

                $da_row[] = $da_value;
            }

            fputcsv( $da_output, $da_row );
        }

        // gettin content
        rewind( $da_output );
        $da_csv_output = stream_get_contents( $da_output );
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closing memory stream.
        fclose( $da_output );

        /**
         * Filter the csv content before output
         *
         * @param string $da_csv_output csv content
         * @param array  $da_entries    entries array
         * @param array  $da_form       form object
         */
        return apply_filters( 'izzygld_eee_csv_content', $da_csv_output, $da_entries, $da_form );
    }

    /**
     * grabbin formatted field value for export
     * handles all the different field types
     *
     * @param array $da_entry      entry object
     * @param array $da_field_data field data from map
     * @param array $da_form       form object
     * @return string formatted value
     */
    private function grab_field_value( $da_entry, $da_field_data, $da_form ) {
        $da_field_id = $da_field_data['id'];
        $da_field    = $da_field_data['field'] ?? null;
        $da_value    = rgar( $da_entry, (string) $da_field_id );

        // handlin special field types
        if ( $da_field ) {
            switch ( $da_field->type ) {
                case 'checkbox':
                    // gettin comma-seperated checked values
                    if ( is_array( $da_field->inputs ) ) {
                        $da_checked = array();
                        foreach ( $da_field->inputs as $da_input ) {
                            $da_input_value = rgar( $da_entry, (string) $da_input['id'] );
                            if ( ! empty( $da_input_value ) ) {
                                $da_checked[] = $da_input_value;
                            }
                        }
                        $da_value = implode( ', ', $da_checked );
                    }
                    break;

                case 'multiselect':
                    // decodin json array
                    if ( ! empty( $da_value ) ) {
                        $da_decoded = json_decode( $da_value, true );
                        if ( is_array( $da_decoded ) ) {
                            $da_value = implode( ', ', $da_decoded );
                        }
                    }
                    break;

                case 'list':
                    // unserializin list field
                    $da_unserialized = maybe_unserialize( $da_value );
                    if ( is_array( $da_unserialized ) ) {
                        $da_formatted = array();
                        foreach ( $da_unserialized as $da_row ) {
                            if ( is_array( $da_row ) ) {
                                $da_formatted[] = implode( ' | ', $da_row );
                            } else {
                                $da_formatted[] = $da_row;
                            }
                        }
                        $da_value = implode( '; ', $da_formatted );
                    }
                    break;

                case 'fileupload':
                    // handlin file urls
                    if ( ! empty( $da_value ) ) {
                        $da_decoded = json_decode( $da_value, true );
                        if ( is_array( $da_decoded ) ) {
                            $da_value = implode( ', ', $da_decoded );
                        }
                    }
                    break;

                case 'date':
                    // formatin date based on field settings
                    if ( ! empty( $da_value ) && ! empty( $da_field->dateFormat ) ) {
                        $da_timestamp = strtotime( $da_value );
                        if ( $da_timestamp ) {
                            $da_value = GFCommon::date_display( $da_value, $da_field->dateFormat );
                        }
                    }
                    break;

                case 'time':
                    // formatin time
                    if ( ! empty( $da_value ) && is_array( $da_value ) ) {
                        $da_hour   = rgar( $da_value, 0 );
                        $da_minute = rgar( $da_value, 1 );
                        $da_ampm   = rgar( $da_value, 2 );
                        $da_value  = "{$da_hour}:{$da_minute}" . ( $da_ampm ? " {$da_ampm}" : '' );
                    }
                    break;

                case 'consent':
                    // formatin consent field
                    $da_value = $da_value ? __( 'Yes', 'izzygld-entry-export-for-gravity-forms' ) : __( 'No', 'izzygld-entry-export-for-gravity-forms' );
                    break;
            }

            // use gf's export method if availalbe
            if ( method_exists( $da_field, 'get_value_export' ) ) {
                $da_export_value = $da_field->get_value_export( $da_entry, (string) $da_field_id, false, true );
                if ( ! empty( $da_export_value ) ) {
                    $da_value = $da_export_value;
                }
            }
        }

        // sanitizin for csv
        $da_value = $this->csv_safe_value( $da_value );

        /**
         * Filter individual field value before export
         *
         * @param string $da_value field value
         * @param array  $da_entry entry object
         * @param mixed  $da_field field object
         * @param array  $da_form  form object
         */
        return apply_filters( 'izzygld_eee_field_value', $da_value, $da_entry, $da_field, $da_form );
    }

    /**
     * sanitizin value for csv output
     * prevents formula injecton and other bad stuff
     *
     * @param mixed $da_value value to sanitize
     * @return string sanitized value
     */
    private function csv_safe_value( $da_value ) {
        if ( is_array( $da_value ) ) {
            $da_value = implode( ', ', array_filter( $da_value ) );
        }

        // convertin to string
        $da_value = (string) $da_value;

        // removin null bytes
        $da_value = str_replace( "\0", '', $da_value );

        // preventin formula injecton (csv injecton protecton)
        $da_first_char = substr( $da_value, 0, 1 );
        if ( in_array( $da_first_char, array( '=', '+', '-', '@', "\t", "\r" ), true ) ) {
            $da_value = "'" . $da_value;
        }

        return $da_value;
    }

    /**
     * makin da export filename
     * generates a nice filename with form title and date
     *
     * @param array $da_form form object
     * @return string filename
     */
    private function make_da_filename( $da_form ) {
        $da_form_title = sanitize_file_name( $da_form['title'] );
        $da_date       = gmdate( 'Y-m-d-His' );

        return sprintf( 'gf-export-%s-%s.csv', $da_form_title, $da_date );
    }

    /**
     * gettin entry count for preview
     * just counts how many entries match the filters
     *
     * @param int   $da_form_id form id
     * @param array $da_filters search filters
     * @return int|WP_Error entry count or error
     */
    public function grab_entry_count( $da_form_id, $da_filters = array() ) {
        $da_search_stuff = $this->make_da_search_stuff( $da_filters );
        return GFAPI::count_entries( $da_form_id, $da_search_stuff );
    }
}
