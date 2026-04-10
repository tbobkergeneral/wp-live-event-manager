<div class="wrap">
    <h1>System Debug</h1>
    
    <div class="lem-admin-container">
        <!-- Redis Connection Test -->
        <div class="lem-section">
            <h2>Redis Connection Test</h2>
            <div class="lem-card">
                <p>Test your Redis connection and basic operations:</p>
                <button id="lem-test-redis" class="button button-primary">Test Redis Connection</button>
                <div id="lem-redis-result" class="lem-result"></div>
                
                <div class="lem-instructions">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                        <h3>Redis Troubleshooting Guide</h3>
                        <div>
                            <button id="lem-expand-all" class="button button-secondary" style="margin-right: 10px;">Expand All</button>
                            <button id="lem-collapse-all" class="button button-secondary">Collapse All</button>
                        </div>
                    </div>
                    
                    <!-- Local Development Section -->
                    <div class="lem-collapsible-section">
                        <div class="lem-collapsible-header" data-target="local-dev">
                            <h4>🔧 Local Development (macOS/Linux)</h4>
                            <span class="lem-toggle-icon">▼</span>
                        </div>
                        <div class="lem-collapsible-content" id="local-dev">
                            <div class="lem-code-block">
                                <h5>0. Install Redis (if not installed):</h5>
                                <code>brew install redis</code>
                                <p><em>For Ubuntu/Debian: sudo apt-get install redis-server</em></p>
                                
                                <h5>1. Check if Redis is installed:</h5>
                                <code>redis-cli --version</code>
                                
                                <h5>2. Check if Redis is running:</h5>
                                <code>redis-cli ping</code>
                                <p><em>Should return: PONG</em></p>
                                
                                <h5>3. Start Redis (if not running):</h5>
                                <code>brew services start redis</code>
                                <p><em>Or manually: redis-server</em></p>
                                
                                <h5>4. Check Redis status:</h5>
                                <code>brew services list | grep redis</code>
                                <p><em>Should show: redis started</em></p>
                                
                                <h5>5. Test Redis connection:</h5>
                                <code>redis-cli</code>
                                <p><em>Then type: ping</em></p>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Server/Production Section -->
                    <div class="lem-collapsible-section">
                        <div class="lem-collapsible-header" data-target="server-prod">
                            <h4>🌐 Server/Production</h4>
                            <span class="lem-toggle-icon">▼</span>
                        </div>
                        <div class="lem-collapsible-content" id="server-prod">
                            <div class="lem-code-block">
                                <h5>1. Check Redis service status:</h5>
                                <code>sudo systemctl status redis</code>
                                <p><em>Or: sudo service redis status</em></p>
                                
                                <h5>2. Start Redis service:</h5>
                                <code>sudo systemctl start redis</code>
                                <p><em>Or: sudo service redis start</em></p>
                                
                                <h5>3. Enable Redis on boot:</h5>
                                <code>sudo systemctl enable redis</code>
                                
                                <h5>4. Check Redis configuration:</h5>
                                <code>sudo cat /etc/redis/redis.conf | grep -E "^(bind|port|requirepass)"</code>
                                
                                <h5>5. Test Redis connection:</h5>
                                <code>redis-cli -h localhost -p 6379 ping</code>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Common Issues Section -->
                    <div class="lem-collapsible-section">
                        <div class="lem-collapsible-header" data-target="common-issues">
                            <h4>🔍 Common Issues & Solutions</h4>
                            <span class="lem-toggle-icon">▼</span>
                        </div>
                        <div class="lem-collapsible-content" id="common-issues">
                            <div class="lem-troubleshooting">
                                <div class="lem-issue">
                                    <strong>❌ Connection refused</strong>
                                    <p>Redis server is not running. Start it with the commands above.</p>
                                </div>
                                
                                <div class="lem-issue">
                                    <strong>❌ Authentication required</strong>
                                    <p>Redis has a password. Check your Redis configuration and update the plugin settings.</p>
                                </div>
                                
                                <div class="lem-issue">
                                    <strong>❌ Wrong port</strong>
                                    <p>Redis might be running on a different port. Check with: <code>redis-cli -p 6380 ping</code></p>
                                </div>
                                
                                <div class="lem-issue">
                                    <strong>❌ Firewall blocking</strong>
                                    <p>Check if port 6379 is open: <code>sudo ufw status</code></p>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Plugin Configuration Section -->
                    <div class="lem-collapsible-section">
                        <div class="lem-collapsible-header" data-target="plugin-config">
                            <h4>⚙️ Plugin Configuration</h4>
                            <span class="lem-toggle-icon">▼</span>
                        </div>
                        <div class="lem-collapsible-content" id="plugin-config">
                            <p>Make sure your Redis settings are correct in the plugin configuration:</p>
                            <ul>
                                <li><strong>Host:</strong> Usually <code>localhost</code> or <code>127.0.0.1</code></li>
                                <li><strong>Port:</strong> Usually <code>6379</code></li>
                                <li><strong>Password:</strong> Leave empty if no password is set</li>
                                <li><strong>Database:</strong> Usually <code>0</code> (plugin will auto-select if conflicts with WordPress cache)</li>
                            </ul>
                            
                            <div class="lem-notice">
                                <h5>🔄 Automatic Database Selection</h5>
                                <p>The plugin automatically detects if database 0 is used by WordPress caching and selects the next available database to avoid conflicts.</p>
                            </div>
                        </div>
                    </div>
                    
                    <h4>💳 Stripe Webhook Local Development</h4>
                    <div class="lem-code-block">
                        <h5>For local development with Stripe CLI:</h5>
                        <p>When using Stripe CLI to forward webhooks to localhost, you need to skip SSL verification:</p>
                        <code>stripe listen --forward-to https://localhost:8443/wp-admin/admin-ajax.php?action=lem_stripe_webhook --skip-verify</code>
                        <p><em>Note: The --skip-verify flag is required for localhost SSL certificates.</em></p>
                    </div>
                </div>
            </div>
        </div>
        

        
        <!-- Clear All Tokens -->
        <div class="lem-section">
            <h2>Clear All Tokens & Sessions</h2>
            <div class="lem-card">
                <div class="lem-warning">
                    <h3>⚠️ Warning: This action cannot be undone!</h3>
                    <p>This will permanently delete:</p>
                    <ul>
                        <li>All JWT tokens from the database</li>
                        <li>All active sessions from Redis</li>
                        <li>All magic tokens from Redis</li>
                        <li>All cached event data from Redis</li>
                    </ul>
                    <p><strong>All users will lose access and need to request new magic links.</strong></p>
                </div>
                <button id="lem-clear-tokens" class="button button-danger">Clear All Tokens & Sessions</button>
                <div id="lem-clear-result" class="lem-result"></div>
            </div>
        </div>
        
        <!-- Debug Information -->
        <div class="lem-section">
            <h2>System Information</h2>
            <div class="lem-card">
                <table class="form-table">
                    <tr>
                        <th>WordPress Version:</th>
                        <td><?php echo get_bloginfo('version'); ?></td>
                    </tr>
                    <tr>
                        <th>PHP Version:</th>
                        <td><?php echo phpversion(); ?></td>
                    </tr>
                    <tr>
                        <th>Plugin Version:</th>
                        <td><?php echo LEM_VERSION; ?></td>
                    </tr>
                    <tr>
                        <th>Debug Mode:</th>
                        <td><?php echo WP_DEBUG ? 'Enabled' : 'Disabled'; ?></td>
                    </tr>
                    <tr>
                        <th>Database Prefix:</th>
                        <td><?php global $wpdb; echo $wpdb->prefix; ?></td>
                    </tr>
                    <tr>
                        <th>JWT Table:</th>
                        <td><?php global $wpdb; echo $wpdb->prefix . 'lem_jwt_tokens'; ?></td>
                    </tr>
                </table>
            </div>
        </div>
    </div>
