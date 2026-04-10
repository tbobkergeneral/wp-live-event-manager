<?php
/**
 * Template for displaying individual events publicly
 * Dark-themed layout with video player and chat
 */

get_header();

global $live_event_manager;

$event_id           = get_the_ID();
$event_date         = get_post_meta($event_id, '_lem_event_date', true);
$is_free            = get_post_meta($event_id, '_lem_is_free', true);
$display_price      = get_post_meta($event_id, '_lem_display_price', true);
$playback_id        = get_post_meta($event_id, '_lem_playback_id', true);
$poster_url         = get_the_post_thumbnail_url($event_id, 'large') ?: '';

// Check if user has access
$event_access = array();
$can_watch = false;
if ($live_event_manager) {
    $event_access = $live_event_manager->get_event_access_state($event_id);
    $can_watch = !empty($event_access['can_watch']) && !empty($event_access['jwt_token']) && !empty($playback_id);
}

$formatted_date = '';
$formatted_time = '';
if ($event_date) {
    $timestamp      = strtotime($event_date);
    $formatted_date = date('F j, Y', $timestamp);
    $formatted_time = date('g:i A', $timestamp);
}

$event_summary = get_post_meta($event_id, '_lem_excerpt', true) ?: wp_trim_words(strip_tags(get_the_content(null, false, $event_id)), 32);
?>

