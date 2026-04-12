<?php
/**
 * Starter Template — single-event.php
 *
 * This is the full-screen event watch page. It is loaded for every
 * lem_event singular post instead of the active WordPress theme.
 *
 * ── All variables in scope ────────────────────────────────────────────────
 *   global LiveEventManager $live_event_manager  Main plugin instance
 *   int    $event_id         WP post ID
 *   string $event_date       ISO 8601 start datetime
 *   bool   $is_free          true for free events
 *   string $playback_id      Mux/OME playback ID
 *   string $poster_url       Featured image URL or ''
 *   string $event_summary    Short description or ''
 *   array  $event_access {
 *       bool   can_watch
 *       string jwt_token
 *       string session_id
 *       string error_message
 *       string success_message
 *       string email
 *   }
 *   bool   $can_watch        Shorthand: access + jwt + playback_id all non-empty
 *
 * ── Required JS globals (must be present for chat + player to work) ───────
 *   window.lemWatchContext    = 'event'
 *   window.lemWatchHasAccess  = true|false
 *   window.lemWatchEventId    = int
 *   window.lemInitialJwt      = string  (only when can_watch)
 *   window.lemViewerName      = string
 *   window.lemSessionId       = string
 *   window.lemAjaxUrl         = string
 *   window.lemNonce           = string
 *   window.lemVendor          = string
 *   window.lemTokenRefreshEnabled = bool
 *
 * ── Required DOM IDs (public.js uses these for chat) ─────────────────────
 *   #lem-chat-messages   container where messages are appended
 *   #lem-chat-empty      hidden when first message arrives
 *   #lem-chat-input      text input
 *   #lem-chat-send       send button
 * ─────────────────────────────────────────────────────────────────────────
 */
