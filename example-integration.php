<?php
/**
 * Example Integration
 *
 * This file demonstrates how to integrate the new services into
 * the existing Live Event Manager plugin code.
 */

if (!defined('ABSPATH')) {
    exit;
}

require_once plugin_dir_path(__FILE__) . 'class-lem-integration.php';

/**
 * Example 1: Improved AJAX Handler for JWT Generation
 *
 * Before: Direct implementation with minimal validation
 * After: Using integration layer with comprehensive validation and error handling
 */
function example_ajax_generate_jwt_improved() {
    $integration = new LEM_Integration();

    $validated_data = $integration->validate_ajax_request(array(
        'email' => 'email',
        'event_id' => 'event_id'
    ));

    $payment_id = isset($_POST['payment_id']) ? sanitize_text_field($_POST['payment_id']) : null;

    $result = $integration->generate_jwt(
        $validated_data['email'],
        $validated_data['event_id'],
        $payment_id
    );

    if ($result === false || is_wp_error($result)) {
        $integration->ajax_error(
            $result ?: 'Failed to generate JWT',
            array('email' => $validated_data['email'])
        );
        return;
    }

    $integration->ajax_success(array(
        'jwt' => $result['jwt'],
        'session_id' => $result['session_id'],
        'jti' => $result['jti'],
        'expires_at' => $result['expires_at']
    ), 'JWT generated successfully');
}

/**
 * Example 2: Improved JWT Validation
 *
 * Before: Complex validation logic in main file
 * After: Clean validation using service layer
 */
function example_validate_jwt_improved($jti) {
    $integration = new LEM_Integration();

    $result = $integration->validate_jwt($jti);

    if (!$result['valid']) {
        return array(
            'valid' => false,
            'error' => $result['error'],
            'code' => $result['code']
        );
    }

    return array(
        'valid' => true,
        'token_data' => $result['token']
    );
}

/**
 * Example 3: Improved Stripe Webhook Handler
 *
 * Before: Inline error handling, no structured logging
 * After: Proper error handling and webhook logging
 */
function example_stripe_webhook_improved() {
    $integration = new LEM_Integration();

    $payload = @file_get_contents('php://input');
    $sig_header = isset($_SERVER['HTTP_STRIPE_SIGNATURE']) ? $_SERVER['HTTP_STRIPE_SIGNATURE'] : '';

    $settings = get_option('lem_settings', array());
    $webhook_secret = $settings['stripe_mode'] === 'live'
        ? $settings['stripe_live_webhook_secret']
        : $settings['stripe_test_webhook_secret'];

    if (empty($webhook_secret)) {
        $integration->log_webhook('stripe', 'unknown', array(), 'failed', 'Webhook secret not configured');
        http_response_code(400);
        exit('Webhook secret not configured');
    }

    try {
        if (!class_exists('\Stripe\Webhook')) {
            throw new Exception('Stripe library not available');
        }

        $event = \Stripe\Webhook::constructEvent($payload, $sig_header, $webhook_secret);

        $integration->log_webhook('stripe', $event->type, $event->data->object, 'processing');

        if ($event->type === 'checkout.session.completed') {
            $session = $event->data->object;
            $event_id = $session->metadata->event_id ?? null;
            $email = $session->customer_details->email ?? null;

            if ($event_id && $email) {
                $jwt_result = $integration->generate_jwt($email, $event_id, $session->id);

                if ($jwt_result !== false && !is_wp_error($jwt_result)) {
                    $integration->log_webhook('stripe', $event->type, $event->data->object, 'success');

                    LEM_Error_Handler::log_message('Stripe payment processed', 'info', array(
                        'session_id' => $session->id,
                        'event_id' => $event_id,
                        'email' => $email
                    ));
                } else {
                    $integration->log_webhook('stripe', $event->type, $event->data->object, 'failed', 'JWT generation failed');
                }
            } else {
                $integration->log_webhook('stripe', $event->type, $event->data->object, 'failed', 'Missing event_id or email');
            }
        } else {
            $integration->log_webhook('stripe', $event->type, $event->data->object, 'success');
        }

        http_response_code(200);
        exit('Webhook processed');

    } catch (\Stripe\Exception\SignatureVerificationException $e) {
        $integration->log_webhook('stripe', 'unknown', array(), 'failed', 'Invalid signature: ' . $e->getMessage());
        http_response_code(400);
        exit('Invalid signature');
    } catch (Exception $e) {
        $integration->log_webhook('stripe', 'unknown', array(), 'failed', $e->getMessage());
        http_response_code(500);
        exit('Webhook processing failed');
    }
}

/**
 * Example 4: Event Sync on Post Save
 *
 * Before: Only stored in WordPress
 * After: Automatically synced to Supabase
 */
