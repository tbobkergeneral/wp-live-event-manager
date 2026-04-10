<?php
/**
 * Smart Event Ticket Block Template
 */

if (!defined('ABSPATH')) {
    exit;
}

$event_id      = $event->event_id;
$event_title   = $event->title;
$event_date    = $event->event_date;
$is_free       = $event->is_free;
$price_id      = $event->price_id;
$playback_id   = $event->playback_id;
$poster_url    = get_the_post_thumbnail_url($event_id, 'large') ?: '';

$event_timestamp = $event_date ? strtotime($event_date) : false;
$formatted_date  = $event_timestamp ? date('F j, Y', $event_timestamp) : '';
$formatted_time  = $event_timestamp ? date('g:i A', $event_timestamp) : '';

$price_display = '';
if ($show_price && !$is_free && !empty($price_id)) {
    $price_display = __('Paid Event', 'live-event-manager');
} elseif ($show_price && $is_free) {
    $price_display = __('Free Event', 'live-event-manager');
}

$event_access     = $event_access ?? array();
$success_message  = $event_access['success_message'] ?? '';
$error_message    = $event_access['error_message'] ?? '';
$jwt_token        = $event_access['jwt_token'] ?? '';
$session_id       = $event_access['session_id'] ?? '';
$can_watch_stream = !empty($event_access['can_watch']) && !empty($jwt_token) && !empty($playback_id);
?>

<script>
window.lemWatchContext = 'event';
window.lemWatchHasAccess = <?php echo $can_watch_stream ? 'true' : 'false'; ?>;
window.lemWatchEventId = <?php echo (int) $event_id; ?>;
<?php if ($can_watch_stream && $jwt_token): ?>
window.lemInitialJwt = <?php echo wp_json_encode($jwt_token); ?>;
<?php endif; ?>
<?php if ($session_id): ?>
window.lemSessionId = <?php echo wp_json_encode($session_id); ?>;
<?php endif; ?>
</script>

