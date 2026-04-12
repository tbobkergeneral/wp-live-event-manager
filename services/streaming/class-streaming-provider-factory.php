<?php
/**
 * Streaming Provider Factory
 */

if (!defined('ABSPATH')) {
    exit;
}

class LEM_Streaming_Provider_Factory {
    
    private static $providers = array();
    private static $instance = null;
    
    private function __construct() {
        // Free / self-hosted (default)
        $this->register_provider('ome', 'LEM_OME_Provider');
        // Paid cloud providers (require credentials)
        $this->register_provider('mux', 'LEM_Mux_Provider');
        // Future paid addons: antmedia, red5pro, wowza
    }
    
    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    public function register_provider($id, $class_name) {
        self::$providers[$id] = $class_name;
    }
    
    public function get_available_providers() {
        return array_keys(self::$providers);
    }
    
    public function get_provider($provider_id = null, $plugin = null) {
        $settings = get_option('lem_settings', array());
        $provider_id = $provider_id ?: ($settings['streaming_provider'] ?? 'ome');

        if (!isset(self::$providers[$provider_id])) {
            $provider_id = 'ome'; // Default free provider
        }

        $class_name = self::$providers[$provider_id];

        // Load provider class if not already loaded
        $provider_file = plugin_dir_path(__FILE__) . 'providers/class-' . strtolower($provider_id) . '-provider.php';
        if (file_exists($provider_file) && !class_exists($class_name)) {
            require_once $provider_file;
        }

        if (!class_exists($class_name)) {
            error_log("LEM: Provider class {$class_name} not found. Falling back to OME.");
            $provider_id = 'ome';
            $class_name  = self::$providers[$provider_id];
            $provider_file = plugin_dir_path(__FILE__) . 'providers/class-ome-provider.php';
            if (file_exists($provider_file) && !class_exists($class_name)) {
                require_once $provider_file;
            }
        }
        
        if (!class_exists($class_name)) {
            return null;
        }
        
        return new $class_name($plugin);
    }
    
    public function get_active_provider($plugin) {
        $settings    = get_option('lem_settings', array());
        $provider_id = $settings['streaming_provider'] ?? 'ome';
        return $this->get_provider($provider_id, $plugin);
    }
    
    public function get_provider_name($provider_id) {
        if (!isset(self::$providers[$provider_id])) {
            return 'Unknown';
        }
        
        $class_name = self::$providers[$provider_id];
        $provider_file = plugin_dir_path(__FILE__) . 'providers/class-' . strtolower($provider_id) . '-provider.php';
        
        if (file_exists($provider_file) && !class_exists($class_name)) {
            require_once $provider_file;
        }
        
        if (class_exists($class_name)) {
            $temp_instance = new $class_name(null);
            return $temp_instance->get_name();
        }
        
        return ucfirst($provider_id);
    }
}
