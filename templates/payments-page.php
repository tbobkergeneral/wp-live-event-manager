<?php
/**
 * Admin — Payments / ticket purchases (Stripe checkout sessions recorded in lem_jwt_tokens).
 */
if (!defined('ABSPATH')) {
    exit;
}

$export_url = add_query_arg(
    array(
        'page'      => 'live-event-manager-payments',
        'lem_event' => $filter_event,
        'lem_export'=> 'csv',
    ),
    admin_url('admin.php')
);
?>
<div class="wrap">
    <h1><?php esc_html_e('Payments', 'live-event-manager'); ?></h1>
    <p class="description">
        <?php esc_html_e('Paid checkouts recorded when access is issued (Stripe Checkout session ID). Amounts are shown in Stripe — open a session for full details.', 'live-event-manager'); ?>
    </p>

    <form method="get" action="<?php echo esc_url(admin_url('admin.php')); ?>" style="margin: 1rem 0;">
        <input type="hidden" name="page" value="live-event-manager-payments" />
        <label for="lem_event"><?php esc_html_e('Filter by event', 'live-event-manager'); ?></label>
        <select name="lem_event" id="lem_event" onchange="this.form.submit()">
            <option value="0"><?php esc_html_e('All events', 'live-event-manager'); ?></option>
            <?php foreach ($events_for_filter as $ev) : ?>
                <option value="<?php echo esc_attr((string) $ev->ID); ?>" <?php selected($filter_event, (int) $ev->ID); ?>>
                    <?php echo esc_html($ev->post_title); ?>
                </option>
            <?php endforeach; ?>
        </select>
    </form>

    <?php if (empty($rows)) : ?>
        <p><?php esc_html_e('No paid ticket records yet.', 'live-event-manager'); ?></p>
    <?php else : ?>
        <p><strong><?php echo esc_html(sprintf(/* translators: %d count */ _n('%d record', '%d records', count($rows), 'live-event-manager'), count($rows))); ?></strong></p>

        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th scope="col"><?php esc_html_e('Purchased', 'live-event-manager'); ?></th>
                    <th scope="col"><?php esc_html_e('Email', 'live-event-manager'); ?></th>
                    <th scope="col"><?php esc_html_e('Event', 'live-event-manager'); ?></th>
                    <th scope="col"><?php esc_html_e('Stripe session', 'live-event-manager'); ?></th>
                    <th scope="col"><?php esc_html_e('Access expires', 'live-event-manager'); ?></th>
                    <th scope="col"><?php esc_html_e('Status', 'live-event-manager'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $row) : ?>
                    <?php
                    $exp_ts   = strtotime($row->expires_at);
                    $is_exp   = $exp_ts && $exp_ts < time();
                    $revoked  = !empty($row->revoked_at);
                    $status   = $revoked ? __('Revoked', 'live-event-manager') : ($is_exp ? __('Expired', 'live-event-manager') : __('Active', 'live-event-manager'));
                    $dash_url = !empty($row->payment_id) ? $stripe_base . rawurlencode($row->payment_id) : '';
                    ?>
                    <tr>
                        <td><?php echo esc_html($row->created_at); ?></td>
                        <td><?php echo esc_html($row->email); ?></td>
                        <td>
                            <?php
                            $title = $row->event_title ?: ('#' . $row->event_id);
                            $edit    = get_edit_post_link((int) $row->event_id);
                            if ($edit) {
                                echo '<a href="' . esc_url($edit) . '">' . esc_html($title) . '</a>';
                            } else {
                                echo esc_html($title);
                            }
                            ?>
                        </td>
                        <td>
                            <?php if ($dash_url) : ?>
                                <a href="<?php echo esc_url($dash_url); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html($row->payment_id); ?></a>
                            <?php else : ?>
                                —
                            <?php endif; ?>
                        </td>
                        <td><?php echo esc_html($row->expires_at); ?></td>
                        <td><?php echo esc_html($status); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>
