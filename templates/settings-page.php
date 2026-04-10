<div class="wrap">
    <h1>Live Event Manager Settings</h1>
    
    <?php
    $settings = get_option('lem_settings', array());

    if (isset($_POST['submit'])) {
        $updated_settings = $settings;

        // Stripe settings
        $updated_settings['stripe_mode'] = sanitize_text_field($_POST['stripe_mode']);
        $updated_settings['stripe_test_publishable_key'] = sanitize_text_field($_POST['stripe_test_publishable_key']);
        $updated_settings['stripe_test_secret_key'] = sanitize_text_field($_POST['stripe_test_secret_key']);
        $updated_settings['stripe_test_webhook_secret'] = sanitize_text_field($_POST['stripe_test_webhook_secret']);
        $updated_settings['stripe_live_publishable_key'] = sanitize_text_field($_POST['stripe_live_publishable_key']);
        $updated_settings['stripe_live_secret_key'] = sanitize_text_field($_POST['stripe_live_secret_key']);
        $updated_settings['stripe_live_webhook_secret'] = sanitize_text_field($_POST['stripe_live_webhook_secret']);
        $updated_settings['use_redis'] = isset($_POST['use_redis']) ? 1 : 0;
        $updated_settings['redis_host'] = sanitize_text_field($_POST['redis_host'] ?? '127.0.0.1');
        $updated_settings['redis_port'] = intval($_POST['redis_port'] ?? 6379);
        $updated_settings['redis_password'] = sanitize_text_field($_POST['redis_password'] ?? '');
        $updated_settings['redis_database'] = intval($_POST['redis_database'] ?? 0);
        // JWT settings are now managed in JWT Manager page
        $updated_settings['debug_mode'] = intval($_POST['debug_mode'] ?? 0);

        update_option('lem_settings', $updated_settings);
        $settings = $updated_settings;

        echo '<div class="notice notice-success"><p>Settings saved successfully!</p></div>';
    }
    ?>
    
    <div class="notice notice-info inline">
        <p><strong>Streaming Provider Settings:</strong> Mux configuration has been moved to <a href="<?php echo admin_url('edit.php?post_type=lem_event&page=live-event-manager-stream-vendors'); ?>">Stream Vendors</a> page. Additional providers can be added in the future.</p>
    </div>
    
    <form method="post" action="">
        <h2>Stripe Configuration</h2>
        <table class="form-table">
            <tr>
                <th scope="row">
                    <label for="stripe_mode">Stripe Mode</label>
                </th>
                <td>
                    <select id="stripe_mode" name="stripe_mode">
                        <option value="test" <?php selected($settings['stripe_mode'] ?? 'test', 'test'); ?>>Test Mode</option>
                        <option value="live" <?php selected($settings['stripe_mode'] ?? 'test', 'live'); ?>>Live Mode</option>
                    </select>
                    <p class="description">
                        Select whether to use Stripe test or live environment.
                    </p>
                </td>
            </tr>
        </table>
        
        <div class="lem-stripe-tabs">
            <div class="lem-tab-nav">
                <button type="button" class="lem-tab-button active" data-tab="test">Test Environment</button>
                <button type="button" class="lem-tab-button" data-tab="live">Live Environment</button>
            </div>
            
            <div id="test-tab" class="lem-tab-content active">
                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="stripe_test_publishable_key">Test Publishable Key</label>
                        </th>
                        <td>
                            <input type="text" id="stripe_test_publishable_key" name="stripe_test_publishable_key" 
                                   value="<?php echo esc_attr($settings['stripe_test_publishable_key'] ?? ''); ?>" 
                                   class="regular-text">
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">
                            <label for="stripe_test_secret_key">Test Secret Key</label>
                        </th>
                        <td>
                            <input type="password" id="stripe_test_secret_key" name="stripe_test_secret_key" 
                                   value="<?php echo esc_attr($settings['stripe_test_secret_key'] ?? ''); ?>" 
                                   class="regular-text">
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">
                            <label for="stripe_test_webhook_secret">Test Webhook Secret</label>
                        </th>
                        <td>
                            <input type="password" id="stripe_test_webhook_secret" name="stripe_test_webhook_secret" 
                                   value="<?php echo esc_attr($settings['stripe_test_webhook_secret'] ?? ''); ?>" 
                                   class="regular-text">
                        </td>
                    </tr>
                </table>
            </div>
            
            <div id="live-tab" class="lem-tab-content">
                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="stripe_live_publishable_key">Live Publishable Key</label>
                        </th>
                        <td>
                            <input type="text" id="stripe_live_publishable_key" name="stripe_live_publishable_key" 
                                   value="<?php echo esc_attr($settings['stripe_live_publishable_key'] ?? ''); ?>" 
                                   class="regular-text">
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">
                            <label for="stripe_live_secret_key">Live Secret Key</label>
                        </th>
                        <td>
                            <input type="password" id="stripe_live_secret_key" name="stripe_live_secret_key" 
                                   value="<?php echo esc_attr($settings['stripe_live_secret_key'] ?? ''); ?>" 
                                   class="regular-text">
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">
                            <label for="stripe_live_webhook_secret">Live Webhook Secret</label>
                        </th>
                        <td>
                            <input type="password" id="stripe_live_webhook_secret" name="stripe_live_webhook_secret" 
                                   value="<?php echo esc_attr($settings['stripe_live_webhook_secret'] ?? ''); ?>" 
                                   class="regular-text">
                        </td>
                    </tr>
                </table>
            </div>
        </div>
        
        <h2>Redis Configuration</h2>
        <table class="form-table">
            <tr>
                <th scope="row">
                    <label for="use_redis">Enable Redis</label>
                </th>
                <td>
                    <label>
                        <input type="checkbox" id="use_redis" name="use_redis" value="1" 
                               <?php checked($settings['use_redis'] ?? 1, 1); ?>>
                        Use Redis for JWT and event caching (recommended for production)
                    </label>
                    <p class="description">
                        Redis provides fast access to JWT tokens and event data for edge playback.
                    </p>
                </td>
            </tr>
            
            <tr>
                <th scope="row">
                    <label for="redis_host">Redis Host</label>
                </th>
                <td>
                    <input type="text" id="redis_host" name="redis_host" 
                           value="<?php echo esc_attr($settings['redis_host'] ?? '127.0.0.1'); ?>" 
                           class="regular-text">
                    <p class="description">
                        Redis server hostname or IP address.
                    </p>
                </td>
            </tr>
            
            <tr>
                <th scope="row">
                    <label for="redis_port">Redis Port</label>
                </th>
                <td>
                    <input type="number" id="redis_port" name="redis_port" 
                           value="<?php echo esc_attr($settings['redis_port'] ?? 6379); ?>" 
                           class="small-text">
                    <p class="description">
                        Redis server port (default: 6379).
                    </p>
                </td>
            </tr>
            
            <tr>
                <th scope="row">
                    <label for="redis_password">Redis Password</label>
                </th>
                <td>
                    <input type="password" id="redis_password" name="redis_password" 
                           value="<?php echo esc_attr($settings['redis_password'] ?? ''); ?>" 
                           class="regular-text">
                    <p class="description">
                        Redis authentication password (leave empty if not required).
                    </p>
                </td>
            </tr>
            
            <tr>
                <th scope="row">
                    <label for="redis_database">Redis Database</label>
                </th>
                <td>
                    <input type="number" id="redis_database" name="redis_database" 
                           value="<?php echo esc_attr($settings['redis_database'] ?? 0); ?>" 
                           class="small-text" min="0" max="15">
                    <p class="description">
                        Redis database number (0-15, default: 0).
                    </p>
                </td>
            </tr>
            
            <tr>
                <th scope="row">JWT Settings</th>
                <td>
                    <p class="description">
                        JWT settings are now managed in the <a href="<?php echo admin_url('admin.php?page=lem-jwt-manager'); ?>">JWT Manager</a> page.
                    </p>
                </td>
            </tr>
            
            <tr>
                <th scope="row">Test Connection</th>
                <td>
                    <button type="button" id="lem-test-redis-settings" class="button button-secondary">Test Redis Connection</button>
                    <span id="lem-redis-test-result"></span>
                    <p class="description">
                        Test the Redis connection with current settings.
                    </p>
                </td>
            </tr>
        </table>
        
        <h2>Debug Settings</h2>
        <table class="form-table">
            <tr>
                <th scope="row">
                    <label for="debug_mode">Debug Mode</label>
                </th>
                <td>
                    <label>
                        <input type="checkbox" id="debug_mode" name="debug_mode" value="1" 
                               <?php checked($settings['debug_mode'] ?? 0, 1); ?>>
                        Enable debug logging
                    </label>
                    <p class="description">
                        When enabled, detailed debug information will be logged to wp-content/debug.log
                    </p>
                </td>
            </tr>
        </table>
        

        
        <h2>Webhook URLs</h2>
        <p>Configure these URLs in your Stripe webhook settings:</p>
        
        <h3>Test Environment</h3>
        <p>Use this URL for test webhooks in Stripe Dashboard:</p>
        <code><?php echo admin_url('admin-ajax.php?action=lem_stripe_webhook'); ?></code>
        
        <h3>Live Environment</h3>
        <p>Use this URL for live webhooks in Stripe Dashboard:</p>
        <code><?php echo admin_url('admin-ajax.php?action=lem_stripe_webhook'); ?></code>
        
        <h2>Required Events</h2>
        <p>Make sure to listen for these Stripe events:</p>
        <ul>
            <li><code>checkout.session.completed</code> - Triggers JWT generation and email sending</li>
        </ul>
        

        
        <h2>Cloudflare Worker Endpoint</h2>
        <p>For JWT status checking from Cloudflare Worker:</p>
        <code><?php echo get_rest_url(null, 'lem/v1/check-jwt-status'); ?></code>
        <p>Method: POST</p>
        <p>Parameters: email, ip, playback_id</p>
        
        <p class="submit">
            <input type="submit" name="submit" id="submit" class="button button-primary" value="Save Settings">
        </p>
    </form>