<div class="lem-live-events-app">
    <!-- Top Navigation -->
    <nav class="lem-top-nav">
        <div class="lem-nav-left">
            <div class="lem-logo">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M9 18V5l12-2v13"></path>
                    <circle cx="6" cy="18" r="3"></circle>
                    <circle cx="18" cy="16" r="3"></circle>
                </svg>
                <span>LiveEvents</span>
            </div>
        </div>
        <div class="lem-nav-center">
            <a href="<?php echo esc_url(home_url('/events')); ?>" class="lem-nav-link">Events</a>
        </div>
        <div class="lem-nav-right">
            <?php if (is_user_logged_in()): ?>
                <a href="<?php echo esc_url(admin_url()); ?>" class="lem-nav-link">Account</a>
                <a href="<?php echo esc_url(wp_logout_url(home_url())); ?>" class="lem-nav-link">Sign Out</a>
            <?php else: ?>
                <a href="<?php echo esc_url(wp_login_url()); ?>" class="lem-nav-link">Sign In</a>
            <?php endif; ?>
        </div>
    </nav>

    <!-- Event Details Bar -->
    <div class="lem-event-details-bar">
        <div class="lem-event-details-content">
            <h1 class="lem-event-title-inline"><?php echo esc_html(get_the_title()); ?></h1>
            <?php if ($formatted_date): ?>
                <div class="lem-event-meta-inline">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <circle cx="12" cy="12" r="10"></circle>
                        <polyline points="12 6 12 12 16 14"></polyline>
                    </svg>
                    <span><?php echo esc_html($formatted_date . ' · ' . $formatted_time); ?></span>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($can_watch): ?>
        <!-- Watch Page Layout -->
        <div class="lem-watch-layout">
            <!-- Left Sidebar: Direct Messages -->
            <aside class="lem-sidebar lem-sidebar-left">
                <div class="lem-sidebar-header">
                    <h3>Direct Messages</h3>
                </div>
                <div class="lem-sidebar-search">
                    <input type="text" placeholder="Search messages..." class="lem-search-input">
                </div>
                <div class="lem-dm-list">
                    <!-- DM items will be populated via JS/API -->
                    <div class="lem-dm-item">
                        <div class="lem-avatar">
                            <span>TM</span>
                        </div>
                        <div class="lem-dm-content">
                            <div class="lem-dm-name">Tim_482</div>
                            <div class="lem-dm-preview">Payment flow I think is go...</div>
                        </div>
                        <div class="lem-dm-time">22:45</div>
                    </div>
                </div>
                <div class="lem-sidebar-footer">
                    <button class="lem-sidebar-btn">DM Settings</button>
                </div>
            </aside>

            <!-- Main Content: Video Player -->
            <main class="lem-main-content">
                <div class="lem-video-section">
                    <div class="lem-video-label">Video Player</div>
                    <div class="lem-video-container">
                        <?php if (!empty($playback_id)): ?>
                            <mux-player
                                playback-id="<?php echo esc_attr($playback_id); ?>"
                                playback-token="<?php echo esc_attr($event_access['jwt_token']); ?>"
                                <?php echo $poster_url ? 'poster="' . esc_url($poster_url) . '"' : ''; ?>
                                metadata-video-title="<?php echo esc_attr(get_the_title()); ?>"
                                accent-color="#7f5af0"
                                style="width:100%;height:100%;"
                            ></mux-player>
                        <?php else: ?>
                            <div class="lem-video-placeholder">
                                <p>Live stream is not currently available</p>
                                <p class="lem-retry-text">Retrying in <span id="lem-retry-countdown">59</span> seconds...</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </main>

            <!-- Right Sidebar: Event Chat & Online Users -->
            <aside class="lem-sidebar lem-sidebar-right">
                <div class="lem-sidebar-tabs">
                    <button class="lem-tab lem-tab-active" data-tab="chat">Event Chat (30)</button>
                    <button class="lem-tab" data-tab="users">Online Users</button>
                </div>
                
                <div class="lem-tab-content" id="lem-chat-tab">
                    <div class="lem-sidebar-search">
                        <input type="text" placeholder="Search users..." class="lem-search-input">
                    </div>
                    <div class="lem-chat-messages">
                        <!-- Chat messages will be populated via JS/API -->
                        <div class="lem-chat-message">
                            <div class="lem-avatar">TM</div>
                            <div class="lem-chat-content">
                                <div class="lem-chat-name">Tim_482</div>
                                <div class="lem-chat-text">Payment flow I think is go...</div>
                            </div>
                            <div class="lem-chat-time">22:45</div>
                        </div>
                    </div>
                    <div class="lem-chat-input">
                        <input type="text" placeholder="#platform-review" class="lem-chat-field">
                        <button class="lem-chat-send">Send</button>
                    </div>
                </div>

                <div class="lem-tab-content lem-hidden" id="lem-users-tab">
                    <div class="lem-sidebar-search">
                        <input type="text" placeholder="Search users..." class="lem-search-input">
                    </div>
                    <div class="lem-online-users">
                        <!-- Online users list -->
                    </div>
                </div>
            </aside>
        </div>
    <?php else: ?>
        <!-- Marketing Page for Users Without Tickets -->
        <div class="lem-marketing-page">
            <div class="lem-marketing-hero">
                <div class="lem-marketing-content">
                    <h1 class="lem-marketing-title"><?php echo esc_html(get_the_title()); ?></h1>
                    <?php if ($formatted_date): ?>
                        <p class="lem-marketing-date">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <circle cx="12" cy="12" r="10"></circle>
                                <polyline points="12 6 12 12 16 14"></polyline>
                            </svg>
                            <?php echo esc_html($formatted_date . ' · ' . $formatted_time); ?>
                        </p>
                    <?php endif; ?>
                    <?php if (!empty($event_summary)): ?>
                        <p class="lem-marketing-description"><?php echo esc_html($event_summary); ?></p>
                    <?php endif; ?>
                    
                    <?php if ($poster_url): ?>
                        <div class="lem-marketing-image">
                            <img src="<?php echo esc_url($poster_url); ?>" alt="<?php echo esc_attr(get_the_title()); ?>">
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="lem-marketing-section">
                <div class="lem-marketing-card">
                    <h2>Join the Live Stream</h2>
                    <p>Get instant access to watch this exclusive event. Enter your email to receive your magic link.</p>
                    
                    <?php
                    if ($live_event_manager) {
                        echo $live_event_manager->render_event_ticket_block(array());
                    }
                    ?>
                </div>

                <div class="lem-marketing-features">
                    <div class="lem-feature">
                        <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"></path>
                        </svg>
                        <h3>Live Chat</h3>
                        <p>Interact with other viewers and the host in real-time</p>
                    </div>
                    <div class="lem-feature">
                        <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <polygon points="23 7 16 12 23 17 23 7"></polygon>
                            <rect x="1" y="5" width="15" height="14" rx="2" ry="2"></rect>
                        </svg>
                        <h3>HD Streaming</h3>
                        <p>Watch in high definition with adaptive bitrate streaming</p>
                    </div>
                    <div class="lem-feature">
                        <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <rect x="2" y="7" width="20" height="14" rx="2" ry="2"></rect>
                            <path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"></path>
                        </svg>
                        <h3>Multi-Device</h3>
                        <p>Access from any device - desktop, tablet, or mobile</p>
                    </div>
                    <div class="lem-feature">
                        <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path>
                        </svg>
                        <h3>Secure Access</h3>
                        <p>Protected by JWT tokens and device verification</p>
                    </div>
                </div>

                <?php if (!empty(get_the_content())): ?>
                    <div class="lem-marketing-details" id="lem-event-details-section">
                        <h2>About This Event</h2>
                        <div class="lem-content">
                            <?php the_content(); ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>
</div>

<?php get_footer(); ?>
