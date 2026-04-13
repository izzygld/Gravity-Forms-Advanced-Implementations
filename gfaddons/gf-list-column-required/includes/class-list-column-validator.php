<?php
/**
 * List Column Validator
 *
 * handles server-side validation for required columns in list fields
 * hooks into gform_field_validation to check each row's required columns
 *
 * @package GF_List_Column_Required
 */

// dont let anyone access this directly
defined( 'ABSPATH' ) || exit;

/**
 * GF_LCR_VALIDATOR class
 *
 * does the actual validation work when a form is submitted
 * checks each row in a list field to make sure required columns are filled
 */
class GF_LCR_VALIDATOR {

    /**
     * hookin up our validation filter
     * this gets called from the main addon init()
     *
     * @return void
     */
    public function hookup() {
        add_filter( 'gform_field_validation', array( $this, 'validate_list_columns' ), 10, 4 );
    }

    /**
     * validatin list field columns that are marked as required
     *
     * how it works:
     * - only fires for list fields with enableColumns turned on
     * - looks at each column's isColumnRequired flag in field.choices
     * - for each submitted row that has at least one value, checks the required columns
     * - if any required column in a non-empty row is blank, fails validation
     * - completely empty rows are skipped (field-level isRequired handles "must have at least one row")
     *
     * @param array    $result the validation result array with is_valid and message
     * @param mixed    $value  the submitted field value
     * @param array    $form   the current form object
     * @param GF_Field $field  the current field object
     * @return array
     */
    public function validate_list_columns( $result, $value, $form, $field ) {
        // bail early if this aint a list field or columns aint enabled
        if ( $field->type !== 'list' ) {
            return $result;
        }

        if ( empty( $field->enableColumns ) || ! is_array( $field->choices ) ) {
            return $result;
        }

        // if already invalid from another validation, dont pile on
        if ( ! rgar( $result, 'is_valid' ) ) {
            return $result;
        }

        // figure out which columns are required
        $da_required_columns = $this->get_required_columns( $field );

        // if no columns marked required, nothin to do
        if ( empty( $da_required_columns ) ) {
            return $result;
        }

        // get the submitted list data as a structured array
        $da_list_values = $this->get_list_values( $value, $field );

        if ( empty( $da_list_values ) || ! is_array( $da_list_values ) ) {
            return $result;
        }

        // figure out which rows have data and which are empty
        $da_has_any_nonempty_row = false;
        foreach ( $da_list_values as $da_row ) {
            if ( is_array( $da_row ) && ! $this->is_row_empty( $da_row ) ) {
                $da_has_any_nonempty_row = true;
                break;
            }
        }

        // check each row for required column values
        $da_missing_columns = array();

        foreach ( $da_list_values as $da_row_index => $da_row ) {
            if ( ! is_array( $da_row ) ) {
                continue;
            }

            // if there are non-empty rows, skip any completely empty ones
            // (extra blank rows the user added but didnt fill)
            // but if ALL rows are empty, validate the first row anyway
            // cuz required columns mean "you gotta fill this in"
            if ( $this->is_row_empty( $da_row ) ) {
                if ( $da_has_any_nonempty_row || $da_row_index > 0 ) {
                    continue;
                }
            }

            // check each required column in this row
            foreach ( $da_required_columns as $da_col_name ) {
                $da_col_value = rgar( $da_row, $da_col_name );

                if ( '' === trim( (string) $da_col_value ) ) {
                    // track which columns are missing (avoid dupes in the message)
                    if ( ! in_array( $da_col_name, $da_missing_columns, true ) ) {
                        $da_missing_columns[] = $da_col_name;
                    }
                }
            }
        }

        // if we found missing required columns, fail the validation
        if ( ! empty( $da_missing_columns ) ) {
            $result['is_valid'] = false;
            $result['message']  = $this->build_error_message( $da_missing_columns );
        }

        return $result;
    }

    /**
     * grabbin the list of required column names from the field choices
     *
     * @param GF_Field $field the list field object
     * @return array column names that are required
     */
    private function get_required_columns( $field ) {
        $da_required = array();

        foreach ( $field->choices as $da_choice ) {
            if ( ! empty( $da_choice['isColumnRequired'] ) ) {
                $da_required[] = rgar( $da_choice, 'text', '' );
            }
        }

        return $da_required;
    }

    /**
     * convertin the submitted value into a structured list array
     * handles both serialized strings and already-parsed arrays
     *
     * @param mixed    $value the raw submitted value
     * @param GF_Field $field the list field object
     * @return array
     */
    private function get_list_values( $value, $field ) {
        // if its already an array of arrays (columns mode), use it
        if ( is_array( $value ) && ! empty( $value ) ) {
            // check if first element is an array (means create_list_array already ran)
            if ( is_array( reset( $value ) ) ) {
                return $value;
            }

            // its a flat array from POST, we need to structure it using column names
            return $field->create_list_array( $value );
        }

        // if its a serialized string (from entry), unserialize it
        if ( is_string( $value ) && ! empty( $value ) ) {
            $da_unserialized = maybe_unserialize( $value );
            if ( is_array( $da_unserialized ) ) {
                return $da_unserialized;
            }
        }

        return array();
    }

    /**
     * checkin if a row is completely empty (all values are blank)
     *
     * @param array $da_row a single row from the list
     * @return bool true if all values in the row are empty
     */
    private function is_row_empty( $da_row ) {
        foreach ( $da_row as $da_val ) {
            if ( '' !== trim( (string) $da_val ) ) {
                return false;
            }
        }
        return true;
    }

    /**
     * buildin a friendly error message listing the missing required columns
     *
     * @param array $da_missing_columns column names that are missing
     * @return string the error message
     */
    private function build_error_message( $da_missing_columns ) {
        if ( count( $da_missing_columns ) === 1 ) {
            return sprintf(
                /* translators: %s: column name */
                esc_html__( 'The "%s" column is required.', 'gf-list-column-required' ),
                esc_html( $da_missing_columns[0] )
            );
        }

        // for multiple missing columns, list em out
        $da_last   = array_pop( $da_missing_columns );
        $da_others = implode( '", "', array_map( 'esc_html', $da_missing_columns ) );

        return sprintf(
            /* translators: %1$s: comma-separated column names, %2$s: last column name */
            esc_html__( 'The "%1$s" and "%2$s" columns are required.', 'gf-list-column-required' ),
            $da_others,
            esc_html( $da_last )
        );
    }
}
