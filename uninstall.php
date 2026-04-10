<?php
/**
 * Uninstall Live Event Manager
 * 
 * This file is executed when the plugin is deleted from WordPress admin
 * It completely removes all plugin data from the database and Redis
 */

// Prevent direct access
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// Load WordPress
require_once(ABSPATH . 'wp-config.php');

/**
 * Complete plugin cleanup
 */
function lem_complete_uninstall() {
    global $wpdb;
    
    // 1. Remove database tables
    $table_jwts = $wpdb->prefix . 'lem_jwt_tokens';
    $wpdb->query("DROP TABLE IF EXISTS $table_jwts");
    
    // 2. Remove WordPress options
    delete_option('lem_settings');
    delete_option('lem_device_settings');
    
    // 3. Remove all lem_event posts
    $event_posts = get_posts(array(
        'post_type' => 'lem_event',
        'numberposts' => -1,
        'post_status' => 'any'
    ));
    
    foreach ($event_posts as $post) {
        wp_delete_post($post->ID, true);
    }
    
    // 4. Clear rewrite rules
    flush_rewrite_rules();
    
    // 5. Clear Redis data (if Redis is available)
    lem_clear_redis_data();
    
    // 6. Remove any scheduled cron jobs
    wp_clear_scheduled_hook('lem_cleanup_expired_sessions');
    
    // 7. Log the uninstall
    error_log('Live Event Manager plugin completely uninstalled');
}

/**
 * Clear all Redis data for this plugin
 */
function lem_clear_redis_data() {
    try {
        // Try to connect to Redis
        $redis = new Redis();
        $redis->connect('127.0.0.1', 6379, 5);
        
        // Get all keys with lem: prefix
        $keys = $redis->keys('lem:*');
        
        if (!empty($keys)) {
            // Delete all plugin keys
            $redis->del($keys);
            error_log('Cleared ' . count($keys) . ' Redis keys for Live Event Manager');
        }
        
        // Also clear session keys
        $session_keys = $redis->keys('session:*');
        if (!empty($session_keys)) {
            $redis->del($session_keys);
        }
        
        // Clear JWT keys
        $jwt_keys = $redis->keys('jwt:*');
        if (!empty($jwt_keys)) {
            $redis->del($jwt_keys);
        }
        
        // Clear magic token keys
        $magic_keys = $redis->keys('magic_token:*');
        if (!empty($magic_keys)) {
            $redis->del($magic_keys);
        }
        
        // Clear revoked keys
        $revoked_keys = $redis->keys('revoked:*');
        if (!empty($revoked_keys)) {
            $redis->del($revoked_keys);
        }
        
        $redis->close();
        
    } catch (Exception $e) {
        error_log('Could not clear Redis data during uninstall: ' . $e->getMessage());
    }
}

// Execute the complete uninstall
lem_complete_uninstall();