</div>

<style>
.lem-result {
    margin-top: 15px;
    padding: 15px;
    border-radius: 8px;
    display: none;
}

.lem-result.success {
    background: #d4edda;
    color: #155724;
    border: 1px solid #c3e6cb;
    display: block;
}

.lem-result.error {
    background: #f8d7da;
    color: #721c24;
    border: 1px solid #f5c6cb;
    display: block;
}

.lem-result.info {
    background: #d1ecf1;
    color: #0c5460;
    border: 1px solid #bee5eb;
    display: block;
}

.lem-logs-table {
    width: 100%;
    border-collapse: collapse;
    margin-top: 15px;
}

.lem-logs-table th,
.lem-logs-table td {
    padding: 8px;
    text-align: left;
    border-bottom: 1px solid #ddd;
}

.lem-logs-table th {
    background-color: #f2f2f2;
    font-weight: bold;
}

.lem-logs-table tr:hover {
    background-color: #f5f5f5;
}

.lem-stats {
    display: flex;
    gap: 20px;
    margin-bottom: 15px;
}

.lem-stat {
    background: #f8f9fa;
    padding: 10px 15px;
    border-radius: 6px;
    border: 1px solid #e9ecef;
}

.lem-stat .number {
    font-size: 1.5em;
    font-weight: bold;
    color: #007cba;
}

