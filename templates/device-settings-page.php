<?php
// Device Identification Settings Page
if (!defined('ABSPATH')) exit;

$device_service = new DeviceIdentificationService();
$settings = array_merge([
    'identification_method' => 'session_based',
    'max_devices'           => (int)(get_option('lem_device_settings', [])['max_devices'] ?? 1),
], (array) $device_service->getSettings());

if (isset($_POST['submit_device_settings'])) {
    check_admin_referer('lem_device_settings', 'lem_device_nonce');

    $max_devices = max(1, min(20, (int)($_POST['max_devices'] ?? 1)));

    $new_settings = array(
        'identification_method' => 'session_based', // always session-based
        'max_devices'           => $max_devices,
    );

    $device_service->updateSettings($new_settings);
    $settings = $device_service->getSettings();

    // Also persist max_devices in the dedicated option read by create_session()
    $existing = get_option('lem_device_settings', array());
    update_option('lem_device_settings', array_merge($existing, array('max_devices' => $max_devices)));

    echo '<div class="notice notice-success"><p>Device identification settings updated successfully!</p></div>';
}
?>

<div class="wrap">
    <h1>Devices</h1>
    <p>Configure how devices and users are identified for JWT validation and access control.</p>
    
    <form method="post" action="">
        <?php wp_nonce_field('lem_device_settings', 'lem_device_nonce'); ?>
        
        <table class="form-table">
            <tr>
                <th scope="row">Device Locking</th>
                <td>
                    <p>
                        Access is locked to the <strong>browser session</strong> that activated the magic link.
                        The <code>lem_session_id</code> cookie is <code>HttpOnly</code> — JavaScript cannot read
                        or copy it, so it cannot be shared between browsers or devices.
                    </p>
                    <p class="description">
                        If a viewer opens their magic link on a second device, the first session is
                        automatically revoked and they must request a new link to regain access.
                        No IP address tracking is used.
                    </p>
                    <!-- Hidden field keeps the value consistent for any code still reading it -->
                    <input type="hidden" name="identification_method" value="session_based">
                </td>
            </tr>

            <tr>
                <th scope="row">Max Devices Per Ticket</th>
                <td>
                    <input
                        type="number"
                        name="max_devices"
                        id="max_devices"
                        value="<?php echo esc_attr((int)($settings['max_devices'] ?? get_option('lem_device_settings', [])['max_devices'] ?? 1)); ?>"
                        min="1"
                        max="20"
                        step="1"
                        style="width:80px;"
                    >
                    <p class="description">
                        How many devices (browser sessions) may watch simultaneously with the same ticket.<br>
                        <strong>1 (default):</strong> strict one-device mode — using a new magic link on a second
                        device immediately revokes the first.<br>
                        <strong>2–5:</strong> useful for households where family members watch on separate screens.<br>
                        When the limit is reached, the oldest device is automatically logged out to make room for
                        the newest one.
                    </p>
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
                    <td><?php echo ucfirst(str_replace('_', ' ', $settings['identification_method'] ?? 'session_based')); ?></td>
                    <td>Session cookie (HttpOnly) — JavaScript cannot read or share it</td>
                </tr>
                <tr>
                    <td><strong>Max Devices</strong></td>
                    <td><?php echo (int)(get_option('lem_device_settings', [])['max_devices'] ?? 1); ?></td>
                    <td>Simultaneous active sessions allowed per ticket</td>
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

