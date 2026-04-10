<?php
/**
 * Mux Streaming Provider Implementation
 */

if (!defined('ABSPATH')) {
    exit;
}

require_once plugin_dir_path(__FILE__) . '../class-streaming-provider-interface.php';

class LEM_Mux_Provider implements LEM_Streaming_Provider_Interface {
    
    private $plugin;
    private $settings;
    
    public function __construct($plugin) {
        $this->plugin = $plugin;
        $this->settings = get_option('lem_settings', array());
    }
    
    public function get_name() {
        return 'Mux';
    }
    
    public function get_id() {
        return 'mux';
    }
    
    public function is_configured() {
        $token_id = $this->settings['mux_token_id'] ?? '';
        $token_secret = $this->settings['mux_token_secret'] ?? '';
        $key_id = $this->settings['mux_key_id'] ?? '';
        $private_key = $this->settings['mux_private_key'] ?? '';
        
        return !empty($token_id) && !empty($token_secret) && !empty($key_id) && !empty($private_key);
    }
    
    public function get_credentials() {
        return array(
            'token_id' => $this->settings['mux_token_id'] ?? '',
            'token_secret' => $this->settings['mux_token_secret'] ?? '',
            'key_id' => $this->settings['mux_key_id'] ?? '',
            'private_key' => $this->settings['mux_private_key'] ?? ''
        );
    }
    
    public function generate_playback_token($email, $event_id, $payment_id = null, $is_refresh = false) {
        // Use existing generate_jwt() method from plugin
        if ($this->plugin && method_exists($this->plugin, 'generate_jwt')) {
            return $this->plugin->generate_jwt($email, $event_id, $payment_id, $is_refresh);
        }
        return false;
    }
    
    public function get_rtmp_info($stream_id = null) {
        $credentials = $this->get_credentials();
        if (!$credentials || empty($credentials['token_id'])) {
            return new WP_Error('not_configured', 'Mux API credentials not configured');
        }
        
        $settings = get_option('lem_settings', array());
        $live_stream_id = $stream_id ?: ($this->settings['mux_live_stream_id'] ?? '');
        
        if (empty($live_stream_id)) {
            return new WP_Error('no_stream_id', 'Stream ID not configured');
        }
        
        // Use plugin's method if available
        if ($this->plugin && method_exists($this->plugin, 'get_rtmp_info')) {
            $request = new WP_REST_Request('GET', '/lem/v1/rtmp-info');
            $request->set_param('stream_id', $live_stream_id);
            return $this->plugin->get_rtmp_info($request);
        }
        
        // Fallback: direct API call
        $url = "https://api.mux.com/video/v1/live-streams/{$live_stream_id}";
        $response = wp_remote_get($url, array(
            'headers' => array(
                'Authorization' => 'Basic ' . base64_encode($credentials['token_id'] . ':' . $credentials['token_secret'])
            ),
            'timeout' => 10
        ));
        
        if (is_wp_error($response)) {
            return $response;
        }
        
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        if (isset($data['error'])) {
            return new WP_Error('mux_api_error', $data['error']['message'] ?? 'Failed to fetch RTMP info');
        }
        
        $stream_data = $data['data'] ?? $data;
        
        return array(
            'stream_key' => $stream_data['stream_key'] ?? '',
            'ingest_url' => 'rtmp://live.mux.com/app',
            'playback_id' => $stream_data['playback_ids'][0]['id'] ?? ''
        );
    }
    
    public function create_stream($params) {
        if ($this->plugin && method_exists($this->plugin, 'create_live_stream')) {
            $request = new WP_REST_Request('POST', '/lem/v1/live-streams');
            foreach ($params as $key => $value) {
                $request->set_param($key, $value);
            }
            return $this->plugin->create_live_stream($request);
        }
        return new WP_Error('not_implemented', 'Stream creation not available');
    }
    
    public function update_stream($stream_id, $params) {
        if ($this->plugin && method_exists($this->plugin, 'update_live_stream')) {
            $request = new WP_REST_Request('PUT', '/lem/v1/live-streams/' . $stream_id);
            $request->set_url_params(array('id' => $stream_id));
            foreach ($params as $key => $value) {
                $request->set_param($key, $value);
            }
            return $this->plugin->update_live_stream($request);
        }
        return new WP_Error('not_implemented', 'Stream update not available');
    }
    
    public function delete_stream($stream_id) {
        if ($this->plugin && method_exists($this->plugin, 'delete_live_stream')) {
            $request = new WP_REST_Request('DELETE', '/lem/v1/live-streams/' . $stream_id);
            $request->set_url_params(array('id' => $stream_id));
            return $this->plugin->delete_live_stream($request);
        }
        return new WP_Error('not_implemented', 'Stream deletion not available');
    }
    
    public function get_stream_details($stream_id) {
        $credentials = $this->get_credentials();
        if (!$credentials || empty($credentials['token_id'])) {
            return new WP_Error('not_configured', 'Mux API credentials not configured');
        }
        
        $url = "https://api.mux.com/video/v1/live-streams/{$stream_id}";
        $response = wp_remote_get($url, array(
            'headers' => array(
                'Authorization' => 'Basic ' . base64_encode($credentials['token_id'] . ':' . $credentials['token_secret'])
            ),
            'timeout' => 10
        ));
        
        if (is_wp_error($response)) {
            return $response;
        }
        
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        if (isset($data['error'])) {
            return new WP_Error('mux_api_error', $data['error']['message'] ?? 'Failed to fetch stream details');
        }
        
        return $data['data'] ?? $data;
    }
    
