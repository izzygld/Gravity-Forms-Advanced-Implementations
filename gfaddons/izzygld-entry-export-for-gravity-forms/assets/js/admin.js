/**
 * Izzygld Entry Export for Gravity Forms - Admin JavaScript
 *
 * handles all the link generaton, copyin, and managment ui stuff
 * basically makes the admin page work
 *
 * @package Izzygld_Entry_Export
 */

(function($) {
    'use strict';

    // localized strings from php - gettin all the text we need
    var da_strings = window.izzygld_eee_admin_strings || {
        nonce: '',
        generating: 'Generating...',
        copied: 'Copied!',
        copy_failed: 'Copy failed',
        confirm_revoke: 'Are you sure you want to revoke this export link?',
        link_revoked: 'Link revoked',
        error: 'An error occurred'
    };

    // detectin if were on a per-form settings page or the global overview page
    var da_form_id = null;
    var is_form_page = false;

    /**
     * startin up the admin functionalty
     * this runs when the page loads
     */
    function start_it_up() {
        // checkin for form managment section (per-form settings page)
        var $da_mgmt = $('.izzygld-eee-form-management');
        if ($da_mgmt.length) {
            da_form_id = $da_mgmt.data('form-id');
            is_form_page = !!da_form_id;
        }
        // fallback: hidden input on form settings page
        if (!is_form_page) {
            var $da_hidden = $('#izzygld-eee-form-id[type="hidden"]');
            if ($da_hidden.length && $da_hidden.val()) {
                da_form_id = $da_hidden.val();
                is_form_page = true;
            }
        }

        hookup_da_events();

        // injectin "Select All" checkbox for the Exportable Fields setting
        stick_in_select_all();

        if (is_form_page) {
            // auto-loadin fields and links for this form
            try {
                load_da_form_fields(da_form_id);
            } catch (e) { /* wpApiSettings may not be availalbe */ }
            load_da_form_links(da_form_id);
        }
    }

    /**
     * stickin in a "Select All" checkbox above the Exportable Fields checkboxes
     * makes it easier to select everythin at once
     */
    function stick_in_select_all() {
        var $da_fields_container = $('#gform_setting_allowed_fields');
        var $da_meta_container = $('#gform_setting_include_meta');
        if (!$da_fields_container.length) return;

        var $da_wrapper = $da_fields_container.find('.gform-settings-input__container');
        if (!$da_wrapper.length) return;

        // collectin checkboxes from both Exportable Fields and Include Entry Metadata
        var $da_field_choices = $da_wrapper.find('input[type="checkbox"]');
        var $da_meta_choices = $da_meta_container.length ? $da_meta_container.find('.gform-settings-input__container input[type="checkbox"]') : $();
        var $da_all_choices = $da_field_choices.add($da_meta_choices);

        if (!$da_all_choices.length) return;

        var all_checked = $da_all_choices.length === $da_all_choices.filter(':checked').length;

        var $da_select_all = $(
            '<div class="izzygld-eee-select-all-wrap" style="margin-bottom:8px;padding-bottom:8px;border-bottom:1px solid #ddd;">' +
            '<label style="font-weight:600;cursor:pointer;">' +
            '<input type="checkbox" id="izzygld-eee-select-all-fields"' + (all_checked ? ' checked' : '') + '> ' +
            'Select All' +
            '</label>' +
            '</div>'
        );

        $da_wrapper.prepend($da_select_all);

        // togglin all checkboxes and their hidden inputs
        $('#izzygld-eee-select-all-fields').on('change', function() {
            var da_checked = $(this).prop('checked');
            $da_all_choices.each(function() {
                $(this).prop('checked', da_checked);
                var $da_hidden = $(this).prev('input[type="hidden"]');
                if ($da_hidden.length) {
                    $da_hidden.val(da_checked ? '1' : '0').trigger('change');
                }
            });
        });

        // updatin "Select All" state when individual checkboxes change
        $da_all_choices.on('change', function() {
            var all_now_checked = $da_all_choices.length === $da_all_choices.filter(':checked').length;
            $('#izzygld-eee-select-all-fields').prop('checked', all_now_checked);
        });
    }

    /**
     * hookin up all the event handlers
     * binds clicks and stuff to the right functions
     */
    function hookup_da_events() {
        // form selecton change (only on global page where its a <select>)
        $('#izzygld-eee-form-id').filter('select').on('change', function() {
            var da_selected_form = $(this).val();
            if (da_selected_form) {
                load_da_form_fields(da_selected_form);
            } else {
                $('#izzygld-eee-fields-container').html(
                    '<p class="description">Select a form to see available fields.</p>'
                );
            }
        });

        // generate link button click
        $('#izzygld-eee-generate-btn').on('click', function(e) {
            e.preventDefault();
            make_da_link();
        });

        // also supportin form submit for backward compat
        $('#izzygld-eee-generate-form').on('submit', function(e) {
            e.preventDefault();
            make_da_link();
        });

        // copy button
        $('#izzygld-eee-copy-btn').on('click', function() {
            copy_to_clipboard();
        });

        // copy individual credential fields
        $(document).on('click', '.izzygld-eee-copy-field', function() {
            var da_target_id = $(this).data('target');
            var da_text = $('#' + da_target_id).text();
            var $da_btn = $(this);
            var da_original_text = $da_btn.text();

            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(da_text).then(function() {
                    $da_btn.text(da_strings.copied);
                    setTimeout(function() { $da_btn.text(da_original_text); }, 2000);
                });
            } else {
                var $da_temp = $('<input>');
                $('body').append($da_temp);
                $da_temp.val(da_text).select();
                document.execCommand('copy');
                $da_temp.remove();
                $da_btn.text(da_strings.copied);
                setTimeout(function() { $da_btn.text(da_original_text); }, 2000);
            }
        });

        // revoke link (delegated)
        $(document).on('click', '.izzygld-eee-revoke-btn', function(e) {
            e.preventDefault();
            var da_token_id = $(this).data('token-id');
            kill_da_link(da_token_id, $(this));
        });

        // regenerate credentials button
        $(document).on('click', '#izzygld-eee-regenerate-creds', function() {
            if (!confirm('Regenerating will invalidate the current credentials. Any external clients using the old credentials will lose access.\n\nContinue?')) {
                return;
            }
            var da_selected_form = is_form_page ? da_form_id : ($('#izzygld-eee-form-id').val() || $('input[name="form_id"]').val());
            if (!da_selected_form) {
                alert('Could not determine form ID.');
                return;
            }
            var $da_btn = $(this);
            $da_btn.prop('disabled', true).text('Regenerating...');
            $.post(ajaxurl, {
                action: 'izzygld_eee_regenerate_creds',
                nonce: da_strings.nonce,
                form_id: da_selected_form
            }, function(da_response) {
                $da_btn.prop('disabled', false).text('Regenerate Credentials');
                if (da_response.success) {
                    $('#izzygld-eee-cred-username').text(da_response.data.username);
                    $('#izzygld-eee-cred-password').text(da_response.data.password);
                } else {
                    alert((da_response.data && da_response.data.message) || da_strings.error);
                }
            }).fail(function() {
                $da_btn.prop('disabled', false).text('Regenerate Credentials');
                alert(da_strings.error);
            });
        });

        // generate secret key button
        window.gfEEEGenerateKey = function() {
            var da_key = make_random_key(64);
            $('input[name="_gform_setting_secret_key"]').val(da_key);
        };
    }

    /**
     * loadin form fields for selecton
     * gets the fields from the api and shows em
     *
     * @param {number} da_selected_form form id
     */
    function load_da_form_fields(da_selected_form) {
        var $da_container = $('#izzygld-eee-fields-container');
        $da_container.html('<p class="description">Loading fields...</p>');

        $.ajax({
            url: wpApiSettings.root + 'izzygld-eee/v1/form-fields/' + da_selected_form,
            method: 'GET',
            beforeSend: function(xhr) {
                xhr.setRequestHeader('X-WP-Nonce', wpApiSettings.nonce);
            },
            success: function(da_response) {
                show_field_checkboxes(da_response, $da_container);
            },
            error: function(xhr) {
                var da_message = xhr.responseJSON && xhr.responseJSON.message
                    ? xhr.responseJSON.message
                    : da_strings.error;
                $da_container.html('<p class="error">' + da_message + '</p>');
            }
        });
    }

    /**
     * showin field selecton checkboxes
     * renders all the checkboxes for pickin fields
     *
     * @param {Object} da_response   api response
     * @param {jQuery} $da_container container element
     */
    function show_field_checkboxes(da_response, $da_container) {
        if (!da_response.export_enabled) {
            $da_container.html(
                '<p class="notice notice-warning" style="padding: 10px;">' +
                'External export is not enabled for this form. ' +
                '<a href="' + get_form_settings_link(da_response.form_id) + '">Enable it in form settings</a>.' +
                '</p>'
            );
            return;
        }

        if (!da_response.fields || da_response.fields.length === 0) {
            $da_container.html('<p class="description">No exportable fields configured for this form.</p>');
            return;
        }

        var da_html = '<fieldset class="izzygld-eee-field-selection">';
        da_html += '<legend class="screen-reader-text">Select fields to export</legend>';
        da_html += '<label class="izzygld-eee-select-all"><input type="checkbox" id="izzygld-eee-select-all"> <strong>Select All Allowed Fields</strong></label>';
        da_html += '<div class="izzygld-eee-fields-list">';

        da_response.fields.forEach(function(da_field) {
            var da_disabled = !da_field.is_allowed ? ' disabled' : '';
            var da_checked = da_field.is_allowed ? ' checked' : '';
            var da_classname = da_field.is_allowed ? 'allowed' : 'not-allowed';

            da_html += '<label class="izzygld-eee-field-item ' + da_classname + '">';
            da_html += '<input type="checkbox" name="fields[]" value="' + da_field.setting + '"' + da_checked + da_disabled + '> ';
            da_html += escape_da_html(da_field.label);
            if (!da_field.is_allowed) {
                da_html += ' <span class="izzygld-eee-not-allowed">(not enabled)</span>';
            }
            da_html += '</label>';
        });

        da_html += '</div>';
        da_html += '</fieldset>';

        $da_container.html(da_html);

        // bindin select all
        $('#izzygld-eee-select-all').on('change', function() {
            var da_checked = $(this).prop('checked');
            $('.izzygld-eee-fields-list input[type="checkbox"]:not(:disabled)').prop('checked', da_checked);
        });
    }

    /**
     * makin da export link
     * sends the ajax request to generate a new link
     */
    function make_da_link() {
        var $da_btn = $('#izzygld-eee-generate-btn');
        var $da_result = $('#izzygld-eee-result');

        // collectin selected fields from the managment section or form
        var da_fields = [];
        $('.izzygld-eee-form-management input[name="fields[]"]:checked, #izzygld-eee-generate-form input[name="fields[]"]:checked').each(function() {
            da_fields.push($(this).val());
        });

        var da_selected_form = is_form_page ? da_form_id : $('#izzygld-eee-form-id').val();

        var da_data = {
            action: 'izzygld_eee_generate_link',
            nonce: da_strings.nonce,
            form_id: da_selected_form,
            fields: da_fields,
            description: $('#izzygld-eee-description').val(),
            expiration: $('#izzygld-eee-expiration').val(),
            start_date: $('#izzygld-eee-start-date').val(),
            end_date: $('#izzygld-eee-end-date').val(),
            status: $('#izzygld-eee-status').val()
        };

        $da_btn.prop('disabled', true).text(da_strings.generating);
        $da_result.addClass('hidden');

        $.post(ajaxurl, da_data, function(da_response) {
            $da_btn.prop('disabled', false).text('Generate Export Link');

            if (da_response.success) {
                $('#izzygld-eee-url').val(da_response.data.url);

                // displayin client credentials (shown once only)
                $('#izzygld-eee-client-username').text(da_response.data.client_username);
                $('#izzygld-eee-client-password').text(da_response.data.client_password);

                var da_expiry_text = da_response.data.expires_at
                    ? 'Expires: ' + da_response.data.expires_at
                    : 'This link never expires';
                $('#izzygld-eee-expiry-info').text(da_expiry_text);

                $da_result.removeClass('hidden');

                // refreshin links list
                if (is_form_page) {
                    load_da_form_links(da_form_id);
                }
            } else {
                alert(da_response.data.message || da_strings.error);
            }
        }).fail(function() {
            $da_btn.prop('disabled', false).text('Generate Export Link');
            alert(da_strings.error);
        });
    }

    /**
     * copyin url to clipboard
     * uses the fancy new api or falls back to old way
     */
    function copy_to_clipboard() {
        var $da_input = $('#izzygld-eee-url');
        var $da_btn = $('#izzygld-eee-copy-btn');
        var da_original_text = $da_btn.text();

        $da_input.select();

        try {
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText($da_input.val()).then(function() {
                    $da_btn.text(da_strings.copied);
                    setTimeout(function() {
                        $da_btn.text(da_original_text);
                    }, 2000);
                }).catch(function() {
                    fallback_copy($da_input, $da_btn, da_original_text);
                });
            } else {
                fallback_copy($da_input, $da_btn, da_original_text);
            }
        } catch (e) {
            $da_btn.text(da_strings.copy_failed);
            setTimeout(function() {
                $da_btn.text(da_original_text);
            }, 2000);
        }
    }

    /**
     * fallback copy method for older browsers
     * uses the old execCommand way
     */
    function fallback_copy($da_input, $da_btn, da_original_text) {
        $da_input[0].select();
        var da_success = document.execCommand('copy');
        $da_btn.text(da_success ? da_strings.copied : da_strings.copy_failed);
        setTimeout(function() {
            $da_btn.text(da_original_text);
        }, 2000);
    }

    /**
     * loadin active export links for all forms
     * for the global overview page
     */
    function load_da_active_links() {
        var $da_tbody = $('#izzygld-eee-links-table tbody');
        if (!$da_tbody.length) return;

        $.ajax({
            url: wpApiSettings.root + 'izzygld-eee/v1/links',
            method: 'GET',
            beforeSend: function(xhr) {
                xhr.setRequestHeader('X-WP-Nonce', wpApiSettings.nonce);
            },
            success: function(da_response) {
                show_links_table(da_response.links, $da_tbody, true);
            },
            error: function() {
                $da_tbody.html('<tr><td colspan="7">Failed to load links.</td></tr>');
            }
        });
    }

    /**
     * loadin export links for a specific form
     * for the form settings page
     *
     * @param {number} da_selected_form form id
     */
    function load_da_form_links(da_selected_form) {
        var $da_tbody = $('#izzygld-eee-links-table tbody');
        if (!$da_tbody.length) return;

        $.post(ajaxurl, {
            action: 'izzygld_eee_get_links',
            nonce: da_strings.nonce,
            form_id: da_selected_form
        }, function(da_response) {
            if (da_response.success) {
                show_links_table(da_response.data.links, $da_tbody, false);
            } else {
                $da_tbody.html('<tr><td colspan="6">Failed to load links.</td></tr>');
            }
        }).fail(function() {
            $da_tbody.html('<tr><td colspan="6">Failed to load links.</td></tr>');
        });
    }

    /**
     * showin the links table
     * renders all the active links in the table
     *
     * @param {Array}   da_links     links array
     * @param {jQuery}  $da_tbody    table body element
     * @param {boolean} show_form    whether to show the form name column
     */
    function show_links_table(da_links, $da_tbody, show_form) {
        var da_col_count = show_form ? 7 : 6;

        if (!da_links || da_links.length === 0) {
            $da_tbody.html('<tr><td colspan="' + da_col_count + '">No active export links.</td></tr>');
            return;
        }

        var da_html = '';
        da_links.forEach(function(da_link) {
            da_html += '<tr data-token-id="' + escape_da_html(da_link.token_id) + '">';
            if (show_form) {
                da_html += '<td>' + escape_da_html(da_link.form_title) + '</td>';
            }
            da_html += '<td>' + escape_da_html(da_link.description || '—') + '</td>';
            da_html += '<td><code>' + escape_da_html(da_link.client_username || '—') + '</code></td>';

            // use formatted dates if availalbe, fall back to raw values
            var da_created = da_link.created_at_formatted || da_link.created_at || '—';
            var da_expires = da_link.time_remaining || da_link.expires_at || 'Never';
            var da_downloads = da_link.downloads_display ||
                (da_link.download_count + ' / ' + (da_link.max_downloads > 0 ? da_link.max_downloads : '∞'));

            da_html += '<td>' + escape_da_html(da_created) + '</td>';
            da_html += '<td>' + escape_da_html(da_expires) + '</td>';
            da_html += '<td>' + escape_da_html(da_downloads) + '</td>';
            da_html += '<td>';
            da_html += '<button type="button" class="button button-small izzygld-eee-revoke-btn" data-token-id="' + escape_da_html(da_link.token_id) + '">Revoke</button>';
            da_html += '</td>';
            da_html += '</tr>';
        });

        $da_tbody.html(da_html);
    }

    /**
     * killin da export link
     * revokes a link so it cant be used no more
     *
     * @param {string} da_token_id token id
     * @param {jQuery} $da_btn     button element
     */
    function kill_da_link(da_token_id, $da_btn) {
        if (!confirm(da_strings.confirm_revoke)) {
            return;
        }

        var da_original_text = $da_btn.text();
        $da_btn.prop('disabled', true).text('...');

        $.post(ajaxurl, {
            action: 'izzygld_eee_revoke_link',
            nonce: da_strings.nonce,
            token_id: da_token_id
        }, function(da_response) {
            if (da_response.success) {
                $da_btn.closest('tr').fadeOut(function() {
                    $(this).remove();
                    if ($('#izzygld-eee-links-table tbody tr').length === 0) {
                        var da_col_count = is_form_page ? 6 : 7;
                        $('#izzygld-eee-links-table tbody').html(
                            '<tr><td colspan="' + da_col_count + '">No active export links.</td></tr>'
                        );
                    }
                });
            } else {
                $da_btn.prop('disabled', false).text(da_original_text);
                alert(da_response.data.message || da_strings.error);
            }
        }).fail(function() {
            $da_btn.prop('disabled', false).text(da_original_text);
            alert(da_strings.error);
        });
    }

    /**
     * gettin form settings url
     * builds the link to the form settings page
     *
     * @param {number} da_selected_form form id
     * @return {string} url
     */
    function get_form_settings_link(da_selected_form) {
        return 'admin.php?page=gf_edit_forms&view=settings&subview=izzygld-entry-export-for-gravity-forms&id=' + da_selected_form;
    }

    /**
     * makin a random key
     * generates a random string for secret keys
     *
     * @param {number} da_length key length
     * @return {string} random key
     */
    function make_random_key(da_length) {
        var da_chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789!@#$%^&*';
        var da_key = '';
        var da_array = new Uint32Array(da_length);
        window.crypto.getRandomValues(da_array);
        for (var i = 0; i < da_length; i++) {
            da_key += da_chars[da_array[i] % da_chars.length];
        }
        return da_key;
    }

    /**
     * makin a random hex string
     * generates random bytes in hex format
     *
     * @param {number} da_bytes number of bytes (output is 2x chars)
     * @return {string} hex string
     */
    function make_random_hex(da_bytes) {
        var da_array = new Uint8Array(da_bytes);
        window.crypto.getRandomValues(da_array);
        return Array.from(da_array, function(b) {
            return ('0' + b.toString(16)).slice(-2);
        }).join('');
    }

    /**
     * escapin html entities
     * prevents xss by escapin special chars
     *
     * @param {string} da_str string to escape
     * @return {string} escaped string
     */
    function escape_da_html(da_str) {
        if (da_str === null || da_str === undefined) {
            return '';
        }
        var da_div = document.createElement('div');
        da_div.textContent = da_str;
        return da_div.innerHTML;
    }

    // startin everythin up when document is ready
    $(document).ready(start_it_up);

})(jQuery);
