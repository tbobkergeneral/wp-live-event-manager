<?php
/**
 * Magic Link Service
 *
 * Encapsulates magic-link emails, token generation/validation, and resend flows.
 */

if (!defined('ABSPATH')) {
    exit;
}

class LEM_Magic_Link_Service {
    /** @var LiveEventManager */
    private $plugin;

    public function __construct($plugin) {
        $this->plugin = $plugin;
    }

    public function send_magic_link_email($email, $jwt, $event_id, $session_id = null) {
        $event = $this->plugin->get_event_by_id($event_id);
        if (!$event) {
            return false;
        }

        $magic_token = $this->generate_magic_token($email, $event_id, $session_id);
        $magic_link = $this->plugin->get_event_url($event_id, array('magic' => $magic_token));

        $unique_code = $this->extract_unique_code($jwt, $session_id);
        $resend_url = $this->plugin->get_event_url($event_id);

        $subject = 'Your Stream Access Link - ' . $event->title;
        $message = "Hello,\n\n";
        $message .= "Here's your access link for the stream: " . $event->title . "\n\n";
        $message .= "Click the link below to access the stream:\n";
        $message .= $magic_link . "\n\n";
        $message .= "⚠️  IMPORTANT: This link is ONE-TIME USE ONLY.\n";
        $message .= "• Do not share this link with others\n";
        $message .= "• If you access from a different device, you'll get a new link\n";
        $message .= "• Previous sessions will be automatically revoked\n\n";
        $message .= "This link will expire in 24 hours.\n\n";
        if (!empty($unique_code)) {
            $message .= "Your access code: " . $unique_code . "\n";
            $message .= "Keep this handy—combine it with your email on the resend page if you ever need another link.\n\n";
        }
        $message .= "Need a new link later? Visit: " . $resend_url . "\n\n";
        $message .= "Best regards,\nLive Event Team";

        $headers = array('Content-Type: text/plain; charset=UTF-8');
        return wp_mail($email, $subject, $message, $headers);
    }

    private function extract_unique_code($jwt, $session_id) {
        if (!empty($jwt)) {
            $parts = explode('.', $jwt);
            if (count($parts) === 3) {
                $payload = json_decode(base64_decode(strtr($parts[1], '-_', '+/')), true);
                if (!empty($payload['jti'])) {
                    return substr($payload['jti'], 0, 8);
                }
            }
        }

        if (!empty($session_id)) {
            return substr($session_id, 0, 8);
        }

        return '';
    }

    public function generate_magic_token($email, $event_id, $session_id = null) {
        $token = bin2hex(random_bytes(32));
        $expires = time() + (24 * 60 * 60);

        $magic_data = array(
            'token' => $token,
            'email' => $email,
            'event_id' => $event_id,
            'session_id' => $session_id,
            'created_at' => time(),
            'expires_at' => $expires,
            'consumed' => false
        );

        $redis = $this->plugin->get_redis_connection();
        if ($redis) {
            $redis->setex('magic_token:' . $token, 24 * 60 * 60, json_encode($magic_data));
        }

        return $token;
    }