function example_sync_event_to_supabase($post_id) {
    if (get_post_type($post_id) !== 'lem_event') {
        return;
    }

    $integration = new LEM_Integration();

    $event_data = array(
        'event_id' => (string)$post_id,
        'title' => get_the_title($post_id),
        'playback_id' => get_post_meta($post_id, '_lem_playback_id', true),
        'playback_restriction_id' => get_post_meta($post_id, '_lem_playback_restriction_id', true),
        'event_date' => get_post_meta($post_id, '_lem_event_date', true),
        'is_free' => get_post_meta($post_id, '_lem_is_free', true) === 'free',
        'price_id' => get_post_meta($post_id, '_lem_price_id', true),
        'display_price' => get_post_meta($post_id, '_lem_display_price', true),
        'status' => get_post_meta($post_id, '_lem_status', true),
        'metadata' => array(
            'excerpt' => get_post_meta($post_id, '_lem_excerpt', true)
        )
    );

    $result = $integration->store_event($event_data);

    if ($result) {
        LEM_Error_Handler::log_message('Event synced to Supabase', 'info', array(
            'event_id' => $post_id
        ));
    }
}

/**
 * Example 5: Session Management
 *
 * Before: Basic session tracking
 * After: Comprehensive session management with revocation
 */
function example_revoke_user_session($session_id) {
    $session_id_validated = LEM_Validation_Service::validate_session_id($session_id);

    if (is_wp_error($session_id_validated)) {
        return array(
            'success' => false,
            'error' => $session_id_validated->get_error_message()
        );
    }

    $integration = new LEM_Integration();

    $result = $integration->revoke_session($session_id_validated);

    if ($result) {
        LEM_Error_Handler::log_message('Session revoked', 'info', array(
            'session_id' => $session_id_validated
        ));

        return array(
            'success' => true,
            'message' => 'Session revoked successfully'
        );
    }

    return array(
        'success' => false,
        'error' => 'Failed to revoke session'
    );
}

/**
 * Example 6: Cron Job for Cleanup
 *
 * Before: Manual cleanup or none
 * After: Automated cleanup via cron
 */
function example_schedule_cleanup() {
    if (!wp_next_scheduled('lem_cleanup_expired_tokens')) {
        wp_schedule_event(time(), 'daily', 'lem_cleanup_expired_tokens');
    }
}

function example_cleanup_expired_tokens() {
    $integration = new LEM_Integration();
    $result = $integration->cleanup_expired_tokens();

    LEM_Error_Handler::log_message('Cleanup job completed', 'info', array(
        'success' => $result
    ));
}

add_action('lem_cleanup_expired_tokens', 'example_cleanup_expired_tokens');

/**
 * Example 7: Admin Page with Validation
 *
 * Before: Minimal validation
 * After: Comprehensive validation and error handling
 */
function example_admin_save_settings() {
    if (!current_user_can('manage_options')) {
        wp_die('Unauthorized');
    }

    $nonce = isset($_POST['_wpnonce']) ? $_POST['_wpnonce'] : '';
    $nonce_validated = LEM_Validation_Service::validate_nonce($nonce, 'lem_save_settings');

    if (is_wp_error($nonce_validated)) {
        LEM_Error_Handler::ajax_error($nonce_validated);
        return;
    }

    $redis_host = isset($_POST['redis_host']) ? sanitize_text_field($_POST['redis_host']) : '127.0.0.1';
    $redis_port = isset($_POST['redis_port']) ? absint($_POST['redis_port']) : 6379;
    $use_redis = isset($_POST['use_redis']) ? LEM_Validation_Service::validate_boolean($_POST['use_redis']) : false;

    $settings = array(
        'redis_host' => $redis_host,
        'redis_port' => $redis_port,
        'use_redis' => $use_redis
    );

    update_option('lem_settings', array_merge(get_option('lem_settings', array()), $settings));

    LEM_Error_Handler::log_message('Settings saved', 'info', array(
        'redis_host' => $redis_host,
        'use_redis' => $use_redis
    ));

    wp_redirect(admin_url('admin.php?page=lem-settings&updated=1'));
    exit;
}

/**
 * Example 8: REST API Endpoint
 *
 * Before: Custom implementation
 * After: Using validation and error handling services
 */
function example_register_rest_endpoint() {
    register_rest_route('lem/v1', '/validate-jwt', array(
        'methods' => 'POST',
        'callback' => 'example_rest_validate_jwt',
        'permission_callback' => '__return_true'
    ));
}

function example_rest_validate_jwt($request) {
    $jti = $request->get_param('jti');

    $jti_validated = LEM_Validation_Service::validate_jti($jti);

    if (is_wp_error($jti_validated)) {
        return new WP_REST_Response(
            LEM_Error_Handler::handle($jti_validated),
            400
        );
    }

    $integration = new LEM_Integration();
    $result = $integration->validate_jwt($jti_validated);

    if (!$result['valid']) {
        return new WP_REST_Response(
            array('valid' => false, 'error' => $result['error']),
            403
        );
    }

    return new WP_REST_Response(
        array('valid' => true, 'token' => $result['token']),
        200
    );
}

add_action('rest_api_init', 'example_register_rest_endpoint');
