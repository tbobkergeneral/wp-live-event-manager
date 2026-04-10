<?php
/**
 * Gated Video Player Block Template
 * Can be used anywhere in WordPress content with JWT validation
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Get block attributes
$event_id = $attributes['eventId'] ?? null;
$playback_id = $attributes['playbackId'] ?? null;
$title = $attributes['title'] ?? 'Live Event';
$description = $attributes['description'] ?? '';
$aspect_ratio = $attributes['aspectRatio'] ?? '16/9';
$show_controls = $attributes['showControls'] ?? true;
$autoplay = $attributes['autoplay'] ?? false;
$muted = $attributes['muted'] ?? false;
$theme = $attributes['theme'] ?? 'dark';

// Get session ID or JWT from URL, or check cookie
$session_id = $_GET['session'] ?? $_COOKIE['lem_session_id'] ?? '';
$jwt_token = $_GET['token'] ?? '';
$is_valid = false;
$event_data = null;
$error_message = '';

// If we have an event ID, get event data
if ($event_id) {
    $event = get_post($event_id);
    if ($event && $event->post_type === 'lem_event') {
        $event_data = (object) array(
            'event_id' => $event->ID,
            'title' => $event->post_title,
            'playback_id' => $playback_id ?: get_post_meta($event->ID, '_lem_playback_id', true),
            'is_free' => get_post_meta($event->ID, '_lem_is_free', true) === 'free',
            'price_id' => get_post_meta($event->ID, '_lem_price_id', true),
            'status' => get_post_meta($event->ID, '_lem_status', true)
        );
    }
}

// Validate session if provided (preferred method)
if (!empty($session_id)) {
    global $live_event_manager;
    
    try {
        $validation_result = $live_event_manager->validate_session($session_id);
        
        if ($validation_result['valid']) {
            $is_valid = true;
            // Use event data from session validation if available
            if ($validation_result['event']) {
                $event_data = $validation_result['event'];
            }
            
            // Get JWT token for this session
            $jwt_token = $live_event_manager->get_jwt_for_session($session_id);
        } else {
            $error_message = $validation_result['error'] ?? 'Invalid or expired session.';
        }
    } catch (Exception $e) {
        $error_message = 'Error validating session.';
    }
}
// Fallback to JWT validation for backward compatibility
elseif (!empty($jwt_token)) {
    global $live_event_manager;
    
    try {
        $validation_result = $live_event_manager->validate_jwt_direct($jwt_token);
        
        if ($validation_result['valid']) {
            $is_valid = true;
            // Use event data from JWT validation if available
            if ($validation_result['event']) {
                $event_data = $validation_result['event'];
            }
            // JWT token is already available for mux-player
        } else {
            $error_message = $validation_result['error'] ?? 'Invalid or expired access token.';
        }
    } catch (Exception $e) {
        $error_message = 'Error validating access token.';
    }
}

// Determine if we should show the video
$show_video = $is_valid && $event_data && !empty($event_data->playback_id);
?>

<div class="lem-gated-video-block lem-theme-<?php echo esc_attr($theme); ?>" 
     data-event-id="<?php echo esc_attr($event_id); ?>"
     data-playback-id="<?php echo esc_attr($event_data->playback_id ?? ''); ?>">
    
    <?php if ($show_video): ?>
        <!-- Valid Access - Show Video Player -->
        <div class="lem-video-player-wrapper" style="--aspect-ratio: <?php echo esc_attr($aspect_ratio); ?>;">
            <div class="lem-video-header">
                <h3 class="lem-video-title"><?php echo esc_html($event_data->title); ?></h3>
                <div class="lem-access-indicator">
                    <span class="lem-access-badge">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M9 12l2 2 4-4"></path>
                            <circle cx="12" cy="12" r="10"></circle>
                        </svg>
                        Live
                    </span>
                </div>
            </div>
            
            <div class="lem-video-container">
                <mux-player
                    stream-type="live"
                    playback-id="<?php echo esc_attr($event_data->playback_id); ?>"
                    <?php echo !empty($jwt_token) ? 'playback-token="' . esc_attr($jwt_token) . '"' : ''; ?>
                    metadata-video-title="<?php echo esc_attr($event_data->title); ?>"
                    metadata-viewer-user-id="<?php echo esc_attr($event_data->event_id); ?>"
                    primary-color="#667eea"
                    secondary-color="#764ba2"
                    <?php echo $autoplay ? 'autoplay' : ''; ?>
                    <?php echo $muted ? 'muted' : ''; ?>
                    style="--controls: <?php echo $show_controls ? 'visible' : 'none'; ?>; --media-object-fit: contain;">
                </mux-player>
                
                <?php if ($show_controls): ?>
                <div class="lem-player-overlay">
                    <div class="lem-player-controls">
                        <button class="lem-fullscreen-btn" onclick="lemToggleFullscreen(this)">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M8 3H5a2 2 0 0 0-2 2v3m18 0V5a2 2 0 0 0-2-2h-3m0 18h3a2 2 0 0 0 2-2v-3M3 16v3a2 2 0 0 0 2 2h3"></path>
                            </svg>
                        </button>
                    </div>
                </div>
                <?php endif; ?>
            </div>
            
            <?php if (!empty($description)): ?>
            <div class="lem-video-description">
                <p><?php echo esc_html($description); ?></p>
            </div>
            <?php endif; ?>
            
            <div class="lem-device-actions">
                <h3>Device Management</h3>
                <p>Need to watch on a different device?</p>
                <a href="<?php echo home_url('/device-swap?event_id=' . $event_data->event_id); ?>" class="lem-switch-device-btn">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <rect x="2" y="3" width="20" height="14" rx="2" ry="2"></rect>
                        <line x1="8" y1="21" x2="16" y2="21"></line>
                        <line x1="12" y1="17" x2="12" y2="21"></line>
                    </svg>
                    Switch to New Device
                </a>
            </div>
        </div>
        
    <?php else: ?>
        <!-- No Access - Show Purchase Option -->
        <div class="lem-access-gate">
            <div class="lem-gate-header">
                <div class="lem-gate-icon">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect>
                        <circle cx="12" cy="16" r="1"></circle>
                        <path d="M7 11V7a5 5 0 0 1 10 0v4"></path>
                    </svg>
                </div>
                <h3 class="lem-gate-title">Access Required</h3>
                <p class="lem-gate-subtitle">Purchase a ticket to watch this event</p>
            </div>
            
            <?php if (!empty($error_message)): ?>
                <div class="lem-gate-error">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <circle cx="12" cy="12" r="10"></circle>
                        <line x1="15" y1="9" x2="9" y2="15"></line>
                        <line x1="9" y1="9" x2="15" y2="15"></line>
                    </svg>
                    <?php echo esc_html($error_message); ?>
                </div>
            <?php endif; ?>
            
            <?php if ($event_data): ?>
                <!-- Show ticket purchase for this event -->
                <div class="lem-gate-purchase">
                    <div class="lem-event-info">
                        <h4><?php echo esc_html($event_data->title); ?></h4>
                        <span class="lem-event-type <?php echo $event_data->is_free ? 'lem-free' : 'lem-paid'; ?>">
                            <?php echo $event_data->is_free ? 'Free Event' : 'Paid Event'; ?>
                        </span>
                    </div>
                    
                    <?php
                    // Render the ticket block for this event
                    global $post;
                    $original_post = $post;
                    $post = get_post($event_data->event_id);
                    setup_postdata($post);
                    
                    $ticket_attributes = array(
                        'buttonText' => $event_data->is_free ? 'Get Free Access' : 'Buy Ticket',
                        'emailPlaceholder' => 'Enter your email to get access',
                        'showPrice' => true,
                        'theme' => $theme,
                        'size' => 'medium',
                        'showEventDetails' => false
                    );
                    
                    echo $live_event_manager->render_event_ticket_block($ticket_attributes);
                    
                    wp_reset_postdata();
                    $post = $original_post;
                    ?>
                </div>
            <?php else: ?>
                <!-- Show general purchase option -->
                <div class="lem-gate-general">
                    <p>This video requires a valid ticket to watch.</p>
                    <a href="<?php echo home_url(); ?>" class="lem-gate-button">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"></path>
                            <polyline points="9,22 9,12 15,12 15,22"></polyline>
                        </svg>
                        View Events
                    </a>
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>

<style>
.lem-gated-video-block {
    margin: 2rem 0;
    border-radius: 12px;
    overflow: hidden;
    box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
}

/* Theme Variations */
.lem-gated-video-block.lem-theme-dark {
    background: #1a1a1a;
    color: #ffffff;
}