</div>

<script>
jQuery(document).ready(function($) {
    // Handle mode selection
    $('#stripe_mode').on('change', function() {
        var mode = $(this).val();
        if (mode === 'test') {
            $('.lem-tab-button[data-tab="test"]').click();
        } else {
            $('.lem-tab-button[data-tab="live"]').click();
        }
    });
    
    // Handle tab switching
    $('.lem-tab-button').on('click', function() {
        var tab = $(this).data('tab');
        
        // Update button states
        $('.lem-tab-button').removeClass('active');
        $(this).addClass('active');
        
        // Update tab content
        $('.lem-tab-content').removeClass('active');
        $('#' + tab + '-tab').addClass('active');
    });
    
    // Initialize based on current mode
    var currentMode = $('#stripe_mode').val();
    if (currentMode === 'live') {
        $('.lem-tab-button[data-tab="live"]').click();
    }
    
    // Redis test button
    $('#lem-test-redis-settings').on('click', function() {
        var button = $(this);
        var result = $('#lem-redis-test-result');
        
        button.prop('disabled', true).text('Testing...');
        result.html('');
        
        $.post(lem_ajax.ajax_url, {
            action: 'lem_test_redis_connection',
            nonce: lem_ajax.nonce,
            use_redis: $('#use_redis').is(':checked') ? 1 : 0,
            redis_host: $('#redis_host').val(),
            redis_port: $('#redis_port').val(),
            redis_password: $('#redis_password').val(),
            redis_database: $('#redis_database').val()
        }, function(response) {
            if (response.success) {
                result.html('<span style="color: green;">✅ ' + response.data.message + '</span>');
            } else {
                result.html('<span style="color: red;">❌ ' + response.data + '</span>');
            }
        }).fail(function() {
            result.html('<span style="color: red;">❌ Network error</span>');
        }).always(function() {
            button.prop('disabled', false).text('Test Redis Connection');
        });
    });
});
</script>

<style>
.lem-stripe-tabs {
    margin-top: 20px;
}

.lem-tab-nav {
    margin-bottom: 20px;
}

.lem-tab-button {
    padding: 10px 20px;
    margin-right: 5px;
    border: 1px solid #ccc;
    background: #f1f1f1;
    cursor: pointer;
}

.lem-tab-button.active {
    background: #0073aa;
    color: white;
    border-color: #0073aa;
}

.lem-tab-content {
    display: none;
}

.lem-tab-content.active {
    display: block;
}
</style> 