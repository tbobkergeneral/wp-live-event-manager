<?php
/**
 * LEM Integration Layer
 *
 * Provides a clean integration layer between the existing plugin code
 * and the new modular services. This allows gradual migration without
 * breaking existing functionality.
 */

if (!defined('ABSPATH')) {
    exit;
}

require_once plugin_dir_path(__FILE__) . 'services/class-services-loader.php';

class LEM_Integration {

    private $services;

    public function __construct() {
        $this->services = LEM_Services_Loader::get_instance();
    }

    /**
     * Generate JWT token (improved version)
     */
    public function generate_jwt($email, $event_id, $payment_id = null, $is_refresh = false) {
        $result = $this->services->jwt()->generate_token($email, $event_id, $payment_id, $is_refresh);

        if (is_wp_error($result)) {
            LEM_Error_Handler::log_message('JWT generation failed', 'error', array(
                'email' => $email,
                'event_id' => $event_id,
                'error' => $result->get_error_message()
            ));
            return false;
        }

        return $result;
    }

    /**
     * Validate JWT token (improved version)
     */
    public function validate_jwt($jti) {
        $result = $this->services->jwt()->validate_token($jti);

        if (is_wp_error($result)) {
            return array(
                'valid' => false,
                'error' => $result->get_error_message(),
                'code' => $result->get_error_code()
            );
        }

        return array(
            'valid' => true,
            'token' => $result
        );
    }

    /**
     * Revoke JWT token (improved version)
     */
    public function revoke_jwt($jti) {
        $result = $this->services->jwt()->revoke_token($jti);

        if (is_wp_error($result)) {
            return false;
        }

        return true;
    }

    /**
     * Get JWT tokens for email and event
     */
    public function get_jwt_tokens($email, $event_id, $active_only = true) {
        $result = $this->services->database()->get_jwt_tokens_by_email_event($email, $event_id, $active_only);

        if (is_wp_error($result)) {
            LEM_Error_Handler::log_message('Failed to retrieve JWT tokens', 'error', array(
                'email' => $email,
                'event_id' => $event_id,
                'error' => $result->get_error_message()
            ));
            return array();
        }

        return $result;
    }

    /**
     * Store event data
     */
    public function store_event($event_data) {
        $result = $this->services->database()->store_event($event_data);

        if (is_wp_error($result)) {
            LEM_Error_Handler::log_message('Failed to store event', 'error', array(
                'event_id' => $event_data['event_id'] ?? 'unknown',
                'error' => $result->get_error_message()
            ));
            return false;
        }

        return true;
    }

    /**
     * Get event data
     */
    public function get_event($event_id) {
        $result = $this->services->database()->get_event($event_id);

        if (is_wp_error($result)) {
            return null;
        }

        return $result;
    }

    /**
     * Create session
     */
    public function create_session($session_data) {
        $result = $this->services->database()->create_session($session_data);

        if (is_wp_error($result)) {
            LEM_Error_Handler::log_message('Failed to create session', 'error', array(
                'session_id' => $session_data['session_id'] ?? 'unknown',
                'error' => $result->get_error_message()
            ));
            return false;
        }

        return isset($result[0]) ? $result[0] : $result;
    }

    /**
     * Get session
     */
    public function get_session($session_id) {
        $result = $this->services->database()->get_session($session_id);

        if (is_wp_error($result)) {
            return null;
        }

        return $result;
    }

    /**
     * Revoke session
     */
    public function revoke_session($session_id) {
        $result = $this->services->database()->revoke_session($session_id);

        if (is_wp_error($result)) {
            return false;
        }

        return true;
    }

    /**
     * Log webhook event
     */
    public function log_webhook($webhook_type, $event_type, $payload, $status = 'pending', $error_message = null) {
        $result = $this->services->database()->log_webhook($webhook_type, $event_type, $payload, $status, $error_message);

        if (is_wp_error($result)) {
            LEM_Error_Handler::log_message('Failed to log webhook', 'error', array(
                'webhook_type' => $webhook_type,
                'event_type' => $event_type,
                'error' => $result->get_error_message()
            ));
        }

        return !is_wp_error($result);
    }

    /**
     * Test Redis connection
     */
    public function test_redis() {
        return $this->services->redis()->test_connection();
    }

    /**
     * Cache data in Redis
     */
    public function cache_set($key, $value, $ttl = null) {
        return $this->services->redis()->set($key, $value, $ttl);
    }

    /**
     * Get cached data from Redis
     */
    public function cache_get($key) {
        return $this->services->redis()->get($key);
    }

    /**
     * Delete cached data from Redis
     */
    public function cache_delete($key) {
        return $this->services->redis()->delete($key);
    }

    /**
     * Validate AJAX request with improved security
     */
    public function validate_ajax_request($required_params = array(), $nonce_action = 'lem_nonce') {
        $result = LEM_Validation_Service::validate_ajax_request($required_params, $nonce_action);

        if (is_wp_error($result)) {
            LEM_Error_Handler::ajax_error($result, array(), 403);
            exit;
        }

        return $result;
    }

    /**
     * Handle AJAX error response
     */
    public function ajax_error($error, $context = array()) {
        LEM_Error_Handler::ajax_error($error, $context);
    }

    /**
     * Handle AJAX success response
     */
    public function ajax_success($data = array(), $message = null) {
        LEM_Error_Handler::ajax_success($data, $message);
    }

    /**
     * Cleanup expired tokens
     */
    public function cleanup_expired_tokens() {
        $result = $this->services->database()->cleanup_expired_tokens();

        if ($result) {
            LEM_Error_Handler::log_message('Expired tokens cleaned up', 'info');
        }

        return $result;
    }

    /**
     * Migrate existing WordPress data to Supabase
     */
    public function migrate_to_database() {
        global $wpdb;

        $table_jwts = $wpdb->prefix . 'lem_jwt_tokens';

        $tokens = $wpdb->get_results("SELECT * FROM $table_jwts LIMIT 100");

        if (empty($tokens)) {
            return array(
                'success' => true,
                'message' => 'No tokens to migrate',
                'migrated' => 0
            );
        }

        $migrated = 0;
        $errors = 0;

        foreach ($tokens as $token) {
            $token_data = array(
                'jti' => $token->jti,
                'hash_jti' => $token->hash_jti ?? '',
                'jwt_token' => $token->jwt_token ?? '',
                'email' => $token->email,
                'event_id' => $token->event_id,
                'payment_id' => $token->payment_id ?? null,
                'ip_address' => $token->ip_address ?? null,
                'session_id' => null,
                'identifier_type' => 'session_based',
                'identifier_value' => null,
                'expires_at' => $token->expires_at
            );

            $result = $this->services->database()->store_jwt_token($token_data);

            if (is_wp_error($result)) {
                $errors++;
            } else {
                $migrated++;
            }
        }

        return array(
            'success' => $errors === 0,
            'message' => "Migrated $migrated tokens, $errors errors",
            'migrated' => $migrated,
            'errors' => $errors
        );
    }
}
