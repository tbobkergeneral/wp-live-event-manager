<?php
/**
 * Device Swap Form Template
 * Allows users to request new magic links for device swapping
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Get current event data
$event_id = $_GET['event_id'] ?? null;
$current_email = $_GET['email'] ?? '';
$error_message = '';
$success_message = '';

// Handle form submission
if ($_POST && isset($_POST['lem_device_swap'])) {
    if (!isset($_POST['lem_device_swap_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['lem_device_swap_nonce'])), 'lem_device_swap')) {
        $error_message = 'Security check failed. Please try again.';
    } else {

    $email = sanitize_email($_POST['email'] ?? '');
    $event_id = sanitize_text_field($_POST['event_id'] ?? '');
    
    if (empty($email) || empty($event_id)) {
        $error_message = 'Please provide both email and event ID.';
    } else {
        global $live_event_manager;
        
        if ($live_event_manager) {
            $result = $live_event_manager->validate_email_and_send_link($email, $event_id);
            
            if ($result['valid']) {
                $success_message = 'New access link sent to your email. Previous sessions have been revoked for security.';
            } else {
                $error_message = $result['error'] ?? 'Failed to send new access link.';
            }
        } else {
            $error_message = 'Plugin not available. Please contact support.';
        }
    }
    } // end else (nonce valid)
}

// Get event details if event_id is provided
$event_data = null;
if ($event_id) {
    $event = get_post($event_id);
    if ($event && $event->post_type === 'lem_event') {
        $event_data = (object) array(
            'event_id' => $event->ID,
            'title' => $event->post_title,
            'is_free' => get_post_meta($event->ID, '_lem_is_free', true) === 'free'
        );
    }
}
?>

<div class="lem-device-swap-container">
    <div class="lem-device-swap-header">
        <div class="lem-device-swap-icon">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <rect x="2" y="3" width="20" height="14" rx="2" ry="2"></rect>
                <line x1="8" y1="21" x2="16" y2="21"></line>
                <line x1="12" y1="17" x2="12" y2="21"></line>
            </svg>
        </div>
        <h2>Switch to New Device</h2>
        <p>Request a new access link to watch on a different device. Your current session will be automatically revoked for security.</p>
    </div>

    <?php if (!empty($error_message)): ?>
        <div class="lem-error-message">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <circle cx="12" cy="12" r="10"></circle>
                <line x1="15" y1="9" x2="9" y2="15"></line>
                <line x1="9" y1="9" x2="15" y2="15"></line>
            </svg>
            <?php echo esc_html($error_message); ?>
        </div>
        
        <?php if (strpos($error_message, 'already used') !== false || strpos($error_message, 'expired') !== false): ?>
            <div class="lem-token-help">
                <h3>Need a New Magic Link?</h3>
                <p>If your magic link has expired or been used, you can request a new one using the form below.</p>
            </div>
        <?php endif; ?>
    <?php endif; ?>

    <?php if (!empty($success_message)): ?>
        <div class="lem-success-message">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path>
                <polyline points="22,4 12,14.01 9,11.01"></polyline>
            </svg>
            <?php echo esc_html($success_message); ?>
        </div>
    <?php endif; ?>

    <form method="post" class="lem-device-swap-form">
        <?php wp_nonce_field('lem_device_swap', 'lem_device_swap_nonce'); ?>
        <?php wp_nonce_field('lem_device_swap', 'lem_device_swap_nonce'); ?>
        <input type="hidden" name="lem_device_swap" value="1">
        
        <div class="lem-form-group">
            <label for="lem_email">Email Address</label>
            <input type="email" id="lem_email" name="email" value="<?php echo esc_attr($current_email); ?>" required>
            <small>Enter the email address associated with your ticket</small>
        </div>

        <?php if ($event_data): ?>
            <input type="hidden" name="event_id" value="<?php echo esc_attr($event_data->event_id); ?>">
            <div class="lem-event-info">
                <h3>Event: <?php echo esc_html($event_data->title); ?></h3>
                <span class="lem-event-type <?php echo $event_data->is_free ? 'lem-free' : 'lem-paid'; ?>">
                    <?php echo $event_data->is_free ? 'Free Event' : 'Paid Event'; ?>
                </span>
            </div>
        <?php else: ?>
            <div class="lem-form-group">
                <label for="lem_event_id">Event ID</label>
                <input type="text" id="lem_event_id" name="event_id" value="<?php echo esc_attr($event_id); ?>" required>
                <small>Enter the event ID you have a ticket for</small>
            </div>
        <?php endif; ?>

        <div class="lem-form-actions">
            <button type="submit" class="lem-submit-btn">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"></path>
                    <polyline points="22,6 12,13 2,6"></polyline>
                </svg>
                Send New Access Link
            </button>
        </div>
    </form>

    <div class="lem-device-swap-info">
        <h3>How it works:</h3>
        <ul>
            <li>Enter the email address associated with your ticket</li>
            <li>We'll verify your ticket and send a new magic link</li>
            <li>Your current session will be automatically revoked</li>
            <li>Click the new link in your email to access on the new device</li>
            <li>Each link is one-time use only for security</li>
        </ul>
    </div>
</div>

<style>
.lem-device-swap-container {
    max-width: 600px;
    margin: 2rem auto;
    padding: 2rem;
    background: #fff;
    border-radius: 12px;
    box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
}

.lem-device-swap-header {
    text-align: center;
    margin-bottom: 2rem;
}

.lem-device-swap-icon {
    width: 64px;
    height: 64px;
    margin: 0 auto 1rem;
    color: #667eea;
}

.lem-device-swap-icon svg {
    width: 100%;
    height: 100%;
}

.lem-device-swap-header h2 {
    margin: 0 0 0.5rem;
    color: #2d3748;
}

.lem-device-swap-header p {
    color: #718096;
    margin: 0;
}

.lem-error-message,
.lem-success-message {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    padding: 1rem;
    border-radius: 8px;
    margin-bottom: 1.5rem;
}

.lem-error-message {
    background: #fed7d7;
    color: #c53030;
    border: 1px solid #feb2b2;
}

.lem-success-message {
    background: #c6f6d5;
    color: #2f855a;
    border: 1px solid #9ae6b4;
}

.lem-error-message svg,
.lem-success-message svg {
    width: 20px;
    height: 20px;
    flex-shrink: 0;
}

.lem-device-swap-form {
    margin-bottom: 2rem;
}

.lem-form-group {
    margin-bottom: 1.5rem;
}

.lem-form-group label {
    display: block;
    margin-bottom: 0.5rem;
    font-weight: 600;
    color: #2d3748;
}

.lem-form-group input {
    width: 100%;
    padding: 0.75rem;
    border: 2px solid #e2e8f0;
    border-radius: 8px;
    font-size: 1rem;
    transition: border-color 0.2s;
}

.lem-form-group input:focus {
    outline: none;
    border-color: #667eea;
}

.lem-form-group small {
    display: block;
    margin-top: 0.25rem;
    color: #718096;
    font-size: 0.875rem;
}

.lem-event-info {
    background: #f7fafc;
    padding: 1rem;
    border-radius: 8px;
    margin-bottom: 1.5rem;
    border-left: 4px solid #667eea;
}

.lem-event-info h3 {
    margin: 0 0 0.5rem;
    color: #2d3748;
}

.lem-event-type {
    display: inline-block;
    padding: 0.25rem 0.75rem;
    border-radius: 9999px;
    font-size: 0.875rem;
    font-weight: 600;
}

.lem-event-type.lem-free {
    background: #c6f6d5;
    color: #2f855a;
}

.lem-event-type.lem-paid {
    background: #fed7d7;
    color: #c53030;
}

.lem-form-actions {
    text-align: center;
}

.lem-submit-btn {
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    padding: 0.75rem 2rem;
    background: #667eea;
    color: white;
    border: none;
    border-radius: 8px;
    font-size: 1rem;
    font-weight: 600;
    cursor: pointer;
    transition: background-color 0.2s;
}

.lem-submit-btn:hover {
    background: #5a67d8;
}

.lem-submit-btn svg {
    width: 20px;
    height: 20px;
}

.lem-device-swap-info {
    background: #f7fafc;
    padding: 1.5rem;
    border-radius: 8px;
    border: 1px solid #e2e8f0;
}

.lem-device-swap-info h3 {
    margin: 0 0 1rem;
    color: #2d3748;
}

.lem-device-swap-info ul {
    margin: 0;
    padding-left: 1.5rem;
    color: #4a5568;
}

.lem-device-swap-info li {
    margin-bottom: 0.5rem;
}

.lem-device-swap-info li:last-child {
    margin-bottom: 0;
}
</style> 