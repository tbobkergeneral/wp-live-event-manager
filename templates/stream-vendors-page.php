<?php
/**
 * Stream Vendors Page - Configure Mux credentials
 * Future: Can be extended to support additional streaming providers
 */

if (!defined('ABSPATH')) {
    exit;
}

$settings = get_option('lem_settings', array());

if (isset($_POST['submit'])) {
    $updated_settings = $settings;
    
    // Mux settings
    $updated_settings['mux_key_id'] = sanitize_text_field($_POST['mux_key_id'] ?? '');
    $updated_settings['mux_private_key'] = sanitize_textarea_field($_POST['mux_private_key'] ?? '');
    $updated_settings['mux_token_id'] = sanitize_text_field($_POST['mux_token_id'] ?? '');
    $updated_settings['mux_token_secret'] = sanitize_text_field($_POST['mux_token_secret'] ?? '');
    $updated_settings['mux_webhook_secret'] = sanitize_text_field($_POST['mux_webhook_secret'] ?? '');
    
    update_option('lem_settings', $updated_settings);
    $settings = $updated_settings;
    
    echo '<div class="notice notice-success"><p>Stream vendor settings saved successfully!</p></div>';
}
?>

<div class="wrap">
    <h1>Stream Vendors</h1>
    <p class="description">Configure your streaming provider credentials. Currently supports Mux. Additional providers can be added in the future.</p>
    
    <form method="post" action="">
        <div class="lem-vendor-tabs">
            <!-- Mux Configuration -->
            <div id="mux-tab" class="lem-tab-content active">
                <h2>Mux Configuration</h2>
                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="mux_key_id">Mux Signing Key ID</label>
                        </th>
                        <td>
                            <input type="text" id="mux_key_id" name="mux_key_id" 
                                   value="<?php echo esc_attr($settings['mux_key_id'] ?? ''); ?>" 
                                   class="regular-text">
                            <p class="description">
                                Your Mux signing key ID from the Mux Dashboard.
                            </p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">
                            <label for="mux_private_key">Mux Private Key (Base64)</label>
                        </th>
                        <td>
                            <textarea id="mux_private_key" name="mux_private_key" rows="4" 
                                      class="large-text"><?php echo esc_textarea($settings['mux_private_key'] ?? ''); ?></textarea>
                            <p class="description">
                                Your Mux private key in base64 format from the Mux Dashboard.
                            </p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">
                            <label for="mux_token_id">Mux API Token ID</label>
                        </th>
                        <td>
                            <input type="text" id="mux_token_id" name="mux_token_id" 
                                   value="<?php echo esc_attr($settings['mux_token_id'] ?? ''); ?>" 
                                   class="regular-text">
                            <p class="description">
                                Your Mux API token ID for managing streams and playback restrictions.
                            </p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">
                            <label for="mux_token_secret">Mux API Token Secret</label>
                        </th>
                        <td>
                            <input type="password" id="mux_token_secret" name="mux_token_secret" 
                                   value="<?php echo esc_attr($settings['mux_token_secret'] ?? ''); ?>" 
                                   class="regular-text">
                            <p class="description">
                                Your Mux API token secret for managing streams and playback restrictions.
                            </p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">
                            <label for="mux_webhook_secret">Mux Webhook Secret (Optional)</label>
                        </th>
                        <td>
                            <input type="password" id="mux_webhook_secret" name="mux_webhook_secret" 
                                   value="<?php echo esc_attr($settings['mux_webhook_secret'] ?? ''); ?>" 
                                   class="regular-text">
                            <p class="description">
                                Optional webhook secret for verifying Mux webhook signatures. Leave empty to disable signature verification.
                            </p>
                        </td>
                    </tr>
                </table>
                
                <h3>Mux Webhook Configuration</h3>
                <p>Configure this URL in your Mux Dashboard webhook settings:</p>
                <code><?php echo admin_url('admin-ajax.php?action=lem_mux_webhook'); ?></code>
                <p class="description">
                    Make sure to listen for the <code>video.asset.ready</code> event in your Mux webhook configuration.
                </p>
            </div>
        </div>
        
        <p class="submit">
            <input type="submit" name="submit" id="submit" class="button button-primary" value="Save Settings">
        </p>
    </form>
</div>


<style>
.lem-vendor-tabs {
    margin-top: 20px;
}

.lem-tab-nav {
    margin-bottom: 20px;
    border-bottom: 1px solid #ccc;
}

.lem-tab-button {
    padding: 10px 20px;
    margin-right: 5px;
    border: 1px solid #ccc;
    border-bottom: none;
    background: #f1f1f1;
    cursor: pointer;
    position: relative;
    top: 1px;
}

.lem-tab-button.active {
    background: #fff;
    border-bottom: 1px solid #fff;
    font-weight: 600;
}

.lem-tab-content {
    display: none;
    padding: 20px 0;
}

.lem-tab-content.active {
    display: block;
}
</style>