<div class="lem-event-ticket-block lem-theme-<?php echo esc_attr($theme); ?> lem-size-<?php echo esc_attr($size); ?>" data-event-id="<?php echo esc_attr($event_id); ?>">
    <?php if ($show_event_details): ?>
        <div class="lem-event-info">
            <h3 class="lem-event-title"><?php echo esc_html($event_title); ?></h3>
            <?php if ($formatted_date): ?>
                <p class="lem-event-date"><?php echo esc_html($formatted_date . ' · ' . $formatted_time); ?></p>
            <?php endif; ?>
            <?php if (!empty($price_display)): ?>
                <div class="lem-event-price">
                    <?php if ($is_free): ?>
                        <span class="lem-price-free"><?php echo esc_html($price_display); ?></span>
                    <?php else: ?>
                        <span class="lem-price-paid"><?php echo esc_html($price_display); ?></span>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <?php if (!empty($success_message)): ?>
        <div class="lem-message lem-message-success"><?php echo esc_html($success_message); ?></div>
    <?php endif; ?>

    <?php if (!empty($error_message)): ?>
        <div class="lem-message lem-message-error"><?php echo esc_html($error_message); ?></div>
    <?php endif; ?>

    <?php if ($can_watch_stream): ?>
        <div class="lem-section lem-text-center">
            <h1><?php echo esc_html($event_title); ?></h1>
            <p class="lem-lede"><?php esc_html_e('You have access. Press play to join the stream.', 'live-event-manager'); ?></p>
        </div>

        <div class="lem-section lem-video">
            <mux-player
                playback-id="<?php echo esc_attr($playback_id); ?>"
                playback-token="<?php echo esc_attr($jwt_token); ?>"
                <?php echo $poster_url ? 'poster="' . esc_url($poster_url) . '"' : ''; ?>
                metadata-video-title="<?php echo esc_attr($event_title); ?>"
                metadata-viewer-user-id="<?php echo esc_attr($event_id); ?>"
                accent-color="#7f5af0"
                style="width:100%;max-height:70vh;"
            ></mux-player>
        </div>

        <div class="lem-section lem-stack">
            <h2><?php esc_html_e('Need a new link?', 'live-event-manager'); ?></h2>
            <p><?php esc_html_e('Switching devices or lost the email? Reissue your magic link in seconds.', 'live-event-manager'); ?></p>
            <form method="post" class="lem-form" style="max-width: 420px; margin:0 auto;">
                <?php wp_nonce_field('lem_request_new_link', 'lem_new_link_nonce'); ?>
                <label for="lem_email_<?php echo esc_attr($event_id); ?>" class="lem-form-label"><?php esc_html_e('Email address', 'live-event-manager'); ?></label>
                <input type="email" id="lem_email_<?php echo esc_attr($event_id); ?>" name="email" class="lem-input" placeholder="you@example.com" required>
                <input type="hidden" name="lem_event_id" value="<?php echo esc_attr($event_id); ?>">
                <p><button type="submit" name="lem_request_new_link" class="lem-button lem-button-primary"><?php esc_html_e('Send me a fresh link', 'live-event-manager'); ?></button></p>
            </form>
        </div>

        <div class="lem-section lem-text-center">
            <a href="<?php echo esc_url(home_url('/events')); ?>" class="lem-button lem-button-secondary"><?php esc_html_e('Explore more events', 'live-event-manager'); ?></a>
        </div>
    <?php else: ?>
        <div class="lem-ticket-form">
            <div class="lem-email-input-group">
                <input type="email" id="lem-email-<?php echo esc_attr($event_id); ?>" class="lem-email-input" placeholder="<?php echo esc_attr($email_placeholder); ?>" required>
                <div class="lem-email-error" style="display:none;"></div>
            </div>
            <button type="button" class="lem-ticket-button lem-button-primary" data-event-id="<?php echo esc_attr($event_id); ?>" data-is-free="<?php echo $is_free ? 'true' : 'false'; ?>" data-price-id="<?php echo esc_attr($price_id); ?>" data-button-text="<?php echo esc_attr($button_text); ?>">
                <span class="lem-button-text"><?php echo esc_html($button_text); ?></span>
                <span class="lem-button-loading" style="display:none;"><svg class="lem-spinner" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="2" fill="none" stroke-linecap="round"></circle></svg></span>
            </button>
            <?php if (!$is_free): ?>
                <div class="lem-payment-methods">
                    <div class="lem-payment-icons">
                        <svg class="lem-payment-icon" viewBox="0 0 24 24"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-2 15l-5-5 1.41-1.41L10 14.17l7.59-7.59L19 8l-9 9z"/></svg>
                        <span><?php esc_html_e('Secure payment powered by Stripe', 'live-event-manager'); ?></span>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <div class="lem-message-container" style="display:none;"></div>

        <div class="lem-divider"></div>

        <div class="lem-section lem-stack" style="max-width:420px;margin:0 auto;">
            <h3><?php esc_html_e('Already purchased?', 'live-event-manager'); ?></h3>
            <p><?php esc_html_e('Resend your magic link using the email on file.', 'live-event-manager'); ?></p>
            <form method="post" class="lem-form">
                <?php wp_nonce_field('lem_request_new_link', 'lem_new_link_nonce'); ?>
                <label for="lem-resend-email-<?php echo esc_attr($event_id); ?>" class="lem-form-label"><?php esc_html_e('Email address', 'live-event-manager'); ?></label>
                <input type="email" id="lem-resend-email-<?php echo esc_attr($event_id); ?>" name="email" class="lem-input" placeholder="you@example.com" required>
                <input type="hidden" name="lem_event_id" value="<?php echo esc_attr($event_id); ?>">
                <p><button type="submit" name="lem_request_new_link" class="lem-button lem-button-secondary"><?php esc_html_e('Send magic link', 'live-event-manager'); ?></button></p>
            </form>
        </div>
    <?php endif; ?>
</div>

