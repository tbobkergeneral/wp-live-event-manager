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
        // Register providers
        $this->register_provider('mux', 'LEM_Mux_Provider');
        // Additional providers can be registered here in the future
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
        $provider_id = $provider_id ?: ($settings['streaming_provider'] ?? 'mux');
        
        if (!isset(self::$providers[$provider_id])) {
            $provider_id = 'mux'; // Fallback to Mux
        }
        
        $class_name = self::$providers[$provider_id];
        
        // Load provider class if not already loaded
        $provider_file = plugin_dir_path(__FILE__) . 'providers/class-' . strtolower($provider_id) . '-provider.php';
        if (file_exists($provider_file) && !class_exists($class_name)) {
            require_once $provider_file;
        }
        
        if (!class_exists($class_name)) {
            error_log("LEM: Provider class {$class_name} not found. Falling back to Mux.");
            $provider_id = 'mux';
            $class_name = self::$providers[$provider_id];
            $provider_file = plugin_dir_path(__FILE__) . 'providers/class-mux-provider.php';
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
        $settings = get_option('lem_settings', array());
        $provider_id = $settings['streaming_provider'] ?? 'mux';
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
