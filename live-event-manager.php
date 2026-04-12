<?php
/**
 * Plugin Name: Live Event Manager
 * Plugin URI: https://simulcast.stream
 * Description: Manage stream events, ticketing, and JWT generation for secure paywall system
 * Version: 1.1.0
 * Author: Simulcast
 * License: GPL v2 or later
 * Text Domain: live-event-manager
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('LEM_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('LEM_PLUGIN_URL', plugin_dir_url(__FILE__));
define('LEM_VERSION', '1.1.0');

// Load Firebase JWT library if available
if (file_exists(LEM_PLUGIN_DIR . 'vendor/autoload.php')) {
    require_once LEM_PLUGIN_DIR . 'vendor/autoload.php';
}

require_once LEM_PLUGIN_DIR . 'includes/class-lem-cache.php';
require_once LEM_PLUGIN_DIR . 'includes/class-lem-access.php';
require_once LEM_PLUGIN_DIR . 'includes/class-lem-device-service.php';
require_once LEM_PLUGIN_DIR . 'includes/class-lem-template-manager.php';
require_once LEM_PLUGIN_DIR . 'services/magic-links/class-magic-link-service.php';

/**
 * Main Plugin Class
 */
class LiveEventManager {
    
    private $device_service;
    private $magic_link_service;
    private $event_access_cache = array();
    private $streaming_provider = null;
    
    // In-memory cache for current request — now delegated to LEM_Cache static helpers.
    // Kept as an alias so existing self::$memory_cache references still compile.
    private static $memory_cache = array();
    
    public function __construct() {
        // Initialize device identification service
        $this->device_service = new DeviceIdentificationService();
        $this->magic_link_service = new LEM_Magic_Link_Service($this);
        
        // Load streaming provider factory and initialize provider
        $this->load_streaming_provider();
        add_action('init', array($this, 'init'));
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_menu', array($this, 'remove_add_new_submenu'), 999);
        add_action('wp_enqueue_scripts', array($this, 'enqueue_public_assets'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));
        
        // AJAX handlers (admin-only: public access goes through Stripe checkout or magic links)
        add_action('wp_ajax_lem_generate_jwt', array($this, 'ajax_generate_jwt'));

        // Public: free-event access (validates event is actually free before issuing)
        add_action('wp_ajax_lem_free_event_access', array($this, 'ajax_free_event_access'));
        add_action('wp_ajax_nopriv_lem_free_event_access', array($this, 'ajax_free_event_access'));
        add_action('wp_ajax_lem_revoke_jwt', array($this, 'ajax_revoke_jwt'));
        
        // Stream setup AJAX handlers
        add_action('wp_ajax_lem_get_rtmp_info', array($this, 'ajax_get_rtmp_info'));
        add_action('wp_ajax_lem_create_simulcast_target', array($this, 'ajax_create_simulcast_target'));
        add_action('wp_ajax_lem_delete_simulcast_target', array($this, 'ajax_delete_simulcast_target'));
        add_action('wp_ajax_lem_save_stream_id', array($this, 'ajax_save_stream_id'));
        
        // Provider upload
        add_action('wp_ajax_lem_upload_provider', array($this, 'ajax_upload_provider'));

        // Template pack management
        add_action('wp_ajax_lem_upload_template',   array($this, 'ajax_upload_template'));
        add_action('wp_ajax_lem_activate_template', array($this, 'ajax_activate_template'));
        add_action('wp_ajax_lem_delete_template',   array($this, 'ajax_delete_template'));
        add_action('admin_post_lem_download_pack',  array($this, 'handle_download_pack'));

        // Stream management AJAX handlers
        add_action('wp_ajax_lem_create_stream', array($this, 'ajax_create_stream'));
        add_action('wp_ajax_lem_delete_stream', array($this, 'ajax_delete_stream'));
        add_action('wp_ajax_lem_update_stream', array($this, 'ajax_update_stream'));
        add_action('wp_ajax_lem_get_stream_details', array($this, 'ajax_get_stream_details'));
        
        // Restrictions AJAX handlers
        add_action('wp_ajax_lem_create_restriction', array($this, 'ajax_create_restriction'));
        add_action('wp_ajax_lem_get_restrictions', array($this, 'ajax_get_restrictions'));
        add_action('wp_ajax_lem_delete_restriction', array($this, 'ajax_delete_restriction'));
        
        // JWT management AJAX handlers
        add_action('wp_ajax_lem_get_jwt_tokens', array($this, 'ajax_get_jwt_tokens'));
        add_action('wp_ajax_lem_revoke_emails_for_event', array($this, 'ajax_revoke_emails_for_event'));

        
        // Stripe session creation
        add_action('wp_ajax_lem_create_stripe_session', array($this, 'ajax_create_stripe_session'));
        add_action('wp_ajax_nopriv_lem_create_stripe_session', array($this, 'ajax_create_stripe_session'));
        
        // Regenerate JWT endpoint
        add_action('wp_ajax_lem_regenerate_jwt', array($this, 'ajax_regenerate_jwt'));
        add_action('wp_ajax_nopriv_lem_regenerate_jwt', array($this, 'ajax_regenerate_jwt'));
        add_action('wp_ajax_lem_check_event_access', array($this, 'ajax_check_event_access'));
        add_action('wp_ajax_nopriv_lem_check_event_access', array($this, 'ajax_check_event_access'));
        
        // Redis connection test (useful for troubleshooting)
        add_action('wp_ajax_lem_test_redis_connection', array($this, 'ajax_test_redis_connection'));

        // Email delivery test
        add_action('wp_ajax_lem_test_email', array($this, 'ajax_test_email'));

        // Capture wp_mail() failures so we can surface them
        add_action('wp_mail_failed', array($this, 'on_wp_mail_failed'));
        
        // Session management AJAX
        add_action('wp_ajax_lem_revoke_session', array($this, 'ajax_revoke_session'));
        add_action('wp_ajax_nopriv_lem_revoke_session', array($this, 'ajax_revoke_session'));
        
        // Email validation for device change
        add_action('wp_ajax_lem_validate_email', array($this, 'ajax_validate_email'));
        add_action('wp_ajax_nopriv_lem_validate_email', array($this, 'ajax_validate_email'));
        
        // Clear all tokens
        add_action('wp_ajax_lem_clear_all_tokens', array($this, 'ajax_clear_all_tokens'));
        
        // Stripe webhook
        add_action('wp_ajax_lem_stripe_webhook', array($this, 'handle_stripe_webhook'));
        add_action('wp_ajax_nopriv_lem_stripe_webhook', array($this, 'handle_stripe_webhook'));
        
        // Mux webhook
        add_action('wp_ajax_lem_mux_webhook', array($this, 'handle_mux_webhook'));
        add_action('wp_ajax_nopriv_lem_mux_webhook', array($this, 'handle_mux_webhook'));
        
        // Ably chat token endpoint
        add_action('wp_ajax_lem_ably_token', array($this, 'ajax_ably_token'));
        add_action('wp_ajax_nopriv_lem_ably_token', array($this, 'ajax_ably_token'));
        
        // JWT settings save endpoint
        add_action('wp_ajax_lem_save_jwt_settings', array($this, 'ajax_save_jwt_settings'));
        
        // REST API endpoints
        add_action('rest_api_init', array($this, 'register_rest_routes'));
        
        // Activation and deactivation hooks
        register_activation_hook(__FILE__, array($this, 'activate'));
        register_deactivation_hook(__FILE__, array($this, 'deactivate'));

        // Show admin notice if Upstash is not configured
        add_action('admin_notices', array('LEM_Cache', 'maybe_show_config_notice'));
    }
    
    public function init() {
        // Ensure database tables exist
        $this->ensure_tables_exist();
        if (class_exists('LEM_Access')) {
            LEM_Access::ensure_revocations_table();
        }
        
        // Register post type and blocks (only if not already registered)
        if (!post_type_exists('lem_event')) {
            $this->register_event_post_type();
        }
        
        $this->add_rewrite_rules();
        
        $this->register_blocks();
        
        // Register shortcodes
        add_shortcode('simulcast_player', array($this, 'render_simulcast_player_shortcode'));
        
        // Register hooks (always register these)
        add_action('add_meta_boxes', array($this, 'add_event_meta_boxes'));
        add_action('save_post', array($this, 'save_event_meta'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_event_edit_assets'));
        add_action('enqueue_block_editor_assets', array($this, 'enqueue_block_editor_assets'));
        add_filter('single_template', array($this, 'load_event_template'));
        add_filter('archive_template', array($this, 'load_event_archive_template'));
        add_filter('template_include', array($this, 'load_confirmation_template'));
        add_filter('template_include', array($this, 'load_additional_templates'));
        add_action('template_redirect', array($this, 'handle_confirmation_redirect'));
        add_action('template_redirect', array($this, 'handle_events_redirect'));
        add_action('template_redirect', array($this, 'redirect_homepage_to_event'));
        add_action('template_redirect', array($this, 'handle_event_magic_link'));
        add_filter('query_vars', array($this, 'add_query_vars'));
        add_filter( 'theme_page_templates', function( $templates ) {
            $templates['page-events.php'] = __( 'Events (lem_events)', 'live-event-manager' );
            return $templates;
        });
        add_filter( 'template_include', function( $template ) {
            if ( is_page() ) {
                $page_template = get_page_template_slug( get_queried_object_id() );
                if ( $page_template === 'page-events.php' ) {
                    $template = LEM_Template_Manager::resolve_template_file('page-events.php');
                }
            }
            return $template;
        });
        
        
    }
    
    /**
     * Load streaming provider using factory
     */
    private function load_streaming_provider() {
        require_once plugin_dir_path(__FILE__) . 'services/streaming/class-streaming-provider-interface.php';
        require_once plugin_dir_path(__FILE__) . 'services/streaming/class-streaming-provider-factory.php';
        
        $factory = LEM_Streaming_Provider_Factory::get_instance();
        $this->streaming_provider = $factory->get_active_provider($this);
    }
    
    /**
     * Get the active streaming provider
     */
    public function get_streaming_provider() {
        if ($this->streaming_provider === null) {
            $this->load_streaming_provider();
        }
        return $this->streaming_provider;
    }
    
    // Debug logging method
    public function debug_log($message, $data = null) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            // Ensure message is a string and clean
            $message = is_string($message) ? $message : (string) $message;
            $message = $this->sanitize_log_string($message);
            
            $log_message = '[LEM] ' . $message;
            
            if ($data !== null) {
                // Handle different data types more carefully
                $encoded_data = null;
                
                if (is_string($data)) {
                    $encoded_data = $this->sanitize_log_string($data);
                } elseif (is_array($data) || is_object($data)) {
                    // Try JSON encoding first
                    $encoded_data = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                    if ($encoded_data === false) {
                        // If JSON fails, use var_export
                        $encoded_data = var_export($data, true);
                    }
                    $encoded_data = $this->sanitize_log_string($encoded_data);
                } elseif (is_numeric($data) || is_bool($data)) {
                    $encoded_data = (string) $data;
                } else {
                    // For any other type, convert to string safely
                    $encoded_data = $this->sanitize_log_string((string) $data);
                }
                
                if ($encoded_data !== null) {
                    $log_message .= ' - ' . $encoded_data;
                }
            }
            
            // Ensure final log message is a valid string and not too long
            if (is_string($log_message) && strlen($log_message) < 8192) {
                error_log($log_message);
            }
        }
    }
    
    // Sanitize strings for logging to prevent binary data corruption
    private function sanitize_log_string($string) {
        if (!is_string($string)) {
            return (string) $string;
        }
        
        // Remove null bytes and other control characters that could corrupt the log
        $string = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $string);
        
        // Ensure it's valid UTF-8
        if (!mb_check_encoding($string, 'UTF-8')) {
            $string = mb_convert_encoding($string, 'UTF-8', 'UTF-8');
        }
        
        // Limit length to prevent huge log entries
        if (strlen($string) > 1000) {
            $string = substr($string, 0, 1000) . '... [truncated]';
        }
        
