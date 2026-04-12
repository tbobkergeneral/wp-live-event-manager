<?php
/**
 * Uninstall Live Event Manager
 * 
 * This file is executed when the plugin is deleted from WordPress admin.
 * It completely removes all plugin data from the database, options, and
 * user-installed template packs.
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

function lem_complete_uninstall() {
    global $wpdb;

    // 1. Remove database tables
    $table_jwts = $wpdb->prefix . 'lem_jwt_tokens';
    $wpdb->query("DROP TABLE IF EXISTS $table_jwts");
    $table_rev = $wpdb->prefix . 'lem_entitlement_revocations';
    $wpdb->query("DROP TABLE IF EXISTS $table_rev");

    // 2. Remove all plugin options
    delete_option('lem_settings');
    delete_option('lem_device_settings');
    delete_option('lem_db_tables_v1');
    delete_option('lem_db_revocations_v1');

    // 3. Remove all lem_event posts and their meta
    $event_posts = get_posts(array(
        'post_type'   => 'lem_event',
        'numberposts' => -1,
        'post_status' => 'any',
    ));
    foreach ($event_posts as $post) {
        wp_delete_post($post->ID, true);
    }

    // 4. Clear rewrite rules
    flush_rewrite_rules();

    // 5. Remove user-installed template packs (wp-content/lem-templates/)
    $templates_dir = WP_CONTENT_DIR . '/lem-templates';
    if (is_dir($templates_dir)) {
        lem_recursive_rmdir($templates_dir);
    }

    // 6. Remove any scheduled cron jobs
    wp_clear_scheduled_hook('lem_cleanup_expired_sessions');

    // 7. Clean up transients
    $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_lem_%' OR option_name LIKE '_transient_timeout_lem_%'");
}

/**
 * Recursively removes a directory and all its contents.
 */
function lem_recursive_rmdir($dir) {
    if (!is_dir($dir)) {
        return;
    }
    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($items as $item) {
        if ($item->isDir()) {
            rmdir($item->getRealPath());
        } else {
            unlink($item->getRealPath());
        }
    }
    rmdir($dir);
}

lem_complete_uninstall();