    public function validate_magic_token($token) {
        $this->plugin->debug_log('Magic token validation started', array('token' => substr($token, 0, 20) . '...'));

        $redis = $this->plugin->get_redis_connection();
        if (!$redis) {
            $settings = get_option('lem_settings', array());
            $redis_enabled = !empty($settings['use_redis']);
            $this->plugin->debug_log('Magic token validation failed - Redis unavailable', array('redis_enabled' => $redis_enabled));

            $message = $redis_enabled
                ? 'Redis connection failed. Check Live Events > Settings and ensure Redis is running.'
                : 'Magic links require Redis. Open the watch link from your email or enable Redis in Live Events settings.';

            return array('valid' => false, 'error' => $message);
        }

        $key = 'magic_token:' . $token;
        $magic_data_json = $redis->get($key);
        if (!$magic_data_json) {
            $this->plugin->debug_log('Magic token validation failed - Token not found in Redis', array('key' => $key));
            return array('valid' => false, 'error' => 'Invalid or expired magic token');
        }

        $magic_data = json_decode($magic_data_json, true);
        $this->plugin->debug_log('Magic token data retrieved', array(
            'email' => $magic_data['email'] ?? 'unknown',
            'event_id' => $magic_data['event_id'] ?? 'unknown',
            'consumed' => $magic_data['consumed'] ?? 'unknown',
            'expires_at' => $magic_data['expires_at'] ?? 'unknown',
            'current_time' => time()
        ));

        if ($magic_data['consumed']) {
            $this->plugin->debug_log('Magic token validation failed - Token already consumed');
            return array('valid' => false, 'error' => 'Magic token already used');
        }

        if (time() > $magic_data['expires_at']) {
            $this->plugin->debug_log('Magic token validation failed - Token expired', array(
                'expires_at' => $magic_data['expires_at'],
                'current_time' => time(),
                'difference' => time() - $magic_data['expires_at']
            ));
            return array('valid' => false, 'error' => 'Magic token expired');
        }

        $existing_session = $this->get_active_session_for_email_event($magic_data['email'], $magic_data['event_id']);
        $this->plugin->debug_log('Session check', array(
            'existing_session' => $existing_session,
            'magic_session_id' => $magic_data['session_id'] ?? 'none'
        ));

        if ($existing_session && $existing_session !== ($magic_data['session_id'] ?? null)) {
            $this->plugin->debug_log('Revoking different existing session', array(
                'existing_session' => $existing_session,
                'magic_session' => $magic_data['session_id'] ?? 'none'
            ));
            $this->plugin->revoke_session($existing_session);
        }

        $jti = $this->resolve_jti_for_magic_token($magic_data, $redis);
        if (!$jti) {
            $this->plugin->debug_log('No valid JTI found for magic token');
            return array('valid' => false, 'error' => 'No valid JWT found for this access');
        }

        $session_id = $this->plugin->create_session($jti, $magic_data['event_id'], $magic_data['email']);

        $magic_data['consumed'] = true;
        $magic_data['consumed_at'] = time();
        $magic_data['session_id'] = $session_id;
        $redis->setex($key, 24 * 60 * 60, json_encode($magic_data));

        return array(
            'valid' => true,
            'email' => $magic_data['email'],
            'event_id' => $magic_data['event_id'],
            'session_id' => $session_id,
            'device_change' => false
        );
    }

    private function resolve_jti_for_magic_token($magic_data, $redis) {
        if (!empty($magic_data['session_id'])) {
            $original_session_data = $redis->get('session:' . $magic_data['session_id']);
            if ($original_session_data) {
                $original_session = json_decode($original_session_data, true);
                if ($original_session && isset($original_session['jti'])) {
                    $this->plugin->debug_log('Using original JTI from session', array('jti' => $original_session['jti']));
                    return $original_session['jti'];
                }
            }
        }

        global $wpdb;
        $table_name = $wpdb->prefix . 'lem_jwt_tokens';
        $jwt_record = $wpdb->get_row($wpdb->prepare(
            "SELECT jti FROM {$table_name} WHERE email = %s AND event_id = %s AND revoked_at IS NULL AND expires_at > %s ORDER BY created_at DESC LIMIT 1",
            $magic_data['email'],
            $magic_data['event_id'],
            date('Y-m-d H:i:s', time())
        ));

        if ($jwt_record) {
            $this->plugin->debug_log('Using JTI from database', array('jti' => $jwt_record->jti));
            return $jwt_record->jti;
        }

        return null;
    }

    public function send_new_device_magic_link($email, $event_id, $session_id) {
        $event = $this->plugin->get_event_by_id($event_id);
        if (!$event) {
            return false;
        }

        $magic_token = $this->generate_magic_token($email, $event_id, $session_id);
        $magic_link = $this->plugin->get_event_url($event_id, array('magic' => $magic_token));

        $subject = 'New Access Link - New Device Detected - ' . $event->title;
        $message = "Hello,\n\n";
        $message .= "We detected you're accessing from a new device for: " . $event->title . "\n\n";
        $message .= "Your previous session has been revoked for security.\n";
        $message .= "Here's your new access link:\n";
        $message .= $magic_link . "\n\n";
        $message .= "⚠️  IMPORTANT: This link is ONE-TIME USE ONLY.\n";
        $message .= "• Do not share this link with others\n";
        $message .= "• Previous sessions have been automatically revoked\n\n";
        $message .= "This link will expire in 24 hours.\n\n";
        $message .= "Best regards,\nLive Event Team";

        $headers = array('Content-Type: text/plain; charset=UTF-8');
        return wp_mail($email, $subject, $message, $headers);
    }