        return $string;
    }
    
    private function ensure_tables_exist() {
        if (get_option('lem_db_tables_v1') === '1') {
            return;
        }
        $this->create_tables();
        update_option('lem_db_tables_v1', '1');
    }
    
    public function activate() {
        $this->debug_log('Plugin activation started');
        
        // Create database tables
        $this->create_tables();
        
        $this->add_rewrite_rules();
        flush_rewrite_rules();
        
        // Set default options
        if (!get_option('lem_settings')) {
            update_option('lem_settings', array(
                // Streaming (Mux)
                'mux_key_id'         => '',
                'mux_private_key'    => '',
                'mux_token_id'       => '',
                'mux_token_secret'   => '',
                'mux_webhook_secret' => '',
                // Payments (Stripe)
                'stripe_mode'                 => 'test',
                'stripe_test_publishable_key' => '',
                'stripe_test_secret_key'      => '',
                'stripe_test_webhook_secret'  => '',
                'stripe_live_publishable_key' => '',
                'stripe_live_secret_key'      => '',
                'stripe_live_webhook_secret'  => '',
                // Cache (Upstash)
                'upstash_redis_url'   => '',
                'upstash_redis_token' => '',
                // Access
                'jwt_expiration_hours'           => 24,
                'jwt_refresh_duration_minutes'   => 15,
                // Debug
                'debug_mode' => 0,
            ));
        }
        
        $this->debug_log('Plugin activation completed');
    }
    
    public function deactivate() {
        // Clear rewrite rules
        flush_rewrite_rules();
        
        // Clear any scheduled cron jobs
        wp_clear_scheduled_hook('lem_cleanup_expired_sessions');
        
        $this->debug_log('Plugin deactivated - rewrite rules cleared');
    }
    
    private function create_tables() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();
        
        $table_jwts = $wpdb->prefix . 'lem_jwt_tokens';
        
        // Create table with basic structure first
        $sql_jwts = "CREATE TABLE IF NOT EXISTS $table_jwts (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            jti varchar(255) NOT NULL,
            hash_jti varchar(255) NOT NULL,
            jwt_token LONGTEXT,
            email varchar(255) NOT NULL,
            event_id varchar(100) NOT NULL,
            payment_id varchar(255),
            ip_address varchar(45),
            expires_at datetime NOT NULL,
            revoked_at datetime NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id)
        ) $charset_collate;";
        
        $result = $wpdb->query($sql_jwts);
        
        if ($result !== false) {
            $indexes_sql = array(
                "ALTER TABLE $table_jwts ADD UNIQUE KEY jti (jti)",
                "ALTER TABLE $table_jwts ADD KEY hash_jti (hash_jti)",
                "ALTER TABLE $table_jwts ADD KEY email (email)",
                "ALTER TABLE $table_jwts ADD KEY event_id (event_id)",
                "ALTER TABLE $table_jwts ADD KEY revoked_at (revoked_at)",
                "ALTER TABLE $table_jwts ADD KEY payment_id (payment_id)",
                "ALTER TABLE $table_jwts ADD KEY email_event (email(191), event_id)"
            );
            
            foreach ($indexes_sql as $index_sql) {
                $wpdb->query($index_sql);
            }
        }
    }
    
    // Register custom post type for events
    private function register_event_post_type() {
        register_post_type('lem_event', array(
            'labels' => array(
                'name'               => 'Live Events',
                'singular_name'      => 'Live Event',
                'menu_name'          => 'Live Events Manager',
                'add_new'            => 'Add New Event',
                'add_new_item'       => 'Add New Live Event',
                'edit_item'          => 'Edit Live Event',
                'new_item'           => 'New Live Event',
                'view_item'          => 'View Live Event',
                'search_items'       => 'Search Live Events',
                'not_found'          => 'No live events found',
                'not_found_in_trash' => 'No live events found in trash',
            ),
            'public' => true,
            'publicly_queryable' => true,
            'show_ui' => true,
            'show_in_menu' => true,
            'show_in_nav_menus' => false,
            'show_in_admin_bar' => true,
            'has_archive' => true,
            'supports' => array('title', 'editor', 'custom-fields', 'thumbnail'),
            'menu_icon' => 'dashicons-video-alt3',
            'menu_position' => 25,
            'show_in_rest' => true,
            'capability_type' => 'post',
            'map_meta_cap' => true,
            'rewrite' => array('slug' => 'events')
        ));
    }
    
    // Add meta boxes for event fields
    public function add_event_meta_boxes() {
        add_meta_box(
            'lem_event_details',
            'Event Details',
            array($this, 'render_event_meta_box'),
            'lem_event',
            'normal',
            'high'
        );
        
        add_meta_box(
            'lem_homepage_meta',
            'Homepage Settings',
            array($this, 'render_homepage_meta_box'),
            'lem_event',
            'side',
            'high'
        );
    }
    
    // Render event meta box
    public function render_event_meta_box($post) {
        wp_nonce_field('lem_save_event_meta', 'lem_event_meta_nonce');

        // ── Load saved meta ───────────────────────────────────────────────
        $playback_id             = get_post_meta($post->ID, '_lem_playback_id', true);
        $live_stream_id          = get_post_meta($post->ID, '_lem_live_stream_id', true);
        $stream_provider         = get_post_meta($post->ID, '_lem_stream_provider', true) ?: 'mux';
        $playback_restriction_id = get_post_meta($post->ID, '_lem_playback_restriction_id', true);
        $event_date              = get_post_meta($post->ID, '_lem_event_date', true);
        $event_end               = get_post_meta($post->ID, '_lem_event_end',  true);
        $is_free                 = get_post_meta($post->ID, '_lem_is_free', true) ?: 'free';
        $price_id                = get_post_meta($post->ID, '_lem_price_id', true);
        $display_price           = get_post_meta($post->ID, '_lem_display_price', true);
        $excerpt                 = get_post_meta($post->ID, '_lem_excerpt', true);

        // ── Fetch available streams via provider factory ───────────────────
        $available_streams    = array();
        $provider_configured  = false;
        $factory              = LEM_Streaming_Provider_Factory::get_instance();
        $settings             = get_option('lem_settings', array());
        $active_provider_id   = $settings['streaming_provider'] ?? 'mux';
        $meta_box_provider    = $factory->get_provider($active_provider_id, $this);

        if ($meta_box_provider && $meta_box_provider->is_configured()) {
            $provider_configured = true;
            $streams_result      = $meta_box_provider->list_streams(100);
            if (!is_wp_error($streams_result) && is_array($streams_result)) {
                foreach ($streams_result as $stream) {
                    $stream['_provider'] = $active_provider_id;
                    $available_streams[] = $stream;
                }
            }
        }

        // ── Build per-stream data for JS (avoid re-parsing data-* in JS) ──
        $streams_js = array();
        foreach ($available_streams as $stream) {
            $sid          = $stream['id'] ?? '';
            $playback_ids = $stream['playback_ids'] ?? array();
            $pbid         = $playback_ids[0]['id'] ?? '';
            $streams_js[$sid] = array(
                'playback_id' => $pbid,
                'stream_key'  => $stream['stream_key']  ?? '',
                'status'      => $stream['status']      ?? 'unknown',
                'name'        => $stream['passthrough'] ?? $sid,
                'provider'    => $stream['_provider']   ?? $active_provider_id,
            );
        }

        $streams_json = wp_json_encode($streams_js);
        $manage_url   = admin_url('edit.php?post_type=lem_event&page=live-event-manager-stream-management');
        $publisher_url = admin_url('edit.php?post_type=lem_event&page=live-event-manager-publisher');
        ?>

        <style>
        #lem_event_details .form-table th { width: 160px; }

        .lem-stream-row { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; }
        .lem-stream-row select { flex: 1; min-width: 200px; }

        #lem-stream-badge {
            display: inline-flex; align-items: center; gap: 5px;
            padding: 3px 10px; border-radius: 10px; font-size: 11px; font-weight: 600;
        }
        #lem-stream-badge.active { background:#edfaed; color:#2a6e2a; border:1px solid #b3e6b3; }
        #lem-stream-badge.idle   { background:#f6f7f7; color:#646970; border:1px solid #dcdcde; }
        #lem-stream-badge.empty  { display:none; }

        #lem-stream-detail {
            margin-top: 6px; font-size: 12px; color: #646970; display: none;
        }
        #lem-stream-detail code { font-size: 11px; }

        .lem-meta-section {
            font-size: 11px; font-weight: 600; text-transform: uppercase;
            letter-spacing: .06em; color: #8c8f94;
            padding: 16px 0 4px; border: none;
        }
        #lem-pricing-fields, #lem-display-price-field { transition: none; }
        </style>

        <table class="form-table">

            <!-- ── Stream ────────────────────────────────────────────────── -->
            <tr><td colspan="2" class="lem-meta-section">Stream</td></tr>

            <tr>
                <th><label for="lem_stream_selector">Live Stream</label></th>
                <td>
                    <?php if ( ! $provider_configured ) : ?>
                        <p class="description" style="color:#d63638;">
                            Provider not configured.
                            <a href="<?php echo esc_url( admin_url( 'edit.php?post_type=lem_event&page=live-event-manager-stream-vendors' ) ); ?>">Set up credentials →</a>
                        </p>
                    <?php elseif ( empty( $available_streams ) ) : ?>
                        <p class="description">
                            No streams yet. <a href="<?php echo esc_url( $manage_url ); ?>" target="_blank">Create one →</a>
                        </p>
                    <?php else : ?>

                    <div class="lem-stream-row">
                        <select id="lem_stream_selector" name="lem_stream_selector">
                            <option value="">— choose a stream —</option>
                            <?php foreach ( $available_streams as $stream ) :
                                $sid     = $stream['id']          ?? '';
                                $sname   = $stream['passthrough'] ?? $sid;
                                $sstatus = $stream['status']      ?? 'unknown';
                            ?>
                            <option value="<?php echo esc_attr( $sid ); ?>"
                                    <?php selected( $live_stream_id, $sid ); ?>>
                                <?php echo esc_html( $sname ); ?> — <?php echo esc_html( ucfirst( $sstatus ) ); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>

                        <span id="lem-stream-badge" class="empty"></span>

                        <a href="<?php echo esc_url( $manage_url ); ?>" target="_blank"
                           class="button button-small">↗ Manage</a>
                    </div>

                    <!-- Confirmation strip shown after selection -->
                    <p id="lem-stream-detail"></p>

                    <?php endif; ?>

                    <!-- Hidden fields — saved values, written by JS on selection -->
                    <input type="hidden" id="lem_live_stream_id"  name="lem_live_stream_id"  value="<?php echo esc_attr( $live_stream_id ); ?>">
                    <input type="hidden" id="lem_playback_id"     name="lem_playback_id"     value="<?php echo esc_attr( $playback_id ); ?>">
                    <input type="hidden" id="lem_stream_provider" name="lem_stream_provider" value="<?php echo esc_attr( $stream_provider ); ?>">
                </td>
            </tr>

            <tr>
                <th><label for="lem_playback_restriction_id">Playback Restriction</label></th>
                <td>
                    <div style="display:flex; gap:8px; align-items:center;">
                        <select id="lem_playback_restriction_id" name="lem_playback_restriction_id"
                                data-current-value="<?php echo esc_attr( $playback_restriction_id ); ?>">
                            <option value="">No restriction</option>
                            <?php if ( ! empty( $playback_restriction_id ) ) : ?>
                            <option value="<?php echo esc_attr( $playback_restriction_id ); ?>" selected>
                                <?php echo esc_html( $playback_restriction_id ); ?> (Loading…)
                            </option>
                            <?php endif; ?>
                        </select>
                        <button type="button" id="lem-refresh-restrictions" class="button button-small">Refresh</button>
                    </div>
                    <p class="description">Optionally limit playback to specific domains.</p>
                </td>
            </tr>

            <!-- ── Event Details ─────────────────────────────────────────── -->
            <tr><td colspan="2" class="lem-meta-section">Event Details</td></tr>

            <tr>
                <th><label for="lem_event_date">Start Date &amp; Time <span style="color:#d63638;">*</span></label></th>
                <td>
                    <input type="datetime-local" id="lem_event_date" name="lem_event_date"
                           value="<?php echo esc_attr( $event_date ); ?>" required>
                    <p class="description">When the live stream begins.</p>
                </td>
            </tr>

            <tr>
                <th><label for="lem_event_end">End Date &amp; Time</label></th>
                <td>
                    <input type="datetime-local" id="lem_event_end" name="lem_event_end"
                           value="<?php echo esc_attr( $event_end ); ?>">
                    <p class="description">
                        When the stream ends. Access tokens expire 2 hours after this time.
                        Leave blank to use the default expiry from Settings.
                    </p>
                </td>
            </tr>

            <tr>
                <th>Event Type</th>
                <td>
                    <label style="margin-right:16px;">
                        <input type="radio" name="lem_is_free" value="free" <?php checked( $is_free, 'free' ); ?>> Free
                    </label>
                    <label>
                        <input type="radio" name="lem_is_free" value="paid" <?php checked( $is_free, 'paid' ); ?>> Paid (Stripe)
                    </label>
                </td>
            </tr>

            <tr id="lem-pricing-fields" style="display:<?php echo $is_free === 'paid' ? 'table-row' : 'none'; ?>;">
                <th><label for="lem_price_id">Stripe Price ID</label></th>
                <td>
                    <input type="text" id="lem_price_id" name="lem_price_id"
                           value="<?php echo esc_attr( $price_id ); ?>"
                           class="regular-text" placeholder="price_xxxxxxxxxxxxxxxx">
                    <p class="description">
                        Create a one-time price in the
                        <a href="https://dashboard.stripe.com/prices" target="_blank">Stripe Dashboard</a>
                        and paste the ID here.
                    </p>
                </td>
            </tr>

            <tr id="lem-display-price-field" style="display:<?php echo $is_free === 'paid' ? 'table-row' : 'none'; ?>;">
                <th><label for="lem_display_price">Display Price</label></th>
                <td>
                    <input type="text" id="lem_display_price" name="lem_display_price"
                           value="<?php echo esc_attr( $display_price ); ?>"
                           class="regular-text" placeholder="e.g. $19.99">
                    <p class="description">Shown on the event page next to the buy button.</p>
                </td>
            </tr>

            <tr>
                <th><label for="lem_excerpt">Excerpt</label></th>
                <td>
                    <textarea id="lem_excerpt" name="lem_excerpt" rows="3" class="large-text"
                              placeholder="Short description for the events listing (≤150 chars)…"
                    ><?php echo esc_textarea( $excerpt ); ?></textarea>
                </td>
            </tr>

        </table>

        <script>
        jQuery(document).ready(function($) {

            var streams = <?php echo $streams_json; ?>;

            // ── Apply a stream selection ──────────────────────────────────
            function applyStream(streamId) {
                var s = streams[streamId];

                if (!s) {
                    $('#lem_live_stream_id').val('');
                    $('#lem_playback_id').val('');
                    $('#lem_stream_provider').val('<?php echo esc_js( $active_provider_id ); ?>');
                    $('#lem-stream-badge').attr('class', 'empty').text('');
                    $('#lem-stream-detail').hide().text('');
                    return;
                }

                // Write hidden fields
                $('#lem_live_stream_id').val(streamId);
                $('#lem_playback_id').val(s.playback_id);
                $('#lem_stream_provider').val(s.provider);

                // Status badge
                var cls   = s.status === 'active' ? 'active' : 'idle';
                var label = s.status.charAt(0).toUpperCase() + s.status.slice(1);
                $('#lem-stream-badge').attr('class', cls).text('● ' + label);

                // Confirmation strip
                var detail = 'Playback ID: <code>' + s.playback_id + '</code>';
                if (!s.playback_id) detail = '<span style="color:#d63638;">No playback ID on this stream yet.</span>';
                $('#lem-stream-detail').html(detail).show();
            }

            // Wire up change
            $('#lem_stream_selector').on('change', function() {
                applyStream($(this).val());
            });

            // Init badge from already-saved stream
            (function() {
                var saved = $('#lem_live_stream_id').val();
                if (saved && streams[saved]) {
                    var s   = streams[saved];
                    var cls = s.status === 'active' ? 'active' : 'idle';
                    $('#lem-stream-badge').attr('class', cls)
                        .text('● ' + s.status.charAt(0).toUpperCase() + s.status.slice(1));
                    var detail = 'Playback ID: <code>' + s.playback_id + '</code>';
                    if (!s.playback_id) detail = '<span style="color:#d63638;">No playback ID on this stream yet.</span>';
                    $('#lem-stream-detail').html(detail).show();
                }
            })();

            // ── Pricing fields ────────────────────────────────────────────
            function updatePricingFields() {
                var paid = $('input[name="lem_is_free"]:checked').val() === 'paid';
                $('#lem-pricing-fields, #lem-display-price-field').toggle(paid);
            }
            $('input[name="lem_is_free"]').on('change', updatePricingFields);
            updatePricingFields();

            // ── Playback restrictions ─────────────────────────────────────
            function loadRestrictions() {
                var $sel  = $('#lem_playback_restriction_id');
                var $btn  = $('#lem-refresh-restrictions');
                var saved = $sel.data('current-value') || $sel.val();
                $btn.prop('disabled', true).text('Loading…');
                $.post(lem_ajax.ajax_url, { action: 'lem_get_restrictions', nonce: lem_ajax.nonce },
                    function(r) {
                        if (r.success && Array.isArray(r.data)) {
                            $sel.find('option:not(:first)').remove();
                            r.data.forEach(function(item) {
                                var domains = item.referrer && item.referrer.allowed_domains
                                    ? item.referrer.allowed_domains.join(', ') : '—';
                                $sel.append($('<option>').val(item.id).text(item.id + ' (' + domains + ')'));
                            });
                            if (saved) $sel.val(saved);
                        }
                    }
                ).always(function() { $btn.prop('disabled', false).text('Refresh'); });
            }

            if ($('#lem_playback_restriction_id').length) loadRestrictions();
            $('#lem-refresh-restrictions').on('click', loadRestrictions);

        });
        </script>
        <?php
    }
    
    // Save event meta data
    public function save_event_meta($post_id) {
        // Check if this is an autosave
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }
        
        // Check if this is a revision
        if (wp_is_post_revision($post_id)) {
            return;
        }
        
        // Check if this is the correct post type
        if (get_post_type($post_id) !== 'lem_event') {
            return;
        }
        
        // Check nonce
        if (!isset($_POST['lem_event_meta_nonce']) || !wp_verify_nonce($_POST['lem_event_meta_nonce'], 'lem_save_event_meta')) {
            return;
        }
        
        // Check homepage meta nonce
        if (isset($_POST['lem_homepage_meta_nonce']) && wp_verify_nonce($_POST['lem_homepage_meta_nonce'], 'lem_save_homepage_meta')) {
            $this->save_homepage_setting($post_id);
        }
        
        // Check permissions
        if (!current_user_can('edit_post', $post_id)) {
            return;
        }
        
        // Debug: Log POST data for event type
        if (isset($_POST['lem_is_free'])) {
            $this->debug_log('Event type save - POST data received', array(
                'post_id' => $post_id,
                'lem_is_free' => $_POST['lem_is_free'],
                'all_post_fields' => array_keys($_POST)
            ));
        } else {
            $this->debug_log('Event type save - No lem_is_free in POST data', array(
                'post_id' => $post_id,
                'all_post_fields' => array_keys($_POST)
            ));
        }
        
        // Save meta fields
        $fields = array(
            'lem_playback_id' => '_lem_playback_id',
            'lem_live_stream_id' => '_lem_live_stream_id',
            'lem_stream_provider' => '_lem_stream_provider',
            'lem_playback_restriction_id' => '_lem_playback_restriction_id',
            'lem_event_date' => '_lem_event_date',
            'lem_event_end'  => '_lem_event_end',
            'lem_is_free' => '_lem_is_free',
            'lem_price_id' => '_lem_price_id',
            'lem_display_price' => '_lem_display_price',
            'lem_excerpt' => '_lem_excerpt'
        );
        
        foreach ($fields as $field_name => $meta_key) {
            if (isset($_POST[$field_name])) {
                $value = sanitize_text_field($_POST[$field_name]);
                update_post_meta($post_id, $meta_key, $value);
                $this->debug_log("Saved field: {$field_name} = {$value}", array('post_id' => $post_id));
                            } else {
                    // Handle radio buttons that might not be in $_POST if none selected
                    if ($field_name === 'lem_is_free') {
                        // Default to free event if no selection
                        update_post_meta($post_id, $meta_key, 'free');
                        $this->debug_log("Set default for {$field_name} = free", array('post_id' => $post_id));
                    }
                }
        }
        
        // Store event data in Redis for fast edge access
        $event_data = array(
            'event_id' => $post_id, // Use post ID as event ID
            'title' => get_the_title($post_id),
            'description' => get_post_field('post_content', $post_id),
            'playback_id' => get_post_meta($post_id, '_lem_playback_id', true),
            'live_stream_id' => get_post_meta($post_id, '_lem_live_stream_id', true),
            'playback_restriction_id' => get_post_meta($post_id, '_lem_playback_restriction_id', true),
            'event_date' => get_post_meta($post_id, '_lem_event_date', true),
            'event_end'  => get_post_meta($post_id, '_lem_event_end',  true),
            'price_id' => get_post_meta($post_id, '_lem_price_id', true),
            'is_free' => get_post_meta($post_id, '_lem_is_free', true) ?: 'free',
            'post_status' => get_post_status($post_id),
            'updated_at' => current_time('mysql')
        );
        
        $this->store_event_redis($post_id, $event_data);
        
        // Clear in-memory cache for this event
        unset(self::$memory_cache["event:{$post_id}"]);
        unset($this->event_access_cache[$post_id]);
    }
    
    // Render homepage meta box
    public function render_homepage_meta_box($post) {
        wp_nonce_field('lem_save_homepage_meta', 'lem_homepage_meta_nonce');
        
        $settings = get_option('lem_settings', array());
        $current_homepage_event = $settings['homepage_event_id'] ?? 0;
        $is_current_homepage = ($current_homepage_event == $post->ID);
        
        ?>
        <div class="lem-homepage-settings">
            <p>
                <label>
                    <input type="checkbox" name="lem_set_as_homepage" value="1" <?php checked($is_current_homepage); ?>>
                    Set this event as the homepage
                </label>
            </p>
            
            <?php if ($is_current_homepage): ?>
                <div class="lem-homepage-status" style="background: #d4edda; padding: 10px; border-radius: 4px; margin-top: 10px;">
                    <strong>✅ This event is currently set as the homepage</strong>
                    <p style="margin: 5px 0 0 0; font-size: 12px;">
                        Visitors to your site's homepage will be redirected to this event page.
                    </p>
                </div>
            <?php else: ?>
                <div class="lem-homepage-status" style="background: #f8f9fa; padding: 10px; border-radius: 4px; margin-top: 10px;">
                    <strong>ℹ️ This event is not the homepage</strong>
                    <p style="margin: 5px 0 0 0; font-size: 12px;">
                        Check the box above to make this event your homepage.
                    </p>
                </div>
            <?php endif; ?>
            
            <p style="margin-top: 15px; font-size: 12px; color: #666;">
                <strong>Note:</strong> Only published events can be set as the homepage. 
                If no event is set as homepage, WordPress will use your default homepage setting.
            </p>
        </div>
        <?php
    }
    
    // Save homepage setting
    private function save_homepage_setting($post_id) {
        $settings = get_option('lem_settings', array());
        
        // Check if this event should be set as homepage
        if (isset($_POST['lem_set_as_homepage']) && $_POST['lem_set_as_homepage'] == '1') {
            // Only allow published events to be homepage
            if (get_post_status($post_id) === 'publish') {
                $settings['homepage_event_id'] = $post_id;
                update_option('lem_settings', $settings);
                $this->debug_log('Event set as homepage', array('post_id' => $post_id));
            }
        } else {
            // If this event was previously the homepage, clear it
            if (isset($settings['homepage_event_id']) && $settings['homepage_event_id'] == $post_id) {
                $settings['homepage_event_id'] = 0;
                update_option('lem_settings', $settings);
                $this->debug_log('Event removed as homepage', array('post_id' => $post_id));
            }
        }
    }
    
    // Register Gutenberg blocks
    public function register_blocks() {
        // Register smart event ticket block (only for event post type)
        register_block_type('lem/event-ticket', array(
            'render_callback' => array($this, 'render_event_ticket_block'),
            'attributes' => array(
                'allowed_post_types' => array(
                    'type' => 'array',
                    'default' => array('lem_event')
                )
            )
        ));
        
        // Register gated video player block (available everywhere)
        register_block_type('lem/gated-video', array(
            'render_callback' => array($this, 'render_gated_video_block')
        ));
    }
    
    // Load custom template for event display
    public function load_event_template($template) {
        global $post;

        if ($post && $post->post_type === 'lem_event') {
            $event_template = LEM_Template_Manager::resolve_template_file('single-event.php');

            if (file_exists($event_template)) {
                // Make the plugin instance available to the template
                global $live_event_manager;
                $live_event_manager = $this;
                $this->debug_log('Loading single event template', array('post_id' => $post->ID));
                return $event_template;
            }
        }

        return $template;
    }

    // Load archive template for events listing
    public function load_event_archive_template($template) {
        if (is_post_type_archive('lem_event')) {
            $archive_template = LEM_Template_Manager::resolve_template_file('page-events.php');

            if (file_exists($archive_template)) {
                $this->debug_log('Loading events archive template');
                return $archive_template;
            }
        }

        return $template;
    }
    
    // Redirect homepage to designated event
    public function redirect_homepage_to_event() {
        // Only redirect if we're on the homepage and not in admin
        if (is_front_page() && !is_admin()) {
            $settings = get_option('lem_settings', array());
            $homepage_event_id = isset($settings['homepage_event_id']) ? intval($settings['homepage_event_id']) : 0;
            
            // Only redirect if a specific event is explicitly set as homepage
            if ($homepage_event_id > 0) {
                $event = get_post($homepage_event_id);
                if ($event && $event->post_type === 'lem_event' && $event->post_status === 'publish') {
                    $event_url = get_permalink($homepage_event_id);
                    wp_redirect($event_url, 302);
                    exit;
                }
            }
            
            // No fallback - let WordPress handle the homepage normally
            // This allows users to set their own homepage in WordPress Settings
        }
    }
    
    // Load confirmation page template
    public function load_confirmation_template($template) {
        global $wp_query;

        // CRITICAL: Do NOT interfere with single events or archives
        if (is_singular('lem_event') || is_post_type_archive('lem_event')) {
            return $template;  // Let other filters handle it
        }

        $this->debug_log('Confirmation template loading check', array(
            'request_uri' => $_SERVER['REQUEST_URI'] ?? 'not set',
            'query_vars' => $wp_query->query_vars,
            'get_params' => $_GET
        ));
        
        // Check if this is a confirmation page request
        if (isset($wp_query->query_vars['lem_confirmation']) && $wp_query->query_vars['lem_confirmation'] == '1') {
            $confirmation_template = LEM_PLUGIN_DIR . 'templates/confirmation-page.php';
            
            if (file_exists($confirmation_template)) {
                $this->debug_log('Loading confirmation template via rewrite rule');
                return $confirmation_template;
            }
        }
        
        // Fallback: Check if the current page is /confirmation (for when rewrite rules don't work)
        if (isset($_SERVER['REQUEST_URI']) && strpos($_SERVER['REQUEST_URI'], '/confirmation') === 0) {
            $confirmation_template = LEM_PLUGIN_DIR . 'templates/confirmation-page.php';
            
            if (file_exists($confirmation_template)) {
                $this->debug_log('Loading confirmation template via fallback method', array('uri' => $_SERVER['REQUEST_URI']));
                return $confirmation_template;
            }
        }
        
        // Additional fallback: Check if this is a 404 and the URL contains confirmation
        if (is_404() && isset($_SERVER['REQUEST_URI']) && strpos($_SERVER['REQUEST_URI'], '/confirmation') === 0) {
            $confirmation_template = LEM_PLUGIN_DIR . 'templates/confirmation-page.php';
            
            if (file_exists($confirmation_template)) {
                $this->debug_log('Loading confirmation template via 404 fallback', array('uri' => $_SERVER['REQUEST_URI']));
                return $confirmation_template;
            }
        }
        
        // Fallback for direct GET parameters
        if (isset($_GET['session_id']) || (isset($_GET['lem_success']) && $_GET['lem_success'] == '1')) {
            $confirmation_template = LEM_PLUGIN_DIR . 'templates/confirmation-page.php';
            
            if (file_exists($confirmation_template)) {
                $this->debug_log('Loading confirmation template via GET parameters');
                return $confirmation_template;
            }
        }
        
        return $template;
    }
    
    // Add rewrite rules for watch page
    public function add_rewrite_rules() {
        // Use more specific patterns to ensure higher priority
        add_rewrite_rule(
            '^confirmation/?$',
            'index.php?lem_confirmation=1',
            'top'
        );
        
        // Add events page
        add_rewrite_rule(
            '^events/?$',
            'index.php?lem_events_page=1',
            'top'
        );
        
        // Add device swap page
        add_rewrite_rule(
            '^device-swap$',
            'index.php?lem_device_swap=1',
            'top'
        );
        
    }
    
    // Load additional templates for LEM routes
    public function load_additional_templates($template) {
        if (is_singular('lem_event') || is_post_type_archive('lem_event')) {
            return $template;
        }

        // Load events page template
        if (get_query_var('lem_events_page')) {
            $events_template = LEM_Template_Manager::resolve_template_file('page-events.php');

            if (file_exists($events_template)) {
                $this->debug_log('Loading events template via rewrite rule');
                return $events_template;
            }
        }
        
        // Load device swap template
        if (get_query_var('lem_device_swap')) {
            $device_swap_template = LEM_PLUGIN_DIR . 'templates/device-swap-form.php';
            
            if (file_exists($device_swap_template)) {
                return $device_swap_template;
            }
        }
        
        // Fallback: Check if the current page is EXACTLY /events (not /events/something)
        // CRITICAL: Must NOT match single events like /events/event-name/
        // CRITICAL: Only run if this is NOT already a single event
        if (isset($_SERVER['REQUEST_URI']) && !is_singular('lem_event')) {
            $uri = trim($_SERVER['REQUEST_URI'], '/');
            // Only match /events or /events/ exactly, not /events/something
            if ($uri === 'events' || preg_match('#^events/?$#', $uri)) {
                $events_template = LEM_Template_Manager::resolve_template_file('page-events.php');

                if (file_exists($events_template)) {
                    $this->debug_log('Loading events template via fallback method', array('uri' => $_SERVER['REQUEST_URI']));
                    return $events_template;
                }
            }
        }
        
        // Fallback: Check if the current page is /device-swap
        if (isset($_SERVER['REQUEST_URI']) && strpos($_SERVER['REQUEST_URI'], '/device-swap') === 0) {
            $device_swap_template = LEM_PLUGIN_DIR . 'templates/device-swap-form.php';
            
            if (file_exists($device_swap_template)) {
                return $device_swap_template;
            }
        }
        
        return $template;
    }
    
    // Add query vars for watch page
    public function add_query_vars($vars) {
        $vars[] = 'lem_session';
        $vars[] = 'lem_confirmation';
        $vars[] = 'lem_events_page';
        $vars[] = 'lem_device_swap';
        return $vars;
    }

    public function get_event_url($event_id, $args = array()) {
        $permalink = get_permalink($event_id);

        if (!$permalink) {
            $permalink = home_url('/');
        }

        if (!empty($args) && is_array($args)) {
            $permalink = add_query_arg($args, $permalink);
        }

        return $permalink;
    }

    private function clear_session_cookie() {
        if (isset($_COOKIE['lem_session_id'])) {
            setcookie('lem_session_id', '', array(
                'expires'  => time() - 3600,
                'path'     => '/',
                'secure'   => is_ssl(),
                'httponly' => true,
                'samesite' => 'Lax',
            ));
            unset($_COOKIE['lem_session_id']);
        }
    }

    public function handle_event_magic_link() {
        if (!is_singular('lem_event')) {
            return;
        }

        global $post;
        $event_id = $post ? $post->ID : 0;
        if (!$event_id) {
            return;
        }

        $base_url = $this->get_event_url($event_id);
        $redirect_base = remove_query_arg(array('magic', 'lem_error', 'lem_success'), $base_url);

        if (isset($_GET['magic'])) {
            $magic_token = sanitize_text_field(wp_unslash($_GET['magic']));

            if (!empty($magic_token)) {
                $result = $this->validate_magic_token($magic_token);

                if (!headers_sent()) {
                    if (!empty($result['valid'])) {
                        $session_id = $result['session_id'] ?? '';
                        if (!empty($session_id)) {
                            // Note: 'secure' uses is_ssl() which is the standard WordPress
                            // pattern. HTTPS should be enforced in production so that the
                            // cookie is always transmitted over a secure channel.
                            setcookie('lem_session_id', $session_id, array(
                                'expires'  => time() + DAY_IN_SECONDS,
                                'path'     => '/',
                                'secure'   => is_ssl(),
                                'httponly' => true,
                                'samesite' => 'Lax',
                            ));
                            $_COOKIE['lem_session_id'] = $session_id;
                        }

                        $this->event_access_cache[$event_id] = null;
                        wp_safe_redirect($redirect_base);
                        exit;
                    }

                    // ── Magic token invalid / already consumed ────────────────────
                    // Do NOT touch the session cookie here. If someone tries to
                    // reuse an already-consumed link (back button, shared URL, etc.),
                    // the current user's active session must be completely unaffected.
                    // Just show the error message and let them request a new link.
                    $error_message = !empty($result['error']) ? $result['error'] : 'Invalid or expired magic token.';
                    $redirect_with_error = add_query_arg('lem_error', rawurlencode($error_message), $redirect_base);
                    wp_safe_redirect($redirect_with_error);
                    exit;
                }
            }
        }

        // Prime access cache so templates can read without recalculating
        $this->get_event_access_state($event_id);
    }

    /**
     * Random anonymous display name for Ably chat (one per session).
     */
    private function generate_chat_name(): string {
        $adjectives = array('Cosmic', 'Neon', 'Crystal', 'Shadow', 'Golden', 'Velvet', 'Electric', 'Mystic', 'Blazing', 'Silent', 'Lunar', 'Crimson', 'Swift', 'Phantom', 'Radiant', 'Arctic', 'Emerald', 'Thunder', 'Pixel', 'Turbo');
        $nouns      = array('Panda', 'Falcon', 'Phoenix', 'Tiger', 'Wolf', 'Hawk', 'Lynx', 'Viper', 'Raven', 'Fox', 'Otter', 'Jaguar', 'Eagle', 'Comet', 'Nova', 'Spark', 'Storm', 'Blaze', 'Reef', 'Orbit');
        return $adjectives[ array_rand( $adjectives ) ] . $nouns[ array_rand( $nouns ) ] . wp_rand( 10, 99 );
    }

    // Session management — gating only (playback is lem:playback:*, not tied to session rotation).
    public function create_session($event_id, $email) {
        $session_id = $this->generate_session_id();
        $expires_at = time() + (24 * 60 * 60); // 24 hours

        $redis = $this->get_redis_connection();
        if ($redis) {
            $session_data = array(
                'session_id' => $session_id,
                'event_id'   => $event_id,
                'email'      => $email,
                'chat_name'  => $this->generate_chat_name(),
                'created_at' => time(),
                'expires_at' => $expires_at,
                'active'     => true,
            );

            $email_hash   = hash('sha256', $email);
            $session_json = wp_json_encode($session_data);
            $ttl          = 24 * 60 * 60;
            $active_key   = "active_sessions:{$event_id}:{$email_hash}";

            $device_settings = get_option('lem_device_settings', array());
            $max_devices     = max(1, (int) ($device_settings['max_devices'] ?? 1));

            $existing_json   = $redis->get($active_key);
            $active_sessions = ($existing_json ? json_decode($existing_json, true) : []) ?: [];

            while (count($active_sessions) >= $max_devices) {
                $oldest_id = array_shift($active_sessions);
                if (!empty($oldest_id)) {
                    $this->redis_delete_session_key($redis, $oldest_id, $event_id, $email);
                    unset(self::$memory_cache["session_val:{$oldest_id}"]);
                }
            }

            $active_sessions[] = $session_id;

            $redis->setex("session:{$session_id}", $ttl, $session_json);
            $redis->setex($active_key, $ttl, wp_json_encode($active_sessions));
            $this->sessions_index_add($redis, $email, $event_id, $session_id, $ttl);

            $this->debug_log('Session created', array(
                'session_id'   => $session_id,
                'event_id'     => $event_id,
                'email'        => $email,
                'active_count' => count($active_sessions),
                'max_devices'  => $max_devices,
            ));
        }

        return $session_id;
    }

    /**
     * Track session id for admin purge (JSON array in Redis).
     */
    private function sessions_index_add($redis, $email, $event_id, $session_id, $ttl) {
        $idx_key = LEM_Access::sessions_index_key($email, $event_id);
        $raw     = $redis->get($idx_key);
        $list    = ($raw ? json_decode($raw, true) : []) ?: [];
        if (!in_array($session_id, $list, true)) {
            $list[] = $session_id;
        }
        $redis->setex($idx_key, $ttl, wp_json_encode(array_values($list)));
    }

    /**
     * Remove one session id from the index (best-effort).
     */
    private function sessions_index_remove($redis, $email, $event_id, $session_id) {
        $idx_key = LEM_Access::sessions_index_key($email, $event_id);
        $raw     = $redis->get($idx_key);
        $list    = ($raw ? json_decode($raw, true) : []) ?: [];
        $list    = array_values(array_filter($list, function ($s) use ($session_id) {
            return $s !== $session_id;
        }));
        if (empty($list)) {
            $redis->del($idx_key);
        } else {
            $redis->setex($idx_key, 24 * 60 * 60, wp_json_encode($list));
        }
    }

    /**
     * Delete session key, active_sessions entry, index, and event_access cache.
     */
    private function redis_delete_session_key($redis, $session_id, $event_id, $email) {
        $email_hash  = hash('sha256', $email);
        $active_key  = "active_sessions:{$event_id}:{$email_hash}";
        $active_json = $redis->get($active_key);
        $list        = ($active_json ? json_decode($active_json, true) : []) ?: [];
        $list        = array_values(array_filter($list, function ($s) use ($session_id) {
            return $s !== $session_id;
        }));
        if (empty($list)) {
            $redis->del($active_key);
        } else {
            $redis->setex($active_key, 24 * 60 * 60, wp_json_encode($list));
        }
        $this->sessions_index_remove($redis, $email, $event_id, $session_id);
        $redis->del("session:{$session_id}");
        $redis->del('event_access:' . (int) $event_id . ':' . $session_id);
    }

    /**
     * Admin / revoke: delete all Redis keys for this email+event (sessions, playback, caches).
     */
    public function purge_redis_access_for_email_event($email, $event_id) {
        $redis = $this->get_redis_connection();
        if (!$redis) {
            return false;
        }
        $event_id   = (int) $event_id;
        $email_hash = hash('sha256', $email);
        $idx_key    = LEM_Access::sessions_index_key($email, $event_id);
        $raw        = $redis->get($idx_key);
        $ids        = ($raw ? json_decode($raw, true) : []) ?: [];
        foreach ($ids as $sid) {
            $redis->del("session:{$sid}");
            $redis->del("event_access:{$event_id}:{$sid}");
        }
        $redis->del($idx_key);
        $redis->del("active_sessions:{$event_id}:{$email_hash}");
        $redis->del(LEM_Access::playback_key($email, $event_id));
        return true;
    }
    
    public function validate_session($session_id) {
        // Check in-memory cache first (5 second TTL per request)
        $memory_key = "session_val:{$session_id}";
        if (isset(self::$memory_cache[$memory_key])) {
            $cached = self::$memory_cache[$memory_key];
            // Check if cache is still fresh (5 seconds)
            if (isset($cached['_cached_at']) && (time() - $cached['_cached_at']) < 5) {
                unset($cached['_cached_at']); // Remove internal field
                return $cached;
            }
        }
        
        $redis = $this->get_redis_connection();
        if (!$redis) {
            $message = 'Upstash Redis is not configured. Session validation requires Upstash — add your credentials in Live Events > Settings > Cache & Access.';
            $result = array('valid' => false, 'error' => $message);
            self::$memory_cache[$memory_key] = array_merge($result, array('_cached_at' => time()));
            return $result;
        }
        
        // Use pipeline to batch Redis calls (reduces network round-trips)
        $pipe = null;
        $use_pipeline = method_exists($redis, 'pipeline');
        
        if ($use_pipeline) {
            $pipe = $redis->pipeline();
            $pipe->get("session:{$session_id}");
            $results = $pipe->execute();
            $session_data = $results[0] ?? false;
        } else {
            $session_data = $redis->get("session:{$session_id}");
        }
        
        if (!$session_data) {
            $is_transport = method_exists($redis, 'last_request_failed') && $redis->last_request_failed();
            $error = $is_transport ? 'Redis connection unavailable — please try again' : 'Session not found';
            $result = array('valid' => false, 'error' => $error);
            self::$memory_cache[$memory_key] = array_merge($result, array('_cached_at' => time()));
            return $result;
        }
        
        $session = json_decode($session_data, true);
        if (!$session) {
            $result = array('valid' => false, 'error' => 'Invalid session data');
            self::$memory_cache[$memory_key] = array_merge($result, array('_cached_at' => time()));
            return $result;
        }
        
        // Check if session is expired
        if (time() > $session['expires_at']) {
            $this->redis_delete_session_key($redis, $session_id, $session['event_id'], $session['email']);
            $result = array('valid' => false, 'error' => 'Session expired');
            self::$memory_cache[$memory_key] = array_merge($result, array('_cached_at' => time()));
            return $result;
        }
        
        if (isset($session['active']) && !$session['active']) {
            $result = array('valid' => false, 'error' => 'Session revoked');
            self::$memory_cache[$memory_key] = array_merge($result, array('_cached_at' => time()));
            return $result;
        }

        // ── Device-limit enforcement ──────────────────────────────────────────
        // active_sessions holds an ordered JSON array of authorised session IDs
        // for this email+event (oldest first). If this session_id is no longer
        // in the list, it was evicted when a newer device was added beyond the
        // max_devices limit — reject it so the old device gets logged out.
        // An empty array means no limit is tracked yet (e.g. fresh install or
        // Redis flush) — allow the session through for backward compatibility.
        $email_hash_check = hash('sha256', $session['email']);
        $active_key       = "active_sessions:{$session['event_id']}:{$email_hash_check}";
        $active_json      = $redis->get($active_key);
        $active_sessions  = ($active_json ? json_decode($active_json, true) : []) ?: [];

        if (!empty($active_sessions) && !in_array($session_id, $active_sessions, true)) {
            $this->revoke_session($session_id);
            $result = array(
                'valid' => false,
                'error' => 'Your access link has been used on another device. Request a new magic link to continue.',
            );
            self::$memory_cache[$memory_key] = array_merge($result, array('_cached_at' => time()));
            return $result;
        }

        // Playback entitlement is validated separately via lem:playback (no per-request JWT coupling).
        $email_hash = hash('sha256', $session['email']);
        $cache_key = 'session_status:' . $session['event_id'] . ':' . $email_hash;
        $redis->setex($cache_key, 5 * 60, json_encode(array(
            'valid' => true,
            'event_id' => $session['event_id'],
            'session_id' => $session_id
        )));

        $event_obj = $this->get_event_by_id($session['event_id']);

        $result = array(
            'valid' => true,
            'session' => $session,
            'event' => $event_obj
        );
        
        // Cache in memory for 5 seconds
        self::$memory_cache[$memory_key] = array_merge($result, array('_cached_at' => time()));
        
        return $result;
    }
    
    public function revoke_session($session_id) {
        $redis = $this->get_redis_connection();
        if (!$redis) {
            return false;
        }

        $session_data = $redis->get("session:{$session_id}");
        if ($session_data) {
            $session = json_decode($session_data, true);
            if ($session) {
                $email_hash      = hash('sha256', $session['email']);
                $active_key      = "active_sessions:{$session['event_id']}:{$email_hash}";
                $active_json     = $redis->get($active_key);
                $active_sessions = ($active_json ? json_decode($active_json, true) : []) ?: [];
                $active_sessions = array_values(array_filter($active_sessions, function ($s) use ($session_id) {
                    return $s !== $session_id;
                }));
                if (empty($active_sessions)) {
                    $redis->del($active_key);
                } else {
                    $redis->setex($active_key, 24 * 60 * 60, wp_json_encode($active_sessions));
                }

                $this->sessions_index_remove($redis, $session['email'], $session['event_id'], $session_id);
                if (!empty($session['jti'])) {
                    $redis->del("jti_session:{$session['jti']}");
                }
                $redis->del("session:{$session_id}");
                $redis->del('event_access:' . (int) $session['event_id'] . ':' . $session_id);

                unset(self::$memory_cache["session_val:{$session_id}"]);

                $this->debug_log('Session revoked', array(
                    'session_id'       => $session_id,
                    'remaining_active' => count($active_sessions),
                ));

                return true;
            }
        }

        return false;
    }
    
    public function revoke_all_sessions_for_event($event_id, $email) {
        $redis = $this->get_redis_connection();
        if (!$redis) {
            return false;
        }

        $email_hash      = hash('sha256', $email);
        $active_key      = "active_sessions:{$event_id}:{$email_hash}";
        $active_json     = $redis->get($active_key);
        $active_sessions = ($active_json ? json_decode($active_json, true) : []) ?: [];

        $revoked = false;
        foreach ($active_sessions as $sid) {
            if ($this->revoke_session($sid)) {
                $revoked = true;
            }
        }

        // Ensure the key is removed even if all sessions were already expired
        $redis->del($active_key);

        return $revoked;
    }
    
    private function generate_session_id() {
        return bin2hex(random_bytes(32));
    }
    
    // Validate JWT by JTI (for session validation) - optimized with caching
    private function validate_jwt_direct_by_jti($jti) {
        // Check in-memory cache first
        $memory_key = "jwt_val:{$jti}";
        if (isset(self::$memory_cache[$memory_key])) {
            $cached = self::$memory_cache[$memory_key];
            if (isset($cached['_cached_at']) && (time() - $cached['_cached_at']) < 5) {
                unset($cached['_cached_at']);
                return $cached;
            }
        }
        
        $redis = $this->get_redis_connection();
        if (!$redis) {
            $message = 'Upstash Redis is not configured. JWT validation requires Upstash — add your credentials in Live Events > Settings > Cache & Access.';
            $result = array('valid' => false, 'error' => $message);
            self::$memory_cache[$memory_key] = array_merge($result, array('_cached_at' => time()));
            return $result;
        }
        
        // Use pipeline to batch Redis calls
        $use_pipeline = method_exists($redis, 'pipeline');
        
        if ($use_pipeline) {
            $pipe = $redis->pipeline();
            $pipe->get("jwt:{$jti}");
            $pipe->exists("revoked:{$jti}");
            $results = $pipe->execute();
            $jwt_data = $results[0] ?? false;
            $is_revoked = $results[1] ?? false;
        } else {
        $jwt_data = $redis->get("jwt:{$jti}");
            $is_revoked = $redis->exists("revoked:{$jti}");
        }
        
        if ($is_revoked) {
            $result = array('valid' => false, 'error' => 'JWT has been revoked');
            self::$memory_cache[$memory_key] = array_merge($result, array('_cached_at' => time()));
            return $result;
        }
        
        if (!$jwt_data) {
            global $wpdb;
            $table = $wpdb->prefix . 'lem_jwt_tokens';

            $jwt_record = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$table} WHERE jti = %s AND revoked_at IS NULL",
                $jti
            ));

            if (!$jwt_record) {
                return array('valid' => false, 'error' => 'JWT not found');
            }

            $expires_at = strtotime($jwt_record->expires_at);
            if ($expires_at !== false && $expires_at <= time()) {
                return array('valid' => false, 'error' => 'JWT has expired');
            }
            $jwt_info = array(
                'jti' => $jwt_record->jti,
                'event_id' => $jwt_record->event_id,
                'email' => $jwt_record->email,
                'expires_at' => $expires_at,
                'jwt_token' => $jwt_record->jwt_token
            );

            $ttl = ($expires_at !== false) ? max(60, $expires_at - time()) : 86400;
            $redis->setex("jwt:{$jti}", $ttl, json_encode($jwt_info));
            $redis->setex("jwt_token:{$jti}", $ttl, $jwt_record->jwt_token);
        } else {
            $jwt_info = json_decode($jwt_data, true);
            if (!$jwt_info) {
                return array('valid' => false, 'error' => 'Invalid JWT data');
            }
        }
        
        // Check if JWT is revoked
        if (isset($jwt_info['revoked']) && $jwt_info['revoked']) {
            return array('valid' => false, 'error' => 'JWT has been revoked');
        }
        
        // Check if JWT is expired
        if (time() > $jwt_info['expires_at']) {
            return array('valid' => false, 'error' => 'JWT has expired');
        }
        
        // Get event data
        $event = $this->get_event_by_id($jwt_info['event_id']);
        if (!$event) {
            return array('valid' => false, 'error' => 'Event not found');
        }
        
        return array(
            'valid' => true,
            'event' => $event,
            'jwt_info' => $jwt_info
        );
    }
    
    // Render smart event ticket block
    public function render_event_ticket_block($attributes) {
        global $post;
        
        // Get event data from current post meta
        $playback_id = get_post_meta($post->ID, '_lem_playback_id', true);
        $event_date = get_post_meta($post->ID, '_lem_event_date', true);
        $is_free = get_post_meta($post->ID, '_lem_is_free', true);
        $price_id = get_post_meta($post->ID, '_lem_price_id', true);
        
        // Get block attributes
        $button_text = isset($attributes['buttonText']) ? $attributes['buttonText'] : 'Get Access';
        $email_placeholder = isset($attributes['emailPlaceholder']) ? $attributes['emailPlaceholder'] : 'Enter your email address';
        $show_price = isset($attributes['showPrice']) ? $attributes['showPrice'] : true;
        $theme = isset($attributes['theme']) ? $attributes['theme'] : 'dark';
        $size = isset($attributes['size']) ? $attributes['size'] : 'large';
        $show_event_details = isset($attributes['showEventDetails']) ? $attributes['showEventDetails'] : true;
        
        // Check if we're on an event page
        if ($post->post_type !== 'lem_event') {
            return '<p>This block can only be used on event pages.</p>';
        }
        
        $success_message = '';
        $error_message = '';

        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['lem_request_new_link'])) {
            $email_input = sanitize_email($_POST['email'] ?? '');
            $nonce_valid = isset($_POST['lem_new_link_nonce']) && wp_verify_nonce($_POST['lem_new_link_nonce'], 'lem_request_new_link');

            if (!$nonce_valid) {
                $error_message = __('Security check failed. Refresh and try again.', 'live-event-manager');
            } elseif (empty($email_input)) {
                $error_message = __('Please enter a valid email address.', 'live-event-manager');
            } else {
                $result = $this->validate_email_and_send_link($email_input, $post->ID);
                if (!empty($result['valid'])) {
                    $success_message = $result['message'] ?? __('Fresh access link sent. Check your inbox!', 'live-event-manager');
                } else {
                    $error_message = $result['error'] ?? __('Unable to send a new link right now. Please try again shortly.', 'live-event-manager');
                }
            }
        }

        // Create event object for template
        $event = (object) array(
            'event_id' => $post->ID, // Use post ID as event ID
            'title' => $post->post_title,
            'event_date' => $event_date,
            'is_free' => $is_free === 'free',
            'price_id' => $price_id,
            'playback_id' => $playback_id,
            'post_status' => get_post_status($post->ID)
        );

        $event_access = $this->get_event_access_state($event->event_id);

        if (!empty($success_message)) {
            $event_access['success_message'] = $success_message;
        }

        if (!empty($error_message) && empty($event_access['error_message'])) {
            $event_access['error_message'] = $error_message;
        }
        
        ob_start();
        include LEM_Template_Manager::resolve_template_file('event-ticket-block.php');
        return ob_get_clean();
    }

    // Render gated video player block
    public function render_gated_video_block($attributes) {
        // Make the plugin instance available to the template
        global $live_event_manager;
        $live_event_manager = $this;
        
        ob_start();
        include LEM_PLUGIN_DIR . 'templates/gated-video-block.php';
        return ob_get_clean();
    }
    
    /**
     * Render simulcast player shortcode
     * Usage: [simulcast_player]
     */
    public function render_simulcast_player_shortcode($atts) {
        // Make the plugin instance available to the template
        global $live_event_manager;
        $live_event_manager = $this;
        
        // Get settings
        $settings = get_option('lem_settings', array());
        $live_stream_id = $settings['mux_live_stream_id'] ?? '';
        
        if (empty($live_stream_id)) {
            return '<div class="lem-error">Mux Live Stream ID not configured. Please set it in Live Events → Settings.</div>';
        }
        
        // Get current user data
        $current_user = wp_get_current_user();
        $user_data = array(
            'user_id' => 0,
            'display_name' => '',
            'is_logged_in' => false
        );
        
        if ($current_user && $current_user->ID > 0) {
            $user_data = array(
                'user_id' => $current_user->ID,
                'display_name' => $current_user->display_name,
                'is_logged_in' => true
            );
        }
        
        // Enqueue React app assets (we'll create this)
        wp_enqueue_script('lem-simulcast-player', LEM_PLUGIN_URL . 'assets/simulcast-player.js', array('jquery'), LEM_VERSION, true);
        wp_localize_script('lem-simulcast-player', 'lemSimulcastData', array(
            'live_stream_id' => $live_stream_id,
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('lem_nonce'),
            'user' => $user_data,
            'api_url' => get_rest_url(null, 'lem/v1/')
        ));
        
        // Render React app container
        ob_start();
        ?>
        <div id="lem-simulcast-player-app" data-live-stream-id="<?php echo esc_attr($live_stream_id); ?>">
            <div class="lem-loading">Loading player...</div>
        </div>
        <?php
        return ob_get_clean();
    }
    
    // Admin menu
    public function add_admin_menu() {
        // 1. Live Streams — primary operational view
        add_submenu_page(
            'edit.php?post_type=lem_event',
            'Live Streams',
            'Live Streams',
            'manage_options',
            'live-event-manager-stream-management',
            array($this, 'render_stream_management_page')
        );

        // 2. Vendors — streaming & payment integrations (was "Stream Vendors")
        add_submenu_page(
            'edit.php?post_type=lem_event',
            'Vendors',
            'Vendors',
            'manage_options',
            'live-event-manager-stream-vendors',
            array($this, 'render_stream_vendors_page')
        );

        // 3. Access Tokens — JWT management (was "JWT Manager")
        add_submenu_page(
            'edit.php?post_type=lem_event',
            'Access Tokens',
            'Access Tokens',
            'manage_options',
            'live-event-manager-jwt',
            array($this, 'render_jwt_page')
        );

        add_submenu_page(
            'edit.php?post_type=lem_event',
            'Revoke access',
            'Revoke access',
            'manage_options',
            'live-event-manager-revoke-access',
            array($this, 'render_revoke_access_page')
        );

        add_submenu_page(
            'edit.php?post_type=lem_event',
            'Payments',
            'Payments',
            'manage_options',
            'live-event-manager-payments',
            array($this, 'render_payments_page')
        );

        // 4. Settings — general plugin settings (was "Event Settings")
        add_submenu_page(
            'edit.php?post_type=lem_event',
            'Settings',
            'Settings',
            'manage_options',
            'live-event-manager-settings',
            array($this, 'render_settings_page')
        );

        // 5. Devices — device-specific settings (was "Device Settings")
        add_submenu_page(
            'edit.php?post_type=lem_event',
            'Devices',
            'Devices',
            'manage_options',
            'live-event-manager-device-settings',
            array($this, 'render_device_settings_page')
        );

        // 6. User Guide
        add_submenu_page(
            'edit.php?post_type=lem_event',
            'User Guide',
            'User Guide',
            'manage_options',
            'live-event-manager-user-guide',
            array($this, 'render_user_guide_page')
        );

        // 7. Templates — custom template pack management
        add_submenu_page(
            'edit.php?post_type=lem_event',
            'Templates',
            'Templates',
            'manage_options',
            'live-event-manager-templates',
            array($this, 'render_templates_page')
        );

        // 8. Debug — system diagnostics (was "System Debug")
        add_submenu_page(
            'edit.php?post_type=lem_event',
            'Debug',
            'Debug',
            'manage_options',
            'live-event-manager-debug',
            array($this, 'render_debug_page')
        );

        // Register old Stream Setup slug so existing bookmarks still work (hidden from menu).
        add_submenu_page(
            'edit.php?post_type=lem_event',
            'Stream Setup',
            'Stream Setup',
            'manage_options',
            'live-event-manager-stream-setup',
            array($this, 'render_stream_setup_page')
        );
        remove_submenu_page('edit.php?post_type=lem_event', 'live-event-manager-stream-setup');
    }
    
    // Remove the automatic "Add New" submenu to avoid duplication
    public function remove_add_new_submenu() {
        remove_submenu_page('edit.php?post_type=lem_event', 'post-new.php?post_type=lem_event');
    }
    
    // Enqueue admin assets
    public function enqueue_admin_assets($hook) {
        // Load on plugin pages and event edit pages
        $should_load = false;
        
        // Plugin pages (submenu slugs contain "live-event-manager")
        if (strpos($hook, 'live-event-manager') !== false) {
            $should_load = true;
        }

        // CPT submenu screens use hooks like lem_event_page_* (slug may not include that substring).
        if (strpos($hook, 'lem_event_page_') !== false) {
            $should_load = true;
        }
        
        // Event edit pages (post.php and post-new.php for lem_event post type)
        if (in_array($hook, array('post.php', 'post-new.php'))) {
            global $post_type;
            if ($post_type === 'lem_event') {
                $should_load = true;
            }
        }
        
        if (!$should_load) {
            return;
        }
        
        wp_enqueue_script('lem-admin', LEM_PLUGIN_URL . 'assets/admin.js', array('jquery'), LEM_VERSION, true);
        wp_enqueue_style('lem-admin', LEM_PLUGIN_URL . 'assets/admin.css', array(), LEM_VERSION);
        
        wp_localize_script('lem-admin', 'lem_ajax', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('lem_nonce'),
            'stream_setup_nonce' => wp_create_nonce('lem_stream_setup_nonce')
        ));
    }
    
    // Ensure lem_ajax is available on event edit pages
    public function enqueue_event_edit_assets() {
        global $post_type;
        
        // Only on lem_event post type pages
        if ($post_type !== 'lem_event') {
            return;
        }
        
        // Add lem_ajax object to the page
        wp_add_inline_script('jquery', '
            if (typeof lem_ajax === "undefined") {
                window.lem_ajax = {
                    ajax_url: "' . admin_url('admin-ajax.php') . '",
                    nonce: "' . wp_create_nonce('lem_nonce') . '"
                };
            }
        ', 'before');
    }
    
    // Enqueue block editor assets
    public function enqueue_block_editor_assets() {
        wp_enqueue_script(
            'lem-blocks',
            LEM_PLUGIN_URL . 'assets/blocks.js',
            array('wp-blocks', 'wp-element', 'wp-editor', 'wp-components', 'wp-i18n'),
            LEM_VERSION,
            true
        );
        
        wp_enqueue_script(
            'lem-gated-video-block',
            LEM_PLUGIN_URL . 'assets/gated-video-block.js',
            array('wp-blocks', 'wp-element', 'wp-editor', 'wp-components', 'wp-i18n'),
            LEM_VERSION,
            true
        );
        
        wp_enqueue_style(
            'lem-blocks-editor',
            LEM_PLUGIN_URL . 'assets/blocks-editor.css',
            array('wp-edit-blocks'),
            LEM_VERSION
        );
        
        wp_localize_script('lem-blocks', 'lem_blocks', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('lem_blocks_nonce')
        ));
        
        // Add filter to restrict event ticket block to event post type only
        add_filter('allowed_block_types_all', array($this, 'restrict_event_ticket_block'), 10, 2);
        add_filter('block_categories_all', array($this, 'restrict_event_ticket_block_category'), 10, 2);
    }
    
    // Restrict event ticket block to event post type only
    public function restrict_event_ticket_block($allowed_block_types, $block_editor_context) {
        // Get current post type
        $post_type = get_post_type();
        
        // If we're not editing an event post type, remove the event ticket block
        if ($post_type !== 'lem_event') {
            if (is_array($allowed_block_types)) {
                $allowed_block_types = array_filter($allowed_block_types, function($block_type) {
                    return $block_type !== 'lem/event-ticket';
                });
            }
        }
        
        return $allowed_block_types;
    }
    
    // Restrict event ticket block category to event post type only
    public function restrict_event_ticket_block_category($block_categories, $block_editor_context) {
        // Get current post type
        $post_type = get_post_type();
        
        // If we're not editing an event post type, hide the Live Event Manager category
        if ($post_type !== 'lem_event') {
            $block_categories = array_filter($block_categories, function($category) {
                // Handle both object and array formats
                if (is_object($category)) {
                    return $category->slug !== 'live-event-manager';
                } elseif (is_array($category)) {
                    return $category['slug'] !== 'live-event-manager';
                }
                return true; // Keep if we can't determine the format
            });
        }
        
        return $block_categories;
    }
    
    // Enqueue public assets
    public function enqueue_public_assets() {
        // Only load on event pages or pages with our blocks
        global $post;
        $should_load = false;
        
        // Check if we're on an event page
        if (is_singular('lem_event')) {
            $should_load = true;
        }

        // Check if we're on the events archive
        if (is_post_type_archive('lem_event')) {
            $should_load = true;
        }

        // Check if we're on the confirmation page
        if (isset($_SERVER['REQUEST_URI']) && strpos($_SERVER['REQUEST_URI'], '/confirmation') === 0) {
            $should_load = true;
        }
        
        // Check if the page contains our blocks
        if ($post && $post->post_content && (has_shortcode($post->post_content, 'lem_ticket_sales') ||
            strpos($post->post_content, 'lem/ticket-sales') !== false ||
            strpos($post->post_content, 'lem/payment-page') !== false)) {
            $should_load = true;
        }

        // Check if the page uses the events template
        if (is_page() && $post) {
            $page_template = get_page_template_slug($post->ID);
            if ($page_template === 'page-events.php') {
                $should_load = true;
            }
        }

        if (!$should_load) {
            return;
        }

        // Enqueue global styles for all LEM pages
        wp_enqueue_style('lem-global-styles', LEM_PLUGIN_URL . 'assets/lem-global-styles.css', array(), LEM_VERSION);
        
        wp_enqueue_script('lem-public', LEM_PLUGIN_URL . 'assets/public.js', array('jquery'), LEM_VERSION, true);
        wp_enqueue_style('lem-public', LEM_PLUGIN_URL . 'assets/public.css', array(), LEM_VERSION);
        
        // Enqueue Mux player script for video functionality
        wp_enqueue_script(
            'mux-player',
            'https://cdn.jsdelivr.net/npm/@mux/mux-player',
            array(),
            null,
            true
        );
        
        // Get current user data for React app
        $current_user = wp_get_current_user();
        $user_data = array(
            'user_id' => 0,
            'display_name' => '',
            'is_logged_in' => false
        );
        
        if ($current_user && $current_user->ID > 0) {
            $user_data = array(
                'user_id' => $current_user->ID,
                'display_name' => $current_user->display_name,
                'is_logged_in' => true
            );
        }
        
        wp_localize_script('lem-public', 'lem_ajax', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('lem_nonce'),
            'user' => $user_data
        ));

        // Ably realtime chat — only on single event pages and only if configured.
        $settings     = get_option('lem_settings', []);
        $ably_api_key = trim($settings['ably_api_key'] ?? '');
        if (!empty($ably_api_key) && is_singular('lem_event')) {
            wp_enqueue_script(
                'ably-realtime',
                'https://cdn.ably.com/lib/ably.min-2.js',
                [],
                null,
                true
            );
            wp_add_inline_script(
                'lem-public',
                'window.lemAblyEnabled   = true;' .
                'window.lemAblyAuthUrl   = ' . wp_json_encode(admin_url('admin-ajax.php')) . ';' .
                'window.lemAblyViewerName = window.lemViewerName || "Viewer";',
                'after'
            );
        }

        // Enqueue custom template pack assets (CSS after lem-public, JS in footer).
        $style_url  = LEM_Template_Manager::get_active_asset_url('assets/style.css');
        $script_url = LEM_Template_Manager::get_active_asset_url('assets/script.js');
        if (!empty($style_url)) {
            wp_enqueue_style(
                'lem-custom-template',
                $style_url,
                array('lem-public'),
                LEM_Template_Manager::get_active_slug()
            );
        }
        if (!empty($script_url)) {
            wp_enqueue_script(
                'lem-custom-template',
                $script_url,
                array('lem-public', 'jquery'),
                LEM_Template_Manager::get_active_slug(),
                true
            );
        }
    }
    

    
    /**
     * Admin: revoke viewer access for an event (DB + Redis).
     */
    public function render_revoke_access_page() {
        if (!current_user_can('manage_options')) {
            return;
        }

        $notice = '';
        if (isset($_POST['lem_revoke_submit']) && isset($_POST['lem_revoke_nonce']) && wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['lem_revoke_nonce'])), 'lem_revoke_access')) {
            $email    = sanitize_email(wp_unslash($_POST['revoke_email'] ?? ''));
            $event_id = intval($_POST['revoke_event_id'] ?? 0);
            if ($email && $event_id && class_exists('LEM_Access')) {
                LEM_Access::record_revocation($email, $event_id, get_current_user_id());
                $this->purge_redis_access_for_email_event($email, $event_id);
                global $wpdb;
                $table = $wpdb->prefix . 'lem_jwt_tokens';
                $wpdb->query(
                    $wpdb->prepare(
                        "UPDATE $table SET revoked_at = %s WHERE email = %s AND event_id = %s AND revoked_at IS NULL",
                        current_time('mysql'),
                        $email,
                        (string) $event_id
                    )
                );
                $notice = 'success';
            } else {
                $notice = 'invalid';
            }
        }

        include LEM_PLUGIN_DIR . 'templates/revoke-access-page.php';
    }

    /**
     * Admin: paid tickets / Stripe sessions (from lem_jwt_tokens).
     */
    public function render_payments_page() {
        if (!current_user_can('manage_options')) {
            return;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'lem_jwt_tokens';

        $filter_event = isset($_GET['lem_event']) ? intval($_GET['lem_event']) : 0;

        $where = 't.payment_id IS NOT NULL AND t.payment_id != \'\'';
        $args  = array();
        if ($filter_event > 0) {
            $where .= ' AND t.event_id = %d';
            $args[] = $filter_event;
        }

        $sql = "SELECT t.id, t.email, t.event_id, t.payment_id, t.created_at, t.expires_at, t.revoked_at, t.jti,
                p.post_title AS event_title
            FROM {$table} t
            LEFT JOIN {$wpdb->posts} p ON p.ID = CAST(t.event_id AS UNSIGNED) AND p.post_type = 'lem_event'
            WHERE {$where}
            ORDER BY t.created_at DESC
            LIMIT 500";

        if (!empty($args)) {
            $rows = $wpdb->get_results($wpdb->prepare($sql, $args));
        } else {
            $rows = $wpdb->get_results($sql);
        }

        $events_for_filter = get_posts(array(
            'post_type'      => 'lem_event',
            'posts_per_page' => 200,
            'post_status'    => 'publish',
            'orderby'        => 'title',
            'order'          => 'ASC',
        ));

        $settings    = get_option('lem_settings', array());
        $stripe_mode = $settings['stripe_mode'] ?? 'test';
        $stripe_base = ($stripe_mode === 'live')
            ? 'https://dashboard.stripe.com/checkout/sessions/'
            : 'https://dashboard.stripe.com/test/checkout/sessions/';

        include LEM_PLUGIN_DIR . 'templates/payments-page.php';
    }

    // Render settings page
    public function render_settings_page() {
        $settings = get_option('lem_settings', array());
        include LEM_PLUGIN_DIR . 'templates/settings-page.php';
    }
    
    // Render restrictions page
    public function render_restrictions_page() {
        $settings = get_option('lem_settings', array());
        include LEM_PLUGIN_DIR . 'templates/restrictions-page.php';
    }
    
    // Render JWT manager page
    public function render_jwt_page() {
        $settings = get_option('lem_settings', array());
        include LEM_PLUGIN_DIR . 'templates/jwt-page.php';
    }
    
    public function render_device_settings_page() {
        include LEM_PLUGIN_DIR . 'templates/device-settings-page.php';
    }
    
    public function render_stream_management_page() {
        include LEM_PLUGIN_DIR . 'templates/stream-management-page.php';
    }
    
    public function render_stream_setup_page() {
        // Stream Setup has been merged into Live Streams — redirect transparently.
        $stream_id = sanitize_text_field($_GET['stream_id'] ?? '');
        $args = array('post_type' => 'lem_event', 'page' => 'live-event-manager-stream-management');
        if ($stream_id) {
            $args['stream_id'] = $stream_id;
        }
        wp_redirect(admin_url(add_query_arg($args, 'edit.php')));
        exit;
    }
    
    public function render_debug_page() {
        include LEM_PLUGIN_DIR . 'templates/debug-page.php';
    }

    public function render_templates_page() {
        include LEM_PLUGIN_DIR . 'templates/templates-page.php';
    }
    
    public function render_publisher_page() {
        include LEM_PLUGIN_DIR . 'templates/publisher-page.php';
    }
    
    public function render_stream_vendors_page() {
        include LEM_PLUGIN_DIR . 'templates/stream-vendors-page.php';
    }
    
    // Stream setup AJAX handlers
    public function ajax_get_rtmp_info() {
        check_ajax_referer('lem_stream_setup_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }
        
        $stream_id = sanitize_text_field($_POST['stream_id'] ?? '');
        if (empty($stream_id)) {
            $settings = get_option('lem_settings', array());
            $stream_id = $settings['mux_live_stream_id'] ?? '';
        }
        
        if (empty($stream_id)) {
            wp_send_json_error('Stream ID is required');
        }
        
        $request = new WP_REST_Request('GET', '/lem/v1/rtmp-info');
        $request->set_param('stream_id', $stream_id);
        $result = $this->get_rtmp_info($request);
        
        if (is_wp_error($result)) {
            wp_send_json_error($result->get_error_message());
        } else {
            wp_send_json_success($result);
        }
    }
    
    public function ajax_create_simulcast_target() {
        check_ajax_referer('lem_stream_setup_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }
        
        $stream_id = sanitize_text_field($_POST['stream_id'] ?? '');
        $url = sanitize_text_field($_POST['url'] ?? '');
        $stream_key = sanitize_text_field($_POST['stream_key'] ?? '');
        
        if (empty($stream_id)) {
            $settings = get_option('lem_settings', array());
            $stream_id = $settings['mux_live_stream_id'] ?? '';
        }
        
        if (empty($stream_id) || empty($url)) {
            wp_send_json_error('Stream ID and URL are required');
        }
        
        $request = new WP_REST_Request('POST', '/lem/v1/simulcast-targets');
        $request->set_param('stream_id', $stream_id);
        $request->set_param('url', $url);
        if ($stream_key) {
            $request->set_param('stream_key', $stream_key);
        }
        
        $result = $this->create_simulcast_target($request);
        
        if (is_wp_error($result)) {
            wp_send_json_error($result->get_error_message());
        } else {
            wp_send_json_success($result);
        }
    }
    
    public function ajax_delete_simulcast_target() {
        check_ajax_referer('lem_stream_setup_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }
        
        $stream_id = sanitize_text_field($_POST['stream_id'] ?? '');
        $target_id = sanitize_text_field($_POST['target_id'] ?? '');
        
        if (empty($stream_id)) {
            $settings = get_option('lem_settings', array());
            $stream_id = $settings['mux_live_stream_id'] ?? '';
        }
        
        if (empty($stream_id) || empty($target_id)) {
            wp_send_json_error('Stream ID and Target ID are required');
        }
        
        $request = new WP_REST_Request('DELETE', '/lem/v1/simulcast-targets/' . $target_id);
        $request->set_param('stream_id', $stream_id);
        
        $result = $this->delete_simulcast_target($request);
        
        if (is_wp_error($result)) {
            wp_send_json_error($result->get_error_message());
        } else {
            wp_send_json_success($result);
        }
    }
    
    // ── Provider upload ───────────────────────────────────────────────────────

    // ── Template pack AJAX handlers ────────────────────────────────────────────

    /**
     * AJAX: Upload and install a template pack from a ZIP file.
     */
    public function ajax_upload_template() {
        check_ajax_referer('lem_templates_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized.');
        }

        $upload_error = $_FILES['template_zip']['error'] ?? -1;
        if (empty($_FILES['template_zip']) || $upload_error !== UPLOAD_ERR_OK) {
            $messages = array(
                UPLOAD_ERR_INI_SIZE   => 'File exceeds the server upload_max_filesize limit.',
                UPLOAD_ERR_FORM_SIZE  => 'File exceeds the form MAX_FILE_SIZE limit.',
                UPLOAD_ERR_PARTIAL    => 'File was only partially uploaded.',
                UPLOAD_ERR_NO_FILE    => 'No file was uploaded.',
                UPLOAD_ERR_NO_TMP_DIR => 'Missing temporary folder on the server.',
                UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk.',
            );
            $msg = $messages[$upload_error] ?? 'Upload failed (error code: ' . intval($upload_error) . ').';
            wp_send_json_error($msg);
        }

        $result = LEM_Template_Manager::install_from_zip($_FILES['template_zip']['tmp_name']);
        if (is_wp_error($result)) {
            wp_send_json_error($result->get_error_message());
        }

        wp_send_json_success(array(
            'message'  => sprintf('Template "%s" installed successfully.', esc_html($result['name'])),
            'template' => $result,
        ));
    }

    /**
     * AJAX: Activate an installed template pack by slug.
     */
    public function ajax_activate_template() {
        check_ajax_referer('lem_templates_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized.');
        }

        $slug   = sanitize_key($_POST['slug'] ?? '');
        $result = LEM_Template_Manager::activate_template($slug);
        if (is_wp_error($result)) {
            wp_send_json_error($result->get_error_message());
        }

        wp_send_json_success(array(
            'message' => 'Template activated.',
            'slug'    => $slug,
        ));
    }

    /**
     * Admin POST: Stream a bundled template pack as a downloadable ZIP.
     *
     * URL: admin-post.php?action=lem_download_pack&pack={slug}&nonce={nonce}
     * Source packs live in plugin/template-packs/{slug}/.
     */
    public function handle_download_pack() {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized.');
        }
        if (!isset($_GET['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['nonce'])), 'lem_download_pack')) {
            wp_die('Invalid or expired link. Please reload the Templates page and try again.');
        }

        $slug     = sanitize_key($_GET['pack'] ?? '');
        $pack_dir = LEM_PLUGIN_DIR . 'template-packs/' . $slug . '/';

        if (empty($slug) || !is_dir($pack_dir)) {
            wp_die('Template pack not found.');
        }

        if (!class_exists('ZipArchive')) {
            wp_die('ZipArchive PHP extension is required to download template packs.');
        }

        // Build ZIP in a temp file
        $tmp = wp_tempnam('lem-pack');
        $zip = new ZipArchive();
        if ($zip->open($tmp, ZipArchive::OVERWRITE) !== true) {
            wp_die('Could not create ZIP file.');
        }

        // Walk all files in the pack directory and add them under {slug}/
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($pack_dir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY
        );
        foreach ($iterator as $file) {
            if (!$file->isFile()) {
                continue;
            }
            $real  = $file->getRealPath();
            $rel   = $slug . '/' . ltrim(str_replace(realpath($pack_dir), '', $real), DIRECTORY_SEPARATOR);
            $rel   = str_replace('\\', '/', $rel); // normalise on Windows
            $zip->addFile($real, $rel);
        }
        $zip->close();

        // Stream to browser
        $filename = $slug . '.zip';
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . filesize($tmp));
        header('Pragma: no-cache');
        header('Expires: 0');
        readfile($tmp);
        unlink($tmp);
        exit;
    }

    /**
     * AJAX: Delete an installed template pack by slug.
     */
    public function ajax_delete_template() {
        check_ajax_referer('lem_templates_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized.');
        }

        $slug   = sanitize_key($_POST['slug'] ?? '');
        $result = LEM_Template_Manager::delete_template($slug);
        if (is_wp_error($result)) {
            wp_send_json_error($result->get_error_message());
        }

        wp_send_json_success(array('message' => 'Template deleted.'));
    }

    // ── Streaming provider upload ───────────────────────────────────────────────

    /**
     * AJAX: Upload a new streaming provider class file.
     *
     * Validates the PHP file without executing it, moves it into
     * services/streaming/providers/, and auto-registers it in the factory.
     */
    public function ajax_upload_provider() {
        check_ajax_referer('lem_upload_provider_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized.');
            return;
        }

        if (empty($_FILES['provider_file']) || $_FILES['provider_file']['error'] !== UPLOAD_ERR_OK) {
            $code = $_FILES['provider_file']['error'] ?? -1;
            wp_send_json_error('Upload failed (error code ' . $code . '). Please try again.');
            return;
        }

        $file = $_FILES['provider_file'];

        // ── Size limit: 512 KB ────────────────────────────────────────────
        if ($file['size'] > 524288) {
            wp_send_json_error('File too large. Maximum size is 512 KB.');
            return;
        }

        // ── Filename must match class-{slug}-provider.php ─────────────────
        $filename = sanitize_file_name(basename($file['name']));
        if (!preg_match('/^class-([a-z0-9]+(?:-[a-z0-9]+)*)-provider\.php$/i', $filename, $slug_match)) {
            wp_send_json_error('Filename must follow the pattern: <code>class-{slug}-provider.php</code> (lowercase, hyphens allowed).');
            return;
        }

        // Derive the provider ID from the filename slug (strip hyphens for the ID)
        $provider_id = strtolower(str_replace('-', '', $slug_match[1]));

        // ── Read content ──────────────────────────────────────────────────
        $content = file_get_contents($file['tmp_name']);
        if ($content === false) {
            wp_send_json_error('Could not read the uploaded file.');
            return;
        }

        // ── Must be valid PHP ─────────────────────────────────────────────
        if (strpos(ltrim($content), '<?php') !== 0) {
            wp_send_json_error('File must be a valid PHP file starting with &lt;?php.');
            return;
        }

        // ── Must implement the interface ──────────────────────────────────
        if (strpos($content, 'implements LEM_Streaming_Provider_Interface') === false) {
            wp_send_json_error('Provider class must implement <code>LEM_Streaming_Provider_Interface</code>.');
            return;
        }

        // ── Extract class name ────────────────────────────────────────────
        if (!preg_match('/class\s+(LEM_\w+_Provider)\s+implements\s+LEM_Streaming_Provider_Interface/i', $content, $class_match)) {
            wp_send_json_error('Could not find a valid class declaration. Expected: <code>class LEM_FooBar_Provider implements LEM_Streaming_Provider_Interface</code>.');
            return;
        }
        $class_name = $class_match[1];

        // ── Strict class name validation (prevent code injection) ─────────
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $class_name)) {
            wp_send_json_error('Invalid class name. Only letters, numbers, and underscores are allowed.');
            return;
        }

        // ── Guard against overwriting built-ins ───────────────────────────
        $protected = ['class-mux-provider.php', 'class-streaming-provider-interface.php', 'class-streaming-provider-factory.php'];
        if (in_array(strtolower($filename), $protected, true)) {
            wp_send_json_error('Cannot overwrite a built-in plugin file.');
            return;
        }

        // ── Destination directory ─────────────────────────────────────────
        $dest_dir = LEM_PLUGIN_DIR . 'services/streaming/providers/';
        if (!is_dir($dest_dir)) {
            wp_send_json_error('Provider directory does not exist: ' . esc_html($dest_dir));
            return;
        }
        if (!is_writable($dest_dir)) {
            wp_send_json_error('Provider directory is not writable. Check filesystem permissions.');
            return;
        }

        // ── Move file ─────────────────────────────────────────────────────
        $dest_path   = $dest_dir . $filename;
        $is_replace  = file_exists($dest_path);

        if (!move_uploaded_file($file['tmp_name'], $dest_path)) {
            wp_send_json_error('Failed to move file into the plugin directory.');
            return;
        }

        // ── Auto-register in factory ──────────────────────────────────────
        $registered      = $this->register_provider_in_factory($provider_id, $class_name);
        $register_snippet = "\$this->register_provider('{$provider_id}', '{$class_name}');";

        wp_send_json_success(array(
            'provider_id'      => $provider_id,
            'class_name'       => $class_name,
            'filename'         => $filename,
            'is_replace'       => $is_replace,
            'registered'       => $registered,
            'register_snippet' => $register_snippet,
        ));
    }

    /**
     * Inject a register_provider() call into the factory file.
     *
     * Uses the "Additional providers" comment as an anchor so the edit is
     * deterministic and safe even if the file has changed.
     */
    private function register_provider_in_factory(string $provider_id, string $class_name): bool {
        // ── Validate inputs to prevent code injection into factory file ───
        if (!current_user_can('manage_options')) {
            return false;
        }
        if (!preg_match('/^[a-z0-9]+$/', $provider_id)) {
            return false;
        }
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $class_name)) {
            return false;
        }

        $factory_file = LEM_PLUGIN_DIR . 'services/streaming/class-streaming-provider-factory.php';

        if (!file_exists($factory_file) || !is_writable($factory_file)) {
            return false;
        }

        $content = file_get_contents($factory_file);
        if ($content === false) {
            return false;
        }

        // Already registered — nothing to do.
        if (strpos($content, "register_provider('{$provider_id}'") !== false) {
            return true;
        }

        $new_line = "        \$this->register_provider('{$provider_id}', '{$class_name}');";
        $anchor   = '        // Additional providers can be registered here in the future';

        if (strpos($content, $anchor) === false) {
            return false; // anchor missing — don't guess
        }

        $updated = str_replace($anchor, $new_line . "\n" . $anchor, $content);

        return file_put_contents($factory_file, $updated) !== false;
    }

    // ── Stream management AJAX handlers ──────────────────────────────────────
    public function ajax_create_stream() {
        check_ajax_referer('lem_stream_management_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }
        
        $provider = sanitize_text_field($_POST['provider'] ?? 'mux');
        $passthrough = sanitize_text_field($_POST['passthrough'] ?? '');
        
        // Handle playback_policies for live stream - can be JSON string or array
        $playback_policies = array('public'); // Default
        if (isset($_POST['playback_policies'])) {
            if (is_string($_POST['playback_policies'])) {
                $decoded = json_decode(stripslashes($_POST['playback_policies']), true);
                if (is_array($decoded) && !empty($decoded)) {
                    $playback_policies = array_map('sanitize_text_field', $decoded);
                } elseif (is_string($decoded)) {
                    $playback_policies = array(sanitize_text_field($decoded));
                }
            } elseif (is_array($_POST['playback_policies'])) {
                $playback_policies = array_map('sanitize_text_field', $_POST['playback_policies']);
            }
        }
        
        // Handle asset_playback_policies for recorded assets - can be JSON string or array
        $asset_playback_policies = array('public'); // Default
        if (isset($_POST['asset_playback_policies'])) {
            if (is_string($_POST['asset_playback_policies'])) {
                $decoded = json_decode(stripslashes($_POST['asset_playback_policies']), true);
                if (is_array($decoded) && !empty($decoded)) {
                    $asset_playback_policies = array_map('sanitize_text_field', $decoded);
                } elseif (is_string($decoded)) {
                    $asset_playback_policies = array(sanitize_text_field($decoded));
                }
            } elseif (is_array($_POST['asset_playback_policies'])) {
                $asset_playback_policies = array_map('sanitize_text_field', $_POST['asset_playback_policies']);
            }
        }
        
        // Handle boolean values - can be 'true', 'false', '1', '0', or actual boolean
        $reduced_latency = isset($_POST['reduced_latency']) && (
            $_POST['reduced_latency'] === 'true' || 
            $_POST['reduced_latency'] === '1' || 
            $_POST['reduced_latency'] === true
        );
        $test_mode = isset($_POST['test_mode']) && (
            $_POST['test_mode'] === 'true' || 
            $_POST['test_mode'] === '1' || 
            $_POST['test_mode'] === true
        );
        
        // Get provider via factory
        $provider_factory = LEM_Streaming_Provider_Factory::get_instance();
        $streaming_provider = $provider_factory->get_provider($provider, $this);
        
        if (!$streaming_provider) {
            wp_send_json_error('Invalid provider or provider not available');
        }
        
        if (!$streaming_provider->is_configured()) {
            wp_send_json_error('Provider credentials not configured. Please configure in Stream Vendors settings.');
        }
        
        // Delegate to provider's create_stream method
        $params = array(
            'passthrough' => $passthrough,
            'playback_policies' => $playback_policies,
            'asset_playback_policies' => $asset_playback_policies,
            'reduced_latency' => $reduced_latency,
            'test_mode' => $test_mode
        );
        
        $result = $streaming_provider->create_stream($params);
        
        if (is_wp_error($result)) {
            $error_message = $result->get_error_message();
            $error_data = $result->get_error_data();
            
            // Include additional error details if available
            if (is_array($error_data) && isset($error_data['status'])) {
                $error_message .= ' (HTTP ' . $error_data['status'] . ')';
            }
            
            $this->debug_log('Stream creation failed', array(
                'provider' => $provider,
                'error' => $error_message,
                'error_code' => $result->get_error_code(),
                'error_data' => $error_data
            ));
            
            wp_send_json_error($error_message);
        }
        
        // Clear cache
        $redis = $this->get_redis_connection();
        if ($redis) {
            if ($provider === 'mux') {
                $redis->del('mux:live_streams_list');
            }
        }
        
        // Add provider marker to result
        if (is_array($result)) {
            $result['_provider'] = $provider;
        }
        
        wp_send_json_success($result);
    }
    
    public function ajax_delete_stream() {
        check_ajax_referer('lem_stream_management_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }
        
        $stream_id = sanitize_text_field($_POST['stream_id'] ?? '');
        if (empty($stream_id)) {
            wp_send_json_error('Stream ID is required');
        }
        
        // Call delete function directly instead of going through REST API
        $credentials = $this->get_mux_api_credentials();
        if (!$credentials) {
            wp_send_json_error('Mux API credentials not configured');
        }
        
        // Call Mux API directly
        $url = "https://api.mux.com/video/v1/live-streams/{$stream_id}";
        $response = wp_remote_request($url, array(
            'method' => 'DELETE',
            'headers' => array(
                'Authorization' => 'Basic ' . base64_encode($credentials['token_id'] . ':' . $credentials['token_secret'])
            ),
            'timeout' => 10
        ));
        
        if (is_wp_error($response)) {
            wp_send_json_error($response->get_error_message());
        }
        
        $code = wp_remote_retrieve_response_code($response);
        
        // Clear cache
        $redis = $this->get_redis_connection();
        if ($redis) {
            $redis->del('mux:live_streams_list');
        }
        
        if ($code === 204 || $code === 200) {
            wp_send_json_success(array('success' => true, 'message' => 'Stream deleted successfully'));
        }
        
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        if (isset($data['error'])) {
            wp_send_json_error($data['error']['message'] ?? 'Failed to delete live stream');
        }
        
        wp_send_json_success(array('success' => true));
    }
    
    public function ajax_update_stream() {
        check_ajax_referer('lem_stream_management_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }
        
        $stream_id = sanitize_text_field($_POST['stream_id'] ?? '');
        $passthrough = sanitize_text_field($_POST['passthrough'] ?? '');
        // Handle boolean values - can be 'true', 'false', '1', '0', or actual boolean
        $reduced_latency = isset($_POST['reduced_latency']) && (
            $_POST['reduced_latency'] === 'true' || 
            $_POST['reduced_latency'] === '1' || 
            $_POST['reduced_latency'] === true
        );
        
        if (empty($stream_id)) {
            $this->debug_log('Update stream error: Missing stream_id', array('POST' => $_POST));
            wp_send_json_error('Stream ID is required. Please close and reopen the edit dialog.');
        }
        
        // Create REST request with proper URL structure
        // Call update function directly instead of going through REST API
        $credentials = $this->get_mux_api_credentials();
        if (!$credentials) {
            wp_send_json_error('Mux API credentials not configured');
        }
        
        // Convert to boolean
        $reduced_latency_bool = ($reduced_latency === '1' || $reduced_latency === 'true' || $reduced_latency === true);
        
        $payload = array();
        if ($passthrough !== '') {
            $payload['passthrough'] = $passthrough;
        }
        $payload['reduced_latency'] = $reduced_latency_bool;
        
        // Call Mux API directly
        $url = "https://api.mux.com/video/v1/live-streams/{$stream_id}";
        $response = wp_remote_request($url, array(
            'method' => 'PUT',
            'headers' => array(
                'Authorization' => 'Basic ' . base64_encode($credentials['token_id'] . ':' . $credentials['token_secret']),
                'Content-Type' => 'application/json'
            ),
            'body' => json_encode($payload),
            'timeout' => 10
        ));
        
        if (is_wp_error($response)) {
            wp_send_json_error($response->get_error_message());
        }
        
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        if (isset($data['error'])) {
            wp_send_json_error($data['error']['message'] ?? 'Failed to update live stream');
        }
        
        // Clear cache
        $redis = $this->get_redis_connection();
        if ($redis) {
            $redis->del('mux:live_streams_list');
        }
        
        wp_send_json_success($data['data'] ?? $data);
    }
    
    public function ajax_get_stream_details() {
        check_ajax_referer('lem_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }
        
        $stream_id = sanitize_text_field($_GET['stream_id'] ?? '');
        
        if (empty($stream_id)) {
            wp_send_json_error('Stream ID is required');
        }
        
        $credentials = $this->get_mux_api_credentials();
        if (!$credentials) {
            wp_send_json_error('Mux API credentials not configured');
        }
        
        // Call Mux API to get stream details
        $url = "https://api.mux.com/video/v1/live-streams/{$stream_id}";
        $response = wp_remote_get($url, array(
            'headers' => array(
                'Authorization' => 'Basic ' . base64_encode($credentials['token_id'] . ':' . $credentials['token_secret'])
            ),
            'timeout' => 10
        ));
        
        if (is_wp_error($response)) {
            wp_send_json_error($response->get_error_message());
        }
        
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        if (isset($data['error'])) {
            wp_send_json_error($data['error']['message'] ?? 'Failed to fetch stream details');
        }
        
        // Return the stream data - Mux API wraps it in 'data' key
        $stream_data = $data['data'] ?? $data;
        
        wp_send_json_success($stream_data);
    }
    
    public function ajax_save_stream_id() {
        check_ajax_referer('lem_stream_setup_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }
        
        $stream_id = sanitize_text_field($_POST['stream_id'] ?? '');
        if (empty($stream_id)) {
            wp_send_json_error('Stream ID is required');
        }
        
        $settings = get_option('lem_settings', array());
        $saved_stream_ids = $settings['mux_saved_stream_ids'] ?? array();
        
        if (!is_array($saved_stream_ids)) {
            $saved_stream_ids = array();
        }
        
        // Add stream ID if not already saved
        if (!in_array($stream_id, $saved_stream_ids)) {
            $saved_stream_ids[] = $stream_id;
            $settings['mux_saved_stream_ids'] = array_unique($saved_stream_ids);
            update_option('lem_settings', $settings);
            wp_send_json_success(array('message' => 'Stream ID saved successfully'));
        } else {
            wp_send_json_success(array('message' => 'Stream ID already saved'));
        }
    }
    
    public function ajax_clear_all_tokens() {
        try {
            $this->debug_log('Clear all tokens AJAX request started');
            
            check_ajax_referer('lem_nonce', 'nonce');
            
            if (!current_user_can('manage_options')) {
                $this->debug_log('Clear all tokens failed - insufficient permissions');
                wp_send_json_error('Insufficient permissions');
            }
        
        $results = array();
        
        // Clear MySQL JWT tokens
        global $wpdb;
        $table_name = $wpdb->prefix . 'lem_jwt_tokens';
        $mysql_deleted = $wpdb->query("DELETE FROM $table_name");
        $results['mysql_deleted'] = $mysql_deleted;
        
        // Clear Redis data
        try {
            $redis = $this->get_redis_connection();
            if ($redis) {
                $redis_keys_deleted = 0;
                
                // NOTE: KEYS command is O(N) and blocks Redis. This is acceptable here
                // because this is an admin-only bulk operation behind manage_options capability.
                // For large Redis instances, consider using SCAN instead.
                $patterns = array(
                    'jwt:*',
                    'jwt_token:*',
                    'revoked:*',
                    'session:*',
                    'jti_session:*',
                    'active_sessions:*',
                    'magic_token:*',
                    'event:*',
                    'event_access:*',
                    'session_status:*'
                );

                foreach ($patterns as $pattern) {
                    try {
                        $keys = $redis->keys($pattern);
                        if ($keys && is_array($keys)) {
                            $deleted = $redis->del($keys);
                            $redis_keys_deleted += $deleted;
                            $this->debug_log("Deleted Redis keys for pattern: $pattern", array('count' => $deleted));
                        }
                    } catch (Exception $e) {
                        $this->debug_log("Error deleting Redis keys for pattern: $pattern", array('error' => $e->getMessage()));
                    }
                }

                $results['redis_warning'] = 'Used KEYS command for bulk clear (admin-only operation).';
                
                $results['redis_keys_deleted'] = $redis_keys_deleted;
            } else {
                $results['redis_keys_deleted'] = 0;
                $results['redis_error'] = 'Redis connection failed';
            }
        } catch (Exception $e) {
            $this->debug_log('Redis clear operation failed', array('error' => $e->getMessage()));
            $results['redis_keys_deleted'] = 0;
            $results['redis_error'] = 'Redis operation failed: ' . $e->getMessage();
        }
        
        $this->debug_log('All tokens cleared by admin', $results);
        
        $response_data = array(
            'message' => 'All tokens and sessions cleared successfully',
            'results' => $results
        );
        
        $this->debug_log('Sending AJAX response', $response_data);
        wp_send_json_success($response_data);
        
        } catch (Exception $e) {
            $this->debug_log('Clear all tokens AJAX failed with exception', array(
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ));
            wp_send_json_error('Clear operation failed: ' . $e->getMessage());
        }
    }
    
    public function render_user_guide_page() {
        include LEM_PLUGIN_DIR . 'templates/user-guide-page.php';
    }
    
    // Mux API methods for playback restrictions
    private function get_mux_api_credentials() {
        $settings = get_option('lem_settings', array());
        $token_id = $settings['mux_token_id'] ?? '';
        $token_secret = $settings['mux_token_secret'] ?? '';
        
        $this->debug_log('Mux API credentials check', array(
            'credentials_configured' => !empty($token_id) && !empty($token_secret)
        ));
        
        if (empty($token_id) || empty($token_secret)) {
            return false;
        }
        
        return array(
            'token_id' => $token_id,
            'token_secret' => $token_secret
        );
    }
    
        public function create_playback_restriction($name, $description, $allowed_domains, $allow_no_referrer = true, $allow_no_user_agent = true, $allow_high_risk_user_agent = true) {
        $credentials = $this->get_mux_api_credentials();
        if (!$credentials) {
            $this->debug_log('Mux API credentials not configured');
            return array('success' => false, 'error' => 'Mux API credentials not configured. Please configure them in Event Manager > Settings.');
        }
        
        // Validate and clean domains
        $cleaned_domains = array();
        foreach ($allowed_domains as $domain) {
            $domain = trim($domain);
            if (!empty($domain)) {
                // Remove protocol if present
                $domain = preg_replace('/^https?:\/\//', '', $domain);
                // Remove trailing slash
                $domain = rtrim($domain, '/');
                $cleaned_domains[] = $domain;
            }
        }
        
        if (empty($cleaned_domains)) {
            return array('success' => false, 'error' => 'At least one valid domain is required');
        }
        
        $this->debug_log('Cleaned domains', array(
            'original' => $allowed_domains,
            'cleaned' => $cleaned_domains
        ));
        
        // Test API credentials with a simple GET request first
        $test_response = wp_remote_get('https://api.mux.com/video/v1/playback-restrictions', array(
            'headers' => array(
                'Authorization' => 'Basic ' . base64_encode($credentials['token_id'] . ':' . $credentials['token_secret'])
            ),
            'timeout' => 10
        ));
        
        if (is_wp_error($test_response)) {
            $this->debug_log('Mux API test failed', array(
                'error' => $test_response->get_error_message()
            ));
            return array('success' => false, 'error' => 'Failed to connect to Mux API: ' . $test_response->get_error_message());
        }
        
        $test_code = wp_remote_retrieve_response_code($test_response);
        $test_body = wp_remote_retrieve_body($test_response);
        $this->debug_log('Mux API test response', array(
            'response_code' => $test_code,
            'body_length' => strlen($test_body),
            'body_preview' => substr($test_body, 0, 200) . (strlen($test_body) > 200 ? '...' : '')
        ));
        
        if ($test_code === 401) {
            return array('success' => false, 'error' => 'Invalid Mux API credentials. Please check your Token ID and Token Secret.');
        }
        
        $payload = array(
            'referrer' => array(
                'allowed_domains' => $cleaned_domains,
                'allow_no_referrer' => (bool) $allow_no_referrer
            ),
            'user_agent' => array(
                'allow_no_user_agent' => (bool) $allow_no_user_agent,
                'allow_high_risk_user_agent' => (bool) $allow_high_risk_user_agent
            )
        );
        
        $url = 'https://api.mux.com/video/v1/playback-restrictions';
                        $this->debug_log('Creating playback restriction', array(
                    'url' => $url,
                    'payload' => $payload,
                    'has_credentials' => !empty($credentials['token_id'])
                ));
        
                        $request_body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                        if ($request_body === false) {
                            return array('success' => false, 'error' => 'Failed to encode request payload');
                        }
                $this->debug_log('Mux API request details', array(
                    'request_body' => $request_body,
                    'content_type' => 'application/json',
                    'timeout' => 30
                ));
                
                $response = wp_remote_post($url, array(
                    'headers' => array(
                        'Content-Type' => 'application/json',
                        'Authorization' => 'Basic ' . base64_encode($credentials['token_id'] . ':' . $credentials['token_secret'])
                    ),
                    'body' => $request_body,
                    'timeout' => 30
                ));
        
        if (is_wp_error($response)) {
            $this->debug_log('WP Error creating restriction', $response->get_error_message());
            return array('success' => false, 'error' => $response->get_error_message());
        }
        
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        $response_code = wp_remote_retrieve_response_code($response);
        
        $this->debug_log('Mux API response', array(
            'response_code' => $response_code,
            'body_length' => strlen($body),
            'body_preview' => substr($body, 0, 200) . (strlen($body) > 200 ? '...' : ''),
            'data' => $data
        ));
        
                        if ($response_code === 201) {
                    // Store the restriction locally for reference
                    $restrictions = get_option('lem_playback_restrictions', array());
                    $restrictions[$data['data']['id']] = array(
                        'name' => $name,
                        'description' => $description,
                        'mux_id' => $data['data']['id'],
                        'created_at' => current_time('mysql')
                    );
                    update_option('lem_playback_restrictions', $restrictions);
                    
                    return array('success' => true, 'data' => $data['data']);
                } else {
                    $error_message = 'Unknown error';
                    if (isset($data['error'])) {
                        if (isset($data['error']['message'])) {
                            $error_message = $data['error']['message'];
                        } elseif (isset($data['error']['messages']) && is_array($data['error']['messages'])) {
                            $error_message = implode(', ', $data['error']['messages']);
                        } elseif (isset($data['error']['type'])) {
                            $error_message = $data['error']['type'];
                        }
                    }
                    
                    $this->debug_log('Mux API error details', array(
                        'response_code' => $response_code,
                        'error_data' => $data['error'] ?? 'No error data',
                        'parsed_error' => $error_message
                    ));
                    
                    return array('success' => false, 'error' => $error_message);
                }
    }
    
    public function get_playback_restrictions() {
        $credentials = $this->get_mux_api_credentials();
        if (!$credentials) {
            $this->debug_log('Mux API credentials not configured');
            return array('success' => false, 'error' => 'Mux API credentials not configured. Please configure them in Event Manager > Settings.');
        }
        
        $url = 'https://api.mux.com/video/v1/playback-restrictions';
        $response = wp_remote_get($url, array(
            'headers' => array(
                'Content-Type' => 'application/json',
                'Authorization' => 'Basic ' . base64_encode($credentials['token_id'] . ':' . $credentials['token_secret'])
            ),
            'timeout' => 30
        ));
        
        if (is_wp_error($response)) {
            return array('success' => false, 'error' => $response->get_error_message());
        }
        
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        if (wp_remote_retrieve_response_code($response) === 200) {
            return array('success' => true, 'data' => $data['data']);
        } else {
            return array('success' => false, 'error' => $data['error']['message'] ?? 'Unknown error');
        }
    }
    
    public function delete_playback_restriction($restriction_id) {
        $credentials = $this->get_mux_api_credentials();
        if (!$credentials) {
            $this->debug_log('Mux API credentials not configured');
            return array('success' => false, 'error' => 'Mux API credentials not configured. Please configure them in Event Manager > Settings.');
        }
        
        $url = 'https://api.mux.com/video/v1/playback-restrictions/' . $restriction_id;
        $response = wp_remote_request($url, array(
            'method' => 'DELETE',
            'headers' => array(
                'Content-Type' => 'application/json',
                'Authorization' => 'Basic ' . base64_encode($credentials['token_id'] . ':' . $credentials['token_secret'])
            ),
            'timeout' => 30
        ));
        
        if (is_wp_error($response)) {
            return array('success' => false, 'error' => $response->get_error_message());
        }
        
        if (wp_remote_retrieve_response_code($response) === 204) {
            // Remove from local storage
            $restrictions = get_option('lem_playback_restrictions', array());
            unset($restrictions[$restriction_id]);
            update_option('lem_playback_restrictions', $restrictions);
            
            return array('success' => true);
        } else {
            $body = wp_remote_retrieve_body($response);
            $data = json_decode($body, true);
            return array('success' => false, 'error' => $data['error']['message'] ?? 'Unknown error');
        }
    }
    
    /**
     * Canonical playback blob in Redis: lem:playback:{email_hash}:{event_id}
     *
     * @param int $expires_ts_unix Expiry as Unix timestamp (TTL derived from now).
     */
    private function store_playback_blob($email, $event_id, array $blob, $expires_ts_unix) {
        $redis = $this->get_redis_connection();
        if (!$redis) {
            return false;
        }
        $ttl = max(60, $expires_ts_unix - time());
        $key = LEM_Access::playback_key($email, $event_id);
        $blob['expires_at_ts'] = $expires_ts_unix;
        return $redis->setex($key, $ttl, wp_json_encode($blob));
    }

    /**
     * Issue or dedupe playback credentials (Mux or OME). Does not create gating sessions.
     *
     * @return array{jwt?:string, jti?:string, expires_at?:int, llhls_url?:string, policy?:string, signature?:string, vendor?:string}|false
     */
    public function generate_jwt($email, $event_id, $payment_id = null, $is_refresh = false) {
        if (class_exists('LEM_Access') && LEM_Access::is_email_revoked_for_event($email, $event_id)) {
            return false;
        }

        $provider = $this->get_streaming_provider();
        if ($provider && $provider->get_id() === 'ome' && $provider->is_configured()) {
            if ($is_refresh) {
                $this->invalidate_existing_tokens($email, $event_id);
            }
            $tok = $provider->generate_playback_token($email, $event_id, $payment_id, false);
            if (is_wp_error($tok) || !is_array($tok)) {
                return false;
            }
            $exp_ts = strtotime($tok['expires_at'] ?? '') ?: (time() + 3600);
            $blob   = array(
                'vendor'    => 'ome',
                'jwt'       => $tok['jwt'] ?? '',
                'llhls_url' => $tok['llhls_url'] ?? '',
                'policy'    => $tok['policy'] ?? '',
                'signature' => $tok['signature'] ?? '',
                'jti'       => $tok['jti'] ?? 'ome',
            );
            $this->store_playback_blob($email, $event_id, $blob, $exp_ts);
            return array(
                'jwt'         => $tok['jwt'] ?? '',
                'jti'         => $tok['jti'] ?? 'ome',
                'expires_at'  => $exp_ts,
                'llhls_url'   => $tok['llhls_url'] ?? '',
                'policy'      => $tok['policy'] ?? '',
                'signature'   => $tok['signature'] ?? '',
                'vendor'      => 'ome',
                'session_id'  => null,
            );
        }

        return $this->issue_mux_playback_token($email, $event_id, $payment_id, $is_refresh);
    }

    /**
     * Mux RS256 playback token + DB row + lem:playback (no session creation).
     */
    public function issue_mux_playback_token($email, $event_id, $payment_id, $is_refresh) {
        $settings    = get_option('lem_settings', array());
        $key_id      = $settings['mux_key_id'] ?? '';
        $private_key = $settings['mux_private_key'] ?? '';

        if (empty($key_id) || empty($private_key) || !class_exists('\Firebase\JWT\JWT')) {
            return false;
        }

        $event = $this->get_event_by_id($event_id);
        if (!$event) {
            return false;
        }

        if ($is_refresh) {
            $this->invalidate_existing_tokens($email, $event_id);
        } else {
            global $wpdb;
            $table    = $wpdb->prefix . 'lem_jwt_tokens';
            $existing = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM $table
                 WHERE email      = %s
                   AND event_id   = %s
                   AND revoked_at IS NULL
                   AND expires_at > %s
                 ORDER BY created_at DESC
                 LIMIT 1",
                $email,
                $event_id,
                current_time('mysql')
            ));

            if ($existing) {
                $exp_ts = strtotime($existing->expires_at);
                $this->store_playback_blob($email, $event_id, array(
                    'vendor'  => 'mux',
                    'mux_jwt' => $existing->jwt_token,
                    'jti'     => $existing->jti,
                ), $exp_ts ? $exp_ts : time() + DAY_IN_SECONDS);

                $this->debug_log('Returning existing valid token (dedup)', array(
                    'email'    => $this->redact_email($email),
                    'event_id' => $event_id,
                    'jti'      => $existing->jti,
                ));
                return array(
                    'jwt'        => $existing->jwt_token,
                    'jti'        => $existing->jti,
                    'session_id' => null,
                    'expires_at' => strtotime($existing->expires_at),
                    'vendor'     => 'mux',
                );
            }
        }

        $event_end_str = $event->event_end ?? '';
        if (!empty($event_end_str)) {
            $end_ts = strtotime($event_end_str);
            if ($end_ts && $end_ts > time()) {
                $exp = $end_ts + (2 * 3600);
            } else {
                $jwt_expiration_hours = $settings['jwt_expiration_hours'] ?? 24;
                $exp                  = time() + ($jwt_expiration_hours * 3600);
            }
        } else {
            $jwt_expiration_hours = $settings['jwt_expiration_hours'] ?? 24;
            $exp                  = time() + ($jwt_expiration_hours * 3600);
        }

        $random_jti = uniqid('jwt_', true);
        $placeholder = $this->generate_session_id();
        $device_identifier = $this->device_service->getDeviceIdentifier(array(
            'session_id' => $placeholder,
        ));

        $device_settings   = get_option('lem_device_settings', array('identification_method' => 'session_based'));
        $identifier_type   = $device_settings['identification_method'] ?? 'session_based';
        $identifier_value  = $placeholder;
        $ip                = $device_identifier['metadata']['ip'] ?? '0.0.0.0';
        $hash_jti          = hash('sha256', $email . '|' . $ip . '|' . $event->playback_id);
        $exp_int           = (int) $exp;

        $payload = array(
            'sub' => $event->playback_id,
            'aud' => 'v',
            'exp' => $exp_int,
            'kid' => $key_id,
            'custom' => array(
                'jti'                => $random_jti,
                'identifier_type'    => $identifier_type,
                'identifier_value'   => $identifier_value,
                'event_id'           => $event_id,
                'ip'                 => $ip,
            ),
        );

        if (!empty($event->playback_restriction_id)) {
            $payload['playback_restriction_id'] = $event->playback_restriction_id;
        }

        try {
            $jwt = \Firebase\JWT\JWT::encode($payload, base64_decode($private_key), 'RS256');

            $this->store_jwt($random_jti, $hash_jti, $jwt, $email, $event_id, $payment_id, $ip, gmdate('Y-m-d H:i:s', $exp));

            $jwt_redis_data = array(
                'jti'                     => $random_jti,
                'jwt_token'               => $jwt,
                'playback_id'             => $event->playback_id,
                'playback_restriction_id' => $event->playback_restriction_id,
                'email'                   => $email,
                'event_id'                => $event_id,
                'device_identifier'       => $device_identifier,
                'identifier_type'         => $identifier_type,
                'identifier_value'        => $identifier_value,
                'expires_at'              => gmdate('Y-m-d H:i:s', $exp),
                'created_at'              => gmdate('Y-m-d H:i:s'),
                'revoked'                 => false,
            );
            $this->store_jwt_redis_by_jti($random_jti, $jwt_redis_data, $exp);
            $this->store_jwt_redis($hash_jti, $payload);
            $this->store_jti_mapping($random_jti, $hash_jti);

            $this->store_playback_blob($email, $event_id, array(
                'vendor'  => 'mux',
                'mux_jwt' => $jwt,
                'jti'     => $random_jti,
            ), $exp_int);

            return array(
                'jwt'        => $jwt,
                'jti'        => $random_jti,
                'expires_at' => $exp_int,
                'vendor'     => 'mux',
                'session_id' => null,
            );
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Ensure lem:playback exists (watch path avoids DB; magic link may only have DB entitlement).
     */
    public function ensure_playback_blob($email, $event_id) {
        $redis = $this->get_redis_connection();
        if (!$redis) {
            return;
        }
        $key = LEM_Access::playback_key($email, $event_id);
        if ($redis->get($key)) {
            return;
        }
        if (class_exists('LEM_Access') && LEM_Access::is_email_revoked_for_event($email, $event_id)) {
            return;
        }
        $this->generate_jwt($email, $event_id, null, false);
    }
    

    
    // AJAX generate JWT (admin-only — viewers get access via Stripe webhook or magic links)
    public function ajax_generate_jwt() {
        check_ajax_referer('lem_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }
        
        $email = sanitize_email($_POST['email'] ?? '');
        $event_id = sanitize_text_field($_POST['event_id'] ?? '');
        $payment_id = sanitize_text_field($_POST['payment_id'] ?? '');
        
        if (empty($email) || empty($event_id)) {
            wp_send_json_error('Email and Event ID are required');
        }
        
        // Validate email format
        if (!is_email($email)) {
            wp_send_json_error('Please enter a valid email address');
        }

        if (class_exists('LEM_Access') && LEM_Access::is_email_revoked_for_event($email, $event_id)) {
            wp_send_json_error('Access for this email has been revoked for this event.');
        }
        
        // Store email as valid access for this event (golden data)
        $this->store_event_email($event_id, $email);
        
        $result = $this->generate_jwt($email, $event_id, $payment_id);
        
        if ($result && is_array($result)) {
            $session_id = $this->create_session($event_id, $email);
            $result['session_id'] = $session_id;

            if (!empty($result['jti'])) {
                $r = $this->get_redis_connection();
                if ($r) {
                    $r->setex("jti_session:{$result['jti']}", 24 * 60 * 60, $session_id);
                }
            }

            // Send magic link email with session ID
            $mail_result = $this->magic_link_service->send_magic_link_email($email, $result['jwt'], $event_id, $session_id);
            $mail_sent   = is_array($mail_result) ? ($mail_result['sent'] ?? false) : (bool) $mail_result;
            $mail_error  = (!$mail_sent && is_array($mail_result)) ? ($mail_result['error'] ?? '') : '';

            if (!headers_sent() && !empty($session_id)) {
                setcookie('lem_session_id', $session_id, array(
                    'expires'  => time() + DAY_IN_SECONDS,
                    'path'     => '/',
                    'secure'   => is_ssl(),
                    'httponly' => true,
                    'samesite' => 'Lax',
                ));
                $_COOKIE['lem_session_id'] = $session_id;
            }

            $message = $mail_sent
                ? 'Access granted! Your magic link is on its way.'
                : 'Access granted! However, the confirmation email could not be sent — ' . ($mail_error ?: 'check your SMTP settings in Live Events → Settings.');

            $watch_url = $this->get_event_url($event_id);
            if ($mail_sent && is_array($mail_result) && !empty($mail_result['magic_link'])) {
                $watch_url = $mail_result['magic_link'];
            } elseif (!$mail_sent) {
                // Email failed: still give a working gate URL (same shape as the email link).
                $magic_token = $this->generate_magic_token($email, $event_id, $session_id);
                if ($magic_token) {
                    $watch_url = $this->get_event_url($event_id, array('magic' => $magic_token));
                }
            }

            wp_send_json_success(array(
                'jwt'        => $result['jwt'],
                'session_id' => $session_id,
                'jti'        => $result['jti'],
                'watch_url'  => $watch_url,
                'mail_sent'  => $mail_sent,
                'mail_error' => $mail_error,
                'message'    => $message,
            ));
        } else {
            wp_send_json_error('Failed to generate access. Please try again.');
        }
    }
    
    /**
     * Public AJAX: grant access for FREE events only.
     * Validates the event exists and is marked free before issuing a JWT + magic link.
     */
    public function ajax_free_event_access() {
        check_ajax_referer('lem_nonce', 'nonce');

        if (!$this->check_rate_limit('free_access', 5)) {
            wp_send_json_error(['message' => 'Too many requests. Please wait a moment.']);
        }

        $email    = sanitize_email($_POST['email'] ?? '');
        $event_id = sanitize_text_field($_POST['event_id'] ?? '');

        if (empty($email) || empty($event_id) || !is_email($email)) {
            wp_send_json_error('A valid email and event are required.');
        }

        $post = get_post($event_id);
        if (!$post || $post->post_type !== 'lem_event' || $post->post_status !== 'publish') {
            wp_send_json_error('Event not found.');
        }

        $is_free = get_post_meta($event_id, '_lem_is_free', true);
        if ($is_free !== 'free') {
            wp_send_json_error('This event requires a ticket purchase.');
        }

        if (class_exists('LEM_Access') && LEM_Access::is_email_revoked_for_event($email, $event_id)) {
            wp_send_json_error('Access for this email has been revoked for this event.');
        }

        $this->store_event_email($event_id, $email);

        $result = $this->generate_jwt($email, $event_id, null);

        if (!$result || !is_array($result)) {
            wp_send_json_error('Failed to generate access. Please try again.');
        }

        $session_id = $this->create_session($event_id, $email);

        if (!empty($result['jti'])) {
            $r = $this->get_redis_connection();
            if ($r) {
                $r->setex("jti_session:{$result['jti']}", 24 * 60 * 60, $session_id);
            }
        }

        $mail_result = $this->magic_link_service->send_magic_link_email($email, $result['jwt'], $event_id, $session_id);
        $mail_sent   = is_array($mail_result) ? ($mail_result['sent'] ?? false) : (bool) $mail_result;
        $mail_error  = (!$mail_sent && is_array($mail_result)) ? ($mail_result['error'] ?? '') : '';

        if (!headers_sent() && !empty($session_id)) {
            setcookie('lem_session_id', $session_id, array(
                'expires'  => time() + DAY_IN_SECONDS,
                'path'     => '/',
                'secure'   => is_ssl(),
                'httponly' => true,
                'samesite' => 'Lax',
            ));
        }

        $watch_url = $this->get_event_url($event_id);
        if ($mail_sent && is_array($mail_result) && !empty($mail_result['magic_link'])) {
            $watch_url = $mail_result['magic_link'];
        } elseif (!$mail_sent) {
            $magic_token = $this->generate_magic_token($email, $event_id, $session_id);
            if ($magic_token) {
                $watch_url = $this->get_event_url($event_id, array('magic' => $magic_token));
            }
        }

        $message = $mail_sent
            ? 'Access granted! Your magic link is on its way.'
            : 'Access granted! However, the confirmation email could not be sent — ' . ($mail_error ?: 'check SMTP settings.');

        wp_send_json_success(array(
            'watch_url'  => $watch_url,
            'mail_sent'  => $mail_sent,
            'message'    => $message,
        ));
    }

    /**
     * Check Stripe session status immediately (before webhook)
     * This provides instant confirmation instead of waiting for webhook
     */
    public function check_stripe_session_immediate($session_id) {
        if (empty($session_id)) {
            return false;
        }
        
        $settings = get_option('lem_settings', array());
        $stripe_mode = $settings['stripe_mode'] ?? 'test';
        
        if ($stripe_mode === 'test') {
            $secret_key = $settings['stripe_test_secret_key'] ?? '';
        } else {
            $secret_key = $settings['stripe_live_secret_key'] ?? '';
        }
        
        if (empty($secret_key)) {
            $this->debug_log('Stripe secret key not configured for immediate check');
            return false;
        }
        
        if (!class_exists('\Stripe\Stripe')) {
            $this->debug_log('Stripe library not available for immediate check');
            return false;
        }
        
        try {
            \Stripe\Stripe::setApiKey($secret_key);
            
            // Retrieve the session from Stripe
            $session = \Stripe\Checkout\Session::retrieve($session_id);
            
            // Check if payment is completed
            if ($session->payment_status === 'paid' && $session->status === 'complete') {
                $event_id = $session->metadata->event_id ?? null;
                $email = $session->customer_details->email ?? null;
                
                if ($event_id && $email) {
                    $this->debug_log('Stripe session confirmed immediately', array(
                        'session_id' => $session_id,
                        'event_id' => $event_id,
                        'email' => $email,
                        'payment_status' => $session->payment_status
                    ));
                    
                    // Check for duplicate to prevent double processing
                    global $wpdb;
                    $table = $wpdb->prefix . 'lem_jwt_tokens';
                    $existing_token = $wpdb->get_row($wpdb->prepare(
                        "SELECT jti, jwt_token, created_at FROM $table WHERE payment_id = %s AND revoked_at IS NULL ORDER BY created_at DESC LIMIT 1",
                        $session_id
                    ));
                    
                    if ($existing_token) {
                        $this->debug_log('Existing JWT found in immediate check, using existing token', array(
                            'session_id' => $session_id,
                            'existing_jti' => $existing_token->jti
                        ));
                        return array(
                            'jwt' => $existing_token->jwt_token,
                            'jti' => $existing_token->jti,
                            'email' => $email,
                            'event_id' => $event_id,
                            'from_cache' => true
                        );
                    }
                    
                    // No JWT exists yet - wait for webhook to process
                    // Don't generate JWT here to avoid duplicates
                    // The webhook will generate it and send the email
                    $this->debug_log('No JWT found yet, waiting for webhook to process', array(
                        'session_id' => $session_id,
                        'event_id' => $event_id,
                        'email' => $email
                    ));
                    
                    // Return false to indicate JWT not ready yet
                    return false;
                }
            } else {
                $this->debug_log('Stripe session not yet paid', array(
                    'session_id' => $session_id,
                    'payment_status' => $session->payment_status ?? 'unknown',
                    'status' => $session->status ?? 'unknown'
                ));
            }
        } catch (\Exception $e) {
            $this->debug_log('Error checking Stripe session immediately', array(
                'session_id' => $session_id,
                'error' => $e->getMessage()
            ));
        }
        
        return false;
    }
    
    /**
     * Store email as valid access for an event (golden data)
     */
    private function store_event_email($event_id, $email) {
        $event_emails = get_post_meta($event_id, '_lem_event_emails', true);
        
        if (!is_array($event_emails)) {
            $event_emails = array();
        }
        
        // Add email if not already present
        $email_lower = strtolower(trim($email));
        if (!in_array($email_lower, $event_emails)) {
            $event_emails[] = $email_lower;
            update_post_meta($event_id, '_lem_event_emails', $event_emails);
            
            $this->debug_log('Stored email for event', array(
                'event_id' => $event_id,
                'email' => $this->redact_email($email_lower),
                'total_emails' => count($event_emails)
            ));
        }
        
        return true;
    }
    
    /**
     * Get all emails with access to an event
     */
    public function get_event_emails($event_id) {
        $event_emails = get_post_meta($event_id, '_lem_event_emails', true);
        return is_array($event_emails) ? $event_emails : array();
    }
    
    // AJAX revoke JWT
    public function ajax_revoke_jwt() {
        check_ajax_referer('lem_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }
        
        $jti = sanitize_text_field($_POST['jti']);
        
        if (empty($jti)) {
            wp_send_json_error('JTI is required');
        }
        
        $result = $this->revoke_jwt($jti);
        
        if ($result) {
            wp_send_json_success('JWT revoked successfully');
        } else {
            wp_send_json_error('Failed to revoke JWT');
        }
    }

    /**
     * Admin AJAX: distinct viewer emails from lem_jwt_tokens for an event (revoke page dropdown).
     */
    public function ajax_revoke_emails_for_event() {
        check_ajax_referer('lem_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        $event_id = isset($_POST['event_id']) ? intval($_POST['event_id']) : 0;
        if ($event_id <= 0) {
            wp_send_json_error('Invalid event');
        }

        global $wpdb;
        $table  = $wpdb->prefix . 'lem_jwt_tokens';
        $emails = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT DISTINCT email FROM {$table} WHERE event_id = %s AND email != '' ORDER BY email ASC",
                (string) $event_id
            )
        );

        $out = array();
        foreach ((array) $emails as $e) {
            $e = sanitize_email($e);
            if ($e !== '') {
                $out[] = $e;
            }
        }
        $out = array_values(array_unique($out));

        wp_send_json_success(array('emails' => $out));
    }

    
    // Restrictions AJAX handlers
    public function ajax_create_restriction() {
        check_ajax_referer('lem_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }
        
        $name = sanitize_text_field($_POST['name']);
        $description = sanitize_textarea_field($_POST['description']);
        $allowed_domains = array_map('trim', explode(',', sanitize_text_field($_POST['allowed_domains'])));
        $allow_no_referrer = intval($_POST['allow_no_referrer']);
        $allow_no_user_agent = intval($_POST['allow_no_user_agent']);
        $allow_high_risk_user_agent = intval($_POST['allow_high_risk_user_agent']);
        
        if (empty($name) || empty($allowed_domains[0])) {
            wp_send_json_error('Name and allowed domains are required');
        }
        
        $result = $this->create_playback_restriction(
            $name,
            $description,
            $allowed_domains,
            $allow_no_referrer,
            $allow_no_user_agent,
            $allow_high_risk_user_agent
        );
        
        if ($result['success']) {
            wp_send_json_success($result['data']);
        } else {
            wp_send_json_error($result['error']);
        }
    }
    
    public function ajax_get_restrictions() {
        check_ajax_referer('lem_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }
        
        $result = $this->get_playback_restrictions();
        
        if ($result['success']) {
            wp_send_json_success($result['data']);
        } else {
            wp_send_json_error($result['error']);
        }
    }
    
    public function ajax_delete_restriction() {
        check_ajax_referer('lem_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }
        
        $restriction_id = sanitize_text_field($_POST['restriction_id']);
        
        if (empty($restriction_id)) {
            wp_send_json_error('Restriction ID is required');
        }
        
        $result = $this->delete_playback_restriction($restriction_id);
        
        if ($result['success']) {
            wp_send_json_success();
        } else {
            wp_send_json_error($result['error']);
        }
    }
    
    public function ajax_get_jwt_tokens() {
        check_ajax_referer('lem_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }
        
        global $wpdb;
        
        $table = $wpdb->prefix . 'lem_jwt_tokens';
        
        $this->debug_log('JWT Manager: Checking table ' . $table);
        
        // Check if table exists
        $table_exists = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = %s AND table_name = %s", DB_NAME, $table));
        $this->debug_log('JWT Manager: Table exists: ' . ($table_exists ? 'yes' : 'no'));
        
        if (!$table_exists) {
            $this->debug_log('JWT Manager: Table does not exist, returning empty array');
            wp_send_json_success(array());
        }
        
        $limit  = max(1, min(500, intval($_POST['limit'] ?? 200)));
        $offset = max(0, intval($_POST['offset'] ?? 0));
        $tokens = $wpdb->get_results($wpdb->prepare(
            "SELECT jti, email, event_id, ip_address, created_at, expires_at
             FROM $table
             WHERE revoked_at IS NULL
             ORDER BY created_at DESC
             LIMIT %d OFFSET %d",
            $limit,
            $offset
        ));
        
        $redis = $this->get_redis_connection();
        
        foreach ($tokens as &$token) {
            $redis_info = array(
                'available' => (bool) $redis,
                'session_id' => null,
                'session' => null,
                'session_raw' => null,
                'jwt_cache' => null,
                'jwt_cache_raw' => null,
                'jwt_token_cache' => null,
                'status_cache' => null,
                'status_cache_key' => null,
            );
            
            if ($redis) {
                $jti = $token->jti;
                
                // Session data
                $session_id = $redis->get("jti_session:{$jti}");
                if ($session_id) {
                    $redis_info['session_id'] = $session_id;
                    $session_raw = $redis->get("session:{$session_id}");
                    if ($session_raw) {
                        $redis_info['session_raw'] = $session_raw;
                        $redis_info['session'] = json_decode($session_raw, true) ?: $session_raw;
                    }
                }
                
                // JWT cache
                $jwt_cache_raw = $redis->get("jwt:{$jti}");
                if ($jwt_cache_raw) {
                    $redis_info['jwt_cache_raw'] = $jwt_cache_raw;
                    $decoded = json_decode($jwt_cache_raw, true);
                    $redis_info['jwt_cache'] = $decoded ?: $jwt_cache_raw;
                }
                
                $jwt_token_cache = $redis->get("jwt_token:{$jti}");
                if ($jwt_token_cache) {
                    $redis_info['jwt_token_cache'] = $jwt_token_cache;
                }
                
                if (!empty($token->email)) {
                    $email_hash = hash('sha256', $token->email);
                    $status_key = 'session_status:' . $token->event_id . ':' . $email_hash;
                    $status_raw = $redis->get($status_key);
                    if ($status_raw) {
                        $redis_info['status_cache_key'] = $status_key;
                        $decoded_status = json_decode($status_raw, true);
                        $redis_info['status_cache'] = $decoded_status ?: $status_raw;
                    }
                }
            }
            
            $token->redis = $redis_info;
        }
        unset($token);
        
        $this->debug_log('JWT Manager: Found ' . count($tokens) . ' tokens');
        $this->debug_log('JWT Manager: Tokens count', count($tokens));
        
        wp_send_json_success($tokens);
    }
    

    
    public function ajax_create_stripe_session() {
        check_ajax_referer('lem_nonce', 'nonce');

        if (!$this->check_rate_limit('stripe_session', 5)) {
            wp_send_json_error(['message' => 'Too many requests. Please wait a moment.']);
        }

        $event_id = sanitize_text_field($_POST['event_id']);
        $price_id = sanitize_text_field($_POST['price_id']);
        $email    = sanitize_email($_POST['email'] ?? '');

        if (empty($event_id) || empty($price_id)) {
            wp_send_json_error('Event ID and Price ID are required');
        }
        
        // Check if Stripe library is available
        if (!class_exists('\Stripe\Stripe')) {
            wp_send_json_error('Stripe library not available. Please run: composer install');
        }
        
        $settings = get_option('lem_settings', array());
        $stripe_mode = $settings['stripe_mode'] ?? 'test';
        
        $this->debug_log('Stripe mode: ' . $stripe_mode);
        
        if ($stripe_mode === 'test') {
            $secret_key = $settings['stripe_test_secret_key'] ?? '';
        } else {
            $secret_key = $settings['stripe_live_secret_key'] ?? '';
        }
        
        $this->debug_log('Stripe key configured: ' . (!empty($secret_key) ? 'yes' : 'no'));
        
        if (empty($secret_key)) {
            wp_send_json_error('Stripe secret key not configured. Please check your settings.');
        }

        if (!empty($email) && $this->magic_link_service->has_valid_ticket($email, $event_id)) {
            wp_send_json_error(array(
                'message' => __('You already have access to this event. Check your inbox for your link or use “Resend” on the event page.', 'live-event-manager'),
            ));
        }
        
        try {
            \Stripe\Stripe::setApiKey($secret_key);
            
            $event = $this->get_event_by_id($event_id);
            if (!$event) {
                wp_send_json_error('Event not found');
            }
            
            $session_args = [
                'payment_method_types' => ['card'],
                'line_items' => [[
                    'price' => $price_id,
                    'quantity' => 1,
                ]],
                'mode' => 'payment',
                'success_url' => home_url('/confirmation?session_id={CHECKOUT_SESSION_ID}'),
                'cancel_url' => get_permalink($event_id) ?: home_url('/'),
                'metadata' => [
                    'event_id'    => $event_id,
                    'event_title' => $event->title,
                    'email'       => $email,
                ],
            ];

            // Pre-fill and lock the customer email on Stripe Checkout
            if (!empty($email)) {
                $session_args['customer_email'] = $email;
            }

            $session = \Stripe\Checkout\Session::create($session_args);
            
            wp_send_json_success(array(
                'checkout_url' => $session->url,
                'session_id' => $session->id
            ));
            
        } catch (\Exception $e) {
            wp_send_json_error('Stripe error: ' . $e->getMessage());
        }
    }
    

    
    // Regenerate JWT using unique code
    public function ajax_regenerate_jwt() {
        check_ajax_referer('lem_nonce', 'nonce');
        
        $email = sanitize_email($_POST['email']);
        $code = sanitize_text_field($_POST['code']);
        
        if (empty($email) || empty($code)) {
            wp_send_json_error('Email and code are required');
        }
        
        global $wpdb;
        $table = $wpdb->prefix . 'lem_jwt_tokens';
        
        // Find the token by email and code (first 8 characters of JTI)
        $tokens = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table WHERE email = %s AND jti LIKE %s ORDER BY created_at DESC LIMIT 1",
            $email,
            $code . '%'
        ));
        
        if (empty($tokens)) {
            wp_send_json_error('Invalid email or code combination');
        }
        
        $token = $tokens[0];
        $event_id = $token->event_id;

        // Invalidate all existing tokens before issuing a fresh one
        $this->invalidate_existing_tokens($email, $event_id);

        // Generate new playback token (invalidates prior rows first)
        $jwt_result = $this->generate_jwt($email, $event_id, $token->payment_id, true);
        
        if ($jwt_result && is_array($jwt_result)) {
            $new_sid = $this->create_session($event_id, $email);
            $this->magic_link_service->send_magic_link_email($email, $jwt_result['jwt'], $event_id, $new_sid);
            
            wp_send_json_success('New access link generated and sent to your email');
        } else {
            wp_send_json_error('Failed to generate new access link');
        }
    }
    
    public function ajax_check_event_access() {
        check_ajax_referer('lem_nonce', 'nonce');

        $event_id = intval($_POST['event_id'] ?? 0);
        if (empty($event_id)) {
            wp_send_json_error('Event ID required');
        }

        $state = $this->get_event_access_state($event_id);

        if (!empty($state['can_watch'])) {
            wp_send_json_success(array(
                'can_watch' => true,
                'watch_url' => $this->get_event_url($event_id),
                'session_id' => $state['session_id'],
                'jwt_token' => $state['jwt_token']
            ));
        }

        wp_send_json_success(array(
            'can_watch' => false,
            'error' => $state['error_message']
        ));
    }

    // Test Redis connection
    public function ajax_test_redis_connection() {
        check_ajax_referer('lem_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        $settings = get_option('lem_settings', array());

        // Accept credentials from the POST body (settings page test button) or fall back to saved settings.
        $url   = sanitize_text_field(wp_unslash($_POST['upstash_redis_url']   ?? $settings['upstash_redis_url']   ?? ''));
        $token = sanitize_text_field(wp_unslash($_POST['upstash_redis_token'] ?? $settings['upstash_redis_token'] ?? ''));

        if (empty($url) || empty($token)) {
            wp_send_json_error('Upstash REST URL and token are required. Add them on the Cache & Access tab.');
        }

        $override = array('upstash_redis_url' => $url, 'upstash_redis_token' => $token);
        $cache    = $this->get_redis_connection($override);

        if (!$cache) {
            wp_send_json_error('Could not build Upstash client — check that the URL and token are correct.');
        }

        // Test SET → GET → DEL round-trip.
        $test_key   = 'lem_test_' . time();
        $test_value = 'ok_' . wp_generate_password(8, false);

        if (!$cache->set($test_key, $test_value, 30)) {
            wp_send_json_error('Upstash SET failed. Check your token has write permissions.');
        }

        $fetched = $cache->get($test_key);
        if ($fetched !== $test_value) {
            wp_send_json_error('Upstash GET returned unexpected value. Expected: ' . $test_value . ', got: ' . $fetched);
        }

        $cache->del($test_key);

        wp_send_json_success(array(
            'message' => 'Upstash connection successful! SET / GET / DEL all passed.',
            'url'     => preg_replace('/^(https?:\/\/[^.]+).*/', '$1…', $url),
        ));
    }
    
    /**
     * Store the last wp_mail() failure so it can be surfaced in the admin.
     */
    public function on_wp_mail_failed( $wp_error ) {
        if ( is_wp_error( $wp_error ) ) {
            set_transient( 'lem_last_mail_error', $wp_error->get_error_message(), HOUR_IN_SECONDS );
        }
    }

    /**
     * AJAX: send a test email to the currently logged-in admin.
     */
    public function ajax_test_email() {
        check_ajax_referer( 'lem_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorised.' );
        }

        $to = sanitize_email( $_POST['to'] ?? '' );
        if ( empty( $to ) ) {
            $to = wp_get_current_user()->user_email;
        }
        if ( ! is_email( $to ) ) {
            wp_send_json_error( 'Invalid email address.' );
        }

        // Clear any stale error before the test
        delete_transient( 'lem_last_mail_error' );

        $from_email = get_option( 'admin_email' );
        $from_name  = get_bloginfo( 'name' ) ?: 'Live Events';
        $headers    = array(
            'Content-Type: text/plain; charset=UTF-8',
            'From: ' . $from_name . ' <' . $from_email . '>',
        );

        $subject = '[Live Event Manager] Test email';
        $message = "This is a test email from Live Event Manager.\n\n"
                 . "If you received this, wp_mail() is working correctly with your current mailer.\n\n"
                 . "From address used: " . $from_email . "\n"
                 . "Sent: " . current_time( 'Y-m-d H:i:s' );

        $sent = wp_mail( $to, $subject, $message, $headers );

        if ( $sent ) {
            wp_send_json_success( array(
                'message' => "Test email sent to {$to}. Check your inbox (and spam folder).",
            ) );
        } else {
            $error = get_transient( 'lem_last_mail_error' ) ?: 'wp_mail() returned false but no error was captured. Check your SMTP plugin configuration.';
            wp_send_json_error( $error );
        }
    }

    public function ajax_revoke_session() {
        check_ajax_referer('lem_nonce', 'nonce');
        
        $session_id = sanitize_text_field($_POST['session_id'] ?? '');
        
        if (empty($session_id)) {
            wp_send_json_error('Session ID is required');
        }

        // Only the session owner (matching cookie) or an admin may revoke.
        $cookie_session = $_COOKIE['lem_session_id'] ?? '';
        if ($session_id !== $cookie_session && !current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }
        
        $result = $this->revoke_session($session_id);
        
        if ($result) {
            wp_send_json_success('Session revoked successfully');
        } else {
            wp_send_json_error('Failed to revoke session');
        }
    }
    
    // AJAX validate email and send new link
    public function ajax_validate_email() {
        check_ajax_referer('lem_nonce', 'nonce');

        if (!$this->check_rate_limit('validate_email', 5)) {
            wp_send_json_error(['message' => 'Too many requests. Please wait a moment.']);
        }

        $email = sanitize_email($_POST['email']);
        $event_id = intval($_POST['event_id']);
        
        if (empty($email) || empty($event_id)) {
            wp_send_json_error('Email and Event ID are required');
        }
        
        $result = $this->validate_email_and_send_link($email, $event_id);
        
        if ($result['valid']) {
            wp_send_json_success($result['message']);
        } else {
            wp_send_json_error($result['error']);
        }
    }
    

    
    // Get all events
    private function get_all_events() {
        $args = array(
            'post_type' => 'lem_event',
            'post_status' => 'any',
            'posts_per_page' => -1,
            'orderby' => 'meta_value',
            'meta_key' => '_lem_event_date',
            'order' => 'DESC'
        );
        
        $posts = get_posts($args);
        $events = array();
        
        foreach ($posts as $post) {
            $event = new stdClass();
            $event->event_id = $post->ID; // Use post ID as event ID
            $event->title = $post->post_title;
            $event->description = $post->post_content;
            $event->playback_id = get_post_meta($post->ID, '_lem_playback_id', true);
            $event->playback_restriction_id = get_post_meta($post->ID, '_lem_playback_restriction_id', true);
            $event->event_date = get_post_meta($post->ID, '_lem_event_date', true);
            $event->price_id = get_post_meta($post->ID, '_lem_price_id', true);
            $event->is_free = get_post_meta($post->ID, '_lem_is_free', true) ?: 'free';
            $event->post_status = $post->post_status;
            $event->created_at = $post->post_date;
            $event->updated_at = $post->post_modified;
            $events[] = $event;
        }
        
        return $events;
    }
    
    // Get event by ID with multi-layer caching
    public function get_event_by_id($event_id) {
        $event_id = intval($event_id);
        
        if ($event_id <= 0) {
            return null;
        }
        
        $cache_key = "event:{$event_id}";
        
        // Level 1: In-memory cache (current request only)
        if (isset(self::$memory_cache[$cache_key])) {
            return self::$memory_cache[$cache_key];
        }
        
        // Level 2: Redis cache (shared across requests)
        $redis = $this->get_redis_connection();
        if ($redis) {
            $cached = $redis->get($cache_key);
            if ($cached !== false) {
                $data = json_decode($cached, true);
                if ($data) {
                    $event = (object) $data;
                    // Store in memory cache too
                    self::$memory_cache[$cache_key] = $event;
                    return $event;
                }
            }
        }
        
        // Level 3: WordPress database (cache miss)
        $post = get_post($event_id);
        
        if (!$post || $post->post_type !== 'lem_event') {
            return null;
        }
        
        $event = new stdClass();
        $event->event_id = $post->ID;
        $event->title = $post->post_title;
        $event->description = $post->post_content;
        $event->playback_id = get_post_meta($post->ID, '_lem_playback_id', true);
        $event->live_stream_id = get_post_meta($post->ID, '_lem_live_stream_id', true);
        $event->playback_restriction_id = get_post_meta($post->ID, '_lem_playback_restriction_id', true);
        $event->event_date = get_post_meta($post->ID, '_lem_event_date', true);
        $event->event_end  = get_post_meta($post->ID, '_lem_event_end',  true);
        $event->price_id = get_post_meta($post->ID, '_lem_price_id', true);
        $event->is_free = get_post_meta($post->ID, '_lem_is_free', true) ?: 'free';
        $event->post_status = $post->post_status;
        $event->created_at = $post->post_date;
        $event->updated_at = $post->post_modified;
        $event->slug = $post->post_name;
        
        // Cache in Redis for 2 hours (events don't change frequently during viewing)
        if ($redis) {
            $redis->setex($cache_key, 7200, json_encode($event));
        }
        
        // Store in memory cache
        self::$memory_cache[$cache_key] = $event;
        
        return $event;
    }
    
    /**
     * Get email by JTI (privacy-focused approach)
     * Only used when absolutely necessary (e.g., confirmation page)
     */
    public function get_email_by_jti($jti) {
        global $wpdb;
        $table = $wpdb->prefix . 'lem_jwt_tokens';
        $token = $wpdb->get_row($wpdb->prepare(
            "SELECT email FROM $table WHERE jti = %s",
            $jti
        ));
        
        return $token ? $token->email : null;
    }
    
    // Store JWT in database
    /**
     * Revoke and remove every active token for a given email + event.
     * Marks rows as revoked in MySQL and deletes the Redis keys so they
     * can never be used again. Called before issuing a replacement token.
     *
     * @param string $email
     * @param string $event_id
     * @return int Number of tokens invalidated
     */
    private function invalidate_existing_tokens($email, $event_id) {
        global $wpdb;
        $table = $wpdb->prefix . 'lem_jwt_tokens';

        $tokens = $wpdb->get_results($wpdb->prepare(
            "SELECT jti, hash_jti FROM $table
             WHERE email     = %s
               AND event_id  = %s
               AND revoked_at IS NULL
               AND expires_at > %s",
            $email,
            $event_id,
            current_time('mysql')
        ));

        if (empty($tokens)) {
            if ($redis = $this->get_redis_connection()) {
                $redis->del(LEM_Access::playback_key($email, $event_id));
            }
            return 0;
        }

        $redis = $this->get_redis_connection();
        if ($redis) {
            $redis->del(LEM_Access::playback_key($email, $event_id));
        }

        foreach ($tokens as $token) {
            // Revoke in DB
            $wpdb->update(
                $table,
                array('revoked_at' => current_time('mysql')),
                array('jti'        => $token->jti),
                array('%s'),
                array('%s')
            );

            // Remove from Redis
            if ($redis) {
                try {
                    $redis->del('jwt:'         . $token->hash_jti);
                    $redis->del('jti:'         . $token->jti);
                    $redis->del('jti_session:' . $token->jti);
                } catch (Exception $e) {
                    $this->debug_log('Redis delete failed during token invalidation', array(
                        'jti'   => $token->jti,
                        'error' => $e->getMessage(),
                    ));
                }
            }
        }

        $this->debug_log('Invalidated existing tokens', array(
            'email'    => $email,
            'event_id' => $event_id,
            'count'    => count($tokens),
        ));

        return count($tokens);
    }

    private function store_jwt($jti, $hash_jti, $jwt_token, $email, $event_id, $payment_id, $ip, $exp) {
        global $wpdb;
        
        $table = $wpdb->prefix . 'lem_jwt_tokens';
        
        return $wpdb->insert($table, array(
            'jti' => $jti,
            'hash_jti' => $hash_jti,
            'jwt_token' => $jwt_token,
            'email' => $email,
            'event_id' => $event_id,
            'payment_id' => $payment_id,
            'ip_address' => null, // Remove IP address storage
            'expires_at' => $exp
        ));
    }
    
    /**
     * Returns the Upstash cache instance, or false if not configured.
     * All existing $redis = $this->get_redis_connection() call sites continue to work
     * because LEM_Cache implements the same interface (get/set/setex/del/exists/keys/ping/pipeline).
     *
     * @param array $override_settings Optional credential overrides (used by connection test).
     * @return LEM_Cache|false
     */
    public function get_redis_connection($override_settings = array()) {
        $upstash_override = array();
        if (!empty($override_settings['upstash_redis_url'])) {
            $upstash_override['url']   = $override_settings['upstash_redis_url'];
            $upstash_override['token'] = $override_settings['upstash_redis_token'] ?? '';
        }
        return LEM_Cache::instance($upstash_override);
    }
    
    // Store JWT in Redis (legacy method using hash_jti)
    private function store_jwt_redis($hash_jti, $jwt_data) {
        $redis = $this->get_redis_connection();
        if (!$redis) return false;
        
        try {
            $key = 'jwt:' . $hash_jti;
            $expiry = $jwt_data['exp'] - time();
            if ($expiry > 0) {
                $encoded_data = json_encode($jwt_data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                if ($encoded_data === false) {
                    return false;
                }
                $redis->setex($key, $expiry, $encoded_data);
                return true;
            }
        } catch (Exception $e) {
            $this->debug_log('Redis operation failed: ' . $e->getMessage());
        }
        return false;
    }

    // Store JWT in Redis by JTI for Cloudflare Worker performance
    private function store_jwt_redis_by_jti($jti, $jwt_data, $exp_timestamp) {
        $redis = $this->get_redis_connection();
        if (!$redis) return false;
        
        try {
            $key = 'jwt:' . $jti;
            $expiry = $exp_timestamp - time();
            if ($expiry > 0) {
                $encoded_data = json_encode($jwt_data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                if ($encoded_data === false) {
                    return false;
                }
                $redis->setex($key, $expiry, $encoded_data);
                
                // Also cache the actual JWT token for fast retrieval
                if (isset($jwt_data['jwt_token'])) {
                    $redis->setex("jwt_token:{$jti}", $expiry, $jwt_data['jwt_token']);
                }
                
                return true;
            }
        } catch (Exception $e) {
            $this->debug_log('Redis operation failed: ' . $e->getMessage());
        }
        return false;
    }

    // Store JTI mapping in Redis
    private function store_jti_mapping($random_jti, $hash_jti) {
        $redis = $this->get_redis_connection();
        if (!$redis) return false;
        
        try {
            $key = 'jti_mapping:' . $random_jti;
            $redis->setex($key, 3600, $hash_jti);
            return true;
        } catch (Exception $e) {
            $this->debug_log('Redis operation failed: ' . $e->getMessage());
        }
        return false;
    }

    // Store event in Redis (optimized with longer TTL)
    private function store_event_redis($event_id, $event_data) {
        $redis = $this->get_redis_connection();
        if (!$redis) return false;
        
        try {
            $key = 'event:' . $event_id;
            $encoded_data = json_encode($event_data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if ($encoded_data === false) {
                return false;
            }
            // Cache for 2 hours (events don't change frequently during viewing)
            $redis->setex($key, 7200, $encoded_data);
            return true;
        } catch (Exception $e) {
            $this->debug_log('Failed to store event in Redis', array('error' => $e->getMessage()));
        }
        return false;
    }
    
    // Revoke JWT
    public function revoke_jwt($jti) {
        global $wpdb;
        
        $table = $wpdb->prefix . 'lem_jwt_tokens';
        
        // Get the token record to get all related data
        $token = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE jti = %s", $jti));
        
        if (!$token) {
            // Try as hash JTI directly
            $token = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE hash_jti = %s", $jti));
            if (!$token) {
                $this->debug_log('JWT not found for revocation', array('jti' => $jti));
                return false;
            }
            $hash_jti = $jti;
        } else {
            $hash_jti = $token->hash_jti;
        }
        
        $event_id = $token->event_id ?? '';
        $email = $token->email ?? '';
        
        // Mark as revoked in database
        $wpdb->update($table, array('revoked_at' => current_time('mysql')), array('hash_jti' => $hash_jti));
        
        $this->debug_log('Revoking JWT', array(
            'jti' => $jti,
            'hash_jti' => $hash_jti,
            'event_id' => $event_id,
            'email' => $this->redact_email($email)
        ));
        
        // Store in Redis for fast lookup (both hash_jti and jti for compatibility)
        $redis = $this->get_redis_connection();
        if ($redis) {
            // Mark as revoked in Redis
            $redis->setex("revoked:{$hash_jti}", 86400, "revoked"); // 24 hours
            $redis->setex("revoked:{$jti}", 86400, "revoked"); // 24 hours
            
            // Clear all cached JWT data
            $redis->del("jwt_token:{$jti}");
            $redis->del("jwt_token:{$hash_jti}");
            
            // Update JWT data in Redis to mark as revoked
            $jwt_data = $redis->get("jwt:{$jti}");
            if ($jwt_data) {
                $jwt_info = json_decode($jwt_data, true);
                if ($jwt_info) {
                    $jwt_info['revoked'] = true;
                    $jwt_info['revoked_at'] = gmdate('Y-m-d H:i:s');
                    $redis->setex("jwt:{$jti}", 86400, json_encode($jwt_info)); // 24 hours
                }
            }
            
            // Clear event access cache for this event and email
            if (!empty($event_id)) {
                // Clear all event access caches for this event (pattern matching)
                $email_hash = hash('sha256', strtolower(trim($email)));
                
                // Clear session-based access cache
                $session_id = $redis->get("jti_session:{$jti}");
                if ($session_id) {
                    $cache_key = "event_access:{$event_id}:{$session_id}";
                    $redis->del($cache_key);
                    $this->debug_log('Cleared event access cache', array('cache_key' => $cache_key));
                }
                
                // Clear session status cache
                $status_key = "session_status:{$event_id}:{$email_hash}";
                $redis->del($status_key);
                
                // Clear active sessions list
                $redis->del("active_sessions:{$event_id}:{$email_hash}");
                
                // Clear JTI to session mapping
                $redis->del("jti_session:{$jti}");
                $redis->del("jti_session:{$hash_jti}");
                
                // Clear session-related memory cache if we have session ID
                if ($session_id) {
                    unset(self::$memory_cache["jwt_session:{$session_id}"]);
                    unset(self::$memory_cache["session_val:{$session_id}"]);
                }
                
                // Clear all event access caches for this event using SCAN (non-blocking, unlike KEYS)
                $pattern = "event_access:{$event_id}:*";
                try {
                    $cursor = null;
                    $total_deleted = 0;
                    do {
                        $result = $redis->scan($cursor, $pattern, 100);
                        if ($result && is_array($result) && count($result) > 0) {
                            $redis->del($result);
                            $total_deleted += count($result);
                        }
                    } while ($cursor > 0);
                    if ($total_deleted > 0) {
                        $this->debug_log('Cleared all event access caches for event', array(
                            'event_id' => $event_id,
                            'keys_cleared' => $total_deleted
                        ));
                    }
                } catch (Exception $e) {
                    $this->debug_log('Error clearing event access cache pattern', array(
                        'pattern' => $pattern,
                        'error' => $e->getMessage()
                    ));
                }
            }
        }
        
        // Clear in-memory cache (always, even if Redis is not available)
        unset(self::$memory_cache["jwt:{$jti}"]);
        unset(self::$memory_cache["jwt_token:{$jti}"]);
        unset(self::$memory_cache["jwt_val:{$jti}"]);
        
        if (!empty($event_id)) {
            unset($this->event_access_cache[$event_id]);
        }
        
        return true;
    }
    
    // Register REST routes
    public function register_rest_routes() {
        register_rest_route('lem/v1', '/check-jwt-status', array(
            'methods' => 'POST',
            'callback' => array($this, 'check_jwt_status'),
            'permission_callback' => function($request) {
                if (is_user_logged_in()) {
                    return true;
                }
                $nonce = $request->get_header('X-WP-Nonce') ?? $request->get_param('lem_nonce');
                return !empty($nonce) && wp_verify_nonce($nonce, 'wp_rest');
            }
        ));
        
        register_rest_route('lem/v1', '/jwt-settings', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_jwt_settings'),
            'permission_callback' => array($this, 'check_admin_permission')
        ));
        
        register_rest_route('lem/v1', '/live-streams', array(
            'methods' => 'GET',
            'callback' => array($this, 'list_live_streams'),
            'permission_callback' => array($this, 'check_admin_permission')
        ));
        
        register_rest_route('lem/v1', '/live-streams', array(
            'methods' => 'POST',
            'callback' => array($this, 'create_live_stream'),
            'permission_callback' => array($this, 'check_admin_permission')
        ));
        
        register_rest_route('lem/v1', '/live-streams/(?P<id>[a-zA-Z0-9_-]+)', array(
            'methods' => 'DELETE',
            'callback' => array($this, 'delete_live_stream'),
            'permission_callback' => array($this, 'check_admin_permission')
        ));
        
        register_rest_route('lem/v1', '/live-streams/(?P<id>[a-zA-Z0-9_-]+)', array(
            'methods' => 'PUT',
            'callback' => array($this, 'update_live_stream'),
            'permission_callback' => array($this, 'check_admin_permission')
        ));
        
        register_rest_route('lem/v1', '/stream-status', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_stream_status'),
            'permission_callback' => array($this, 'check_admin_permission')
        ));
        
        register_rest_route('lem/v1', '/rtmp-info', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_rtmp_info'),
            'permission_callback' => array($this, 'check_admin_permission')
        ));
        
        register_rest_route('lem/v1', '/simulcast-targets', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_simulcast_targets'),
            'permission_callback' => array($this, 'check_admin_permission')
        ));
        
        register_rest_route('lem/v1', '/simulcast-targets', array(
            'methods' => 'POST',
            'callback' => array($this, 'create_simulcast_target'),
            'permission_callback' => array($this, 'check_admin_permission')
        ));
        
        register_rest_route('lem/v1', '/simulcast-targets/(?P<id>[a-zA-Z0-9_-]+)', array(
            'methods' => 'DELETE',
            'callback' => array($this, 'delete_simulcast_target'),
            'permission_callback' => array($this, 'check_admin_permission')
        ));

    }
    
    // Check admin permission for REST API
    private function check_admin_permission() {
        return current_user_can('manage_options');
    }
    
    // REST endpoint for JWT revocation check (Cloudflare Worker - edge-optimized)
    public function check_jwt_status($request) {
        $this->debug_log('JWT revocation check request received', array(
            'method' => $request->get_method()
        ));
        
        // Get JWT token (only parameter needed for revocation check)
        $jwt_token = $request->get_param('jwt') ?: $request->get_param('token');
        
        // Log the request for security monitoring
        $this->debug_log('JWT revocation check parameters', array(
            'has_jwt' => !empty($jwt_token),
            'jwt_length' => strlen($jwt_token ?? '')
        ));
        
        // JWT token is required
        if (empty($jwt_token)) {
            $this->debug_log('JWT revocation check failed - missing JWT token');
            return new WP_Error('missing_jwt', 'JWT token is required', array('status' => 400));
        }
        
        // Check JWT revocation status only
        return $this->validate_jwt_for_worker($jwt_token);
    }
    
    // JWT revocation check for Cloudflare Worker (edge-optimized)
    private function validate_jwt_for_worker($jwt_token, $detected_ip = null, $user_agent = null, $referer = null) {
        $this->debug_log('JWT revocation check for Cloudflare Worker', array(
            'jwt_length' => strlen($jwt_token)
        ));
        
        try {
            // Decode JWT to extract JTI (signature verification done by Worker)
            $parts = explode('.', $jwt_token);
            if (count($parts) !== 3) {
                throw new \Exception('Invalid JWT format');
            }
            $payload = json_decode(base64_decode(strtr($parts[1], '-_', '+/')), true);
            if (!$payload) {
                throw new \Exception('Invalid JWT payload');
            }
            
            $jti = $payload['jti'] ?? null;
            if (!$jti) {
                throw new \Exception('JTI not found in JWT payload');
            }
            
            $this->debug_log('JWT payload extracted for revocation check', array(
                'jti' => $jti,
                'playback_id' => $payload['sub'] ?? 'none'
            ));
            
            // Redis-only JWT validation for Cloudflare Worker performance
            $redis = $this->get_redis_connection();
            
            if (!$redis) {
                $this->debug_log('Redis not available for JWT validation', array('jti' => $jti));
                return new WP_Error('redis_unavailable', 'Redis not available for JWT validation', array('status' => 503));
            }
            
            // Use pipeline to batch Redis calls (reduces network round-trips)
            $use_pipeline = method_exists($redis, 'pipeline');
            
            if ($use_pipeline) {
                $pipe = $redis->pipeline();
                $pipe->exists("revoked:{$jti}");
                $pipe->get("jwt:{$jti}");
                $results = $pipe->execute();
                $revoked_in_redis = $results[0] ?? false;
                $jwt_data = $results[1] ?? false;
            } else {
            $revoked_in_redis = $redis->exists("revoked:{$jti}");
                $jwt_data = $redis->get("jwt:{$jti}");
            }
            
            if ($revoked_in_redis) {
                $this->debug_log('JWT revoked in Redis', array('jti' => $jti));
                return array(
                    'revoked' => true,
                    'status' => 'revoked',
                    'message' => 'JWT token has been revoked',
                    'jti' => $jti,
                    'playback_id' => $payload['sub'] ?? null,
                    'device_identifier' => null,
                    'identifier_type' => null,
                    'identifier_value' => null,
                    'session_id' => null,
                    'ip_address' => null,
                    'fingerprint' => null,
                    'check_method' => 'redis'
                );
            }
            
            // Check if JWT exists and get its data from Redis
            if (!$jwt_data) {
                $this->debug_log('JWT not found in Redis', array('jti' => $jti));
                return array(
                    'revoked' => false,
                    'status' => 'not_found',
                    'message' => 'JWT token not found in Redis',
                    'jti' => $jti,
                    'playback_id' => $payload['sub'] ?? null,
                    'device_identifier' => null,
                    'identifier_type' => null,
                    'identifier_value' => null,
                    'session_id' => null,
                    'ip_address' => null,
                    'fingerprint' => null,
                    'check_method' => 'redis'
                );
            }
            
            $jwt_info = json_decode($jwt_data, true);
            
            // Check if JWT is expired using Redis data
            if (isset($jwt_info['expires_at']) && strtotime($jwt_info['expires_at']) < time()) {
                $this->debug_log('JWT expired in Redis', array('jti' => $jti, 'expires_at' => $jwt_info['expires_at']));
                return array(
                    'revoked' => false,
                    'status' => 'expired',
                    'message' => 'JWT token has expired',
                    'jti' => $jti,
                    'playback_id' => $payload['sub'] ?? null,
                    'device_identifier' => $jwt_info['device_identifier'] ?? null,
                    'identifier_type' => $jwt_info['identifier_type'] ?? null,
                    'identifier_value' => $jwt_info['identifier_value'] ?? null,
                    'session_id' => $jwt_info['session_id'] ?? null,
                    'ip_address' => $jwt_info['ip_address'] ?? null,
                    'fingerprint' => $jwt_info['fingerprint'] ?? null,
                    'check_method' => 'redis'
                );
            }
            
            // JWT is active and not revoked
            $this->debug_log('JWT validation passed (Redis-only)', array(
                'jti' => $jti,
                'playback_id' => $payload['sub'] ?? null
            ));
            
            return array(
                'revoked' => false,
                'status' => 'active',
                'jti' => $jti,
                'playback_id' => $payload['sub'] ?? null,
                'playback_restriction_id' => $payload['playback_restriction_id'] ?? null,
                'device_identifier' => $jwt_info['device_identifier'] ?? null,
                'identifier_type' => $jwt_info['identifier_type'] ?? 'session_based',
                'identifier_value' => $jwt_info['identifier_value'] ?? null,
                'session_id' => $jwt_info['session_id'] ?? null,
                'ip_address' => $jwt_info['ip_address'] ?? null, // Legacy field
                'fingerprint' => $jwt_info['fingerprint'] ?? null, // Legacy field
                'redis_available' => true,
                'check_method' => 'redis'
            );
            
        } catch (\Exception $e) {
            $this->debug_log('JWT revocation check error', array('error' => $e->getMessage()));
            return new WP_Error('jwt_check_error', 'JWT revocation check failed: ' . $e->getMessage(), array('status' => 400));
        }
    }
    
    // REST endpoint for JWT settings
    public function get_jwt_settings($request) {
        $settings = get_option('lem_settings', array());
        
        return array(
            'jwt_expiration_hours' => intval($settings['jwt_expiration_hours'] ?? 24),
            'jwt_refresh_duration_minutes' => intval($settings['jwt_refresh_duration_minutes'] ?? 15),
            'refresh_interval_minutes' => intval($settings['jwt_refresh_duration_minutes'] ?? 15) - 1
        );
    }
    
    /**
     * Get Mux stream status (active/idle) for dual-state player
     */
    public function get_stream_status($request) {
        $settings = get_option('lem_settings', array());
        $live_stream_id = $request->get_param('stream_id') ?: ($settings['mux_live_stream_id'] ?? '');
        
        if (empty($live_stream_id)) {
            return new WP_Error('missing_stream_id', 'Stream ID is required', array('status' => 400));
        }
        
        $credentials = $this->get_mux_api_credentials();
        if (!$credentials) {
            return new WP_Error('mux_not_configured', 'Mux API credentials not configured', array('status' => 500));
        }
        
        // Call Mux API to get live stream status
        $url = "https://api.mux.com/video/v1/live-streams/{$live_stream_id}";
        $response = wp_remote_get($url, array(
            'headers' => array(
                'Authorization' => 'Basic ' . base64_encode($credentials['token_id'] . ':' . $credentials['token_secret'])
            )
        ));
        
        if (is_wp_error($response)) {
            return new WP_Error('mux_api_error', $response->get_error_message(), array('status' => 500));
        }
        
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        if (!$data || isset($data['error'])) {
            return new WP_Error('mux_api_error', 'Failed to fetch stream status', array('status' => 500));
        }
        
        $status = $data['data']['status'] ?? 'idle';
        $is_active = $status === 'active';
        
        // Get most recent asset if stream is idle
        $recent_asset = null;
        if (!$is_active) {
            $recent_asset = $this->get_most_recent_asset($live_stream_id, $credentials);
        }
        
        return array(
            'stream_id' => $live_stream_id,
            'status' => $status,
            'is_active' => $is_active,
            'recent_asset' => $recent_asset
        );
    }
    
    /**
     * Get RTMP stream key and ingest URL (cached for 1 hour)
     */
    public function get_rtmp_info($request) {
        $settings = get_option('lem_settings', array());
        $live_stream_id = $request->get_param('stream_id') ?: ($settings['mux_live_stream_id'] ?? '');
        
        if (empty($live_stream_id)) {
            return new WP_Error('missing_stream_id', 'Stream ID is required', array('status' => 400));
        }
        
        // Cache RTMP info (rarely changes)
        $redis = $this->get_redis_connection();
        $cache_key = "mux:rtmp_info:{$live_stream_id}";
        
        if ($redis) {
            $cached = $redis->get($cache_key);
            if ($cached !== false) {
                return json_decode($cached, true);
            }
        }
        
        $credentials = $this->get_mux_api_credentials();
        if (!$credentials) {
            return new WP_Error('mux_not_configured', 'Mux API credentials not configured', array('status' => 500));
        }
        
        // Call Mux API to get live stream info
        $url = "https://api.mux.com/video/v1/live-streams/{$live_stream_id}";
        $response = wp_remote_get($url, array(
            'headers' => array(
                'Authorization' => 'Basic ' . base64_encode($credentials['token_id'] . ':' . $credentials['token_secret'])
            ),
            'timeout' => 3
        ));
        
        if (is_wp_error($response)) {
            return new WP_Error('mux_api_error', $response->get_error_message(), array('status' => 500));
        }
        
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        if (!$data || isset($data['error'])) {
            return new WP_Error('mux_api_error', 'Failed to fetch RTMP info', array('status' => 500));
        }
        
        $stream_data = $data['data'] ?? array();
        
        $result = array(
            'stream_key' => $stream_data['stream_key'] ?? '',
            'ingest_url' => $stream_data['reconnect_window'] ? 
                ($stream_data['reconnect_window']['ingest_url'] ?? '') : 
                ($stream_data['ingest_url'] ?? ''),
            'playback_id' => $stream_data['playback_ids'][0]['id'] ?? ''
        );
        
        // Cache for 1 hour (RTMP credentials rarely change)
        if ($redis) {
            $redis->setex($cache_key, 3600, json_encode($result));
        }
        
        return $result;
    }
    
    /**
     * List all live streams from Mux
     */
    public function list_live_streams($request, $bypass_cache = false) {
        $credentials = $this->get_mux_api_credentials();
        if (!$credentials) {
            return new WP_Error('mux_not_configured', 'Mux API credentials not configured', array('status' => 500));
        }

        $redis     = $this->get_redis_connection();
        $cache_key = 'mux:live_streams_list';

        // Check cache unless caller wants fresh data
        if (!$bypass_cache && $redis) {
            $cached = $redis->get($cache_key);
            if ($cached !== false) {
                $decoded = json_decode($cached, true);
                if (is_array($decoded)) {
                    return $decoded;
                }
                // Corrupt entry — clear it and fall through to API
                $redis->del($cache_key);
            }
        }

        // Call Mux API
        $url      = 'https://api.mux.com/video/v1/live-streams?limit=100';
        $response = wp_remote_get($url, array(
            'headers' => array(
                'Authorization' => 'Basic ' . base64_encode($credentials['token_id'] . ':' . $credentials['token_secret']),
            ),
            'timeout' => 15,
        ));

        if (is_wp_error($response)) {
            return new WP_Error('mux_api_error', $response->get_error_message(), array('status' => 500));
        }

        $http_code = wp_remote_retrieve_response_code($response);
        $body      = wp_remote_retrieve_body($response);
        $data      = json_decode($body, true);

        if ($http_code !== 200 || !is_array($data)) {
            $msg = isset($data['error']['messages'][0]) ? $data['error']['messages'][0] : "HTTP {$http_code}: {$body}";
            return new WP_Error('mux_api_error', $msg, array('status' => $http_code));
        }

        if (isset($data['error'])) {
            return new WP_Error('mux_api_error', $data['error']['message'] ?? 'Mux API error', array('status' => 500));
        }

        $result = array(
            'data'      => $data['data'] ?? array(),
            'cached_at' => time(),
        );

        // Cache for 60 seconds — short enough to stay fresh, long enough to avoid hammering the API
        if ($redis) {
            $redis->setex($cache_key, 60, json_encode($result));
        }

        return $result;
    }
    
    /**
     * Create a new live stream via Mux API
     */
    public function create_live_stream($request) {
        $credentials = $this->get_mux_api_credentials();
        if (!$credentials) {
            return new WP_Error('mux_not_configured', 'Mux API credentials not configured', array('status' => 500));
        }
        
        $passthrough = $request->get_param('passthrough') ?: '';
        $reduced_latency = $request->get_param('reduced_latency');
        $test_mode = $request->get_param('test_mode');
        
        // Get playback_policies for live stream from request, default to 'public' if not provided
        $playback_policies = $request->get_param('playback_policies');
        if (empty($playback_policies) || !is_array($playback_policies)) {
            $playback_policies = array('public');
        }
        
        // Ensure playback_policies is an array
        if (is_string($playback_policies)) {
            $playback_policies = array($playback_policies);
        }
        
        // Validate playback policies (only allow 'public', 'signed', 'drm')
        $valid_policies = array('public', 'signed', 'drm');
        $playback_policies = array_filter($playback_policies, function($policy) use ($valid_policies) {
            return in_array($policy, $valid_policies);
        });
        
        // If no valid policies, default to 'public'
        if (empty($playback_policies)) {
            $playback_policies = array('public');
        }
        
        // Get asset_playback_policies for recorded assets from request, default to 'public' if not provided
        $asset_playback_policies = $request->get_param('asset_playback_policies');
        if (empty($asset_playback_policies) || !is_array($asset_playback_policies)) {
            // If not provided, use the same as playback_policies
            $asset_playback_policies = $playback_policies;
        }
        
        // Ensure asset_playback_policies is an array
        if (is_string($asset_playback_policies)) {
            $asset_playback_policies = array($asset_playback_policies);
        }
        
        // Validate asset playback policies (only allow 'public', 'signed', 'drm')
        $asset_playback_policies = array_filter($asset_playback_policies, function($policy) use ($valid_policies) {
            return in_array($policy, $valid_policies);
        });
        
        // If no valid policies, default to 'public'
        if (empty($asset_playback_policies)) {
            $asset_playback_policies = array('public');
        }
        
        // Convert to boolean if needed
        if ($reduced_latency === '1' || $reduced_latency === 'true' || $reduced_latency === true) {
            $reduced_latency = true;
        } else {
            $reduced_latency = false;
        }
        
        if ($test_mode === '1' || $test_mode === 'true' || $test_mode === true) {
            $test_mode = true;
        } else {
            $test_mode = false;
        }
        
        $payload = array();
        
        // Ensure arrays are properly indexed (not associative)
        $playback_policies = array_values($playback_policies);
        $asset_playback_policies = array_values($asset_playback_policies);
        
        // Set playback_policies for the live stream
        $payload['playback_policies'] = $playback_policies;
        
        // Set playback_policies for recorded assets (separate from live stream)
        $payload['new_asset_settings'] = array(
            'playback_policies' => $asset_playback_policies
        );
        
        if (!empty($passthrough)) {
            $payload['passthrough'] = $passthrough;
        }
        if ($reduced_latency) {
            $payload['reduced_latency'] = true;
        }
        if ($test_mode) {
            $payload['test'] = true;
        }
        
        // Log the payload for debugging
        $this->debug_log('Creating Mux stream with payload', array(
            'payload' => $payload,
            'json' => json_encode($payload, JSON_PRETTY_PRINT)
        ));
        
        // Call Mux API to create live stream
        $url = 'https://api.mux.com/video/v1/live-streams';
        $json_body = json_encode($payload);
        
        $response = wp_remote_post($url, array(
            'headers' => array(
                'Authorization' => 'Basic ' . base64_encode($credentials['token_id'] . ':' . $credentials['token_secret']),
                'Content-Type' => 'application/json'
            ),
            'body' => $json_body,
            'timeout' => 10
        ));
        
        if (is_wp_error($response)) {
            return new WP_Error('mux_api_error', $response->get_error_message(), array('status' => 500));
        }
        
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        if (isset($data['error'])) {
            return new WP_Error('mux_api_error', $data['error']['message'] ?? 'Failed to create live stream', array('status' => 500));
        }
        
        // Clear cache
        $redis = $this->get_redis_connection();
        if ($redis) {
            $redis->del('mux:live_streams_list');
        }
        
        // Return the stream data - Mux API wraps it in 'data' key
        $stream_data = $data['data'] ?? $data;
        
        // Ensure we return the full stream object with ID and playback_ids
        if (is_array($stream_data)) {
            if (!isset($stream_data['id']) && isset($data['data']['id'])) {
                $stream_data['id'] = $data['data']['id'];
            }
            // Ensure playback_ids are included
            if (!isset($stream_data['playback_ids']) && isset($data['data']['playback_ids'])) {
                $stream_data['playback_ids'] = $data['data']['playback_ids'];
            }
        }
        
        return $stream_data;
    }
    
    /**
     * Delete a live stream via Mux API
     */
    public function delete_live_stream($request) {
        $credentials = $this->get_mux_api_credentials();
        if (!$credentials) {
            return new WP_Error('mux_not_configured', 'Mux API credentials not configured', array('status' => 500));
        }
        
        // Get stream ID from URL parameters (REST route pattern: /live-streams/(?P<id>...)
        $url_params = $request->get_url_params();
        $stream_id = $url_params['id'] ?? '';
        
        // Fallback: try regular param
        if (empty($stream_id)) {
            $stream_id = $request->get_param('id');
        }
        
        if (empty($stream_id)) {
            $this->debug_log('Delete stream: Missing stream ID', array(
                'url_params' => $url_params,
                'all_params' => $request->get_params()
            ));
            return new WP_Error('missing_stream_id', 'Stream ID is required', array('status' => 400));
        }
        
        // Call Mux API to delete live stream
        $url = "https://api.mux.com/video/v1/live-streams/{$stream_id}";
        $response = wp_remote_request($url, array(
            'method' => 'DELETE',
            'headers' => array(
                'Authorization' => 'Basic ' . base64_encode($credentials['token_id'] . ':' . $credentials['token_secret'])
            ),
            'timeout' => 10
        ));
        
        if (is_wp_error($response)) {
            return new WP_Error('mux_api_error', $response->get_error_message(), array('status' => 500));
        }
        
        $code = wp_remote_retrieve_response_code($response);
        
        // Clear cache
        $redis = $this->get_redis_connection();
        if ($redis) {
            $redis->del('mux:live_streams_list');
        }
        
        if ($code === 204 || $code === 200) {
            return array('success' => true, 'message' => 'Stream deleted successfully');
        }
        
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        if (isset($data['error'])) {
            return new WP_Error('mux_api_error', $data['error']['message'] ?? 'Failed to delete live stream', array('status' => 500));
        }
        
        return array('success' => true);
    }
    
    /**
     * Update a live stream via Mux API
     */
    public function update_live_stream($request) {
        $credentials = $this->get_mux_api_credentials();
        if (!$credentials) {
            return new WP_Error('mux_not_configured', 'Mux API credentials not configured', array('status' => 500));
        }
        
        // Get stream ID from URL parameters (REST route pattern: /live-streams/(?P<id>...)
        $url_params = $request->get_url_params();
        $stream_id = $url_params['id'] ?? '';
        
        // Fallback: try regular param
        if (empty($stream_id)) {
            $stream_id = $request->get_param('id');
        }
        
        if (empty($stream_id)) {
            $this->debug_log('Update stream: Missing stream ID', array(
                'url_params' => $url_params,
                'all_params' => $request->get_params()
            ));
            return new WP_Error('missing_stream_id', 'Stream ID is required', array('status' => 400));
        }
        
        $passthrough = $request->get_param('passthrough');
        $reduced_latency = $request->get_param('reduced_latency');
        
        // Convert to boolean if needed
        if ($reduced_latency === '1' || $reduced_latency === 'true' || $reduced_latency === true) {
            $reduced_latency = true;
        } else {
            $reduced_latency = false;
        }
        
        $payload = array();
        if ($passthrough !== null && $passthrough !== '') {
            $payload['passthrough'] = $passthrough;
        }
        if ($reduced_latency !== null) {
            $payload['reduced_latency'] = $reduced_latency;
        }
        
        if (empty($payload)) {
            return new WP_Error('missing_params', 'No update parameters provided', array('status' => 400));
        }
        
        // Call Mux API to update live stream
        $url = "https://api.mux.com/video/v1/live-streams/{$stream_id}";
        $response = wp_remote_request($url, array(
            'method' => 'PUT',
            'headers' => array(
                'Authorization' => 'Basic ' . base64_encode($credentials['token_id'] . ':' . $credentials['token_secret']),
                'Content-Type' => 'application/json'
            ),
            'body' => json_encode($payload),
            'timeout' => 10
        ));
        
        if (is_wp_error($response)) {
            return new WP_Error('mux_api_error', $response->get_error_message(), array('status' => 500));
        }
        
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        if (isset($data['error'])) {
            return new WP_Error('mux_api_error', $data['error']['message'] ?? 'Failed to update live stream', array('status' => 500));
        }
        
        // Clear cache
        $redis = $this->get_redis_connection();
        if ($redis) {
            $redis->del('mux:live_streams_list');
        }
        
        return $data['data'] ?? $data;
    }
    
    /**
     * Get Simulcast targets
     */
    public function get_simulcast_targets($request) {
        $settings = get_option('lem_settings', array());
        $live_stream_id = $request->get_param('stream_id') ?: ($settings['mux_live_stream_id'] ?? '');
        
        if (empty($live_stream_id)) {
            return new WP_Error('missing_stream_id', 'Stream ID is required', array('status' => 400));
        }
        
        $credentials = $this->get_mux_api_credentials();
        if (!$credentials) {
            return new WP_Error('mux_not_configured', 'Mux API credentials not configured', array('status' => 500));
        }
        
        // Call Mux API to get simulcast targets
        $url = "https://api.mux.com/video/v1/live-streams/{$live_stream_id}/simulcast-targets";
        $response = wp_remote_get($url, array(
            'headers' => array(
                'Authorization' => 'Basic ' . base64_encode($credentials['token_id'] . ':' . $credentials['token_secret'])
            )
        ));
        
        if (is_wp_error($response)) {
            return new WP_Error('mux_api_error', $response->get_error_message(), array('status' => 500));
        }
        
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        // Mux API returns data directly as array, not wrapped
        // Check if it's already an array or wrapped in 'data'
        if (isset($data['data']) && is_array($data['data'])) {
            return $data['data'];
        }
        
        // If it's already an array, return it
        if (is_array($data)) {
            return $data;
        }
        
        return array();
    }
    
    /**
     * Create Simulcast target
     */
    public function create_simulcast_target($request) {
        $settings = get_option('lem_settings', array());
        $live_stream_id = $request->get_param('stream_id') ?: ($settings['mux_live_stream_id'] ?? '');
        $url = $request->get_param('url');
        $stream_key = $request->get_param('stream_key');
        
        if (empty($live_stream_id) || empty($url)) {
            return new WP_Error('missing_params', 'Stream ID and URL are required', array('status' => 400));
        }
        
        $credentials = $this->get_mux_api_credentials();
        if (!$credentials) {
            return new WP_Error('mux_not_configured', 'Mux API credentials not configured', array('status' => 500));
        }
        
        $payload = array('url' => $url);
        if (!empty($stream_key)) {
            $payload['stream_key'] = $stream_key;
        }
        
        // Call Mux API to create simulcast target
        $api_url = "https://api.mux.com/video/v1/live-streams/{$live_stream_id}/simulcast-targets";
        $response = wp_remote_post($api_url, array(
            'headers' => array(
                'Authorization' => 'Basic ' . base64_encode($credentials['token_id'] . ':' . $credentials['token_secret']),
                'Content-Type' => 'application/json'
            ),
            'body' => json_encode($payload)
        ));
        
        if (is_wp_error($response)) {
            return new WP_Error('mux_api_error', $response->get_error_message(), array('status' => 500));
        }
        
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        if (isset($data['error'])) {
            return new WP_Error('mux_api_error', $data['error']['message'] ?? 'Failed to create simulcast target', array('status' => 500));
        }
        
        return $data['data'] ?? array();
    }
    
    /**
     * Delete Simulcast target
     */
    public function delete_simulcast_target($request) {
        $settings = get_option('lem_settings', array());
        $live_stream_id = $request->get_param('stream_id') ?: ($settings['mux_live_stream_id'] ?? '');
        $target_id = $request->get_param('id');
        
        if (empty($live_stream_id) || empty($target_id)) {
            return new WP_Error('missing_params', 'Stream ID and target ID are required', array('status' => 400));
        }
        
        $credentials = $this->get_mux_api_credentials();
        if (!$credentials) {
            return new WP_Error('mux_not_configured', 'Mux API credentials not configured', array('status' => 500));
        }
        
        // Call Mux API to delete simulcast target
        $url = "https://api.mux.com/video/v1/live-streams/{$live_stream_id}/simulcast-targets/{$target_id}";
        $response = wp_remote_request($url, array(
            'method' => 'DELETE',
            'headers' => array(
                'Authorization' => 'Basic ' . base64_encode($credentials['token_id'] . ':' . $credentials['token_secret'])
            )
        ));
        
        if (is_wp_error($response)) {
            return new WP_Error('mux_api_error', $response->get_error_message(), array('status' => 500));
        }
        
        $code = wp_remote_retrieve_response_code($response);
        if ($code >= 200 && $code < 300) {
            return array('success' => true);
        }
        
        return new WP_Error('mux_api_error', 'Failed to delete simulcast target', array('status' => 500));
    }
    
    /**
     * Get most recent asset for a live stream
     */
    private function get_most_recent_asset($live_stream_id, $credentials) {
        // Get assets for this stream
        $url = "https://api.mux.com/video/v1/live-streams/{$live_stream_id}";
        $response = wp_remote_get($url, array(
            'headers' => array(
                'Authorization' => 'Basic ' . base64_encode($credentials['token_id'] . ':' . $credentials['token_secret'])
            )
        ));
        
        if (is_wp_error($response)) {
            return null;
        }
        
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        if (!$data || !isset($data['data']['recent_asset_ids']) || empty($data['data']['recent_asset_ids'])) {
            return null;
        }
        
        // Get the most recent asset
        $asset_id = $data['data']['recent_asset_ids'][0];
        $asset_url = "https://api.mux.com/video/v1/assets/{$asset_id}";
        $asset_response = wp_remote_get($asset_url, array(
            'headers' => array(
                'Authorization' => 'Basic ' . base64_encode($credentials['token_id'] . ':' . $credentials['token_secret'])
            )
        ));
        
        if (is_wp_error($asset_response)) {
            return null;
        }
        
        $asset_body = wp_remote_retrieve_body($asset_response);
        $asset_data = json_decode($asset_body, true);
        
        if (!$asset_data || !isset($asset_data['data'])) {
            return null;
        }
        
        $asset = $asset_data['data'];
        $playback_id = $asset['playback_ids'][0]['id'] ?? '';
        
        return array(
            'asset_id' => $asset_id,
            'playback_id' => $playback_id,
            'status' => $asset['status'] ?? '',
            'duration' => $asset['duration'] ?? 0
        );
    }
    
    // Direct JWT validation method (legacy)
    private function validate_jwt_direct($jwt_token, $ip = null, $playback_id = null) {
        $this->debug_log('Validating JWT directly', array('jwt_length' => strlen($jwt_token)));
        
        try {
            // Check if JWT library is available
            if (!class_exists('\Firebase\JWT\JWT')) {
                $this->debug_log('JWT library not available');
                return new WP_Error('jwt_library_missing', 'JWT validation library not available', array('status' => 500));
            }
            
            // Decode JWT without verification first to get payload
            $parts = explode('.', $jwt_token);
            if (count($parts) !== 3) {
                throw new \Exception('Invalid JWT format');
            }
            $payload = json_decode(base64_decode(strtr($parts[1], '-_', '+/')), true);
            if (!$payload) {
                throw new \Exception('Invalid JWT payload');
            }
            
            $this->debug_log('JWT payload extracted', array(
                'jti' => $payload['jti'] ?? 'none',
                'exp' => $payload['exp'] ?? 'none',
                'sub' => $payload['sub'] ?? 'none'
            ));
            
            // Check if JWT is expired
            if (isset($payload['exp']) && $payload['exp'] < time()) {
                $this->debug_log('JWT expired', array('exp' => $payload['exp'], 'current_time' => time()));
                return array(
                    'valid' => false,
                    'status' => 'expired',
                    'message' => 'JWT token has expired'
                );
            }
            
            // Check if JWT is revoked in Redis
            $redis = $this->get_redis_connection();
            if ($redis && isset($payload['jti'])) {
                if ($redis->exists("revoked:{$payload['jti']}")) {
                    $this->debug_log('JWT revoked in Redis', array('jti' => $payload['jti']));
                    return array(
                        'valid' => false,
                        'status' => 'revoked',
                        'message' => 'JWT token has been revoked'
                    );
                }
            }
            
            // Check if JWT exists in database
            global $wpdb;
            $table = $wpdb->prefix . 'lem_jwt_tokens';
            $token_record = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM $table WHERE jti = %s AND revoked_at IS NULL",
                $payload['jti'] ?? ''
            ));
            
            if (!$token_record) {
                $this->debug_log('JWT not found in database', array('jti' => $payload['jti'] ?? 'none'));
                return array(
                    'valid' => false,
                    'status' => 'not_found',
                    'message' => 'JWT token not found in database'
                );
            }
            
            // Check if JWT is expired in database
            if (strtotime($token_record->expires_at) < time()) {
                $this->debug_log('JWT expired in database', array(
                    'expires_at' => $token_record->expires_at,
                    'current_time' => gmdate('Y-m-d H:i:s')
                ));
                return array(
                    'valid' => false,
                    'status' => 'expired',
                    'message' => 'JWT token has expired'
                );
            }
            
            // Optional: Validate IP if provided (email removed for privacy)
            if ($ip && $token_record->ip_address !== $ip) {
                $this->debug_log('JWT IP mismatch', array(
                    'provided_ip' => $ip,
                    'token_ip' => $token_record->ip_address
                ));
                return array(
                    'valid' => false,
                    'status' => 'invalid',
                    'message' => 'IP address does not match JWT token'
                );
            }
            
            // JWT is valid
            $this->debug_log('JWT validation successful', array(
                'jti' => $payload['jti'],
                'email' => $token_record->email,
                'event_id' => $token_record->event_id
            ));
            
            return array(
                'valid' => true,
                'status' => 'active',
                'jti' => $payload['jti'],
                'email' => $token_record->email,
                'event_id' => $token_record->event_id,
                'playback_id' => $payload['sub'] ?? null,
                'playback_restriction_id' => $payload['playback_restriction_id'] ?? null,
                'expires_at' => $token_record->expires_at
            );
            
        } catch (\Exception $e) {
            $this->debug_log('JWT validation error', array('error' => $e->getMessage()));
            return new WP_Error('jwt_validation_error', 'JWT validation failed: ' . $e->getMessage(), array('status' => 400));
        }
    }
    
    // Hash-based JWT validation method (fallback)
    private function validate_jwt_by_hash($email, $ip, $playback_id) {
        $this->debug_log('Validating JWT by hash', array('email' => $this->redact_email($email), 'ip' => $ip, 'playback_id' => $playback_id));
        
        // Recreate hash JTI for Redis lookup
        $hash_jti = hash('sha256', $email . '|' . $ip . '|' . $playback_id);
        
        $redis = $this->get_redis_connection();
        if ($redis && $redis->exists("revoked:{$hash_jti}")) {
            $this->debug_log('JWT revoked by hash', array('hash_jti' => $hash_jti));
            return array(
                'valid' => false,
                'status' => 'revoked',
                'message' => 'JWT token has been revoked'
            );
        }
        
        // Check database for active token
        global $wpdb;
        $table = $wpdb->prefix . 'lem_jwt_tokens';
        $token_record = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table WHERE hash_jti = %s AND revoked_at IS NULL AND expires_at > NOW()",
            $hash_jti
        ));
        
        if (!$token_record) {
            $this->debug_log('JWT not found by hash', array('hash_jti' => $hash_jti));
            return array(
                'valid' => false,
                'status' => 'not_found',
                'message' => 'No active JWT token found for these parameters'
            );
        }
        
        $this->debug_log('JWT validation successful by hash', array(
            'hash_jti' => $hash_jti,
            'email' => $token_record->email,
            'event_id' => $token_record->event_id
        ));
        
        return array(
            'valid' => true,
            'status' => 'active',
            'jti' => $token_record->jti,
            'email' => $token_record->email,
            'event_id' => $token_record->event_id,
            'playback_id' => $playback_id,
            'expires_at' => $token_record->expires_at
        );
    }
    
    // Test endpoint for JWT verification (development only)

    
    // Handle Stripe webhook
    public function handle_stripe_webhook() {
        $this->debug_log('Stripe webhook received');
        
        $settings = get_option('lem_settings', array());
        $webhook_secret = $settings['stripe_mode'] === 'live' 
            ? $settings['stripe_live_webhook_secret'] 
            : $settings['stripe_test_webhook_secret'];
        
        if (empty($webhook_secret)) {
            $this->debug_log('Webhook secret not configured');
            status_header(400);
            wp_die('Webhook secret not configured', 'Webhook Error', array('response' => 400));
        }

        $payload = @file_get_contents('php://input');
        $sig_header = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';
        if (empty($sig_header)) {
            $this->debug_log('Missing Stripe-Signature header');
            status_header(400);
            wp_die('Missing signature', 'Webhook Error', array('response' => 400));
        }

        try {
            // Check if Stripe library is available
            if (!class_exists('\Stripe\Webhook')) {
                $this->debug_log('Stripe library not available');
                status_header(500);
                wp_die('Stripe library not available', 'Webhook Error', array('response' => 500));
            }

            $event = \Stripe\Webhook::constructEvent($payload, $sig_header, $webhook_secret);
        } catch(\UnexpectedValueException $e) {
            $this->debug_log('Invalid payload: ' . $e->getMessage());
            status_header(400);
            wp_die('Invalid payload', 'Webhook Error', array('response' => 400));
        } catch(\Stripe\Exception\SignatureVerificationException $e) {
            $this->debug_log('Invalid signature: ' . $e->getMessage());
            status_header(400);
            wp_die('Invalid signature', 'Webhook Error', array('response' => 400));
        }
        
        $this->debug_log('Webhook event: ' . $event->type);
        
        if ($event->type === 'checkout.session.completed') {
            $session = $event->data->object;
            $event_id = $session->metadata->event_id ?? null;
            // Prefer the email we stored at checkout time; fall back to what Stripe collected
            $email = $session->metadata->email ?? $session->customer_details->email ?? null;

            if ($event_id && $email) {
                $this->debug_log('Processing payment for event: ' . $event_id . ', email: ' . $this->redact_email($email));

                global $wpdb;
                $table = $wpdb->prefix . 'lem_jwt_tokens';
                
                // Check for existing JWT with this payment_id (session_id)
                $existing_token = $wpdb->get_row($wpdb->prepare(
                    "SELECT jti, jwt_token, created_at FROM $table WHERE payment_id = %s AND revoked_at IS NULL ORDER BY created_at DESC LIMIT 1",
                    $session->id
                ));

                if ($existing_token) {
                    $this->debug_log('Stripe webhook duplicate detected. Skipping JWT regeneration.', array(
                        'session_id' => $session->id,
                        'event_id' => $event_id,
                        'email' => $this->redact_email($email),
                        'existing_jti' => $existing_token->jti
                    ));
                    
                    // Still send the magic link email if it hasn't been sent yet
                    // Check if magic link was already sent by checking if session exists
                    $redis = $this->get_redis_connection();
                    $session_id_from_jti = null;
                    if ($redis) {
                        $session_id_from_jti = $redis->get("jti_session:{$existing_token->jti}");
                    }
                    
                    // If no session exists, send the magic link email
                    if (!$session_id_from_jti) {
                        $this->magic_link_service->send_magic_link_email($email, $existing_token->jwt_token, $event_id, null);
                        $this->debug_log('Magic link email sent for existing JWT', array(
                            'session_id' => $session->id,
                            'jti' => $existing_token->jti
                        ));
                    }
                } else {
                    if ($this->magic_link_service->has_valid_ticket($email, $event_id)) {
                        $this->debug_log('Stripe webhook: skipped issuing access — email already has valid access for this event', array(
                            'email'      => $this->redact_email($email),
                            'event_id'   => $event_id,
                            'session_id' => $session->id,
                        ));
                    } else {
                        // Store email as valid access before generating JWT
                        $this->store_event_email($event_id, $email);

                        // Generate playback token (paid events)
                        $jwt_result = $this->generate_jwt($email, $event_id, $session->id);
                        if ($jwt_result && isset($jwt_result['jwt'])) {
                            $sid = $this->create_session($event_id, $email);
                            $jti_for_session = $jwt_result['jti'] ?? '';
                            if (!empty($jti_for_session)) {
                                $r = $this->get_redis_connection();
                                if ($r) {
                                    $r->setex("jti_session:{$jti_for_session}", 24 * 60 * 60, $sid);
                                }
                            }
                            $this->magic_link_service->send_magic_link_email($email, $jwt_result['jwt'], $event_id, $sid);
                            $this->debug_log('JWT generated and email sent for payment', array(
                                'session_id' => $session->id,
                                'event_id'   => $event_id,
                                'email'      => $this->redact_email($email),
                                'jti'        => $jwt_result['jti'] ?? 'unknown',
                            ));
                        } else {
                            $this->debug_log('Failed to generate JWT for payment', array(
                                'session_id' => $session->id,
                                'event_id'   => $event_id,
                                'email'      => $this->redact_email($email),
                            ));
                        }
                    }
                }
            } else {
                $this->debug_log('Missing event_id or email in session metadata', array(
                    'session_id' => $session->id,
                    'event_id' => $event_id,
                    'has_email' => !empty($email),
                ));
            }
        }

        status_header(200);
        wp_die('Webhook processed', '', array('response' => 200));
    }

    /**
     * Handle Mux webhook events
     * Specifically handles video.asset.ready to create "Past Stream" posts
     */
    public function handle_mux_webhook() {
        $this->debug_log('Mux webhook received');
        
        $payload = @file_get_contents('php://input');
        $signature = $_SERVER['HTTP_MUX_SIGNATURE'] ?? '';
        
        // Verify webhook signature if configured
        $settings = get_option('lem_settings', array());
        $webhook_secret = $settings['mux_webhook_secret'] ?? '';
        
        if (empty($webhook_secret)) {
            $this->debug_log('Mux webhook secret not configured — rejecting request');
            status_header(403);
            wp_die('Webhook secret not configured', 'Webhook Error', array('response' => 403));
        }

        $expected_signature = hash_hmac('sha256', $payload, $webhook_secret);
        if (!hash_equals($expected_signature, $signature)) {
            $this->debug_log('Invalid Mux webhook signature');
            status_header(401);
            wp_die('Invalid signature', 'Webhook Error', array('response' => 401));
        }

        $event = json_decode($payload, true);

        if (!$event) {
            $this->debug_log('Invalid Mux webhook payload');
            status_header(400);
            wp_die('Invalid payload', 'Webhook Error', array('response' => 400));
        }
        
        $event_type = $event['type'] ?? '';
        $this->debug_log('Mux webhook event: ' . $event_type);
        
        // Handle video.asset.ready event
        if ($event_type === 'video.asset.ready') {
            $asset_data = $event['data'] ?? array();
            $asset_id = $asset_data['id'] ?? '';
            $playback_id = $asset_data['playback_ids'][0]['id'] ?? '';
            $status = $asset_data['status'] ?? '';
            
            if ($asset_id && $playback_id && $status === 'ready') {
                // Find the event that matches this playback_id
                $events = get_posts(array(
                    'post_type' => 'lem_event',
                    'meta_key' => '_lem_playback_id',
                    'meta_value' => $playback_id,
                    'posts_per_page' => 1
                ));
                
                if (!empty($events)) {
                    $event_post = $events[0];
                    $event_id = $event_post->ID;
                    
                    // Create a "Past Stream" post
                    $past_stream_title = 'Past Stream: ' . $event_post->post_title;
                    $past_stream_id = wp_insert_post(array(
                        'post_title' => $past_stream_title,
                        'post_content' => 'This is an automatically created past stream recording.',
                        'post_status' => 'publish',
                        'post_type' => 'lem_event',
                        'post_parent' => $event_id,
                        'meta_input' => array(
                            '_lem_playback_id' => $playback_id,
                            '_lem_asset_id' => $asset_id,
                            '_lem_is_past_stream' => '1',
                            '_lem_original_event_id' => $event_id,
                            '_lem_status' => 'past'
                        )
                    ));
                    
                    if ($past_stream_id && !is_wp_error($past_stream_id)) {
                        $this->debug_log('Past stream post created', array(
                            'past_stream_id' => $past_stream_id,
                            'original_event_id' => $event_id,
                            'asset_id' => $asset_id,
                            'playback_id' => $playback_id
                        ));
                    } else {
                        $this->debug_log('Failed to create past stream post', array(
                            'error' => is_wp_error($past_stream_id) ? $past_stream_id->get_error_message() : 'Unknown error'
                        ));
                    }
                } else {
                    $this->debug_log('No event found for playback_id', array('playback_id' => $playback_id));
                }
            }
        }

        status_header(200);
        wp_die('Webhook processed', '', array('response' => 200));
    }

    // Get client IP address (handles proxies and load balancers)
    private function get_client_ip() {
        // Only trust proxy headers if explicitly configured (prevents IP spoofing)
        $trust_proxy = defined('LEM_TRUST_PROXY_HEADERS') && LEM_TRUST_PROXY_HEADERS;

        if ($trust_proxy) {
            $ip_keys = array('HTTP_CLIENT_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_FORWARDED', 'HTTP_X_CLUSTER_CLIENT_IP', 'HTTP_FORWARDED_FOR', 'HTTP_FORWARDED', 'REMOTE_ADDR');
        } else {
            $ip_keys = array('REMOTE_ADDR');
        }

        foreach ($ip_keys as $key) {
            if (array_key_exists($key, $_SERVER) === true) {
                foreach (explode(',', $_SERVER[$key]) as $ip) {
                    $ip = trim($ip);
                    // First try to find a public IP
                    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false) {
                        return $ip;
                    }
                }
            }
        }
        
        // If no public IP found, try to get any valid IP (including private ones)
        foreach ($ip_keys as $key) {
            if (array_key_exists($key, $_SERVER) === true) {
                foreach (explode(',', $_SERVER[$key]) as $ip) {
                    $ip = trim($ip);
                    if (filter_var($ip, FILTER_VALIDATE_IP) !== false) {
                        return $ip;
                    }
                }
            }
        }
        
        // Final fallback
        return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    }

    /**
     * Simple transient-based rate limiting for public AJAX endpoints.
     */
    private function check_rate_limit($action, $limit_seconds = 3) {
        $ip = $this->get_client_ip();
        $key = 'lem_rl_' . md5($action . $ip);
        if (get_transient($key)) {
            return false; // rate limited
        }
        set_transient($key, 1, $limit_seconds);
        return true; // allowed
    }

    /**
     * Redact an email address for safe logging.
     */
    private function redact_email($email) {
        if (empty($email) || !is_string($email)) return '[empty]';
        $parts = explode('@', $email);
        if (count($parts) !== 2) return '[invalid]';
        return substr($parts[0], 0, 3) . '***@' . $parts[1];
    }

    // Send magic link email
    private function send_magic_link_email($email, $jwt, $event_id, $session_id = null) {
        return $this->magic_link_service->send_magic_link_email($email, $jwt, $event_id, $session_id);
    }
    
    // Generate one-time magic token
    public function generate_magic_token($email, $event_id, $session_id = null) {
        return $this->magic_link_service->generate_magic_token($email, $event_id, $session_id);
    }
    
    public function validate_magic_token($token) {
        return $this->magic_link_service->validate_magic_token($token);
    }
    
    // Create session for magic token
    private function create_session_for_magic_token($email, $event_id) {
        return $this->create_session($event_id, $email);
    }
    
    // Send new magic link for device change
    private function send_new_device_magic_link($email, $event_id, $session_id) {
        return $this->magic_link_service->send_new_device_magic_link($email, $event_id, $session_id);
    }
    
    public function validate_email_and_send_link($email, $event_id) {
        return $this->magic_link_service->validate_email_and_send_link($email, $event_id);
    }
    
    // Check if there's a valid ticket for email/event
    private function check_valid_ticket($email, $event_id) {
        return $this->magic_link_service->has_valid_ticket($email, $event_id);
    }

    private function cache_session_status($event_id, $email, $valid) {
        $this->magic_link_service->cache_session_status($event_id, $email, $valid);
    }
    

    
    // Get active session for email and event
    private function get_active_session_for_email_event($email, $event_id) {
        return $this->magic_link_service->get_active_session_for_email_event($email, $event_id);
    }
    
    public function get_event_access_state($event_id) {
        $event_id = intval($event_id);

        if ($event_id <= 0) {
            return array(
                'event' => null,
                'can_watch' => false,
                'session_id' => '',
                'jwt_token' => '',
                'email' => '',
                'chat_name' => '',
                'error_message' => __('Missing event context.', 'live-event-manager'),
                'success_message' => ''
            );
        }

        // Level 1: In-memory cache (current request only)
        if (isset($this->event_access_cache[$event_id])) {
            return $this->event_access_cache[$event_id];
        }

        // Level 2: Redis cache per session (shared across requests)
        $session_id = $_COOKIE['lem_session_id'] ?? '';
        $redis = $this->get_redis_connection();
        
        if ($redis && !empty($session_id)) {
            $cache_key = "event_access:{$event_id}:{$session_id}";
            $cached = $redis->get($cache_key);
            
            if ($cached !== false) {
                $state = json_decode($cached, true);
                if ($state) {
                    // Re-validate JWT if cached state says user can watch
                    // This ensures revoked JWTs don't allow access
                    if ($state['can_watch'] && !empty($state['jwt_token'])) {
                        // Extract JTI from JWT to check revocation
                        $jwt_parts = explode('.', $state['jwt_token']);
                        if (count($jwt_parts) === 3) {
                            $payload = json_decode(base64_decode(strtr($jwt_parts[1], '-_', '+/')), true);
                            $jti = $payload['custom']['jti'] ?? '';
                            
                            if (!empty($jti)) {
                                // Check if JWT is revoked
                                $is_revoked = $redis->exists("revoked:{$jti}");
                                if ($is_revoked) {
                                    // JWT was revoked, clear cache and recalculate
                                    $redis->del($cache_key);
                                    unset($this->event_access_cache[$event_id]);
                                    $this->debug_log('Cached access state invalidated due to revoked JWT', array(
                                        'jti' => $jti,
                                        'event_id' => $event_id,
                                        'session_id' => $session_id
                                    ));
                                    // Fall through to recalculate below
                                } else {
                                    // JWT is still valid, use cached state
                                    $this->event_access_cache[$event_id] = $state;
                                    return $state;
                                }
                            } else {
                                // Can't extract JTI, use cached state (shouldn't happen)
                                $this->event_access_cache[$event_id] = $state;
                                return $state;
                            }
                        } else {
                            // Invalid JWT format, use cached state
                            $this->event_access_cache[$event_id] = $state;
                            return $state;
                        }
                    } else {
                        // Cached state says no access, use it
                        $this->event_access_cache[$event_id] = $state;
                        return $state;
                    }
                }
            }
        }

        // Level 3: Calculate (cache miss)
        $state = array(
            'event' => $this->get_event_by_id($event_id), // This is now cached
            'can_watch' => false,
            'session_id' => '',
            'jwt_token' => '',
            'email' => '',
            'chat_name' => '',
            'error_message' => '',
            'success_message' => ''
        );

        if (isset($_GET['lem_error'])) {
            $state['error_message'] = sanitize_text_field(wp_unslash($_GET['lem_error']));
        }

        if (isset($_GET['lem_success']) && $_GET['lem_success'] === '1') {
            $state['success_message'] = __('Access confirmed. Enjoy the stream!', 'live-event-manager');
        }

        if (!empty($session_id)) {
            $session_validation = $this->validate_session($session_id); // Now optimized with caching

            if (!empty($session_validation['valid'])) {
                $session = $session_validation['session'];
                $event_matches = isset($session['event_id']) && (string) $session['event_id'] === (string) $event_id;

                if ($event_matches) {
                    $jwt_token = $this->get_jwt_for_session($session_id);

                    if (!empty($jwt_token)) {
                        $state['can_watch'] = true;
                        $state['session_id'] = $session_id;
                        $state['jwt_token'] = $jwt_token;
                        $state['event'] = $session_validation['event'] ?: $state['event'];
                        $state['email'] = $session['email'] ?? '';
                        $state['chat_name'] = $session['chat_name'] ?? '';
                    } else {
                        $state['error_message'] = __('Unable to fetch stream access token. Request a new link to continue.', 'live-event-manager');
                    }
                } else {
                    $state['error_message'] = __('Your active session is linked to a different event. Request a new link for this stream.', 'live-event-manager');
                }
            } else {
                $error = $session_validation['error'] ?? '';
                $state['error_message'] = $error ?: __('Session expired. Request a new link to continue.', 'live-event-manager');

                // Only destroy the cookie for definitive/terminal session failures.
                // Transient errors (Redis unavailable, connection timeout) should
                // NOT wipe the cookie — the user still has valid access and will
                // recover on the next page load once Redis is back.
                $transient_error = (
                    stripos($error, 'Redis') !== false ||
                    stripos($error, 'connection') !== false ||
                    stripos($error, 'unavailable') !== false
                );

                if (!$transient_error) {
                    $this->clear_session_cookie();
                }
            }
        }

        // Cache result in Redis (5 minute TTL - session-based)
        if ($redis && !empty($session_id)) {
            $cache_key = "event_access:{$event_id}:{$session_id}";
            $ttl = $state['can_watch'] ? 300 : 30; // 5 min for valid, 30 sec for errors
            $redis->setex($cache_key, $ttl, json_encode($state));
        }

        // Store in memory cache
        $this->event_access_cache[$event_id] = $state;

        return $state;
    }

    /**
     * Primary playback string for the player (Mux JWT or OME WebRTC URL) from lem:playback.
     */
    public function get_jwt_for_session($session_id) {
        $memory_key = "jwt_session:{$session_id}";
        if (isset(self::$memory_cache[$memory_key])) {
            return self::$memory_cache[$memory_key];
        }

        $redis = $this->get_redis_connection();
        if (!$redis) {
            return null;
        }

        $session_data_json = $redis->get("session:{$session_id}");
        if (!$session_data_json) {
            return null;
        }

        $session_data = json_decode($session_data_json, true);
        if (!$session_data) {
            return null;
        }
        if (isset($session_data['active']) && !$session_data['active']) {
            return null;
        }

        $email    = $session_data['email'] ?? '';
        $event_id = $session_data['event_id'] ?? 0;
        if ($email === '' || !$event_id) {
            return null;
        }

        if (class_exists('LEM_Access') && LEM_Access::is_email_revoked_for_event($email, $event_id)) {
            return null;
        }

        $key = LEM_Access::playback_key($email, $event_id);
        $raw = $redis->get($key);
        if (!$raw) {
            $this->ensure_playback_blob($email, $event_id);
            $raw = $redis->get($key);
        }
        if (!$raw) {
            return null;
        }

        $blob = json_decode($raw, true);
        if (!is_array($blob)) {
            return null;
        }

        $token = '';
        if (($blob['vendor'] ?? '') === 'mux') {
            $token = $blob['mux_jwt'] ?? '';
        } elseif (($blob['vendor'] ?? '') === 'ome') {
            $token = $blob['jwt'] ?? '';
        } else {
            $token = $blob['mux_jwt'] ?? $blob['jwt'] ?? '';
        }

        if ($token !== '') {
            self::$memory_cache[$memory_key] = $token;
        }

        return $token !== '' ? $token : null;
    }

    /**
     * Full playback blob for OME templates (policy, llhls, etc.).
     */
    public function get_playback_blob_for_session($session_id) {
        $redis = $this->get_redis_connection();
        if (!$redis) {
            return null;
        }
        $session_data_json = $redis->get("session:{$session_id}");
        if (!$session_data_json) {
            return null;
        }
        $session_data = json_decode($session_data_json, true);
        if (!$session_data || empty($session_data['email']) || empty($session_data['event_id'])) {
            return null;
        }
        $raw = $redis->get(LEM_Access::playback_key($session_data['email'], $session_data['event_id']));
        if (!$raw) {
            $this->ensure_playback_blob($session_data['email'], $session_data['event_id']);
            $raw = $redis->get(LEM_Access::playback_key($session_data['email'], $session_data['event_id']));
        }
        $blob = $raw ? json_decode($raw, true) : null;
        return is_array($blob) ? $blob : null;
    }

    /**
     * Clear in-memory cache (useful for testing or when data changes)
     */
    public static function clear_memory_cache() {
        self::$memory_cache = array();
    }
    
    /**
     * Get memory cache stats (for debugging)
     */
    public static function get_memory_cache_stats() {
        return array(
            'keys' => count(self::$memory_cache),
            'size' => strlen(serialize(self::$memory_cache))
        );
    }

    
    // Handle confirmation page redirects
    public function handle_confirmation_redirect() {
        // Check if this is a confirmation page request
        if (isset($_SERVER['REQUEST_URI']) && strpos($_SERVER['REQUEST_URI'], '/confirmation') === 0) {
            $this->debug_log('Confirmation redirect handler triggered', array('uri' => $_SERVER['REQUEST_URI']));
            
            // Load the confirmation template directly
            $confirmation_template = LEM_PLUGIN_DIR . 'templates/confirmation-page.php';
            if (file_exists($confirmation_template)) {
                // Set status to 200 to prevent 404
                status_header(200);
                
                // Include the confirmation template
                include $confirmation_template;
                exit;
            }
        }
    }
    
    // Handle events page redirects
    public function handle_events_redirect() {
        if (is_admin()) {
            return;
        }

        // Never override the single event template
        if (is_singular('lem_event')) {
            return;
        }

        $should_render_events = false;

        // Respect rewrite/query detection first
        if (is_post_type_archive('lem_event') || get_query_var('lem_events_page')) {
            $should_render_events = true;
        } else {
            $request_uri = $_SERVER['REQUEST_URI'] ?? '';
            $request_path = $request_uri ? parse_url($request_uri, PHP_URL_PATH) : '';

            if ($request_path !== null && $request_path !== '') {
                $home_path = parse_url(home_url('/'), PHP_URL_PATH) ?: '';

                if (!empty($home_path) && strpos($request_path, $home_path) === 0) {
                    $request_path = substr($request_path, strlen($home_path));
                }

                $trimmed_path = trim($request_path, '/');

                if ($trimmed_path === 'events') {
                    $should_render_events = true;
                }
            }
        }

        if (!$should_render_events) {
            return;
        }

        $this->debug_log('Events redirect handler triggered', array('uri' => $_SERVER['REQUEST_URI'] ?? '')); 

        $events_template = LEM_Template_Manager::resolve_template_file('page-events.php');
        if (file_exists($events_template)) {
            status_header(200);
            include $events_template;
            exit;
        }
    }
    
    /**
     * Issue a short-lived Ably TokenRequest for an authenticated viewer.
     *
     * Validates the lem_session_id cookie + event access before signing.
     * The token is scoped to a single channel so viewers cannot subscribe
     * to other events' chat rooms.
     */
    public function ajax_ably_token() {
        check_ajax_referer('lem_nonce', 'nonce');

        $event_id = intval($_POST['event_id'] ?? 0);
        if (!$event_id) {
            wp_send_json_error('Missing event_id', 400);
            return;
        }

        // Require a valid watch session.
        $session_id = $_COOKIE['lem_session_id'] ?? '';
        if (empty($session_id)) {
            wp_send_json_error('No active session', 403);
            return;
        }

        $access = $this->get_event_access_state($event_id);
        if (empty($access['can_watch'])) {
            wp_send_json_error('Access denied', 403);
            return;
        }

        $settings = get_option('lem_settings', []);
        $ably_key = trim($settings['ably_api_key'] ?? '');
        if (empty($ably_key) || strpos($ably_key, ':') === false) {
            wp_send_json_error('Ably not configured', 503);
            return;
        }

        $colon_pos  = strrpos($ably_key, ':');
        $key_name   = substr($ably_key, 0, $colon_pos);
        $key_secret = substr($ably_key, $colon_pos + 1);

        // Use the session ID as the clientId so each device has a stable identity.
        $client_id   = $access['session_id'] ?? $session_id;
        $channel     = 'lem:chat:' . $event_id;
        $capability  = wp_json_encode([$channel => ['subscribe', 'publish', 'presence', 'history']]);
        $ttl         = 3600 * 1000; // 1 hour in ms
        $timestamp   = (int) round(microtime(true) * 1000);
        $nonce       = bin2hex(random_bytes(8));

        // Ably HMAC-SHA256 signing string (each field on its own line, trailing newline).
        $sign_string = implode("\n", [
            $key_name,
            $ttl,
            $capability,
            $client_id,
            $timestamp,
            $nonce,
            '', // trailing newline required by spec
        ]);

        $mac = base64_encode(hash_hmac('sha256', $sign_string, $key_secret, true));

        wp_send_json_success([
            'keyName'    => $key_name,
            'clientId'   => $client_id,
            'nonce'      => $nonce,
            'timestamp'  => $timestamp,
            'capability' => $capability,
            'ttl'        => $ttl,
            'mac'        => $mac,
        ]);
    }

    // AJAX handler for saving JWT settings
    public function ajax_save_jwt_settings() {
        try {
            $this->debug_log('JWT settings save request started');
            
            // Verify nonce for security
            check_ajax_referer('lem_nonce', 'nonce');
            
            // Check permissions
            if (!current_user_can('manage_options')) {
                $this->debug_log('JWT settings save failed - insufficient permissions');
                wp_send_json_error('Insufficient permissions');
                return;
            }
            
            // Get current settings
            $settings = get_option('lem_settings', array());
            
            // Validate and sanitize input
            $jwt_expiration_hours = intval($_POST['jwt_expiration_hours'] ?? 24);
            $jwt_refresh_duration_minutes = intval($_POST['jwt_refresh_duration_minutes'] ?? 15);
            
            // Validate ranges
            if ($jwt_expiration_hours < 1 || $jwt_expiration_hours > 168) {
                wp_send_json_error('Initial JWT expiration must be between 1 and 168 hours');
                return;
            }
            
            if ($jwt_refresh_duration_minutes < 5 || $jwt_refresh_duration_minutes > 60) {
                wp_send_json_error('JWT refresh duration must be between 5 and 60 minutes');
                return;
            }
            
            // Update settings
            $settings['jwt_expiration_hours'] = $jwt_expiration_hours;
            $settings['jwt_refresh_duration_minutes'] = $jwt_refresh_duration_minutes;
            
            // Save settings
            $result = update_option('lem_settings', $settings);
            
            if ($result) {
                $this->debug_log('JWT settings saved successfully', array(
                    'jwt_expiration_hours' => $jwt_expiration_hours,
                    'jwt_refresh_duration_minutes' => $jwt_refresh_duration_minutes
                ));
                
                wp_send_json_success('JWT settings saved successfully');
            } else {
                $this->debug_log('JWT settings save failed - database error');
                wp_send_json_error('Failed to save settings to database');
            }
            
        } catch (Exception $e) {
            $this->debug_log('JWT settings save exception', array(
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ));
            wp_send_json_error('JWT settings save failed: ' . $e->getMessage());
        }
    }
}

// Initialize the plugin
global $live_event_manager;
$live_event_manager = new LiveEventManager();
?>