.lem-stat .label {
    font-size: 0.9em;
    color: #666;
}

.lem-instructions {
    margin-top: 30px;
    padding: 20px;
    background: #f8f9fa;
    border-radius: 8px;
    border: 1px solid #e9ecef;
}

.lem-instructions h3 {
    margin-top: 0;
    color: #333;
    border-bottom: 2px solid #007cba;
    padding-bottom: 10px;
}

.lem-instructions h4 {
    color: #007cba;
    margin-top: 25px;
    margin-bottom: 15px;
}

.lem-instructions h5 {
    color: #555;
    margin-top: 15px;
    margin-bottom: 8px;
    font-size: 1em;
}

/* Collapsible Sections */
.lem-collapsible-section {
    margin-bottom: 15px;
    border: 1px solid #e9ecef;
    border-radius: 6px;
    overflow: hidden;
}

.lem-collapsible-header {
    background: #f8f9fa;
    padding: 15px 20px;
    cursor: pointer;
    display: flex;
    justify-content: space-between;
    align-items: center;
    border-bottom: 1px solid #e9ecef;
    transition: background-color 0.2s ease;
}

.lem-collapsible-header:hover {
    background: #e9ecef;
}

.lem-collapsible-header h4 {
    margin: 0;
    color: #007cba;
    font-size: 1.1em;
}

.lem-toggle-icon {
    font-size: 0.8em;
    color: #666;
    transition: transform 0.3s ease;
}

.lem-collapsible-header.collapsed .lem-toggle-icon {
    transform: rotate(-90deg);
}

.lem-collapsible-content {
    max-height: 1000px;
    overflow: hidden;
    transition: max-height 0.3s ease-in-out, opacity 0.3s ease-in-out;
    background: white;
    opacity: 1;
}

.lem-collapsible-content.collapsed {
    max-height: 0;
    opacity: 0;
}

.lem-collapsible-content > div {
    padding: 20px;
}

/* Add some spacing between sections */
.lem-instructions .lem-collapsible-section:not(:last-child) {
    margin-bottom: 20px;
}

.lem-code-block {
    background: #f1f3f4;
    padding: 15px;
    border-radius: 6px;
    margin: 10px 0;
    border-left: 4px solid #007cba;
}

.lem-code-block code {
    background: #e8eaed;
    padding: 4px 8px;
    border-radius: 4px;
    font-family: 'Courier New', monospace;
    color: #d73a49;
    display: inline-block;
    margin: 5px 0;
}

.lem-code-block p {
    margin: 5px 0;
    color: #666;
}

.lem-troubleshooting {
    margin: 15px 0;
}

.lem-issue {
    background: #fff3cd;
    border: 1px solid #ffeaa7;
    padding: 12px;
    border-radius: 6px;
    margin: 10px 0;
}

.lem-issue strong {
    color: #856404;
    display: block;
    margin-bottom: 5px;
}

.lem-warning {
    background: #fff3cd;
    border: 1px solid #ffeaa7;
    padding: 15px;
    border-radius: 6px;
    margin-bottom: 20px;
}

.lem-warning h3 {
    color: #856404;
    margin-top: 0;
    margin-bottom: 10px;
}

.lem-warning p {
    color: #856404;
    margin: 8px 0;
}

.lem-warning ul {
    margin: 10px 0;
    padding-left: 20px;
}

.lem-warning li {
    color: #856404;
    margin: 5px 0;
}

