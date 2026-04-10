<?php
/**
 * Template Name: Events (lem_events)
 * Template Post Type: page
 */
get_header();

$paged = get_query_var('paged') ? (int) get_query_var('paged') : 1;

$events_query = new WP_Query([
    'post_type'      => 'lem_event',
    'posts_per_page' => 12,
    'paged'          => $paged,
    'meta_key'       => '_lem_event_date',
    'orderby'        => 'date',
    'order'          => 'DESC',
    'post_status'    => 'publish'
]);
?>

<div class="lem-shell">
    <div class="lem-shell-inset">
        <div class="lem-stack">
            <div class="lem-section lem-text-center">
                <span class="lem-chip">Line-up</span>
                <h1 class="lem-title">Upcoming & on-demand</h1>
                <p class="lem-text">Hand-picked sessions from creators, trainers, and coaches we love. Reserve a spot or jump straight in if you’re already verified.</p>
            </div>

            <?php if ($events_query->have_posts()) : ?>
                <div class="lem-grid">
                    <?php while ($events_query->have_posts()) : $events_query->the_post();
                        $event_id       = get_the_ID();
                        $event_date     = get_post_meta($event_id, '_lem_event_date', true);
                        $event_type     = get_post_meta($event_id, '_lem_is_free', true);
                        $display_price  = get_post_meta($event_id, '_lem_display_price', true);
                        $excerpt        = get_post_meta($event_id, '_lem_excerpt', true);
                        $featured_image = get_the_post_thumbnail_url($event_id, 'medium_large');

                        $formatted_date = '';
                        $formatted_time = '';
                        if ($event_date) {
                            $timestamp      = strtotime($event_date);
                            $formatted_date = date('F j, Y', $timestamp);
                            $formatted_time = date('g:i A', $timestamp);
                        }
                    ?>
                        <article class="lem-card" style="padding: var(--lem-spacing-lg, 1.6rem);">
                            <?php if ($featured_image): ?>
                                <div style="border-radius: var(--lem-radius-md, 16px); overflow:hidden; margin-bottom: var(--lem-spacing-md, 1rem);">
                                    <img src="<?php echo esc_url($featured_image); ?>" alt="<?php echo esc_attr(get_the_title()); ?>" style="width:100%; height: 220px; object-fit: cover;">
                                </div>
                            <?php endif; ?>

                            <div class="lem-chip-row">
                                <span class="lem-chip"><?php echo $event_type === 'paid' ? 'Ticketed' : 'Free access'; ?></span>
                                <?php if ($event_date): ?>
                                    <span class="lem-chip is-outline"><?php echo esc_html($formatted_date); ?></span>
                                <?php endif; ?>
                            </div>

                            <h2 class="lem-heading" style="font-size:1.4rem; margin-top: var(--lem-spacing-sm,0.65rem);">
                                <?php echo esc_html(get_the_title()); ?>
                            </h2>

                            <?php if ($event_date): ?>
                                <p class="lem-text-muted" style="margin-bottom: var(--lem-spacing-sm,0.65rem);">
                                    <?php echo esc_html($formatted_time); ?> · <?php echo esc_html($formatted_date); ?>
                                </p>
                            <?php endif; ?>

                            <p class="lem-text">
                                <?php echo esc_html($excerpt ? $excerpt : wp_trim_words(get_the_content(), 26, '…')); ?>
                            </p>

                            <div class="lem-divider"></div>

                            <div class="lem-grid" style="grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));">
                                <?php if ($event_type === 'paid' && $display_price): ?>
                                    <span class="lem-text"><strong><?php echo esc_html($display_price); ?></strong></span>
                                <?php else: ?>
                                    <span class="lem-text">Free to join</span>
                                <?php endif; ?>
                                <a href="<?php echo esc_url(get_permalink()); ?>" class="lem-button">View event</a>
                            </div>
                        </article>
                    <?php endwhile; ?>
                </div>

                <?php if ($events_query->max_num_pages > 1) : ?>
                    <div class="lem-section lem-text-center">
                        <?php
                        echo paginate_links(array(
                            'base'      => str_replace(999999999, '%#%', esc_url(get_pagenum_link(999999999))),
                            'format'    => '?paged=%#%',
                            'current'   => max(1, get_query_var('paged')),
                            'total'     => $events_query->max_num_pages,
                            'prev_text' => 'Previous',
                            'next_text' => 'Next',
                            'type'      => 'list',
                            'end_size'  => 2,
                            'mid_size'  => 2
                        ));
                        ?>
                    </div>
                <?php endif; ?>
            <?php else : ?>
                <div class="lem-section lem-text-center">
                    <div class="lem-icon" style="background: rgba(255,184,97,0.18); color:#c27a1a;">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <circle cx="12" cy="12" r="10"></circle>
                            <line x1="12" y1="8" x2="12" y2="12"></line>
                            <line x1="12" y1="16" x2="12.01" y2="16"></line>
                        </svg>
                    </div>
                    <h2 class="lem-heading">Nothing on the calendar… yet</h2>
                    <p class="lem-text">New drops land every week. Follow the newsletter and we’ll nudge you first.</p>
                </div>
            <?php endif; ?>

            <?php wp_reset_postdata(); ?>
        </div>
    </div>
</div>

<?php get_footer(); ?>
