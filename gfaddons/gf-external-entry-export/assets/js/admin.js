/**
 * GF External Entry Export - Admin JavaScript
 *
 * Handles link generation, copying, and management UI.
 *
 * @package GF_External_Entry_Export
 */

(function($) {
    'use strict';

    // Localized strings available via gf_eee_admin_strings
    var strings = window.gf_eee_admin_strings || {
        nonce: '',
        generating: 'Generating...',
        copied: 'Copied!',
        copy_failed: 'Copy failed',
        confirm_revoke: 'Are you sure you want to revoke this export link?',
        link_revoked: 'Link revoked',
        error: 'An error occurred'
    };

    // Detect if we're on a per-form settings page or the global overview page.
    var formScopedId = null;
    var isFormScoped = false;

    /**
     * Initialize admin functionality.
     */
    function init() {
        // Check for form management section (per-form settings page).
        var $mgmt = $('.gf-eee-form-management');
        if ($mgmt.length) {
            formScopedId = $mgmt.data('form-id');
            isFormScoped = !!formScopedId;
        }
        // Fallback: hidden input on form settings page.
        if (!isFormScoped) {
            var $hidden = $('#gf-eee-form-id[type="hidden"]');
            if ($hidden.length && $hidden.val()) {
                formScopedId = $hidden.val();
                isFormScoped = true;
            }
        }

        bindEvents();

        // Inject "Select All" checkbox for the Exportable Fields setting (runs first, no dependencies).
        injectSelectAllForExportableFields();

        if (isFormScoped) {
            // Auto-load fields and links for this form (uses REST API / AJAX).
            try {
                loadFormFields(formScopedId);
            } catch (e) { /* wpApiSettings may not be available */ }
            loadFormLinks(formScopedId);
        }
    }

    /**
     * Inject a "Select All" checkbox above the Exportable Fields checkboxes.
     */
    function injectSelectAllForExportableFields() {
        var $fieldsContainer = $('#gform_setting_allowed_fields');
        var $metaContainer = $('#gform_setting_include_meta');
        if (!$fieldsContainer.length) return;

        var $wrapper = $fieldsContainer.find('.gform-settings-input__container');
        if (!$wrapper.length) return;

        // Collect checkboxes from both Exportable Fields and Include Entry Metadata
        var $fieldChoices = $wrapper.find('input[type="checkbox"]');
        var $metaChoices = $metaContainer.length ? $metaContainer.find('.gform-settings-input__container input[type="checkbox"]') : $();
        var $allChoices = $fieldChoices.add($metaChoices);

        if (!$allChoices.length) return;

        var allChecked = $allChoices.length === $allChoices.filter(':checked').length;

        var $selectAll = $(
            '<div class="gf-eee-select-all-wrap" style="margin-bottom:8px;padding-bottom:8px;border-bottom:1px solid #ddd;">' +
            '<label style="font-weight:600;cursor:pointer;">' +
            '<input type="checkbox" id="gf-eee-select-all-fields"' + (allChecked ? ' checked' : '') + '> ' +
            'Select All' +
            '</label>' +
            '</div>'
        );

        $wrapper.prepend($selectAll);

        // Toggle all checkboxes and their hidden inputs
        $('#gf-eee-select-all-fields').on('change', function() {
            var checked = $(this).prop('checked');
            $allChoices.each(function() {
                $(this).prop('checked', checked);
                var $hidden = $(this).prev('input[type="hidden"]');
                if ($hidden.length) {
                    $hidden.val(checked ? '1' : '0').trigger('change');
                }
            });
        });

        // Update "Select All" state when individual checkboxes change
        $allChoices.on('change', function() {
            var allNowChecked = $allChoices.length === $allChoices.filter(':checked').length;
            $('#gf-eee-select-all-fields').prop('checked', allNowChecked);
        });
    }

    /**
     * Bind event handlers.
     */
    function bindEvents() {
        // Form selection change (only on global page where it's a <select>)
        $('#gf-eee-form-id').filter('select').on('change', function() {
            var formId = $(this).val();
            if (formId) {
                loadFormFields(formId);
            } else {
                $('#gf-eee-fields-container').html(
                    '<p class="description">Select a form to see available fields.</p>'
                );
            }
        });

        // Generate link — button click (per-form page uses <button>, not form submit)
        $('#gf-eee-generate-btn').on('click', function(e) {
            e.preventDefault();
            generateLink();
        });

        // Also support form submit for backward compat
        $('#gf-eee-generate-form').on('submit', function(e) {
            e.preventDefault();
            generateLink();
        });

        // Copy button
        $('#gf-eee-copy-btn').on('click', function() {
            copyToClipboard();
        });

        // Copy individual credential fields
        $(document).on('click', '.gf-eee-copy-field', function() {
            var targetId = $(this).data('target');
            var text = $('#' + targetId).text();
            var $btn = $(this);
            var originalText = $btn.text();

            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(text).then(function() {
                    $btn.text(strings.copied);
                    setTimeout(function() { $btn.text(originalText); }, 2000);
                });
            } else {
                var $temp = $('<input>');
                $('body').append($temp);
                $temp.val(text).select();
                document.execCommand('copy');
                $temp.remove();
                $btn.text(strings.copied);
                setTimeout(function() { $btn.text(originalText); }, 2000);
            }
        });

        // Revoke link (delegated)
        $(document).on('click', '.gf-eee-revoke-btn', function(e) {
            e.preventDefault();
            var tokenId = $(this).data('token-id');
            revokeLink(tokenId, $(this));
        });

        // Regenerate credentials button — server-side so password is hashed securely
        $(document).on('click', '#gf-eee-regenerate-creds', function() {
            if (!confirm('Regenerating will invalidate the current credentials. Any external clients using the old credentials will lose access.\n\nContinue?')) {
                return;
            }
            var formId = isFormScoped ? formScopedId : ($('#gf-eee-form-id').val() || $('input[name="form_id"]').val());
            if (!formId) {
                alert('Could not determine form ID.');
                return;
            }
            var $btn = $(this);
            $btn.prop('disabled', true).text('Regenerating...');
            $.post(ajaxurl, {
                action: 'gf_eee_regenerate_creds',
                nonce: strings.nonce,
                form_id: formId
            }, function(response) {
                $btn.prop('disabled', false).text('Regenerate Credentials');
                if (response.success) {
                    $('#gf-eee-cred-username').text(response.data.username);
                    $('#gf-eee-cred-password').text(response.data.password);
                } else {
                    alert((response.data && response.data.message) || strings.error);
                }
            }).fail(function() {
                $btn.prop('disabled', false).text('Regenerate Credentials');
                alert(strings.error);
            });
        });

        // Generate secret key button
        window.gfEEEGenerateKey = function() {
            var key = generateRandomKey(64);
            $('input[name="_gform_setting_secret_key"]').val(key);
        };
    }

    /**
     * Load form fields for selection.
     *
     * @param {number} formId Form ID.
     */
    function loadFormFields(formId) {
        var $container = $('#gf-eee-fields-container');
        $container.html('<p class="description">Loading fields...</p>');

        $.ajax({
            url: wpApiSettings.root + 'gf-eee/v1/form-fields/' + formId,
            method: 'GET',
            beforeSend: function(xhr) {
                xhr.setRequestHeader('X-WP-Nonce', wpApiSettings.nonce);
            },
            success: function(response) {
                renderFieldSelection(response, $container);
            },
            error: function(xhr) {
                var message = xhr.responseJSON && xhr.responseJSON.message
                    ? xhr.responseJSON.message
                    : strings.error;
                $container.html('<p class="error">' + message + '</p>');
            }
        });
    }

    /**
     * Render field selection checkboxes.
     *
     * @param {Object} response   API response.
     * @param {jQuery} $container Container element.
     */
    function renderFieldSelection(response, $container) {
        if (!response.export_enabled) {
            $container.html(
                '<p class="notice notice-warning" style="padding: 10px;">' +
                'External export is not enabled for this form. ' +
                '<a href="' + getFormSettingsUrl(response.form_id) + '">Enable it in form settings</a>.' +
                '</p>'
            );
            return;
        }

        if (!response.fields || response.fields.length === 0) {
            $container.html('<p class="description">No exportable fields configured for this form.</p>');
            return;
        }

        var html = '<fieldset class="gf-eee-field-selection">';
        html += '<legend class="screen-reader-text">Select fields to export</legend>';
        html += '<label class="gf-eee-select-all"><input type="checkbox" id="gf-eee-select-all"> <strong>Select All Allowed Fields</strong></label>';
        html += '<div class="gf-eee-fields-list">';

        response.fields.forEach(function(field) {
            var disabled = !field.is_allowed ? ' disabled' : '';
            var checked = field.is_allowed ? ' checked' : '';
            var className = field.is_allowed ? 'allowed' : 'not-allowed';

            html += '<label class="gf-eee-field-item ' + className + '">';
            html += '<input type="checkbox" name="fields[]" value="' + field.setting + '"' + checked + disabled + '> ';
            html += escapeHtml(field.label);
            if (!field.is_allowed) {
                html += ' <span class="gf-eee-not-allowed">(not enabled)</span>';
            }
            html += '</label>';
        });

        html += '</div>';
        html += '</fieldset>';

        $container.html(html);

        // Bind select all
        $('#gf-eee-select-all').on('change', function() {
            var checked = $(this).prop('checked');
            $('.gf-eee-fields-list input[type="checkbox"]:not(:disabled)').prop('checked', checked);
        });
    }

    /**
     * Generate export link.
     */
    function generateLink() {
        var $btn = $('#gf-eee-generate-btn');
        var $result = $('#gf-eee-result');

        // Collect selected fields from the management section or form
        var fields = [];
        $('.gf-eee-form-management input[name="fields[]"]:checked, #gf-eee-generate-form input[name="fields[]"]:checked').each(function() {
            fields.push($(this).val());
        });

        var formId = isFormScoped ? formScopedId : $('#gf-eee-form-id').val();

        var data = {
            action: 'gf_eee_generate_link',
            nonce: strings.nonce,
            form_id: formId,
            fields: fields,
            description: $('#gf-eee-description').val(),
            expiration: $('#gf-eee-expiration').val(),
            start_date: $('#gf-eee-start-date').val(),
            end_date: $('#gf-eee-end-date').val(),
            status: $('#gf-eee-status').val()
        };

        $btn.prop('disabled', true).text(strings.generating);
        $result.addClass('hidden');

        $.post(ajaxurl, data, function(response) {
            $btn.prop('disabled', false).text('Generate Export Link');

            if (response.success) {
                $('#gf-eee-url').val(response.data.url);

                // Display client credentials (shown once only)
                $('#gf-eee-client-username').text(response.data.client_username);
                $('#gf-eee-client-password').text(response.data.client_password);

                var expiryText = response.data.expires_at
                    ? 'Expires: ' + response.data.expires_at
                    : 'This link never expires';
                $('#gf-eee-expiry-info').text(expiryText);

                $result.removeClass('hidden');

                // Refresh links list
                if (isFormScoped) {
                    loadFormLinks(formScopedId);
                }
            } else {
                alert(response.data.message || strings.error);
            }
        }).fail(function() {
            $btn.prop('disabled', false).text('Generate Export Link');
            alert(strings.error);
        });
    }

    /**
     * Copy URL to clipboard.
     */
    function copyToClipboard() {
        var $input = $('#gf-eee-url');
        var $btn = $('#gf-eee-copy-btn');
        var originalText = $btn.text();

        $input.select();

        try {
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText($input.val()).then(function() {
                    $btn.text(strings.copied);
                    setTimeout(function() {
                        $btn.text(originalText);
                    }, 2000);
                }).catch(function() {
                    fallbackCopy($input, $btn, originalText);
                });
            } else {
                fallbackCopy($input, $btn, originalText);
            }
        } catch (e) {
            $btn.text(strings.copy_failed);
            setTimeout(function() {
                $btn.text(originalText);
            }, 2000);
        }
    }

    /**
     * Fallback copy method.
     */
    function fallbackCopy($input, $btn, originalText) {
        $input[0].select();
        var success = document.execCommand('copy');
        $btn.text(success ? strings.copied : strings.copy_failed);
        setTimeout(function() {
            $btn.text(originalText);
        }, 2000);
    }

    /**
     * Load active export links (global — all forms).
     */
    function loadActiveLinks() {
        var $tbody = $('#gf-eee-links-table tbody');
        if (!$tbody.length) return;

        $.ajax({
            url: wpApiSettings.root + 'gf-eee/v1/links',
            method: 'GET',
            beforeSend: function(xhr) {
                xhr.setRequestHeader('X-WP-Nonce', wpApiSettings.nonce);
            },
            success: function(response) {
                renderLinksTable(response.links, $tbody, true);
            },
            error: function() {
                $tbody.html('<tr><td colspan="7">Failed to load links.</td></tr>');
            }
        });
    }

    /**
     * Load export links for a specific form (form settings page).
     *
     * @param {number} formId Form ID.
     */
    function loadFormLinks(formId) {
        var $tbody = $('#gf-eee-links-table tbody');
        if (!$tbody.length) return;

        $.post(ajaxurl, {
            action: 'gf_eee_get_links',
            nonce: strings.nonce,
            form_id: formId
        }, function(response) {
            if (response.success) {
                renderLinksTable(response.data.links, $tbody, false);
            } else {
                $tbody.html('<tr><td colspan="6">Failed to load links.</td></tr>');
            }
        }).fail(function() {
            $tbody.html('<tr><td colspan="6">Failed to load links.</td></tr>');
        });
    }

    /**
     * Render links table.
     *
     * @param {Array}   links       Links array.
     * @param {jQuery}  $tbody      Table body element.
     * @param {boolean} showForm    Whether to show the form name column.
     */
    function renderLinksTable(links, $tbody, showForm) {
        var colCount = showForm ? 7 : 6;

        if (!links || links.length === 0) {
            $tbody.html('<tr><td colspan="' + colCount + '">No active export links.</td></tr>');
            return;
        }

        var html = '';
        links.forEach(function(link) {
            html += '<tr data-token-id="' + escapeHtml(link.token_id) + '">';
            if (showForm) {
                html += '<td>' + escapeHtml(link.form_title) + '</td>';
            }
            html += '<td>' + escapeHtml(link.description || '—') + '</td>';
            html += '<td><code>' + escapeHtml(link.client_username || '—') + '</code></td>';

            // Use formatted dates if available, fall back to raw values
            var created = link.created_at_formatted || link.created_at || '—';
            var expires = link.time_remaining || link.expires_at || 'Never';
            var downloads = link.downloads_display ||
                (link.download_count + ' / ' + (link.max_downloads > 0 ? link.max_downloads : '∞'));

            html += '<td>' + escapeHtml(created) + '</td>';
            html += '<td>' + escapeHtml(expires) + '</td>';
            html += '<td>' + escapeHtml(downloads) + '</td>';
            html += '<td>';
            html += '<button type="button" class="button button-small gf-eee-revoke-btn" data-token-id="' + escapeHtml(link.token_id) + '">Revoke</button>';
            html += '</td>';
            html += '</tr>';
        });

        $tbody.html(html);
    }

    /**
     * Revoke an export link.
     *
     * @param {string} tokenId Token ID.
     * @param {jQuery} $btn    Button element.
     */
    function revokeLink(tokenId, $btn) {
        if (!confirm(strings.confirm_revoke)) {
            return;
        }

        var originalText = $btn.text();
        $btn.prop('disabled', true).text('...');

        $.post(ajaxurl, {
            action: 'gf_eee_revoke_link',
            nonce: strings.nonce,
            token_id: tokenId
        }, function(response) {
            if (response.success) {
                $btn.closest('tr').fadeOut(function() {
                    $(this).remove();
                    if ($('#gf-eee-links-table tbody tr').length === 0) {
                        var colCount = isFormScoped ? 6 : 7;
                        $('#gf-eee-links-table tbody').html(
                            '<tr><td colspan="' + colCount + '">No active export links.</td></tr>'
                        );
                    }
                });
            } else {
                $btn.prop('disabled', false).text(originalText);
                alert(response.data.message || strings.error);
            }
        }).fail(function() {
            $btn.prop('disabled', false).text(originalText);
            alert(strings.error);
        });
    }

    /**
     * Get form settings URL.
     *
     * @param {number} formId Form ID.
     * @return {string} URL.
     */
    function getFormSettingsUrl(formId) {
        return 'admin.php?page=gf_edit_forms&view=settings&subview=gf-external-entry-export&id=' + formId;
    }

    /**
     * Generate random key.
     *
     * @param {number} length Key length.
     * @return {string} Random key.
     */
    function generateRandomKey(length) {
        var chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789!@#$%^&*';
        var key = '';
        var array = new Uint32Array(length);
        window.crypto.getRandomValues(array);
        for (var i = 0; i < length; i++) {
            key += chars[array[i] % chars.length];
        }
        return key;
    }

    /**
     * Generate random hex string.
     *
     * @param {number} bytes Number of bytes (output is 2x chars).
     * @return {string} Hex string.
     */
    function generateRandomHex(bytes) {
        var array = new Uint8Array(bytes);
        window.crypto.getRandomValues(array);
        return Array.from(array, function(b) {
            return ('0' + b.toString(16)).slice(-2);
        }).join('');
    }

    /**
     * Escape HTML entities.
     *
     * @param {string} str String to escape.
     * @return {string} Escaped string.
     */
    function escapeHtml(str) {
        if (str === null || str === undefined) {
            return '';
        }
        var div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }

    // Initialize on document ready
    $(document).ready(init);

})(jQuery);
