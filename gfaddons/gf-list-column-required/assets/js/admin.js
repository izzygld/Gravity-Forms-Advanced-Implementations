/**
 * GF List Column Required - Form Editor JS
 *
 * injects a "Required" checkbox next to each column in the list field editor
 * uses gf's built-in callback system: window["gform_append_field_choice_option_list"]
 * which gets called by GetFieldChoices() for list-type fields
 *
 * the isColumnRequired property is stored on each choice object in field.choices[]
 * and gets saved automatically when the form is saved (its part of the field json)
 *
 * @package GF_List_Column_Required
 */

(function ($) {
    'use strict';

    /**
     * this callback is called by GetFieldChoices() in gravityforms/js.php
     * for each column choice when rendering the columns list in the form editor
     * it receives the field object and the choice index
     * returns HTML string that gets appended after each column row's inputs
     */
    window['gform_append_field_choice_option_list'] = function (field, index) {
        if (!field || !field.choices || !field.choices[index]) {
            return '';
        }

        var isRequired = field.choices[index].isColumnRequired ? 'checked="checked"' : '';

        var html = '<span class="gf-lcr-required-wrap">';
        html += '<input type="checkbox" ';
        html += 'id="gf_lcr_col_required_' + index + '" ';
        html += 'class="gf-lcr-col-required" ';
        html += 'data-index="' + index + '" ';
        html += isRequired + ' ';
        html += 'onclick="gfLcrSetColumnRequired(this, ' + index + ');" ';
        html += '/>';
        html += '<label for="gf_lcr_col_required_' + index + '" class="gf-lcr-required-label">';
        html += 'Required';
        html += '</label>';
        html += '</span>';

        return html;
    };

    /**
     * called when the "Required" checkbox is toggled for a column
     * sets the isColumnRequired property on the choice object
     * GF saves this as part of the field JSON when the form is saved
     */
    window.gfLcrSetColumnRequired = function (checkbox, index) {
        var field = GetSelectedField();

        if (!field || !field.choices || !field.choices[index]) {
            return;
        }

        field.choices[index].isColumnRequired = checkbox.checked;
    };

    /**
     * when the main field-level "Required" checkbox is toggled ON,
     * auto-check all column required checkboxes for list fields.
     * this saves the admin from having to check each column individually
     * when they want the whole thing required.
     *
     * we listen on the #field_required checkbox which calls
     * SetFieldRequired(this.checked) via onclick in form_detail.php
     */
    $(document).on('click', '#field_required', function () {
        var field = GetSelectedField();

        // only do this for list fields with columns enabled
        if (!field || GetInputType(field) !== 'list' || !field.enableColumns || !field.choices) {
            return;
        }

        var isChecked = $(this).is(':checked');

        // only auto-set when turning required ON, dont clear when turning OFF
        // (admin might want the field optional but still keep specific columns required)
        if (!isChecked) {
            return;
        }

        // mark all columns as required
        for (var i = 0; i < field.choices.length; i++) {
            field.choices[i].isColumnRequired = true;
        }

        // refresh the column choices UI so the checkboxes reflect the new state
        LoadFieldChoices(field);
    });

})(jQuery);