.button-danger {
    background: #dc3545 !important;
    border-color: #dc3545 !important;
    color: white !important;
}

.button-danger:hover {
    background: #c82333 !important;
    border-color: #bd2130 !important;
}

.lem-issue p {
    margin: 5px 0;
    color: #856404;
}

.lem-instructions ul {
    margin: 10px 0;
    padding-left: 20px;
}

.lem-instructions li {
    margin: 5px 0;
    color: #555;
}

.lem-notice {
    background: #e7f3ff;
    border: 1px solid #b3d9ff;
    padding: 15px;
    border-radius: 6px;
    margin: 15px 0;
}

.lem-notice h5 {
    margin: 0 0 10px 0;
    color: #0066cc;
}

.lem-notice p {
    margin: 0;
    color: #0066cc;
}
</style>

<script>
jQuery(document).ready(function($) {
    // Ensure lem_ajax object is available
    if (typeof lem_ajax === 'undefined') {
        console.warn('lem_ajax object not found, creating fallback');
        window.lem_ajax = {
            ajax_url: '<?php echo admin_url('admin-ajax.php'); ?>',
            nonce: '<?php echo wp_create_nonce('lem_nonce'); ?>'
        };
    }
    
    // Collapsible sections functionality
    $('.lem-collapsible-header').on('click', function() {
        var $header = $(this);
        var $content = $('#' + $header.data('target'));
        var $icon = $header.find('.lem-toggle-icon');
        
        if ($content.hasClass('collapsed')) {
            // Expand
            $content.removeClass('collapsed');
            $header.removeClass('collapsed');
            $icon.text('▼');
        } else {
            // Collapse
            $content.addClass('collapsed');
            $header.addClass('collapsed');
            $icon.text('▶');
        }
    });
    
    // Initialize all sections as collapsed by default
    $('.lem-collapsible-content').addClass('collapsed');
    $('.lem-collapsible-header').addClass('collapsed');
    $('.lem-collapsible-header .lem-toggle-icon').text('▶');
    
    // Expand all sections
    $('#lem-expand-all').on('click', function() {
        $('.lem-collapsible-content').removeClass('collapsed');
        $('.lem-collapsible-header').removeClass('collapsed');
        $('.lem-collapsible-header .lem-toggle-icon').text('▼');
    });
    
    // Collapse all sections
    $('#lem-collapse-all').on('click', function() {
        $('.lem-collapsible-content').addClass('collapsed');
        $('.lem-collapsible-header').addClass('collapsed');
        $('.lem-collapsible-header .lem-toggle-icon').text('▶');
    });
    // Test Redis connection
    $('#lem-test-redis').on('click', function() {
        var $button = $(this);
        var $result = $('#lem-redis-result');
        
        $button.prop('disabled', true).text('Testing...');
        $result.removeClass('success error info').hide();
        
        $.post(lem_ajax.ajax_url, {
            action: 'lem_test_redis_connection',
            nonce: lem_ajax.nonce
        }, function(response) {
            if (response.success) {
                var html = '<h4>✅ ' + response.data.message + '</h4>';
                html += '<table class="form-table">';
                html += '<tr><th>Redis Version:</th><td>' + response.data.redis_version + '</td></tr>';
                html += '<tr><th>Connected Clients:</th><td>' + response.data.connected_clients + '</td></tr>';
                html += '<tr><th>Used Memory:</th><td>' + response.data.used_memory_human + '</td></tr>';
                html += '<tr><th>Uptime:</th><td>' + Math.floor(response.data.uptime_in_seconds / 3600) + ' hours</td></tr>';
                html += '<tr><th>Current Database:</th><td>' + response.data.current_database + '</td></tr>';
                html += '<tr><th>Host:</th><td>' + response.data.host + '</td></tr>';
                html += '<tr><th>Port:</th><td>' + response.data.port + '</td></tr>';
                html += '</table>';
                
                $result.addClass('success').html(html).show();
            } else {
                $result.addClass('error').html('<h4>❌ Redis Test Failed</h4><p>' + response.data + '</p>').show();
            }
        }).fail(function() {
            $result.addClass('error').html('<h4>❌ Network Error</h4><p>Failed to connect to server.</p>').show();
        }).always(function() {
            $button.prop('disabled', false).text('Test Redis Connection');
        });
    });
    
    // Check Redis status
    $('#lem-check-redis-status').on('click', function() {
        var $button = $(this);
        var $result = $('#lem-redis-result');
        
        $button.prop('disabled', true).text('Checking...');
        $result.removeClass('success error info').hide();
        
        // Check if Redis is enabled in settings
        $.post(lem_ajax.ajax_url, {
            action: 'lem_check_redis_status',
            nonce: lem_ajax.nonce
        }, function(response) {
            if (response.success) {
                var html = '<h4>🔍 Redis Status Check</h4>';
                html += '<table class="form-table">';
                html += '<tr><th>Redis Enabled:</th><td>' + (response.data.enabled ? '✅ Yes' : '❌ No') + '</td></tr>';
                html += '<tr><th>Host:</th><td>' + response.data.host + '</td></tr>';
                html += '<tr><th>Port:</th><td>' + response.data.port + '</td></tr>';
                html += '<tr><th>Database:</th><td>' + response.data.database + '</td></tr>';
                html += '<tr><th>Password Set:</th><td>' + (response.data.password_set ? 'Yes' : 'No') + '</td></tr>';
                html += '</table>';
                
                if (!response.data.enabled) {
                    html += '<div class="notice notice-warning"><p><strong>Redis is disabled!</strong> Enable it in Live Events > Settings > Redis Configuration.</p></div>';
                }
                
                $result.addClass('info').html(html).show();
            } else {
                $result.addClass('error').html('<h4>❌ Status Check Failed</h4><p>' + response.data + '</p>').show();
            }
        }).fail(function() {
            $result.addClass('error').html('<h4>❌ Network Error</h4><p>Failed to check Redis status.</p>').show();
        }).always(function() {
            $button.prop('disabled', false).text('Check Redis Status');
        });
    });
    

    
    // Clear all tokens
    $('#lem-clear-tokens').on('click', function() {
        console.log('Clear tokens button clicked');
        console.log('lem_ajax object:', typeof lem_ajax !== 'undefined' ? lem_ajax : 'undefined');
        
        if (!confirm('⚠️ WARNING: This will permanently delete ALL tokens and sessions!\n\nAll users will lose access and need to request new magic links.\n\nThis action cannot be undone!\n\nAre you sure you want to continue?')) {
            return;
        }
        
        var $button = $(this);
        var $result = $('#lem-clear-result');
        
        $button.prop('disabled', true).text('Clearing...');
        $result.removeClass('success error info').hide();
        
        console.log('Sending AJAX request to:', lem_ajax.ajax_url);
        console.log('Request data:', {
            action: 'lem_clear_all_tokens',
            nonce: lem_ajax.nonce
        });
        
        $.post(lem_ajax.ajax_url, {
            action: 'lem_clear_all_tokens',
            nonce: lem_ajax.nonce
        }, function(response) {
            console.log('AJAX response received:', response);
            if (response.success) {
                var html = '<h4>✅ All Tokens Cleared Successfully</h4>';
                html += '<p>' + response.data.message + '</p>';
                html += '<div class="lem-stats">';
                html += '<div class="lem-stat"><span class="number">' + response.data.results.mysql_deleted + '</span><div class="label">Database Records Deleted</div></div>';
                html += '<div class="lem-stat"><span class="number">' + response.data.results.redis_keys_deleted + '</span><div class="label">Redis Keys Deleted</div></div>';
                html += '</div>';
                
                if (response.data.results.redis_error) {
                    html += '<div class="notice notice-warning"><p><strong>Redis Warning:</strong> ' + response.data.results.redis_error + '</p></div>';
                }
                
                $result.addClass('success').html(html).show();
            } else {
                $result.addClass('error').html('<h4>❌ Clear Failed</h4><p>' + response.data + '</p>').show();
            }
        }).fail(function(xhr, status, error) {
            console.log('AJAX request failed:', {xhr: xhr, status: status, error: error});
            $result.addClass('error').html('<h4>❌ Network Error</h4><p>Failed to clear tokens. Check browser console for details.</p>').show();
        }).always(function() {
            $button.prop('disabled', false).text('Clear All Tokens & Sessions');
        });
    });
});
</script> 