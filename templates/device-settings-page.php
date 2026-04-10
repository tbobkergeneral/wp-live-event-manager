<?php
// Device Identification Settings Page
if (!defined('ABSPATH')) exit;

$device_service = new DeviceIdentificationService();
$settings = $device_service->getSettings();

if (isset($_POST['submit_device_settings'])) {
    check_admin_referer('lem_device_settings', 'lem_device_nonce');
    
    $new_settings = array(
        'identification_method' => sanitize_text_field($_POST['identification_method'] ?? 'session_based'),
        'ip_fallback_enabled' => isset($_POST['ip_fallback_enabled']),
        'session_fallback_enabled' => isset($_POST['session_fallback_enabled']),
        'fingerprint_enabled' => isset($_POST['fingerprint_enabled']),
        'custom_token_enabled' => isset($_POST['custom_token_enabled']),
        'strict_mode' => isset($_POST['strict_mode'])
    );
    
    $device_service->updateSettings($new_settings);
    $settings = $device_service->getSettings();
    
    echo '<div class="notice notice-success"><p>Device identification settings updated successfully!</p></div>';
}
?>

<div class="wrap">
    <h1>Device Identification Settings</h1>
    <p>Configure how devices and users are identified for JWT validation and access control.</p>
    
    <form method="post" action="">
        <?php wp_nonce_field('lem_device_settings', 'lem_device_nonce'); ?>
        
        <table class="form-table">
            <tr>
                <th scope="row">
                    <label for="identification_method">Identification Method</label>
                </th>
                <td>
                    <select name="identification_method" id="identification_method">
                        <option value="session_based" <?php selected($settings['identification_method'], 'session_based'); ?>>
                            Session-Based (Recommended)
                        </option>
                        <option value="ip_address" <?php selected($settings['identification_method'], 'ip_address'); ?>>
                            IP Address (Legacy)
                        </option>
                        <option value="fingerprint" <?php selected($settings['identification_method'], 'fingerprint'); ?>>
                            Device Fingerprint (Future)
                        </option>
                        <option value="custom_token" <?php selected($settings['identification_method'], 'custom_token'); ?>>
                            Custom Token (Future)
                        </option>
                        <option value="hybrid" <?php selected($settings['identification_method'], 'hybrid'); ?>>
                            Hybrid (Multiple Methods)
                        </option>
                    </select>
                    <p class="description">
                        <strong>Session-Based (Recommended):</strong> Uses unique session IDs for device identification. Prevents link sharing and provides real-time revocation.<br>
                        <strong>IP Address (Legacy):</strong> Uses IP address for identification. Less reliable due to IP changes.
                    </p>
                </td>
            </tr>
            
            <tr>
                <th scope="row">Security Level</th>
                <td>
                    <fieldset>
                        <label>
                            <input type="checkbox" name="strict_mode" value="1" 
                                   <?php checked($settings['strict_mode']); ?>>
                            Strict Mode
                        </label>
                        <p class="description">
                            When enabled, requires exact device match. When disabled, allows fuzzy matching (e.g., same subnet for IP).
                        </p>
                    </fieldset>
                </td>
            </tr>
            
            <tr>
                <th scope="row">Fallback Options</th>
                <td>
                    <fieldset>
                        <label>
                            <input type="checkbox" name="ip_fallback_enabled" value="1" 
                                   <?php checked($settings['ip_fallback_enabled']); ?>>
                            Enable IP Fallback
                        </label>
                        <p class="description">
                            When session-based identification fails, fall back to IP address for backward compatibility.
                        </p>
                        
                        <br>
                        
                        <label>
                            <input type="checkbox" name="session_fallback_enabled" value="1" 
                                   <?php checked($settings['session_fallback_enabled'] ?? false); ?>>
                            Enable Session Fallback
                        </label>
                        <p class="description">
                            When IP-based identification fails, fall back to session-based identification.
                        </p>
                    </fieldset>
                </td>
            </tr>
            
            <tr>
                <th scope="row">Future Features</th>
                <td>
                    <fieldset>
                        <label>
                            <input type="checkbox" name="fingerprint_enabled" value="1" 
                                   <?php checked($settings['fingerprint_enabled']); ?> disabled>
                            Enable Fingerprint Support
                        </label>
                        <p class="description">
                            <em>Coming soon:</em> Device fingerprinting for privacy-focused identification.
                        </p>
                        
                        <br>
                        
                        <label>
                            <input type="checkbox" name="custom_token_enabled" value="1" 
                                   <?php checked($settings['custom_token_enabled']); ?> disabled>
                            Enable Custom Token Support
                        </label>
                        <p class="description">
                            <em>Coming soon:</em> Custom device tokens for advanced device management.
                        </p>
                    </fieldset>
                </td>
            </tr>
        </table>
        
        <h2>Current Configuration</h2>
        <table class="widefat">
            <thead>
                <tr>
                    <th>Setting</th>
                    <th>Value</th>
                    <th>Description</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td><strong>Active Method</strong></td>
                    <td><?php echo ucfirst(str_replace('_', ' ', $settings['identification_method'])); ?></td>
                    <td>Currently used identification method</td>
                </tr>
                <tr>
                    <td><strong>Security Level</strong></td>
                    <td><?php echo $settings['strict_mode'] ? 'Strict' : 'Fuzzy'; ?></td>
                    <td>How strictly devices must match</td>
                </tr>
                <tr>
                    <td><strong>IP Fallback</strong></td>
                    <td><?php echo $settings['ip_fallback_enabled'] ? 'Enabled' : 'Disabled'; ?></td>
                    <td>Fallback to IP when primary method fails</td>
                </tr>
            </tbody>
        </table>
        
        <h2>Migration Path</h2>
        <div class="notice notice-info">
            <h3>Future-Proof Design</h3>
            <p><strong>Current:</strong> Session-based identification (recommended)</p>
            <p><strong>Legacy:</strong> IP-based identification (still supported)</p>
            <p><strong>Phase 1:</strong> Add fingerprinting support (privacy-focused)</p>
            <p><strong>Phase 2:</strong> Custom token system (advanced device management)</p>
            <p><strong>Phase 3:</strong> Hybrid system (multiple identification methods)</p>
        </div>
        
        <div class="notice notice-warning">
            <h3>Session-Based Security Benefits</h3>
            <ul>
                <li><strong>Prevents Link Sharing:</strong> Each session is unique and tied to specific user</li>
                <li><strong>Device Independent:</strong> Works with VPNs, mobile networks, shared IPs</li>
                <li><strong>Privacy Focused:</strong> No sensitive data in URLs or tokens</li>
                <li><strong>Automatic Cleanup:</strong> Sessions expire after 24 hours</li>
                <li><strong>Manual Device Control:</strong> Users control when to change devices</li>
            </ul>
        </div>
        
        <p class="submit">
            <input type="submit" name="submit_device_settings" class="button-primary" value="Update Device Settings">
        </p>
    </form>
</div>

<script>
jQuery(document).ready(function($) {
    // Show/hide options based on selected method
    $('#identification_method').on('change', function() {
        var method = $(this).val();
        
        // Show relevant options based on method
        if (method === 'hybrid') {
            $('input[name="fingerprint_enabled"]').prop('disabled', false);
            $('input[name="custom_token_enabled"]').prop('disabled', false);
        } else {
            $('input[name="fingerprint_enabled"]').prop('disabled', true);
            $('input[name="custom_token_enabled"]').prop('disabled', true);
        }
    });
});
</script> 