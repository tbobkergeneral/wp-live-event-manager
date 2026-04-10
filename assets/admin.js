jQuery(document).ready(function($) {
    // Stream selector for event meta box
    $('#lem_stream_selector').on('change', function() {
        var $option = $(this).find('option:selected');
        var selectedValue = $(this).val();
        
        // Don't do anything if "Select a stream" option is chosen
        if (!selectedValue || selectedValue === '') {
            return;
        }
        
        // Use attr() instead of data() to get the raw HTML5 data attribute value
        var playbackId = $option.attr('data-playback-id');
        var streamId = $option.attr('data-stream-id');
        var provider = $option.attr('data-provider') || 'mux';
        
        console.log('Stream selected:', { playbackId: playbackId, streamId: streamId, provider: provider, selectedValue: selectedValue });
        
        // Always populate live stream ID if available
        if (streamId && streamId !== '') {
            $('#lem_live_stream_id').val(streamId);
            console.log('Live Stream ID set to:', streamId);
        } else if (selectedValue) {
            // Fallback: use the selected value as stream ID
            $('#lem_live_stream_id').val(selectedValue);
            console.log('Live Stream ID set to selected value:', selectedValue);
        }
        
        // Set provider
        $('#lem_stream_provider').val(provider);
        $('#lem-provider-display').text(provider.toUpperCase());
        console.log('Stream Provider set to:', provider);
        
        // Update labels based on provider
        updateProviderLabels(provider);
        
        // Populate playback ID if available and not 'N/A'
        if (playbackId && playbackId !== 'N/A' && playbackId !== '') {
            $('#lem_playback_id').val(playbackId);
            console.log('Playback ID set to:', playbackId);
        } else {
            // If playback ID is not in the dropdown, try to fetch it from the stream
            if (provider === 'mux') {
                // If playback ID is not in the dropdown, try to fetch it from the stream
                console.log('Playback ID not in dropdown, attempting to fetch from stream...');
                if (streamId || selectedValue) {
                    var streamIdToFetch = streamId || selectedValue;
                    // Fetch stream details to get playback ID
                    $.ajax({
                        url: lem_ajax.ajax_url,
                        type: 'GET',
                        data: {
                            action: 'lem_get_stream_details',
                            stream_id: streamIdToFetch,
                            nonce: lem_ajax.nonce
                        },
                        success: function(response) {
                            if (response.success && response.data && response.data.playback_ids) {
                                var playbackIds = response.data.playback_ids;
                                if (playbackIds.length > 0) {
                                    var fetchedPlaybackId = playbackIds[0].id || playbackIds[0];
                                    $('#lem_playback_id').val(fetchedPlaybackId);
                                    console.log('Playback ID fetched and set to:', fetchedPlaybackId);
                                }
                            }
                        },
                        error: function(xhr, status, error) {
                            console.log('Could not fetch playback ID:', error);
                        }
                    });
                }
            }
        }
    });
    
    // Function to update labels based on provider
    function updateProviderLabels(provider) {
        provider = provider || 'mux';
        var providerName = provider.toUpperCase();
        
        // Update Playback ID label (always Mux for now)
        var playbackLabel = $('#lem_playback_id').closest('tr').find('th label');
        playbackLabel.text('Playback ID *');
        playbackLabel.closest('tr').find('td p.description').text('Mux Playback ID (used for the video player)');
        
        // Update Live Stream ID label (always Mux for now)
        var streamLabel = $('#lem_live_stream_id').closest('tr').find('th label');
        streamLabel.text('Live Stream ID');
        streamLabel.closest('tr').find('td p.description').text('Mux Live Stream ID (for RTMP info, stream status, and Simulcast). Leave empty to use global setting.');
        
        // Update provider display
        $('#lem-provider-display').text(providerName);
    }
    
    // Update labels on page load based on current provider
    var currentProvider = $('#lem_stream_provider').val() || 'mux';
    updateProviderLabels(currentProvider);
    });
    
    // Add notification styles
    if (!$('#lem-notification-styles').length) {
        $('head').append('<style id="lem-notification-styles">' +
            '.lem-notification {' +
                'position: fixed;' +
                'top: 20px;' +
                'right: 20px;' +
                'padding: 15px 20px;' +
                'border-radius: 8px;' +
                'color: white;' +
                'font-weight: 500;' +
                'z-index: 9999;' +
                'max-width: 400px;' +
                'box-shadow: 0 4px 12px rgba(0,0,0,0.15);' +
                'transform: translateX(100%);' +
                'transition: transform 0.3s ease;' +
            '}' +
            '.lem-notification.show { transform: translateX(0); }' +
            '.lem-notification.success { background: linear-gradient(135deg, #4CAF50, #45a049); }' +
            '.lem-notification.error { background: linear-gradient(135deg, #f44336, #d32f2f); }' +
            '.lem-notification.info { background: linear-gradient(135deg, #2196F3, #1976D2); }' +
            '.lem-notification.warning { background: linear-gradient(135deg, #ff9800, #f57c00); }' +
            '.lem-notification .close { float: right; margin-left: 10px; cursor: pointer; opacity: 0.8; }' +
            '.lem-notification .close:hover { opacity: 1; }' +
        '</style>');
    }
    
    // Helper function to show notifications
    function showNotification(message, type = 'info') {
        // Remove existing notifications
        $('.lem-notification').remove();
        
        var notification = $('<div class="lem-notification ' + type + '">' +
            '<span class="close">&times;</span>' +
            '<span class="message">' + message + '</span>' +
        '</div>');
        
        $('body').append(notification);
        
        // Show notification
        setTimeout(function() {
            notification.addClass('show');
        }, 100);
        
        // Auto-hide after 5 seconds
        setTimeout(function() {
            hideNotification(notification);
        }, 5000);
        
        // Close button
        notification.find('.close').on('click', function() {
            hideNotification(notification);
        });
    }
    
    function hideNotification(notification) {
        notification.removeClass('show');
        setTimeout(function() {
            notification.remove();
        }, 300);
    }
    
    // Helper function to show notices (legacy - now uses notifications)
    function showNotice(message, type = 'success') {
        showNotification(message, type);
    }
    
    // Helper function to refresh events list
    function refreshEventsList() {
        $.ajax({
            url: lem_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'lem_get_events_list',
                nonce: lem_ajax.nonce
            },
            success: function(response) {
                if (response.success) {
                    $('#lem-events-list').html(response.data);
                }
            }
        });
    }
    
    // Stripe Tab Functionality
    $('.lem-tab-button').on('click', function() {
        var tab = $(this).data('tab');
        
        // Update active tab button
        $('.lem-tab-button').removeClass('active');
        $(this).addClass('active');
        
        // Update active tab content
        $('.lem-tab-content').removeClass('active');
        $('#' + tab + '-tab').addClass('active');
    });
    
    // Show/hide pricing fields based on event type
    $('input[name="is_free"]').on('change', function() {
        var isFree = $(this).val() === '1';
        $('#pricing-fields').toggle(!isFree);
    });
    
    // Load playback restrictions on page load (only if dropdown exists)
    if ($('#playback_restriction_id').length > 0) {
        loadPlaybackRestrictions(false); // false = don't show success message
    }
    
    // Refresh restrictions button
    $('#lem-refresh-restrictions').on('click', function() {
        loadPlaybackRestrictions(true); // true = show success message
    });
    
    // Function to load playback restrictions
    function loadPlaybackRestrictions(showSuccessMessage = false) {
        var $select = $('#lem_playback_restriction_id');
        var $button = $('#lem-refresh-restrictions');
        
        // Only proceed if the select element exists
        if ($select.length === 0) {
            return;
        }
        
        // Store the currently selected value (use data attribute as fallback)
        var currentValue = $select.val() || $select.data('current-value');
        
        // Disable button and show loading
        $button.prop('disabled', true).text('Loading...');
        
        $.ajax({
            url: lem_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'lem_get_restrictions',
                nonce: lem_ajax.nonce
            },
            success: function(response) {
                if (response.success) {
                    // Clear existing options except the first one
                    $select.find('option:not(:first)').remove();
                    
                    // Add restriction options
                    response.data.forEach(function(restriction) {
                        var domains = restriction.referrer.allowed_domains.join(', ');
                        var optionText = restriction.id + ' (' + domains + ')';
                        var $option = $('<option value="' + restriction.id + '">' + optionText + '</option>');
                        
                        // Mark as selected if it matches the current value
                        if (restriction.id === currentValue) {
                            $option.prop('selected', true);
                        }
                        
                        $select.append($option);
                    });
                    
                    // Restore the selected value if it wasn't found in the new options
                    if (currentValue && !$select.find('option[value="' + currentValue + '"]').length) {
                        $select.val(currentValue);
                    }
                    
                    // Only show success message if explicitly requested
                    if (showSuccessMessage) {
                        showNotice('Restrictions loaded successfully');
                    }
                } else {
                    showNotice('Error loading restrictions: ' + response.data, 'error');
                }
            },
            error: function() {
                showNotice('Network error loading restrictions', 'error');
            },
            complete: function() {
                $button.prop('disabled', false).text('Refresh Restrictions');
            }
        });
    }
    
    // Create Event Form
    $('#lem-create-event-form').on('submit', function(e) {
        e.preventDefault();
        
        var form = $(this);
        var submitBtn = form.find('button[type="submit"]');
        
        // Disable submit button
        submitBtn.prop('disabled', true).text('Creating...');
        
        $.ajax({
            url: lem_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'lem_create_event',
                nonce: lem_ajax.nonce,
                event_id: $('#event_id').val(),
                title: $('#event_title').val(),
                description: $('#event_description').val(),
                playback_id: $('#playback_id').val(),
                playback_restriction_id: $('#playback_restriction_id').val(),
                event_date: $('#event_date').val(),
                is_free: $('input[name="is_free"]:checked').val(),
                price_id: $('#price_id').val()
            },
            success: function(response) {
                if (response.success) {
                    showNotice(response.data);
                    form[0].reset();
                    refreshEventsList();
                } else {
                    showNotice(response.data, 'error');
                }
            },
            error: function() {
                showNotice('An error occurred while creating the event.', 'error');
            },
            complete: function() {
                submitBtn.prop('disabled', false).text('Create Event');
            }
        });
    });
    
    // Edit Event Modal
    $('.lem-edit-event').on('click', function() {
        var eventData = JSON.parse($(this).data('event'));
        
        // Populate modal fields
        $('#edit_event_id').val(eventData.event_id);
        $('#edit_title').val(eventData.title);
        $('#edit_description').val(eventData.description);
        $('#edit_playback_id').val(eventData.playback_id);
        $('#edit_playback_restriction_id').val(eventData.playback_restriction_id);
        $('#edit_event_date').val(eventData.event_date.replace(' ', 'T'));
        $('#edit_status').val(eventData.status);
        
        // Show modal
        $('#lem-edit-modal').show();
    });
    
    // Close modal
    $('.lem-modal-close, .lem-modal-cancel').on('click', function() {
        $('#lem-edit-modal').hide();
    });
    
    // Close modal when clicking outside
    $(window).on('click', function(e) {
        if ($(e.target).hasClass('lem-modal')) {
            $('.lem-modal').hide();
        }
    });
    
    // Update Event Form
    $('#lem-edit-event-form').on('submit', function(e) {
        e.preventDefault();
        
        var form = $(this);
        var submitBtn = form.find('button[type="submit"]');
        
        // Disable submit button
        submitBtn.prop('disabled', true).text('Updating...');
        
        $.ajax({
            url: lem_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'lem_update_event',
                nonce: lem_ajax.nonce,
                event_id: $('#edit_event_id').val(),
                title: $('#edit_title').val(),
                description: $('#edit_description').val(),
                playback_id: $('#edit_playback_id').val(),
                playback_restriction_id: $('#edit_playback_restriction_id').val(),
                event_date: $('#edit_event_date').val(),
                status: $('#edit_status').val()
            },
            success: function(response) {
                if (response.success) {
                    showNotice(response.data);
                    $('#lem-edit-modal').hide();
                    refreshEventsList();
                } else {
                    showNotice(response.data, 'error');
                }
            },
            error: function() {
                showNotice('An error occurred while updating the event.', 'error');
            },
            complete: function() {
                submitBtn.prop('disabled', false).text('Update Event');
            }
        });
    });
    
    // Generate JWT Form
    $('#lem-generate-jwt-form').on('submit', function(e) {
        e.preventDefault();
        
        var form = $(this);
        var submitBtn = form.find('button[type="submit"]');
        var resultDiv = $('#lem-jwt-result');
        
        // Disable submit button
        submitBtn.prop('disabled', true).text('Generating...');
        resultDiv.hide();
        
        $.ajax({
            url: lem_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'lem_generate_jwt',
                nonce: lem_ajax.nonce,
                email: $('#jwt_email').val(),
                event_id: $('#jwt_event_id').val(),
                payment_id: $('#jwt_payment_id').val()
            },
            success: function(response) {
                if (response.success) {
                    $('#lem-jwt-token').val(response.data.jwt);
                    var watchUrl = response.data.watch_url || (window.location.origin + '/events');
                    $('#lem-magic-link').text(watchUrl);
                    resultDiv.show();
                    showNotice(response.data.message);
                    form[0].reset();
                } else {
                    showNotice(response.data, 'error');
                }
            },
            error: function() {
                showNotice('An error occurred while generating the JWT.', 'error');
            },
            complete: function() {
                submitBtn.prop('disabled', false).text('Generate JWT');
            }
        });
    });
    
    // Revoke JWT Form
    $('#lem-revoke-jwt-form').on('submit', function(e) {
        e.preventDefault();
        
        var form = $(this);
        var submitBtn = form.find('button[type="submit"]');
        
        // Disable submit button
        submitBtn.prop('disabled', true).text('Revoking...');
        
        $.ajax({
            url: lem_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'lem_revoke_jwt',
                nonce: lem_ajax.nonce,
                jti: $('#revoke_jti').val()
            },
            success: function(response) {
                if (response.success) {
                    showNotice(response.data);
                    form[0].reset();
                } else {
                    showNotice(response.data, 'error');
                }
            },
            error: function() {
                showNotice('An error occurred while revoking the JWT.', 'error');
            },
            complete: function() {
                submitBtn.prop('disabled', false).text('Revoke JWT');
            }
        });
    });
    
    // Generate Ticket Page Button
    $(document).on('click', '.lem-generate-ticket-page', function() {
        var eventId = $(this).data('event-id');
        var ticketPageUrl = window.location.origin + '/?lem_ticket_event=' + eventId;
        
        // Create a temporary textarea to copy the URL
        var textarea = $('<textarea>');
        textarea.val(ticketPageUrl);
        $('body').append(textarea);
        textarea.select();
        document.execCommand('copy');
        textarea.remove();
        
        showNotice('Ticket page URL copied to clipboard: ' + ticketPageUrl);
    });
    
    // Copy to clipboard functionality
    $('.copy-to-clipboard').on('click', function() {
        var text = $(this).data('clipboard-text');
        
        // Create a temporary textarea
        var textarea = $('<textarea>');
        textarea.val(text);
        $('body').append(textarea);
        textarea.select();
        document.execCommand('copy');
        textarea.remove();
        
        showNotice('Copied to clipboard!');
    });
    
    // Load playback restrictions on page load (for event edit pages)
    if ($('#lem_playback_restriction_id').length > 0) {
        // Get the current value from data attribute
        var $select = $('#lem_playback_restriction_id');
        var currentValue = $select.data('current-value');
        
        // Set the current value if it exists
        if (currentValue) {
            $select.val(currentValue);
        }
        
        loadPlaybackRestrictions();
    }
    
    // Refresh restrictions button
    $('#lem-refresh-restrictions').on('click', function() {
        loadPlaybackRestrictions(true);
    });
    
}); 