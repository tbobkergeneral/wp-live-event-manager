<div class="wrap">
    <h1>Playback Restrictions</h1>
    
    <?php
    // Check for missing dependencies
    if (!class_exists('\Stripe\Stripe')): ?>
        <div class="notice notice-warning">
            <p><strong>Missing Dependencies:</strong> Stripe PHP library is not installed.</p>
            <p>Please run <code>composer install</code> in the plugin directory to install required dependencies.</p>
        </div>
    <?php endif; ?>
    
    <div class="lem-admin-container">
        <!-- Create New Restriction Section -->
        <div class="lem-section">
            <h2>Create New Playback Restriction</h2>
            
            <div class="lem-card">
                <form id="lem-create-restriction-form">
                    <table class="form-table">
                        <tr>
                            <th><label for="restriction_name">Restriction Name *</label></th>
                            <td><input type="text" id="restriction_name" name="name" required placeholder="e.g., Production Domain Restriction"></td>
                        </tr>
                        <tr>
                            <th><label for="restriction_description">Description</label></th>
                            <td><textarea id="restriction_description" name="description" rows="3" placeholder="Description of this restriction..."></textarea></td>
                        </tr>
                        <tr>
                            <th><label for="allowed_domains">Allowed Domains *</label></th>
                            <td>
                                <input type="text" id="allowed_domains" name="allowed_domains" required placeholder="*.example.com, foo.com, bar.com">
                                <p class="description">Comma-separated list of domains. Use *.example.com for wildcards, * for all domains</p>
                            </td>
                        </tr>
                        <tr>
                            <th><label>Referrer Settings</label></th>
                            <td>
                                <label>
                                    <input type="checkbox" name="allow_no_referrer" value="1" checked> Allow requests without referrer header
                                </label>
                                <p class="description">Allow playback from mobile apps, smart TVs, etc.</p>
                            </td>
                        </tr>
                        <tr>
                            <th><label>User Agent Settings</label></th>
                            <td>
                                <label>
                                    <input type="checkbox" name="allow_no_user_agent" value="1" checked> Allow requests without user agent
                                </label>
                                <br>
                                <label>
                                    <input type="checkbox" name="allow_high_risk_user_agent" value="1" checked> Allow high-risk user agents
                                </label>
                                <p class="description">High-risk user agents are defined by Mux</p>
                            </td>
                        </tr>
                    </table>
                    <p class="submit">
                        <button type="submit" class="button button-primary">Create Restriction</button>
                    </p>
                </form>
            </div>
        </div>
        
        <!-- Restrictions List -->
        <div class="lem-section">
            <h2>Existing Restrictions</h2>
            
            <div class="lem-card">
                <div id="lem-restrictions-list">
                    <p>Loading restrictions...</p>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
.lem-notification {
    position: fixed;
    top: 20px;
    right: 20px;
    padding: 15px 20px;
    border-radius: 8px;
    color: white;
    font-weight: 500;
    z-index: 9999;
    max-width: 400px;
    box-shadow: 0 4px 12px rgba(0,0,0,0.15);
    transform: translateX(100%);
    transition: transform 0.3s ease;
}

.lem-notification.show {
    transform: translateX(0);
}

.lem-notification.success {
    background: linear-gradient(135deg, #4CAF50, #45a049);
}

.lem-notification.error {
    background: linear-gradient(135deg, #f44336, #d32f2f);
}

.lem-notification.info {
    background: linear-gradient(135deg, #2196F3, #1976D2);
}

.lem-notification.warning {
    background: linear-gradient(135deg, #ff9800, #f57c00);
}

.lem-notification .close {
    float: right;
    margin-left: 10px;
    cursor: pointer;
    opacity: 0.8;
}

.lem-notification .close:hover {
    opacity: 1;
}
</style>

<script>
jQuery(document).ready(function($) {
    // Notification function
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
    
    // Load restrictions on page load
    loadRestrictions();
    
    // Create restriction form
    $('#lem-create-restriction-form').on('submit', function(e) {
        e.preventDefault();
        
        var formData = {
            action: 'lem_create_restriction',
            nonce: lem_ajax.nonce,
            name: $('#restriction_name').val(),
            description: $('#restriction_description').val(),
            allowed_domains: $('#allowed_domains').val(),
            allow_no_referrer: $('input[name="allow_no_referrer"]').is(':checked') ? 1 : 0,
            allow_no_user_agent: $('input[name="allow_no_user_agent"]').is(':checked') ? 1 : 0,
            allow_high_risk_user_agent: $('input[name="allow_high_risk_user_agent"]').is(':checked') ? 1 : 0
        };
        
        $.post(lem_ajax.ajax_url, formData, function(response) {
            if (response.success) {
                showNotification('Restriction created successfully!', 'success');
                $('#lem-create-restriction-form')[0].reset();
                loadRestrictions();
            } else {
                showNotification('Error: ' + response.data, 'error');
            }
        });
    });
    
    function loadRestrictions() {
        $.post(lem_ajax.ajax_url, {
            action: 'lem_get_restrictions',
            nonce: lem_ajax.nonce
        }, function(response) {
            if (response.success) {
                displayRestrictions(response.data);
            } else {
                $('#lem-restrictions-list').html('<p>Error loading restrictions: ' + response.data + '</p>');
            }
        });
    }
    
    function displayRestrictions(restrictions) {
        if (restrictions.length === 0) {
            $('#lem-restrictions-list').html('<p>No restrictions found.</p>');
            return;
        }
        
        var html = '<table class="wp-list-table widefat fixed striped">';
        html += '<thead><tr><th>ID</th><th>Name</th><th>Allowed Domains</th><th>Settings</th><th>Actions</th></tr></thead><tbody>';
        
        restrictions.forEach(function(restriction) {
            var domains = restriction.referrer.allowed_domains.join(', ');
            var settings = [];
            if (restriction.referrer.allow_no_referrer) settings.push('Allow no referrer');
            if (restriction.user_agent.allow_no_user_agent) settings.push('Allow no user agent');
            if (restriction.user_agent.allow_high_risk_user_agent) settings.push('Allow high-risk user agent');
            
            html += '<tr>';
            html += '<td>' + restriction.id + '</td>';
            html += '<td>' + restriction.id + '</td>'; // Using ID as name since Mux doesn't store custom names
            html += '<td>' + domains + '</td>';
            html += '<td>' + settings.join(', ') + '</td>';
            html += '<td><button class="button button-small lem-delete-restriction" data-id="' + restriction.id + '">Delete</button></td>';
            html += '</tr>';
        });
        
        html += '</tbody></table>';
        $('#lem-restrictions-list').html(html);
    }
    
    // Delete restriction
    $(document).on('click', '.lem-delete-restriction', function() {
        if (!confirm('Are you sure you want to delete this restriction?')) {
            return;
        }
        
        var restrictionId = $(this).data('id');
        
        $.post(lem_ajax.ajax_url, {
            action: 'lem_delete_restriction',
            nonce: lem_ajax.nonce,
            restriction_id: restrictionId
        }, function(response) {
            if (response.success) {
                showNotification('Restriction deleted successfully!', 'success');
                loadRestrictions();
            } else {
                showNotification('Error: ' + response.data, 'error');
            }
        });
    });
});
</script> 