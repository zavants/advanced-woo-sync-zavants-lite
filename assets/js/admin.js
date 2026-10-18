jQuery(document).ready(function ($) {
    'use strict';

    // Toggle store role options
    function toggleStoreRoleOptions(role) {
        var centralOptions = $('#swos-central-options');
        var connectionTitle = $('#swos-connection-title');
        var remoteLabel = $('#swos-remote-label');
        var urlDescription = $('#swos-url-description');
        var testDescription = $('#swos-test-description');
        var centralOnlyRows = $('.swos-central-only');

        centralOptions.show();

        // Show all central-only rows by default
        centralOnlyRows.show();

        if (role === 'central') {
            connectionTitle.text('Remote Store Configuration');
            remoteLabel.text('Remote Store URL');
            urlDescription.text('Full URL of the remote store that will connect to this central store');
            testDescription.text('Verify the connection to the remote store:');
        } else {
            // REMOTE STORE ROLE
            connectionTitle.text('Central Store Configuration');
            remoteLabel.text('Central Store URL');
            urlDescription.text('Full URL of the central store where orders will be sent');
            testDescription.text('Verify the connection to the central store:');

            // Hide settings only relevant for RECEIVED orders (Central Store receives, not Remote)
            centralOnlyRows.hide();
        }
    }

    // Handle store role change
    $('#swos-store-role').on('change', function () {
        toggleStoreRoleOptions($(this).val());
    });

    // Initialize store role on page load
    toggleStoreRoleOptions($('#swos-store-role').val());

    // Toggle customer fixed ID field
    function toggleCustomerIdField() {
        var value = $('#swos-customer-handling').val();
        var wrapper = $('#swos-fixed-id-wrapper');
        var input = $('#swos-customer-fixed-id');

        if (value === 'fixed_id') {
            wrapper.slideDown();
            input.prop('disabled', false);
        } else {
            wrapper.slideUp();
            input.prop('disabled', true);
        }
    }

    // Handle customer handling change
    $('#swos-customer-handling').on('change', function () {
        toggleCustomerIdField();
    });

    // Initialize customer handling on page load
    toggleCustomerIdField();

    // Toggle price adjustment options
    function togglePriceAdjustment() {
        var enabled = $('#swos-price-adj-enabled').is(':checked');
        var optionsDiv = $('#swos-price-adj-options');

        if (enabled) {
            optionsDiv.slideDown();
        } else {
            optionsDiv.slideUp();
        }
    }

    // Update price adjustment symbol
    function updatePriceAdjustmentSymbol() {
        var type = $('#swos-price-adj-type').val();
        var symbol = $('#swos-price-adj-symbol');

        if (type === 'percentage') {
            symbol.text('%');
        } else {
            // Get currency symbol from WooCommerce (it's already in the span)
            // We'll just update to the default currency symbol if needed
            var currencySymbol = symbol.data('currency') || '$';
            symbol.text(currencySymbol);
        }
    }

    // Handle price adjustment checkbox
    $('#swos-price-adj-enabled').on('change', function () {
        togglePriceAdjustment();
    });

    // Handle price adjustment type change
    $('#swos-price-adj-type').on('change', function () {
        updatePriceAdjustmentSymbol();
    });

    // Toggle Order Sending Options
    function toggleOrderSendingOptions() {
        var isEnabled = $('#swos-order-sync-enabled').is(':checked');
        var sendingOptions = $('.swos-sending-options');
        var inputs = sendingOptions.find('select, input');

        if (isEnabled) {
            sendingOptions.css('opacity', '1');
            inputs.prop('disabled', false);
        } else {
            sendingOptions.css('opacity', '0.5');
            inputs.prop('disabled', true);
        }
    }

    // Handle Enable Order Sync change
    $('#swos-order-sync-enabled').on('change', function () {
        toggleOrderSendingOptions();
    });

    // Initialize Order Sending Options on load
    toggleOrderSendingOptions();

    // Initialize price adjustment on page load
    togglePriceAdjustment();

    // Toggle price adjustment fields based on coupon selection
    function togglePriceAdjustmentFields() {
        var selectedCoupon = $('#swos-price-adj-coupon').val();
        var typeField = $('#swos-price-adj-type');
        var valueField = $('#swos-price-adj-value');
        var isAutomaticCoupon = (selectedCoupon === '' || !selectedCoupon);

        if (isAutomaticCoupon) {
            // Automatic coupon: enable type and value fields
            typeField.prop('disabled', false);
            valueField.prop('disabled', false);
            typeField.closest('tr').removeClass('swos-opacity-50');
            valueField.closest('tr').removeClass('swos-opacity-50');
        } else {
            // Existing coupon selected: disable type and value fields
            typeField.prop('disabled', true);
            valueField.prop('disabled', true);
            typeField.closest('tr').addClass('swos-opacity-50');
            valueField.closest('tr').addClass('swos-opacity-50');
        }
    }

    // Handle coupon selection change
    $('#swos-price-adj-coupon').on('change', function () {
        togglePriceAdjustmentFields();
    });

    // Initialize price adjustment fields state on page load
    togglePriceAdjustmentFields();

    // Toggle stock sync options
    function toggleStockSyncOptions() {
        var enabled = $('#swos-stock-sync-enabled').is(':checked');
        var optionsRows = $('.swos-stock-option-row'); // Updated selector for rows

        if (enabled) {
            optionsRows.show().removeClass('swos-hidden'); // Remove hidden class
        } else {
            optionsRows.hide().addClass('swos-hidden'); // Add hidden class
        }
    }

    // Toggle stock sync value input based on type
    function toggleStockSyncValueInput() {
        var type = $('#swos-stock-sync-type').val();
        var valueRow = $('#swos-stock-sync-value-row');

        // Hide all inputs first
        $('#swos-stock-sync-sku-input').hide().addClass('swos-hidden');
        $('#swos-stock-sync-tag-input').hide().addClass('swos-hidden');
        $('#swos-stock-sync-category-input').hide().addClass('swos-hidden');

        // Show the relevant input
        if (type === 'sku') {
            valueRow.show();
            $('#swos-stock-sync-sku-input').slideDown().removeClass('swos-hidden');
        } else if (type === 'tag') {
            valueRow.show();
            $('#swos-stock-sync-tag-input').slideDown().removeClass('swos-hidden');
        } else if (type === 'category') {
            valueRow.show();
            $('#swos-stock-sync-category-input').slideDown().removeClass('swos-hidden');
        } else if (type === 'all') {
            // No value input needed for 'all' type
            valueRow.hide();
        }
    }

    // Handle stock sync checkbox
    $('#swos-stock-sync-enabled').on('change', function () {
        toggleStockSyncOptions();
    });

    // Handle stock sync type change
    $('#swos-stock-sync-type').on('change', function () {
        toggleStockSyncValueInput();
    });

    // Initialize stock sync on page load
    toggleStockSyncOptions();
    toggleStockSyncValueInput();

    // Test connection button
    $('#swos-test-connection-btn').on('click', function (e) {
        e.preventDefault();

        var button = $(this);
        var resultsDiv = $('#swos-test-results');

        // Disable button and show loading
        button.prop('disabled', true).text('Testing...');
        resultsDiv.removeClass('success error').html('');

        // Make AJAX request
        $.ajax({
            url: swosAdmin.ajaxurl,
            type: 'POST',
            data: {
                action: 'swos_test_connection',
                nonce: swosAdmin.nonce,
                url: $('input[name="swos_settings[remote_store_url]"]').val(),
                key: $('input[name="swos_settings[remote_store_consumer_key]"]').val(),
                secret: $('input[name="swos_settings[remote_store_consumer_secret]"]').val()
            },
            success: function (response) {
                if (response.success) {
                    resultsDiv.addClass('success').html('<strong>✓</strong> ' + response.data.message);
                } else {
                    resultsDiv.addClass('error').html('<strong>✗</strong> ' + response.data.message);
                }
            },
            error: function () {
                resultsDiv.addClass('error').html('<strong>✗</strong> Error testing connection.');
            },
            complete: function () {
                // Re-enable button
                button.prop('disabled', false).text('Test Connection');
            }
        });
    });

    // Test log button
    var testLogButton = $('#swos-test-log');

    if (testLogButton.length > 0) {
        testLogButton.on('click', function (e) {
            e.preventDefault();

            var button = $(this);
            var messageSpan = $('#swos-clear-logs-message');

            // Disable button and show loading
            button.prop('disabled', true).text('Generating...');
            messageSpan.removeClass('success error').html('');

            // Make AJAX request
            $.ajax({
                url: swosAdmin.ajaxurl,
                type: 'POST',
                data: {
                    action: 'swos_test_log',
                    nonce: swosAdmin.testLogNonce
                },
                success: function (response) {
                    if (response.success) {
                        messageSpan.addClass('success swos-text-success').html(response.data.message);
                        // Reload page after 1.5 seconds to show new logs
                        setTimeout(function () {
                            location.reload();
                        }, 1500);
                    } else {
                        messageSpan.addClass('error swos-text-error').html('✗ ' + response.data.message);
                    }
                },
                error: function (jqXHR, textStatus, errorThrown) {
                    console.error('SWOS: Test log AJAX error:', textStatus, errorThrown);
                    console.error('SWOS: Response:', jqXHR.responseText);
                    messageSpan.addClass('error swos-text-error').html('✗ Error generating test logs.');
                },
                complete: function () {
                    // Re-enable button
                    button.prop('disabled', false).text('🧪 Generate Test Log');
                }
            });
        });
    }

    // Clear logs button
    var clearLogsButton = $('.swos-clear-logs-btn');

    if (clearLogsButton.length > 0) {
        $('.swos-clear-logs-btn').on('click', function (e) {
            e.preventDefault();

            if (!confirm(swosAdmin.clear_logs_confirm || 'Are you sure you want to delete all logs? This action cannot be undone.')) {
                return;
            }

            var button = $(this);
            var logType = button.data('type');
            var messageSpan = $('#swos-clear-logs-message');

            // Disable button and show loading
            button.prop('disabled', true).text('Deleting...');
            messageSpan.removeClass('success error').html('');

            // Make AJAX request
            $.ajax({
                url: swosAdmin.ajaxurl,
                type: 'POST',
                data: {
                    action: 'swos_clear_logs',
                    log_type: logType,
                    nonce: swosAdmin.clearLogsNonce
                },
                success: function (response) {
                    if (response.success) {
                        messageSpan.addClass('success swos-text-success').html('✓ ' + response.data.message);
                        // Reload page after 1 second to show empty logs
                        setTimeout(function () {
                            location.reload();
                        }, 1000);
                    } else {
                        messageSpan.addClass('error swos-text-error').html('✗ ' + response.data.message);
                    }
                },
                error: function (jqXHR, textStatus, errorThrown) {
                    console.error('SWOS: AJAX error:', textStatus, errorThrown);
                    console.error('SWOS: Response:', jqXHR.responseText);
                    messageSpan.addClass('error swos-text-error').html('✗ Error deleting logs.');
                },
                complete: function () {
                    // Re-enable button
                    button.prop('disabled', false).text('Clear all logs');
                }
            });
        });
    }

    // Revoke credentials button
    $('#swos-revoke-credentials-btn').on('click', function (e) {
        e.preventDefault();

        if (!confirm(swosAdmin.revoke_confirm)) {
            return;
        }

        var button = $(this);
        button.prop('disabled', true).text('Revoking...');

        $.ajax({
            url: swosAdmin.ajaxurl,
            type: 'POST',
            data: {
                action: 'swos_revoke_credentials',
                nonce: swosAdmin.revokeNonce
            },
            success: function (response) {
                if (response.success) {
                    alert(response.data.message);
                    location.reload();
                } else {
                    alert('Error: ' + response.data.message);
                }
            },
            error: function () {
                alert('Error revoking credentials.');
            },
            complete: function () {
                button.prop('disabled', false).text('Revoke Credentials');
            }
        });
    });

    // Refresh remote config button
    $('#swos-refresh-remote-config-btn').on('click', function (e) {
        e.preventDefault();

        var button = $(this);
        var resultsDiv = $('#swos-test-results');

        button.prop('disabled', true).text('Refreshing...');
        resultsDiv.removeClass('success error').html('');

        $.ajax({
            url: swosAdmin.ajaxurl,
            type: 'POST',
            data: {
                action: 'swos_refresh_remote_config',
                nonce: swosAdmin.refreshConfigNonce
            },
            success: function (response) {
                if (response.success) {
                    resultsDiv.addClass('success').html('<strong>✓</strong> ' + response.data.message);
                    // Reload to show updated config
                    setTimeout(function () {
                        location.reload();
                    }, 1500);
                } else {
                    resultsDiv.addClass('error').html('<strong>✗</strong> ' + response.data.message);
                }
            },
            error: function () {
                resultsDiv.addClass('error').html('<strong>✗</strong> Error refreshing config.');
            },
            complete: function () {
                button.prop('disabled', false).text('Refresh Remote Config');
            }
        });
    });
});
