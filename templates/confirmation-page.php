<?php
/**
 * Confirmation Page Template
 */

get_header();

global $live_event_manager;

$session_id = $_GET['session_id'] ?? '';
$event_id   = $_GET['event_id'] ?? '';
$jwt        = $_GET['jwt'] ?? '';
$jti        = $_GET['jti'] ?? '';
$email      = '';
$event      = null;

if ($live_event_manager) {
    $live_event_manager->debug_log('Confirmation page loaded', array(
        'session_id' => $session_id,
        'event_id'   => $event_id,
        'has_jwt'    => !empty($jwt),
        'has_jti'    => !empty($jti)
    ));
}

if (!empty($session_id) && empty($jwt)) {
    global $wpdb;
    $table = $wpdb->prefix . 'lem_jwt_tokens';
    $token = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$table} WHERE payment_id = %s ORDER BY created_at DESC LIMIT 1",
        $session_id
    ));

    if ($token) {
        $email = $token->email;
        $event_id = $token->event_id;
        $jti = $token->jti;
        $jwt = $token->jwt_token;
        if ($live_event_manager) {
            $live_event_manager->debug_log('Found JWT token in database', array(
                'jti' => $jti,
                'email' => $email,
                'event_id' => $event_id
            ));
        }
    } else {
        // No JWT found - check Stripe session status immediately
        if ($live_event_manager) {
            $stripe_result = $live_event_manager->check_stripe_session_immediate($session_id);
            if ($stripe_result && isset($stripe_result['jwt'])) {
                $jwt = $stripe_result['jwt'];
                $jti = $stripe_result['jti'] ?? '';
                $email = $stripe_result['email'] ?? '';
                if (empty($event_id) && isset($stripe_result['event_id'])) {
                    $event_id = $stripe_result['event_id'];
                }
                if ($live_event_manager) {
                    $live_event_manager->debug_log('Immediate Stripe check successful', array(
                        'session_id' => $session_id,
                        'jti' => $jti,
                        'email' => $email,
                        'event_id' => $event_id
                    ));
                }
            }
        }
    }
}

if (!empty($jwt) && empty($jti)) {
    $parts = explode('.', $jwt);
    if (count($parts) === 3) {
        $payload = json_decode(base64_decode(strtr($parts[1], '-_', '+/')), true);
        if ($payload && isset($payload['jti'])) {
            $jti = $payload['jti'];
        }
    }
}

if (!empty($jti)) {
    global $wpdb;
    $table = $wpdb->prefix . 'lem_jwt_tokens';
    $token = $wpdb->get_row($wpdb->prepare(
        "SELECT email, event_id FROM {$table} WHERE jti = %s",
        $jti
    ));
    if ($token) {
        $email = $token->email;
        if (empty($event_id)) {
            $event_id = $token->event_id;
        }
    }
}

if (!empty($event_id)) {
    $event_post = get_post($event_id);
    if ($event_post && $event_post->post_type === 'lem_event') {
        $event = $event_post;
    }
}

$unique_code = '';
if (!empty($jti)) {
    $unique_code = substr($jti, 0, 8);
} elseif (!empty($jwt)) {
    $unique_code = substr(md5($jwt), 0, 8);
}

$has_session_reference = !empty($session_id);
$has_token_reference   = !empty($jwt) || !empty($jti);
$pending_confirmation  = $has_session_reference && !$has_token_reference;
$has_valid_data        = $has_session_reference || $has_token_reference;
?>

