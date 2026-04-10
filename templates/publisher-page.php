<?php
/**
 * Publisher Page - Show publish instructions for selected event
 * Supports Mux (RTMP)
 */

if (!defined('ABSPATH')) {
    exit;
}

global $live_event_manager;

// Get all events for selector
$events = get_posts(array(
    'post_type' => 'lem_event',
    'posts_per_page' => -1,
    'orderby' => 'date',
    'order' => 'DESC',
    'post_status' => array('publish', 'draft', 'future')
));

// Get selected event
$selected_event_id = isset($_GET['event_id']) ? intval($_GET['event_id']) : 0;
$selected_event = null;
$live_stream_id = '';
$playback_id = '';

if ($selected_event_id) {
    $selected_event = get_post($selected_event_id);
    if ($selected_event && $selected_event->post_type === 'lem_event') {
        $live_stream_id = get_post_meta($selected_event_id, '_lem_live_stream_id', true);
        $playback_id = get_post_meta($selected_event_id, '_lem_playback_id', true);
    }
}

// Get RTMP info for Mux
$publish_info = null;
if ($live_stream_id && $live_event_manager) {
    $request = new WP_REST_Request('GET', '/lem/v1/rtmp-info');
    $request->set_param('stream_id', $live_stream_id);
    $rtmp_result = $live_event_manager->get_rtmp_info($request);
    if (!is_wp_error($rtmp_result)) {
        $publish_info = array(
            'type' => 'rtmp',
            'stream_key' => $rtmp_result['stream_key'] ?? '',
            'ingest_url' => $rtmp_result['ingest_url'] ?? '',
            'playback_id' => $rtmp_result['playback_id'] ?? $playback_id
        );
    }
}
?>

<div class="wrap">
    <h1>Publisher</h1>
    <p class="description">Select an event to view publish instructions and connect your streaming software.</p>
    
    <!-- Event Selector -->
    <div class="postbox" style="padding: 20px; margin-bottom: 20px;">
        <h2 style="margin-top: 0;">Select Event</h2>
        <form method="get" action="<?php echo admin_url('admin.php'); ?>" style="display: flex; gap: 10px; align-items: end;">
            <input type="hidden" name="page" value="live-event-manager-publisher">
            <div style="flex: 1;">
                <label for="event-select" style="display: block; font-weight: 600; margin-bottom: 5px;">Choose an event:</label>
                <select id="event-select" name="event_id" class="regular-text" style="width: 100%;" required>
                    <option value="">-- Select an event --</option>
                    <?php foreach ($events as $event): ?>
                        <option value="<?php echo esc_attr($event->ID); ?>" <?php selected($selected_event_id, $event->ID); ?>>
                            <?php echo esc_html($event->post_title); ?> 
                            (<?php echo esc_html(get_post_status($event->ID)); ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button type="submit" class="button button-primary">Load Event</button>
        </form>
    </div>
    
    <?php if ($selected_event && $publish_info): ?>
        
        <div class="postbox" style="padding: 20px; margin-bottom: 20px;">
            <h2 style="margin-top: 0;">Event: <?php echo esc_html($selected_event->post_title); ?></h2>
            <p><strong>Stream ID:</strong> <code><?php echo esc_html($live_stream_id); ?></code></p>
        </div>
        
        <?php if ($publish_info && $publish_info['type'] === 'rtmp'): ?>
            <!-- Mux RTMP Instructions -->
            <div class="postbox" style="padding: 20px;">
                <h2 style="margin-top: 0;">RTMP Publishing (OBS, Streamlabs, etc.)</h2>
                <p class="description">Use these credentials in your streaming software to publish to Mux.</p>
                
                <div style="margin-bottom: 20px;">
                    <label style="display: block; font-weight: 600; margin-bottom: 5px;">Stream Key:</label>
                    <div style="display: flex; gap: 10px;">
                        <input type="text" id="rtmp-stream-key" value="<?php echo esc_attr($publish_info['stream_key']); ?>" 
                               readonly class="regular-text" style="font-family: monospace;">
                        <button type="button" class="button button-small lem-copy-btn" data-copy="rtmp-stream-key">Copy</button>
                    </div>
                </div>
                
                <div style="margin-bottom: 20px;">
                    <label style="display: block; font-weight: 600; margin-bottom: 5px;">Ingest URL:</label>
                    <div style="display: flex; gap: 10px;">
                        <input type="text" id="rtmp-ingest-url" value="<?php echo esc_attr($publish_info['ingest_url']); ?>" 
                               readonly class="regular-text" style="font-family: monospace;">
                        <button type="button" class="button button-small lem-copy-btn" data-copy="rtmp-ingest-url">Copy</button>
                    </div>
                </div>
                
                <div style="padding: 15px; background: #f0f0f1; border-radius: 4px; margin-top: 20px;">
                    <h3 style="margin-top: 0; font-size: 14px;">OBS Setup Instructions:</h3>
                    <ol style="margin: 10px 0; padding-left: 20px;">
                        <li>Open OBS Settings → Stream</li>
                        <li>Set Service to "Custom"</li>
                        <li>Paste the <strong>Ingest URL</strong> above into "Server"</li>
                        <li>Paste the <strong>Stream Key</strong> above into "Stream Key"</li>
                        <li>Click "OK" and start streaming!</li>
                    </ol>
                </div>
            </div>
        <?php endif; ?>
        
    <?php elseif ($selected_event && !$publish_info): ?>
        <div class="notice notice-warning">
            <p><strong>No publish information available.</strong> Please ensure the event has a valid Live Stream ID configured.</p>
        </div>
    <?php elseif (!$selected_event_id): ?>
        <div class="notice notice-info">
            <p>Please select an event above to view publish instructions.</p>
        </div>
    <?php endif; ?>
</div>

<script>
jQuery(document).ready(function($) {
    // Copy buttons
    $('.lem-copy-btn').on('click', function() {
        var targetId = $(this).data('copy');
        var input = $('#' + targetId);
        input.select();
        document.execCommand('copy');
        
        var btn = $(this);
        var originalText = btn.text();
        btn.text('Copied!').addClass('button-primary');
        setTimeout(function() {
            btn.text(originalText).removeClass('button-primary');
        }, 2000);
    });
});
</script>

<style>
.lem-copy-btn {
    white-space: nowrap;
}
</style>