    public function list_streams($limit = 100) {
        if ($this->plugin && method_exists($this->plugin, 'list_live_streams')) {
            $request = new WP_REST_Request('GET', '/lem/v1/live-streams');
            $request->set_param('limit', $limit);
            $result = $this->plugin->list_live_streams($request);
            if (is_wp_error($result)) {
                return $result;
            }
            return $result['data'] ?? array();
        }
        return new WP_Error('not_implemented', 'Stream listing not available');
    }
    
    public function get_stream_status($stream_id = null) {
        if ($this->plugin && method_exists($this->plugin, 'get_stream_status')) {
            $request = new WP_REST_Request('GET', '/lem/v1/stream-status');
            if ($stream_id) {
                $request->set_param('stream_id', $stream_id);
            }
            return $this->plugin->get_stream_status($request);
        }
        return new WP_Error('not_implemented', 'Stream status not available');
    }
    
    public function create_simulcast_target($stream_id, $url) {
        if ($this->plugin && method_exists($this->plugin, 'create_simulcast_target')) {
            $request = new WP_REST_Request('POST', '/lem/v1/simulcast-targets');
            $request->set_param('stream_id', $stream_id);
            $request->set_param('url', $url);
            return $this->plugin->create_simulcast_target($request);
        }
        return new WP_Error('not_implemented', 'Simulcast target creation not available');
    }
    
    public function list_simulcast_targets($stream_id = null) {
        if ($this->plugin && method_exists($this->plugin, 'get_simulcast_targets')) {
            $request = new WP_REST_Request('GET', '/lem/v1/simulcast-targets');
            if ($stream_id) {
                $request->set_param('stream_id', $stream_id);
            }
            return $this->plugin->get_simulcast_targets($request);
        }
        return new WP_Error('not_implemented', 'Simulcast target listing not available');
    }
    
    public function delete_simulcast_target($stream_id, $target_id) {
        if ($this->plugin && method_exists($this->plugin, 'delete_simulcast_target')) {
            $request = new WP_REST_Request('DELETE', '/lem/v1/simulcast-targets/' . $target_id);
            $request->set_param('stream_id', $stream_id);
            return $this->plugin->delete_simulcast_target($request);
        }
        return new WP_Error('not_implemented', 'Simulcast target deletion not available');
    }
    
    public function get_webrtc_publish_url($stream_id = null) {
        // Mux doesn't support WebRTC publishing
        return new WP_Error('not_supported', 'Mux does not support WebRTC publishing');
    }
    
    public function get_webrtc_playback_url($stream_id = null) {
        // Mux uses HLS/DASH, not WebRTC
        return new WP_Error('not_supported', 'Mux uses HLS/DASH, not WebRTC playback');
    }
    
    public function get_playback_url($stream_id = null) {
        $details = $this->get_stream_details($stream_id);
        if (is_wp_error($details)) {
            return $details;
        }
        
        $playback_id = $details['playback_ids'][0]['id'] ?? '';
        if (empty($playback_id)) {
            return new WP_Error('no_playback_id', 'No playback ID found for stream');
        }
        
        return 'https://stream.mux.com/' . $playback_id . '.m3u8';
    }
    
    public function get_player_component($playback_id, $token = null, $options = array()) {
        $autoplay = $options['autoplay'] ?? false;
        $muted = $options['muted'] ?? false;
        $poster = $options['poster'] ?? '';
        $stream_type = $options['stream_type'] ?? 'live';
        $title = $options['title'] ?? '';
        
        $attrs = array(
            'playback-id="' . esc_attr($playback_id) . '"',
            'accent-color="#7f5af0"'
        );
        
        if ($token) {
            $attrs[] = 'playback-token="' . esc_attr($token) . '"';
        }
        
        if ($poster) {
            $attrs[] = 'poster="' . esc_url($poster) . '"';
        }
        
        if ($stream_type) {
            $attrs[] = 'stream-type="' . esc_attr($stream_type) . '"';
        }
        
        if ($title) {
            $attrs[] = 'metadata-video-title="' . esc_attr($title) . '"';
        }
        
        if ($autoplay) {
            $attrs[] = 'autoplay';
        }
        
        if ($muted) {
            $attrs[] = 'muted';
        }
        
        return '<mux-player ' . implode(' ', $attrs) . ' style="width:100%;height:100%;"></mux-player>';
    }
    
    public function handle_webhook($payload, $signature = null) {
        if ($this->plugin && method_exists($this->plugin, 'handle_mux_webhook')) {
            // Store payload and signature for the handler
            $_SERVER['HTTP_MUX_SIGNATURE'] = $signature;
            return $this->plugin->handle_mux_webhook();
        }
        return false;
    }
    
    public function get_settings_fields() {
        return array(
            'mux_key_id' => array(
                'label' => 'Mux Signing Key ID',
                'type' => 'text',
                'required' => true
            ),
            'mux_private_key' => array(
                'label' => 'Mux Private Key (Base64)',
                'type' => 'textarea',
                'required' => true
            ),
            'mux_token_id' => array(
                'label' => 'Mux API Token ID',
                'type' => 'text',
                'required' => false
            ),
            'mux_token_secret' => array(
                'label' => 'Mux API Token Secret',
                'type' => 'password',
                'required' => false
            ),
            'mux_live_stream_id' => array(
                'label' => 'Default Mux Live Stream ID',
                'type' => 'text',
                'required' => false
            ),
            'mux_webhook_secret' => array(
                'label' => 'Mux Webhook Secret',
                'type' => 'password',
                'required' => false
            )
        );
    }
    
    public function validate_settings($settings) {
        $errors = array();
        
        if (empty($settings['mux_key_id'])) {
            $errors[] = 'Mux Signing Key ID is required';
        }
        
        if (empty($settings['mux_private_key'])) {
            $errors[] = 'Mux Private Key is required';
        }
        
        return empty($errors) ? true : $errors;
    }
}