<script>
jQuery(document).ready(function($) {
    function showMessage(container, message, type) {
        const classes = ['lem-message-success', 'lem-message-error'];
        container.removeClass(classes.join(' '));
        container.addClass(type === 'error' ? 'lem-message-error' : 'lem-message-success');
        container.text(message).fadeIn(200);
    }

    function isValidEmail(email) {
        const regex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
        return regex.test(email);
    }

    $('.lem-ticket-button').on('click', function() {
        const button = $(this);
        const eventId = button.data('event-id');
        const isFree = button.data('is-free') === 'true';
        const priceId = button.data('price-id');
        const emailInput = $('#lem-email-' + eventId);
        const email = emailInput.val().trim();
        const messageContainer = button.closest('.lem-event-ticket-block').find('.lem-message-container');

        if (!email || !isValidEmail(email)) {
            messageContainer.show();
            showMessage(messageContainer, '<?php echo esc_js(__('Please enter a valid email address.', 'live-event-manager')); ?>', 'error');
            emailInput.addClass('lem-error');
            emailInput.focus();
            return;
        }

        emailInput.removeClass('lem-error');
        messageContainer.hide();

        button.prop('disabled', true);
        button.find('.lem-button-text').hide();
        button.find('.lem-button-loading').show();

        if (isFree) {
            $.ajax({
                url: lem_ajax.ajax_url,
                type: 'POST',
                data: {
                    action: 'lem_generate_jwt',
                    email: email,
                    event_id: eventId,
                    nonce: lem_ajax.nonce
                },
                success: function(response) {
                    if (response.success) {
                        const message = response.data && response.data.message ? response.data.message : 'Access granted! Check your email for the magic link.';
                        showMessage(messageContainer, message, 'success');
                        
                        // Clear email input
                        emailInput.val('');
                        
                        // If watch URL is provided, redirect after a short delay
                        if (response.data && response.data.watch_url) {
                            setTimeout(function() {
                                window.location.href = response.data.watch_url;
                            }, 2000);
                        }
                    } else {
                        const message = response.data && response.data.message ? response.data.message : response.data;
                        showMessage(messageContainer, message || '<?php echo esc_js(__('Unable to generate access. Please try again.', 'live-event-manager')); ?>', 'error');
                    }
                },
                error: function() {
                    showMessage(messageContainer, '<?php echo esc_js(__('Network error. Please try again.', 'live-event-manager')); ?>', 'error');
                },
                complete: function() {
                    button.prop('disabled', false);
                    button.find('.lem-button-loading').hide();
                    button.find('.lem-button-text').show();
                }
            });
        } else {
            // Only require price ID for paid events (isFree is already declared above)
            if (!isFree && !priceId) {
                showMessage(messageContainer, '<?php echo esc_js(__('Price ID not configured. Please contact support.', 'live-event-manager')); ?>', 'error');
                button.prop('disabled', false);
                button.find('.lem-button-loading').hide();
                button.find('.lem-button-text').show();
                return;
            }

            $.ajax({
                url: lem_ajax.ajax_url,
                type: 'POST',
                data: {
                    action: 'lem_create_stripe_session',
                    event_id: eventId,
                    email: email,
                    price_id: priceId,
                    nonce: lem_ajax.nonce
                },
                success: function(response) {
                    if (response.success && response.data && response.data.checkout_url) {
                        window.location.href = response.data.checkout_url;
                    } else {
                        const message = response.data && response.data.message ? response.data.message : response.data;
                        showMessage(messageContainer, message || '<?php echo esc_js(__('Unable to start Stripe checkout. Please try again.', 'live-event-manager')); ?>', 'error');
                    }
                },
                error: function() {
                    showMessage(messageContainer, '<?php echo esc_js(__('Network error. Please try again.', 'live-event-manager')); ?>', 'error');
                },
                complete: function() {
                    button.prop('disabled', false);
                    button.find('.lem-button-loading').hide();
                    button.find('.lem-button-text').show();
                }
            });
        }
    });
});
</script> 