?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo('charset'); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo esc_html(get_the_title() . ' — ' . get_bloginfo('name')); ?></title>
<?php wp_head(); ?>
<style>
    /* ── Your custom styles go here ── */
    body { margin: 0; font-family: system-ui, sans-serif; background: #111; color: #eee; }
    .starter-nav { padding: 1rem 2rem; background: #1a1a2e; }
    .starter-layout { display: flex; height: calc(100vh - 54px); }
    .starter-player { flex: 1; display: flex; flex-direction: column; }
    mux-player { width: 100%; flex: 1; }
    .starter-chat { width: 300px; display: flex; flex-direction: column; background: #0f0f1a; border-left: 1px solid #333; }
    .starter-chat-msgs { flex: 1; overflow-y: auto; padding: 1rem; }
    .starter-chat-footer { padding: .75rem; display: flex; gap: .5rem; }
    #lem-chat-input { flex: 1; background: #222; color: #eee; border: 1px solid #444; padding: .5rem; border-radius: 4px; }
    #lem-chat-send { background: #6366f1; color: #fff; border: none; padding: .5rem 1rem; border-radius: 4px; cursor: pointer; }
    .starter-paywall { max-width: 480px; margin: 4rem auto; padding: 2rem; background: #1a1a2e; border-radius: 8px; }
</style>
</head>
<body <?php body_class('lem-fullscreen-event'); ?>>
<?php
global $live_event_manager;
$event_id      = get_the_ID();
$event_date    = get_post_meta($event_id, '_lem_event_date', true);
$is_free       = get_post_meta($event_id, '_lem_is_free', true);
$playback_id   = get_post_meta($event_id, '_lem_playback_id', true);
$poster_url    = get_the_post_thumbnail_url($event_id, 'large') ?: '';
$event_summary = get_post_meta($event_id, '_lem_excerpt', true)
    ?: wp_trim_words(strip_tags(get_the_content(null, false, $event_id)), 40);
$event_access  = $live_event_manager ? $live_event_manager->get_event_access_state($event_id) : [];
$can_watch     = !empty($event_access['can_watch']) && !empty($event_access['jwt_token']) && !empty($playback_id);

$viewer_email        = $event_access['email'] ?? '';
$viewer_display_name = ! empty( $event_access['chat_name'] )
    ? $event_access['chat_name']
    : ( $viewer_email ? ucfirst( strtolower( strstr( $viewer_email, '@', true ) ) ) : 'Viewer' );

$ts             = $event_date ? strtotime($event_date) : false;
$formatted_date = $ts ? date('F j, Y', $ts) : '';
$formatted_time = $ts ? date('g:i A T', $ts) : '';
?>

<!-- Nav -->
<nav class="starter-nav">
    <strong><?php echo esc_html(get_bloginfo('name')); ?></strong>
    — <?php echo esc_html(get_the_title()); ?>
</nav>

<?php if ($can_watch): ?>

<!-- Watch layout -->
<div class="starter-layout">
    <div class="starter-player">
        <mux-player
            playback-id="<?php echo esc_attr($playback_id); ?>"
            playback-token="<?php echo esc_attr($event_access['jwt_token']); ?>"
            stream-type="live"
            <?php echo $poster_url ? 'poster="' . esc_url($poster_url) . '"' : ''; ?>
            metadata-video-title="<?php echo esc_attr(get_the_title()); ?>"
            accent-color="#6366f1"
            style="width:100%;display:block;"
        ></mux-player>
        <div style="padding:1rem;">
            <h1 style="margin:0 0 .25rem;"><?php echo esc_html(get_the_title()); ?></h1>
            <?php if ($formatted_date): ?>
                <p style="margin:0;color:#888;"><?php echo esc_html($formatted_date . ' · ' . $formatted_time); ?></p>
            <?php endif; ?>
        </div>
    </div>

    <!-- Chat — IDs must match for public.js -->
    <aside class="starter-chat">
        <div style="padding:.75rem 1rem;border-bottom:1px solid #333;font-size:.85rem;color:#aaa;">Stream Chat</div>
        <div class="starter-chat-msgs" id="lem-chat-messages">
            <div id="lem-chat-empty" style="color:#666;font-size:.85rem;">No messages yet.</div>
        </div>
        <div class="starter-chat-footer">
            <input id="lem-chat-input" type="text" placeholder="Say something…" maxlength="300" autocomplete="off">
            <button id="lem-chat-send">→</button>
        </div>
    </aside>
</div>

<script>
document.body.classList.add('lem-watch-mode');
window.lemWatchContext    = 'event';
window.lemWatchHasAccess  = true;
window.lemWatchEventId    = <?php echo (int) $event_id; ?>;
window.lemInitialJwt      = <?php echo wp_json_encode($event_access['jwt_token']); ?>;
window.lemViewerName      = <?php echo wp_json_encode($viewer_display_name); ?>;
window.lemSessionId       = <?php echo wp_json_encode($event_access['session_id'] ?? ''); ?>;
window.lemAjaxUrl         = <?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>;
window.lemNonce           = <?php echo wp_json_encode(wp_create_nonce('lem_nonce')); ?>;
window.lemVendor               = <?php echo wp_json_encode(get_option('lem_settings', [])['streaming_provider'] ?? 'mux'); ?>;
window.lemTokenRefreshEnabled  = <?php
    $factory  = LEM_Streaming_Provider_Factory::get_instance();
    $provider = $factory->get_active_provider($live_event_manager);
    echo ($provider && $provider->supports_token_refresh()) ? 'true' : 'false';
?>;
</script>

<?php else: ?>

<!-- Paywall / marketing -->
<div class="starter-paywall">
    <h1><?php echo esc_html(get_the_title()); ?></h1>
    <?php if ($formatted_date): ?>
        <p><?php echo esc_html($formatted_date . ' · ' . $formatted_time); ?></p>
    <?php endif; ?>
    <?php if ($live_event_manager): ?>
        <?php echo $live_event_manager->render_event_ticket_block([]); ?>
    <?php endif; ?>
</div>

<?php endif; ?>

<?php wp_footer(); ?>
</body>
</html>