.lem-gated-video-block.lem-theme-light {
    background: #ffffff;
    color: #1a1a1a;
    border: 1px solid #e5e7eb;
}

.lem-gated-video-block.lem-theme-minimal {
    background: transparent;
    color: inherit;
    box-shadow: none;
}

/* Video Player Wrapper */
.lem-video-player-wrapper {
    width: 100%;
}

.lem-video-player-wrapper::before {
    content: '';
    display: block;
    padding-top: calc(100% / (var(--aspect-ratio, 16/9)));
}

.lem-video-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 1rem 1.5rem;
    border-bottom: 1px solid;
}

.lem-theme-dark .lem-video-header {
    border-color: #333;
}

.lem-theme-light .lem-video-header {
    border-color: #e5e7eb;
}

.lem-video-title {
    margin: 0;
    font-size: 1.25rem;
    font-weight: 600;
}

.lem-access-badge {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 4px 12px;
    background: rgba(34, 197, 94, 0.1);
    color: #22c55e;
    border: 1px solid rgba(34, 197, 94, 0.2);
    border-radius: 16px;
    font-size: 0.875rem;
    font-weight: 500;
}

.lem-access-badge svg {
    width: 14px;
    height: 14px;
}

/* Video Container */
.lem-video-container {
    position: relative;
    width: 100%;
    background: #000;
}

.lem-video-container mux-player {
    width: 100%;
    height: 100%;
    --controls: none;
    --media-object-fit: contain;
}

.lem-player-overlay {
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    pointer-events: none;
    z-index: 10;
}