<div class="lem-shell">
    <div class="lem-shell-inset">
        <div class="lem-card lem-stack">
            <div class="lem-section lem-text-center">
                <div class="lem-chip-row" style="justify-content: center;">
                    <span class="lem-chip"><?php echo $pending_confirmation ? 'Almost there' : 'Access secured'; ?></span>
                    <?php if ($event): ?>
                        <span class="lem-chip is-outline"><?php echo esc_html($event->post_title); ?></span>
                    <?php endif; ?>
                </div>

                <h1 class="lem-title"><?php echo $pending_confirmation ? 'We’re confirming your payment' : 'You’re good to go'; ?></h1>
                <p class="lem-text">
                    <?php if ($pending_confirmation): ?>
                        Stripe is finalising your payment. We’ll email the magic link the moment it clears.
                    <?php else: ?>
                        We’ve emailed your personal magic link. Keep the unique code handy in case you need to resend it later.
                    <?php endif; ?>
                </p>
            </div>

            <?php if ($event): ?>
                <div class="lem-section">
                    <h2 class="lem-heading">Event details</h2>
                    <ul class="lem-list">
                        <li><?php echo esc_html($event->post_title); ?></li>
                        <?php
                        $event_date = get_post_meta($event->ID, '_lem_event_date', true);
                        if ($event_date) {
                            echo '<li>' . esc_html(date('F j, Y \a\t g:i A', strtotime($event_date))) . '</li>';
                        }
                        ?>
                        <?php if (!empty($email)): ?>
                            <li>Registered email: <strong><?php echo esc_html($email); ?></strong></li>
                        <?php endif; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <?php if ($has_valid_data): ?>
                <?php if (!empty($jwt)): ?>
                    <div class="lem-section lem-text-center">
                        <h2 class="lem-heading">Launch the stream</h2>
                        <?php
                        $watch_event_id = $event ? $event->ID : $event_id;
                        $watch_url = $live_event_manager ? $live_event_manager->get_event_url($watch_event_id, array('token' => $jwt)) : get_permalink($watch_event_id);
                        ?>
                        <a href="<?php echo esc_url($watch_url); ?>" class="lem-button">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <polygon points="13,2 3,14 12,14 11,22 21,10 12,10 13,2"></polygon>
                            </svg>
                            Open watch page
                        </a>

                        <?php if (!empty($unique_code)): ?>
                            <div class="lem-section lem-text-center">
                                <p class="lem-text">Unique code for resend:</p>
                                <div class="lem-inline" style="justify-content: center;">
                                    <strong id="lem-unique-code" style="font-size:1.1rem; letter-spacing:0.18em;"><?php echo esc_html(strtoupper($unique_code)); ?></strong>
                                    <button class="lem-button lem-button-ghost" id="lem-copy-code" type="button">Copy code</button>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php else: ?>
                    <div class="lem-section lem-alert lem-alert-warning">
                        Still waiting on the green light from Stripe. We’ll send the magic link automatically once the payment is confirmed.
                    </div>
                <?php endif; ?>
            <?php else: ?>
                <div class="lem-section lem-alert lem-alert-warning">
                    We couldn’t find a recent payment or magic link. Double-check the URL or contact support with your receipt.
                </div>
            <?php endif; ?>

            <div class="lem-divider"></div>

            <div class="lem-section" id="lem-regenerate">
                <h2 class="lem-heading">Need to resend the link?</h2>
                <p class="lem-text">Use the email on file and the 8-character code above. We’ll send a fresh one-time link instantly.</p>
                <div class="lem-grid-min" style="max-width: 420px;">
                    <label class="lem-form-label" for="lem-regenerate-email">Email</label>
                    <input type="email" id="lem-regenerate-email" class="lem-input" placeholder="you@example.com" value="<?php echo esc_attr($email); ?>">

                    <label class="lem-form-label" for="lem-regenerate-code">Unique code</label>
                    <input type="text" id="lem-regenerate-code" class="lem-input" placeholder="First 8 characters" maxlength="8" value="<?php echo esc_attr(strtoupper($unique_code)); ?>">

                    <button type="button" class="lem-button" id="lem-regenerate-button">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <polyline points="23 4 23 10 17 10"></polyline>
                            <path d="M20.49 15A9 9 0 1 1 23 10"></path>
                        </svg>
                        Resend my link
                    </button>
                    <div id="lem-regenerate-result" class="lem-resend-message"></div>
                </div>
            </div>

            <div class="lem-divider"></div>

            <div class="lem-section">
                <h3 class="lem-heading">What&rsquo;s next?</h3>
                <ul class="lem-list">
                    <li>Check the promotions/spam tab if the email isn’t in your inbox.</li>
                    <li>The link is one-time use. Request a resend if you switch devices.</li>
                    <li>Need help? Reply to the confirmation email and we’ll jump in.</li>
                </ul>
            </div>
        </div>
    </div>
</div>

<script>
(() => {
    const copyBtn = document.getElementById('lem-copy-code');
    const codeEl = document.getElementById('lem-unique-code');
    if (copyBtn && codeEl) {
        copyBtn.addEventListener('click', () => {
            navigator.clipboard.writeText(codeEl.textContent.trim()).then(() => {
                const original = copyBtn.textContent;
                copyBtn.textContent = 'Copied!';
                setTimeout(() => copyBtn.textContent = original, 2000);
            });
        });
    }
})();

jQuery(function($) {
    const $button = $('#lem-regenerate-button');
    const ajaxConfig = (typeof lem_ajax !== 'undefined') ? lem_ajax : {
        ajax_url: '<?php echo admin_url('admin-ajax.php'); ?>',
        nonce: '<?php echo wp_create_nonce('lem_nonce'); ?>'
    };

    $button.on('click', function() {
        const email = $('#lem-regenerate-email').val().trim();
        const code  = $('#lem-regenerate-code').val().trim();
        const $result = $('#lem-regenerate-result');
        const originalHtml = $button.data('original-html') || $button.html();

        if (!$button.data('original-html')) {
            $button.data('original-html', originalHtml);
        }

        if (!email || !code) {
            $result.text('Please fill in both your email and unique code.').addClass('lem-alert-error').show();
            return;
        }

        $button.prop('disabled', true).html('Sending…');
        $result.hide();

        $.post(ajaxConfig.ajax_url, {
            action: 'lem_regenerate_jwt',
            email: email,
            code: code,
            nonce: ajaxConfig.nonce
        }).done(function(response) {
            if (response && response.success) {
                const message = response.data || 'New magic link sent to your inbox.';
                $result.removeClass('lem-alert-error').addClass('lem-alert-success').text(message).show();
            } else {
                const errorMsg = (response && response.data) ? response.data : 'Unable to resend right now. Try again shortly.';
                $result.removeClass('lem-alert-success').addClass('lem-alert-error').text(errorMsg).show();
            }
        }).fail(function() {
            $result.removeClass('lem-alert-success').addClass('lem-alert-error').text('Network error. Please try again.').show();
        }).always(function() {
            const restoreHtml = $button.data('original-html') || 'Resend my link';
            $button.prop('disabled', false).html(restoreHtml);
        });
    });
});
</script>

<?php get_footer(); ?> 