    public function validate_email_and_send_link($email, $event_id) {
        if (!$this->has_valid_ticket($email, $event_id)) {
            return array('valid' => false, 'error' => 'No valid ticket found for this email and event');
        }

        global $wpdb;
        $table_name = $wpdb->prefix . 'lem_jwt_tokens';
        $jwt_record = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table_name} WHERE email = %s AND event_id = %s AND revoked_at IS NULL AND expires_at > %s ORDER BY created_at DESC LIMIT 1",
            $email,
            $event_id,
            date('Y-m-d H:i:s', time())
        ));

        if (!$jwt_record) {
            return array('valid' => false, 'error' => 'No valid JWT record found');
        }

        $new_session_id = $this->plugin->create_session($jwt_record->jti, $event_id, $email);
        $result = $this->send_magic_link_email($email, $jwt_record->jwt_token, $event_id, $new_session_id);

        return $result
            ? array('valid' => true, 'message' => 'New access link sent to your email')
            : array('valid' => false, 'error' => 'Failed to send access link');
    }

    public function has_valid_ticket($email, $event_id) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'lem_jwt_tokens';

        $result = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table_name} WHERE email = %s AND event_id = %s AND payment_id IS NOT NULL AND revoked_at IS NULL AND expires_at > %s",
            $email,
            $event_id,
            date('Y-m-d H:i:s', time())
        ));

        if ($result) {
            $this->plugin->debug_log('Valid paid ticket found', array(
                'email' => $email,
                'event_id' => $event_id,
                'payment_id' => $result->payment_id,
                'jti' => $result->jti
            ));
            return true;
        }

        $free_result = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table_name} WHERE email = %s AND event_id = %s AND revoked_at IS NULL AND expires_at > %s",
            $email,
            $event_id,
            date('Y-m-d H:i:s', time())
        ));

        if ($free_result) {
            $this->plugin->debug_log('Valid free ticket found', array(
                'email' => $email,
                'event_id' => $event_id,
                'payment_id' => $free_result->payment_id,
                'jti' => $free_result->jti
            ));
            $this->cache_session_status($event_id, $email, true);
            return true;
        }

        $this->plugin->debug_log('No valid ticket found', array('email' => $email, 'event_id' => $event_id));
        $this->cache_session_status($event_id, $email, false);
        return false;
    }

    public function cache_session_status($event_id, $email, $valid) {
        $redis = $this->plugin->get_redis_connection();
        if (!$redis) {
            return;
        }

        $email_hash = hash('sha256', $email);
        $key = 'session_status:' . $event_id . ':' . $email_hash;
        $redis->setex($key, 5 * 60, json_encode(array(
            'valid' => (bool) $valid,
            'event_id' => $event_id
        )));
    }

    public function get_active_session_for_email_event($email, $event_id) {
        $redis = $this->plugin->get_redis_connection();
        if (!$redis) {
            return null;
        }

        $email_hash = hash('sha256', $email);
        return $redis->get("active_session:{$event_id}:{$email_hash}");
    }

    public function get_jwt_for_session($session_id) {
        $redis = $this->plugin->get_redis_connection();
        if (!$redis) {
            return null;
        }

        $session_data_json = $redis->get('session:' . $session_id);
        if (!$session_data_json) {
            return null;
        }

        $session_data = json_decode($session_data_json, true);
        if (!$session_data || empty($session_data['active'])) {
            return null;
        }

        $jwt_token = $redis->get('jwt_token:' . $session_data['jti']);
        if ($jwt_token) {
            $this->plugin->debug_log('JWT retrieved from Redis cache', array(
                'session_id' => $session_id,
                'jti' => $session_data['jti']
            ));
            return $jwt_token;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'lem_jwt_tokens';
        $token_record = $wpdb->get_row($wpdb->prepare(
            "SELECT jwt_token FROM $table WHERE jti = %s AND revoked_at IS NULL",
            $session_data['jti']
        ));

        if ($token_record) {
            $redis->setex('jwt_token:' . $session_data['jti'], 24 * 60 * 60, $token_record->jwt_token);
            $this->plugin->debug_log('JWT retrieved from database and cached in Redis', array(
                'session_id' => $session_id,
                'jti' => $session_data['jti']
            ));
            return $token_record->jwt_token;
        }

        return null;
    }
}
