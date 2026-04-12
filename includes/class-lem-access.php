<?php
/**
 * Paywall: Redis playback cache + DB entitlement revocations.
 */

if (!defined('ABSPATH')) {
    exit;
}

class LEM_Access {

    /** @var bool Whether ensure_revocations_table() has already run this request. */
    private static $table_ensured = false;

    /**
     * Redis key for playback blob (JSON). TTL = event end + buffer.
     */
    public static function playback_key(string $email, $event_id): string {
        return 'lem:playback:' . hash('sha256', strtolower(trim($email))) . ':' . (int) $event_id;
    }

    /**
     * Index of session IDs for this email+event (JSON array). Used for admin purge.
     */
    public static function sessions_index_key(string $email, $event_id): string {
        return 'lem:sessions_index:' . hash('sha256', strtolower(trim($email))) . ':' . (int) $event_id;
    }

    /**
     * Ensure revocations table exists (dbDelta).
     */
    public static function ensure_revocations_table(): void {
        if (self::$table_ensured) {
            return;
        }
        self::$table_ensured = true;

        global $wpdb;
        $table   = $wpdb->prefix . 'lem_entitlement_revocations';
        $installed = get_option('lem_db_revocations_v1', '');
        if ($installed === '1') {
            return;
        }

        $charset_collate = $wpdb->get_charset_collate();
        $sql             = "CREATE TABLE $table (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            email varchar(255) NOT NULL,
            event_id bigint(20) unsigned NOT NULL,
            revoked_at datetime NOT NULL,
            revoked_by bigint(20) unsigned NULL,
            PRIMARY KEY (id),
            UNIQUE KEY email_event (email(191), event_id),
            KEY event_id (event_id)
        ) $charset_collate;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);

        update_option('lem_db_revocations_v1', '1');
    }

    public static function is_email_revoked_for_event(string $email, $event_id): bool {
        $cache_key = 'lem:revoked:' . hash('sha256', strtolower(trim($email))) . ':' . (int) $event_id;
        $redis = \LEM_Cache::instance();
        if ($redis) {
            $cached = $redis->get($cache_key);
            if ($cached === '1') {
                return true;
            }
            if ($cached === '0') {
                return false;
            }
        }

        global $wpdb;
        self::ensure_revocations_table();
        $table = $wpdb->prefix . 'lem_entitlement_revocations';
        $n     = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM $table WHERE email = %s AND event_id = %d",
                $email,
                (int) $event_id
            )
        );
        $revoked = $n > 0;

        if ($redis) {
            $redis->setex($cache_key, $revoked ? 3600 : 30, $revoked ? '1' : '0');
        }

        return $revoked;
    }

    public static function record_revocation(string $email, $event_id, $revoked_by_user_id = null): bool {
        $email = strtolower(trim($email));
        global $wpdb;
        self::ensure_revocations_table();
        $table = $wpdb->prefix . 'lem_entitlement_revocations';

        $result = $wpdb->replace(
            $table,
            array(
                'email'      => $email,
                'event_id'   => (int) $event_id,
                'revoked_at' => current_time('mysql'),
                'revoked_by' => $revoked_by_user_id ? (int) $revoked_by_user_id : 0,
            ),
            array('%s', '%d', '%s', '%d')
        );

        $redis = \LEM_Cache::instance();
        if ($redis) {
            $redis->setex('lem:revoked:' . hash('sha256', strtolower(trim($email))) . ':' . (int) $event_id, 3600, '1');
        }

        return $result !== false;
    }

    /**
     * Remove revocation row so the user can receive a new access link (optional admin action).
     */
    public static function clear_revocation(string $email, $event_id): void {
        global $wpdb;
        self::ensure_revocations_table();
        $table = $wpdb->prefix . 'lem_entitlement_revocations';
        $wpdb->delete(
            $table,
            array(
                'email'    => $email,
                'event_id' => (int) $event_id,
            ),
            array('%s', '%d')
        );

        $redis = \LEM_Cache::instance();
        if ($redis) {
            $redis->del('lem:revoked:' . hash('sha256', strtolower(trim($email))) . ':' . (int) $event_id);
        }
    }
}