.lem-player-controls {
    position: absolute;
    bottom: 1rem;
    right: 1rem;
    pointer-events: auto;
}

.lem-fullscreen-btn {
    background: rgba(0, 0, 0, 0.7);
    border: none;
    color: white;
    padding: 8px;
    border-radius: 6px;
    cursor: pointer;
    transition: background 0.2s;
}

.lem-fullscreen-btn:hover {
    background: rgba(0, 0, 0, 0.9);
}

.lem-fullscreen-btn svg {
    width: 16px;
    height: 16px;
}

.lem-video-description {
    padding: 1rem 1.5rem;
    border-top: 1px solid;
}

.lem-theme-dark .lem-video-description {
    border-color: #333;
}

.lem-theme-light .lem-video-description {
    border-color: #e5e7eb;
}

.lem-video-description p {
    margin: 0;
    color: inherit;
    opacity: 0.8;
}

/* Access Gate */
.lem-access-gate {
    padding: 2rem 1.5rem;
    text-align: center;
}

.lem-gate-header {
    margin-bottom: 1.5rem;
}

.lem-gate-icon {
    margin-bottom: 1rem;
}

.lem-gate-icon svg {
    width: 48px;
    height: 48px;
    color: #667eea;
}

.lem-gate-title {
    margin: 0 0 0.5rem 0;
    font-size: 1.5rem;
    font-weight: 600;
}

.lem-gate-subtitle {
    margin: 0;
    opacity: 0.7;
    font-size: 1rem;
}

.lem-gate-error {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    background: rgba(239, 68, 68, 0.1);
    border: 1px solid rgba(239, 68, 68, 0.2);
    color: #ef4444;
    padding: 12px 16px;
    border-radius: 8px;
    margin-bottom: 1.5rem;
    font-size: 0.875rem;
}

.lem-gate-error svg {
    width: 16px;
    height: 16px;
    flex-shrink: 0;
}

/* Gate Purchase */
.lem-gate-purchase {
    max-width: 400px;
    margin: 0 auto;
}

.lem-event-info {
    margin-bottom: 1.5rem;
}

.lem-event-info h4 {
    margin: 0 0 0.5rem 0;
    font-size: 1.125rem;
    font-weight: 600;
}

.lem-event-type {
    display: inline-block;
    padding: 4px 8px;
    border-radius: 4px;
    font-size: 0.75rem;
    font-weight: 500;
}

.lem-event-type.lem-free {
    background: rgba(34, 197, 94, 0.1);
    color: #22c55e;
    border: 1px solid rgba(34, 197, 94, 0.2);
}

.lem-event-type.lem-paid {
    background: rgba(59, 130, 246, 0.1);
    color: #3b82f6;
    border: 1px solid rgba(59, 130, 246, 0.2);
}

/* Gate General */
.lem-gate-general {
    margin-top: 1.5rem;
}

.lem-gate-general p {
    margin: 0 0 1rem 0;
    opacity: 0.7;
}

.lem-gate-button {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    background: #667eea;
    color: white;
    text-decoration: none;
    padding: 10px 20px;
    border-radius: 6px;
    font-weight: 500;
    font-size: 0.875rem;
    transition: background 0.2s;
}

.lem-gate-button:hover {
    background: #5a67d8;
    color: white;
}

.lem-gate-button svg {
    width: 16px;
    height: 16px;
}

/* Responsive Design */
@media (max-width: 768px) {
    .lem-video-header {
        flex-direction: column;
        gap: 0.5rem;
        text-align: center;
    }
    
    .lem-video-title {
        font-size: 1.125rem;
    }
    
    .lem-access-gate {
        padding: 1.5rem 1rem;
    }
    
    .lem-gate-title {
        font-size: 1.25rem;
    }
}

/* Mux Player Customization */
mux-player {
    --controls: none;
    --media-object-fit: contain;
    --primary-color: #667eea;
    --secondary-color: #764ba2;
}
</style>

<script>
// Fullscreen toggle function for this specific block
function lemToggleFullscreen(button) {
    const block = button.closest('.lem-gated-video-block');
    const player = block.querySelector('mux-player');
    
    if (player) {
        if (document.fullscreenElement) {
            document.exitFullscreen();
        } else {
            player.requestFullscreen();
        }
    }
}

// Auto-hide controls for this block
document.addEventListener('DOMContentLoaded', function() {
    const blocks = document.querySelectorAll('.lem-gated-video-block');
    
    blocks.forEach(block => {
        const playerContainer = block.querySelector('.lem-video-container');
        const playerControls = block.querySelector('.lem-player-controls');
        
        if (playerContainer && playerControls) {
            let controlsTimeout;
            
            playerContainer.addEventListener('mousemove', () => {
                playerControls.style.opacity = '1';
                clearTimeout(controlsTimeout);
                
                controlsTimeout = setTimeout(() => {
                    playerControls.style.opacity = '0';
                }, 3000);
            });
            
            playerContainer.addEventListener('mouseleave', () => {
                playerControls.style.opacity = '0';
            });
            
            // Initialize controls visibility
            playerControls.style.opacity = '0';
            playerControls.style.transition = 'opacity 0.3s ease';
        }
    });
});
</script> 