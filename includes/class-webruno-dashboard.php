<?php
if (!defined('ABSPATH')) exit;

/**
 * Local, zero-account CRM view: reads data already on the site (orders,
 * customers, products, CF7/WPForms leads, users) and renders a stats grid,
 * a revenue trend chart, a real customer table, top products, rule-based
 * recommendations, a locked automation preview, and an inline pricing card.
 * Runs entirely before any Webruno connection exists -- this IS the
 * "why would I pay" moment, so the purchase path lives on this page.
 */
class Webruno_Dashboard {

    /** Main entry: render everything. */
    public static function render() {
        $stats     = self::gather_stats();
        $customers = $stats['has_woocommerce'] ? self::gather_customers() : [];
        $products  = $stats['has_woocommerce'] ? self::gather_top_products() : [];
        $recs      = self::build_recommendations($stats);
        ?>
        <div class="webruno-dash">
            <?php self::render_stats($stats); ?>
            <?php if (!empty($stats['weekly_revenue'])) self::render_revenue_chart($stats['weekly_revenue'], $stats['currency']); ?>
            <?php if (!empty($customers)) self::render_customer_table($customers, $stats['currency']); ?>
            <?php if (!empty($products)) self::render_top_products($products, $stats['currency']); ?>
            <?php if (!empty($recs)) self::render_recommendations($recs); ?>
            <?php self::render_pro_preview($stats, $customers); ?>
            <?php self::render_pricing_inline($stats); ?>
        </div>
        <?php // JS for VIP/notes/compose/send lives in assets/admin.js (event delegation), not inline. ?>
        <?php
    }

    /* ---------------- Data gathering (local only, no external calls) ---------------- */

    private static function gather_stats() {
        $stats = [
            'has_woocommerce'   => class_exists('WooCommerce'),
            'has_cf7'           => defined('WPCF7_VERSION'),
            'has_wpforms'       => class_exists('WPForms'),
            'orders_30d'        => 0,
            'revenue_30d'       => 0.0,
            'revenue_prev_30d'  => 0.0,
            'revenue_trend_pct' => 0,
            'avg_order_value'   => 0.0,
            'currency'          => '',
            'total_customers'   => 0,
            'repeat_customers'  => 0,
            'stale_customers'   => 0,
            'revenue_at_risk'   => 0.0,
            'weekly_revenue'    => [],
            'top_customer'      => null,
            'leads_30d'         => 0,
            'new_users_30d'     => 0,
        ];

        if ($stats['has_woocommerce'] && function_exists('wc_get_orders')) {
            $stats = array_merge($stats, self::gather_woocommerce_stats());
        }

        $stats['leads_30d']     = self::count_recent_comments_as_leads_fallback();
        $stats['new_users_30d'] = self::count_new_users(30);

        return $stats;
    }

    private static function gather_woocommerce_stats() {
        $now      = current_time('timestamp');
        $start_30 = gmdate('Y-m-d H:i:s', $now - 30 * DAY_IN_SECONDS);
        $start_60 = gmdate('Y-m-d H:i:s', $now - 60 * DAY_IN_SECONDS);

        $orders_30d = wc_get_orders([
            'status'       => ['wc-completed', 'wc-processing'],
            'date_created' => '>' . strtotime($start_30),
            'limit'        => -1,
            'return'       => 'ids',
        ]);

        $orders_prev_30d = wc_get_orders([
            'status'       => ['wc-completed', 'wc-processing'],
            'date_created' => strtotime($start_60) . '...' . strtotime($start_30),
            'limit'        => -1,
            'return'       => 'ids',
        ]);

        $revenue = 0.0;
        $customer_order_counts = [];
        foreach ($orders_30d as $order_id) {
            $order = wc_get_order($order_id);
            if (!$order || !($order instanceof WC_Order)) continue;
            $revenue += (float) $order->get_total();
            $email = $order->get_billing_email();
            if ($email) {
                $customer_order_counts[$email] = ($customer_order_counts[$email] ?? 0) + 1;
            }
        }

        $revenue_prev = 0.0;
        foreach ($orders_prev_30d as $order_id) {
            $order = wc_get_order($order_id);
            if (!$order || !($order instanceof WC_Order)) continue;
            $revenue_prev += (float) $order->get_total();
        }

        $revenue_trend_pct = 0;
        if ($revenue_prev > 0) {
            $revenue_trend_pct = (int) round((($revenue - $revenue_prev) / $revenue_prev) * 100);
        } elseif ($revenue > 0) {
            $revenue_trend_pct = 100;
        }

        $avg_order_value = count($orders_30d) > 0 ? $revenue / count($orders_30d) : 0.0;
        $repeat_customers = count(array_filter($customer_order_counts, fn($c) => $c >= 2));

        $all_order_ids = wc_get_orders([
            'limit'  => -1,
            'return' => 'ids',
            'status' => ['wc-completed', 'wc-processing'],
        ]);

        $last_order_by_email      = [];
        $lifetime_total_by_email  = [];
        $lifetime_orders_by_email = [];
        $name_by_email            = [];
        $weekly_revenue           = [];

        for ($w = 7; $w >= 0; $w--) {
            $bucket_start = strtotime('-' . ($w * 7) . ' days', strtotime('monday this week', $now));
            $weekly_revenue[$bucket_start] = 0.0;
        }

        foreach ($all_order_ids as $oid) {
            $order = wc_get_order($oid);
            if (!$order || !($order instanceof WC_Order)) continue;
            $email = $order->get_billing_email();
            if (!$email) continue;

            $ts = $order->get_date_created() ? $order->get_date_created()->getTimestamp() : 0;
            $total = (float) $order->get_total();

            if (!isset($last_order_by_email[$email]) || $ts > $last_order_by_email[$email]) {
                $last_order_by_email[$email] = $ts;
            }
            $lifetime_total_by_email[$email]  = ($lifetime_total_by_email[$email] ?? 0) + $total;
            $lifetime_orders_by_email[$email] = ($lifetime_orders_by_email[$email] ?? 0) + 1;

            $name = trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name());
            if ($name) $name_by_email[$email] = $name;

            foreach (array_keys($weekly_revenue) as $bucket_start) {
                $bucket_end = $bucket_start + 7 * DAY_IN_SECONDS;
                if ($ts >= $bucket_start && $ts < $bucket_end) {
                    $weekly_revenue[$bucket_start] += $total;
                    break;
                }
            }
        }

        $stale_cutoff    = $now - 60 * DAY_IN_SECONDS;
        $stale_emails    = array_keys(array_filter($last_order_by_email, fn($ts) => $ts < $stale_cutoff));
        $stale_customers = count($stale_emails);

        $revenue_at_risk = 0.0;
        foreach ($stale_emails as $email) {
            $orders_count = $lifetime_orders_by_email[$email] ?? 1;
            $lifetime     = $lifetime_total_by_email[$email] ?? 0;
            $revenue_at_risk += $orders_count > 0 ? ($lifetime / $orders_count) : 0;
        }

        $top_customer = null;
        if (!empty($lifetime_total_by_email)) {
            $sorted = $lifetime_total_by_email;
            arsort($sorted);
            $top_email = array_key_first($sorted);
            $top_customer = [
                'email'  => $top_email,
                'name'   => $name_by_email[$top_email] ?? $top_email,
                'total'  => $lifetime_total_by_email[$top_email],
                'orders' => $lifetime_orders_by_email[$top_email] ?? 0,
            ];
        }

        return [
            'orders_30d'        => count($orders_30d),
            'revenue_30d'       => $revenue,
            'revenue_prev_30d'  => $revenue_prev,
            'revenue_trend_pct' => $revenue_trend_pct,
            'avg_order_value'   => $avg_order_value,
            'currency'          => get_woocommerce_currency_symbol(),
            'total_customers'   => count($last_order_by_email),
            'repeat_customers'  => $repeat_customers,
            'stale_customers'   => $stale_customers,
            'revenue_at_risk'   => $revenue_at_risk,
            'weekly_revenue'    => $weekly_revenue,
            'top_customer'      => $top_customer,
            '_all_order_ids'            => $all_order_ids,
            '_last_order_by_email'      => $last_order_by_email,
            '_lifetime_total_by_email'  => $lifetime_total_by_email,
            '_lifetime_orders_by_email' => $lifetime_orders_by_email,
            '_name_by_email'            => $name_by_email,
        ];
    }

    /** Avoid recomputing WooCommerce stats twice per page load. */
    private static function gather_woocommerce_stats_cache() {
        static $cached = null;
        if ($cached === null) {
            $cached = (class_exists('WooCommerce') && function_exists('wc_get_orders'))
                ? self::gather_woocommerce_stats()
                : false;
        }
        return $cached;
    }

    /** Builds the customer table rows: top 10 by lifetime value. */
    private static function gather_customers() {
        $stats = self::gather_woocommerce_stats_cache();
        if (!$stats) return [];

        $rows = [];
        foreach ($stats['_lifetime_total_by_email'] as $email => $total) {
            $last_ts = $stats['_last_order_by_email'][$email] ?? 0;
            $rows[] = [
                'email'      => $email,
                'name'       => $stats['_name_by_email'][$email] ?? $email,
                'orders'     => $stats['_lifetime_orders_by_email'][$email] ?? 0,
                'total'      => $total,
                'last_order' => $last_ts,
                'is_stale'   => $last_ts > 0 && $last_ts < (current_time('timestamp') - 60 * DAY_IN_SECONDS),
                'is_repeat'  => ($stats['_lifetime_orders_by_email'][$email] ?? 0) >= 2,
            ];
        }

        usort($rows, fn($a, $b) => $b['total'] <=> $a['total']);
        return array_slice($rows, 0, 10);
    }

    /** Top 3 products by revenue across all completed/processing orders. */
    private static function gather_top_products() {
        $stats = self::gather_woocommerce_stats_cache();
        if (!$stats || empty($stats['_all_order_ids'])) return [];

        $revenue_by_product = [];
        $qty_by_product      = [];

        foreach ($stats['_all_order_ids'] as $oid) {
            $order = wc_get_order($oid);
            if (!$order || !($order instanceof WC_Order)) continue;

            foreach ($order->get_items() as $item) {
                if (!is_a($item, 'WC_Order_Item_Product')) continue;
                $name = $item->get_name();
                if (!$name) continue;
                $revenue_by_product[$name] = ($revenue_by_product[$name] ?? 0) + (float) $item->get_total();
                $qty_by_product[$name]     = ($qty_by_product[$name] ?? 0) + (int) $item->get_quantity();
            }
        }

        arsort($revenue_by_product);
        $top = [];
        $i = 0;
        foreach ($revenue_by_product as $name => $revenue) {
            $top[] = [
                'name'    => $name,
                'revenue' => $revenue,
                'qty'     => $qty_by_product[$name] ?? 0,
            ];
            if (++$i >= 3) break;
        }
        return $top;
    }

    /**
     * Best-effort lead count without requiring the sync classes to have
     * logged anything yet -- counts recent WPForms entries if that table
     * exists; otherwise 0. Cached for 5 minutes since this queries a
     * third-party plugin's table directly (no WP core API for it) and the
     * dashboard doesn't need to the second.
     */
    private static function count_recent_comments_as_leads_fallback() {
        $cache_key = 'webruno_crm_leads_30d';
        $cached = get_transient($cache_key);
        if ($cached !== false) {
            return (int) $cached;
        }

        global $wpdb;
        $count = 0;

        // Table name cannot be parameterized via $wpdb->prepare() placeholders;
        // it is built entirely from $wpdb->prefix (not user input) and escaped
        // before use, so this is safe despite being a direct/interpolated query.
        $wpforms_table = esc_sql($wpdb->prefix . 'wpforms_entries');

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- table name is derived from $wpdb->prefix and escaped above; result is cached via transient just below.
        $table_exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpforms_table));

        if ($table_exists === $wpforms_table) {
            $count += (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- result is cached via transient just below.
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$wpforms_table} WHERE date >= %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- table name is derived from $wpdb->prefix and escaped above.
                    gmdate('Y-m-d H:i:s', current_time('timestamp') - 30 * DAY_IN_SECONDS)
                )
            );
        }

        set_transient($cache_key, $count, 5 * MINUTE_IN_SECONDS);

        return $count;
    }

    private static function count_new_users($days) {
        $days = (int) $days;
        $cache_key = 'webruno_crm_new_users_' . $days . 'd';
        $cached = get_transient($cache_key);
        if ($cached !== false) {
            return (int) $cached;
        }

        global $wpdb;
        $count = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- result is cached via transient just below.
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->users} WHERE user_registered >= %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $wpdb->users is a core wpdb property, not user input.
                gmdate('Y-m-d H:i:s', current_time('timestamp') - $days * DAY_IN_SECONDS)
            )
        );

        set_transient($cache_key, $count, 5 * MINUTE_IN_SECONDS);

        return $count;
    }

    /* ---------------- Rendering: stat cards ---------------- */

    private static function render_stats($s) {
        ?>
        <div class="webruno-dash-grid">
            <?php if ($s['has_woocommerce']): ?>
                <div class="webruno-dash-card">
                    <span class="webruno-dash-num"><?php echo esc_html($s['orders_30d']); ?></span>
                    <span class="webruno-dash-label"><?php esc_html_e('orders, last 30 days', 'webruno-crm'); ?></span>
                </div>
                <div class="webruno-dash-card">
                    <span class="webruno-dash-num">
                        <?php echo esc_html($s['currency'] . number_format_i18n($s['revenue_30d'], 0)); ?>
                        <?php if ($s['revenue_trend_pct'] !== 0): ?>
                            <span class="webruno-dash-trend <?php echo $s['revenue_trend_pct'] > 0 ? 'up' : 'down'; ?>">
                                <?php echo $s['revenue_trend_pct'] > 0 ? '▲' : '▼'; ?> <?php echo esc_html(abs($s['revenue_trend_pct'])); ?>%
                            </span>
                        <?php endif; ?>
                    </span>
                    <span class="webruno-dash-label"><?php esc_html_e('revenue, last 30 days (vs prior 30)', 'webruno-crm'); ?></span>
                </div>
                <div class="webruno-dash-card">
                    <span class="webruno-dash-num"><?php echo esc_html($s['currency'] . number_format_i18n($s['avg_order_value'], 0)); ?></span>
                    <span class="webruno-dash-label"><?php esc_html_e('average order value', 'webruno-crm'); ?></span>
                </div>
                <div class="webruno-dash-card">
                    <span class="webruno-dash-num"><?php echo esc_html($s['total_customers']); ?></span>
                    <span class="webruno-dash-label"><?php esc_html_e('total customers', 'webruno-crm'); ?></span>
                </div>
                <div class="webruno-dash-card">
                    <span class="webruno-dash-num"><?php echo esc_html($s['repeat_customers']); ?></span>
                    <span class="webruno-dash-label"><?php esc_html_e('repeat customers (30d)', 'webruno-crm'); ?></span>
                </div>
                <div class="webruno-dash-card <?php echo $s['stale_customers'] > 0 ? 'webruno-dash-card-warn' : ''; ?>">
                    <span class="webruno-dash-num"><?php echo esc_html($s['currency'] . number_format_i18n($s['revenue_at_risk'], 0)); ?></span>
                    <span class="webruno-dash-label">
                        <?php
                        printf(
                            /* translators: %d: number of customers who haven't ordered in 60+ days */
                            esc_html(_n('revenue at risk — %d customer gone quiet 60d+', 'revenue at risk — %d customers gone quiet 60d+', $s['stale_customers'], 'webruno-crm')),
                            (int) $s['stale_customers']
                        );
                        ?>
                    </span>
                </div>
            <?php endif; ?>

            <?php if ($s['leads_30d'] > 0): ?>
                <div class="webruno-dash-card">
                    <span class="webruno-dash-num"><?php echo esc_html($s['leads_30d']); ?></span>
                    <span class="webruno-dash-label"><?php esc_html_e('form leads, last 30 days', 'webruno-crm'); ?></span>
                </div>
            <?php endif; ?>

            <div class="webruno-dash-card">
                <span class="webruno-dash-num"><?php echo esc_html($s['new_users_30d']); ?></span>
                <span class="webruno-dash-label"><?php esc_html_e('new users, last 30 days', 'webruno-crm'); ?></span>
            </div>
        </div>
        <?php
    }

    /* ---------------- Rendering: revenue chart ---------------- */

    private static function render_revenue_chart($weekly_revenue, $currency) {
        $values = array_values($weekly_revenue);
        $max = max($values) ?: 1;
        $bar_w = 44;
        $gap = 16;
        $chart_h = 120;
        $width = count($values) * ($bar_w + $gap);
        ?>
        <div class="webruno-dash-chart">
            <h3><?php esc_html_e('Revenue, last 8 weeks', 'webruno-crm'); ?></h3>
            <svg viewBox="0 0 <?php echo esc_attr($width); ?> <?php echo esc_attr($chart_h + 30); ?>" class="webruno-dash-chart-svg" preserveAspectRatio="xMinYMid meet">
                <?php $x = 0; foreach ($weekly_revenue as $week_start => $amount):
                    $h = $max > 0 ? max(2, ($amount / $max) * $chart_h) : 2;
                    $y = $chart_h - $h;
                    $label = gmdate('M j', $week_start);
                    ?>
                    <rect x="<?php echo esc_attr($x); ?>" y="<?php echo esc_attr($y); ?>" width="<?php echo esc_attr($bar_w); ?>" height="<?php echo esc_attr($h); ?>" rx="3" class="webruno-dash-chart-bar"></rect>
                    <text x="<?php echo esc_attr($x + $bar_w / 2); ?>" y="<?php echo esc_attr($chart_h + 16); ?>" text-anchor="middle" class="webruno-dash-chart-label"><?php echo esc_html($label); ?></text>
                    <?php if ($amount > 0): ?>
                    <text x="<?php echo esc_attr($x + $bar_w / 2); ?>" y="<?php echo esc_attr(max(10, $y - 4)); ?>" text-anchor="middle" class="webruno-dash-chart-value"><?php echo esc_html($currency . number_format_i18n($amount, 0)); ?></text>
                <?php endif; ?>
                    <?php $x += $bar_w + $gap; endforeach; ?>
            </svg>
        </div>
        <?php
    }

    /* ---------------- Rendering: customer table ---------------- */

    /** Public accessor for the CSV export -- same top-10 list, no truncation of the loop. */
    public static function get_customers_for_export() {
        return self::gather_customers();
    }

    private static function render_customer_table($customers, $currency) {
        $export_url = wp_nonce_url(
            add_query_arg(['action' => 'webruno_crm_export_customers'], admin_url('admin-ajax.php')),
            'webruno_crm_ajax',
            'nonce'
        );
        $store_name = get_bloginfo('name');
        ?>
        <div class="webruno-dash-customers">
            <div class="webruno-dash-customers-header">
                <h3><?php esc_html_e('Your customers', 'webruno-crm'); ?></h3>
                <a href="<?php echo esc_url($export_url); ?>" class="button"><?php esc_html_e('Export CSV', 'webruno-crm'); ?></a>
            </div>
            <table class="webruno-dash-customer-table">
                <thead>
                <tr>
                    <th><?php esc_html_e('Customer', 'webruno-crm'); ?></th>
                    <th><?php esc_html_e('Orders', 'webruno-crm'); ?></th>
                    <th><?php esc_html_e('Lifetime value', 'webruno-crm'); ?></th>
                    <th><?php esc_html_e('Last order', 'webruno-crm'); ?></th>
                    <th><?php esc_html_e('Status', 'webruno-crm'); ?></th>
                    <th><?php esc_html_e('VIP', 'webruno-crm'); ?></th>
                    <th><?php esc_html_e('Note', 'webruno-crm'); ?></th>
                    <th><?php esc_html_e('Message', 'webruno-crm'); ?></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($customers as $c):
                    $meta = class_exists('Webruno_Customer_Meta') ? Webruno_Customer_Meta::get_for_email($c['email']) : ['vip' => false, 'note' => '', 'last_messaged' => 0];
                    $first_name = trim(explode(' ', $c['name'])[0]) ?: __('there', 'webruno-crm');
                    if ($c['is_stale']) {
                        /* translators: %s: store name */
                        $draft_subject = sprintf(__('We miss you at %s', 'webruno-crm'), $store_name);
                    } else {
                        /* translators: %s: store name */
                        $draft_subject = sprintf(__('Thanks for being a great customer at %s', 'webruno-crm'), $store_name);
                    }
                    if ($c['is_stale']) {
                        $draft_body = sprintf(
                            /* translators: 1: customer first name, 2: store name */
                            __("Hi %1\$s,\n\nWe noticed it's been a while since your last order at %2\$s. Here's 10%% off to welcome you back — use code WELCOME10 at checkout.\n\nHope to see you again soon!", 'webruno-crm'),
                            $first_name, $store_name
                        );
                    } else {
                        $draft_body = sprintf(
                            /* translators: 1: customer first name, 2: store name */
                            __("Hi %1\$s,\n\nJust wanted to say thanks for shopping with us at %2\$s — we appreciate you!\n\nAs a thank you, here's 10%% off your next order: code THANKS10.", 'webruno-crm'),
                            $first_name, $store_name
                        );
                    }
                    $row_id = 'wbrn-row-' . md5($c['email']);
                    ?>
                    <tr class="webruno-dash-customer-row" data-email="<?php echo esc_attr($c['email']); ?>">
                        <td>
                            <strong><?php echo esc_html($c['name']); ?></strong><br>
                            <span class="webruno-dash-customer-email"><?php echo esc_html($c['email']); ?></span>
                        </td>
                        <td><?php echo esc_html($c['orders']); ?></td>
                        <td><?php echo esc_html($currency . number_format_i18n($c['total'], 0)); ?></td>
                        <td><?php echo $c['last_order'] ? esc_html(human_time_diff($c['last_order'], current_time('timestamp')) . ' ' . __('ago', 'webruno-crm')) : '—'; ?></td>
                        <td>
                            <?php if ($c['is_stale']): ?>
                                <span class="webruno-dash-tag webruno-dash-tag-stale"><?php esc_html_e('Gone quiet', 'webruno-crm'); ?></span>
                            <?php elseif ($c['is_repeat']): ?>
                                <span class="webruno-dash-tag webruno-dash-tag-repeat"><?php esc_html_e('Repeat', 'webruno-crm'); ?></span>
                            <?php else: ?>
                                <span class="webruno-dash-tag webruno-dash-tag-new"><?php esc_html_e('New', 'webruno-crm'); ?></span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <label class="webruno-dash-vip-toggle">
                                <input type="checkbox" class="webruno-dash-vip-checkbox" <?php checked($meta['vip']); ?> />
                                <span>★</span>
                            </label>
                        </td>
                        <td>
                            <input type="text" class="webruno-dash-note-input" value="<?php echo esc_attr($meta['note']); ?>" placeholder="<?php esc_attr_e('Add a note…', 'webruno-crm'); ?>" />
                        </td>
                        <td>
                            <button type="button" class="button webruno-dash-compose-toggle" aria-expanded="false" data-target="<?php echo esc_attr($row_id); ?>" data-label-open="<?php esc_attr_e('Compose', 'webruno-crm'); ?>" data-label-closed="<?php esc_attr_e('Close', 'webruno-crm'); ?>">
                                <?php esc_html_e('Compose', 'webruno-crm'); ?>
                            </button>
                            <?php if ($meta['last_messaged']): ?>
                                <div class="webruno-dash-last-sent">
                                    <?php
                                    printf(
                                        /* translators: %s: how long ago, e.g. "2 days" */
                                        esc_html__('Sent %s ago', 'webruno-crm'),
                                        esc_html(human_time_diff($meta['last_messaged'], current_time('timestamp')))
                                    );
                                    ?>
                                </div>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr class="webruno-dash-compose-row" id="<?php echo esc_attr($row_id); ?>" hidden>
                        <td colspan="8">
                            <div class="webruno-dash-compose-box">
                                <label class="webruno-dash-modal-label"><?php esc_html_e('Subject', 'webruno-crm'); ?></label>
                                <input type="text" class="widefat webruno-dash-compose-subject" value="<?php echo esc_attr($draft_subject); ?>" />
                                <label class="webruno-dash-modal-label"><?php esc_html_e('Message', 'webruno-crm'); ?></label>
                                <textarea class="widefat webruno-dash-compose-body" rows="6"><?php echo esc_textarea($draft_body); ?></textarea>
                                <div class="webruno-dash-modal-actions">
                                    <span class="webruno-dash-send-status" hidden></span>
                                    <button type="button" class="button button-primary webruno-dash-send-btn" data-email="<?php echo esc_attr($c['email']); ?>">
                                        <?php esc_html_e('Send email now', 'webruno-crm'); ?>
                                    </button>
                                </div>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <p class="webruno-dash-customers-note">
                <?php esc_html_e('Showing your top 10 customers by lifetime value. Tag VIPs, add notes, and send a one-off message free — Pro does all of this automatically, with AI-personalized copy.', 'webruno-crm'); ?>
            </p>
        </div>
        <?php
    }

    /* ---------------- Rendering: top products ---------------- */

    private static function render_top_products($products, $currency) {
        ?>
        <div class="webruno-dash-products">
            <h3><?php esc_html_e('Best-selling products', 'webruno-crm'); ?></h3>
            <ul class="webruno-dash-products-list">
                <?php foreach ($products as $i => $p): ?>
                    <li>
                        <span class="webruno-dash-products-rank"><?php echo esc_html($i + 1); ?></span>
                        <span class="webruno-dash-products-name"><?php echo esc_html($p['name']); ?></span>
                        <span class="webruno-dash-products-meta">
                            <?php
                            printf(
                                /* translators: 1: qty sold, 2: currency+revenue */
                                esc_html__('%1$d sold · %2$s revenue', 'webruno-crm'),
                                (int) $p['qty'],
                                esc_html($currency . number_format_i18n($p['revenue'], 0))
                            );
                            ?>
                        </span>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php
    }

    /* ---------------- Rendering: recommendations ---------------- */

    private static function build_recommendations($s) {
        $recs = [];

        if ($s['has_woocommerce'] && $s['stale_customers'] >= 1) {
            $recs[] = [
                'icon' => '⏰',
                'text' => sprintf(
                    /* translators: %d: number of customers who haven't ordered in 60+ days */
                    _n(
                        '%d customer hasn\'t ordered in 60+ days — Webruno can auto-send a win-back message.',
                        '%d customers haven\'t ordered in 60+ days — Webruno can auto-send win-back messages.',
                        $s['stale_customers'],
                        'webruno-crm'
                    ),
                    $s['stale_customers']
                ),
            ];
        }

        if ($s['has_woocommerce'] && $s['repeat_customers'] >= 1) {
            $recs[] = [
                'icon' => '🔁',
                'text' => sprintf(
                    /* translators: %d: number of repeat customers */
                    _n(
                        '%d customer ordered more than once this month — Webruno can flag repeat buyers automatically.',
                        '%d customers ordered more than once this month — Webruno can flag repeat buyers automatically.',
                        $s['repeat_customers'],
                        'webruno-crm'
                    ),
                    $s['repeat_customers']
                ),
            ];
        }

        if ($s['has_woocommerce'] && $s['top_customer'] && $s['top_customer']['orders'] >= 2) {
            $recs[] = [
                'icon' => '⭐',
                'text' => sprintf(
                    /* translators: 1: customer name, 2: currency+amount */
                    __('%1$s is your top customer with %2$s lifetime — Webruno can auto-tag VIPs and notify you on their next order.', 'webruno-crm'),
                    $s['top_customer']['name'],
                    $s['currency'] . number_format_i18n($s['top_customer']['total'], 0)
                ),
            ];
        }

        if ($s['leads_30d'] >= 1) {
            $recs[] = [
                'icon' => '📥',
                'text' => sprintf(
                    /* translators: %d: number of form leads */
                    _n(
                        '%d form lead came in this month with no follow-up tracking — Webruno reminds you when a lead goes cold.',
                        '%d form leads came in this month with no follow-up tracking — Webruno reminds you when a lead goes cold.',
                        $s['leads_30d'],
                        'webruno-crm'
                    ),
                    $s['leads_30d']
                ),
            ];
        }

        if ($s['has_woocommerce'] && $s['revenue_trend_pct'] < 0) {
            $recs[] = [
                'icon' => '📉',
                'text' => sprintf(
                    /* translators: %d: percentage revenue dropped by */
                    __('Revenue is down %d%% vs the prior 30 days — Webruno surfaces which customers stopped buying and why.', 'webruno-crm'),
                    abs($s['revenue_trend_pct'])
                ),
            ];
        }

        if ($s['has_woocommerce'] && $s['orders_30d'] === 0) {
            $recs[] = [
                'icon' => '📊',
                'text' => __('No orders synced yet — connect Webruno to see revenue trends and abandoned-cart recovery.', 'webruno-crm'),
            ];
        }

        if (empty($recs)) {
            $recs[] = [
                'icon' => '👋',
                'text' => __('Connect Webruno to start tracking leads and customers automatically as they come in.', 'webruno-crm'),
            ];
        }

        return $recs;
    }

    private static function render_recommendations($recs) {
        ?>
        <div class="webruno-dash-recs">
            <h3><?php esc_html_e('Recommended for you', 'webruno-crm'); ?></h3>
            <ul>
                <?php foreach ($recs as $rec): ?>
                    <li>
                        <span class="webruno-dash-rec-icon"><?php echo esc_html($rec['icon']); ?></span>
                        <span><?php echo esc_html($rec['text']); ?></span>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php
    }

    /* ---------------- Rendering: locked Pro automation preview ---------------- */

    private static function render_pro_preview($s, $customers) {
        if (!$s['has_woocommerce']) return;

        if ($s['stale_customers'] >= 1) {
            $badge_sub = sprintf(
                /* translators: 1: currency+amount recoverable, 2: number of quiet customers */
                esc_html__('Estimated %1$s recoverable across %2$d quiet customers, sent automatically — no manual follow-up needed.', 'webruno-crm'),
                esc_html($s['currency'] . number_format_i18n($s['revenue_at_risk'], 0)),
                (int) $s['stale_customers']
            );
            $meta = __('Auto win-back SMS/email — draft', 'webruno-crm');
            $body = sprintf(
                /* translators: %s: store name */
                __('"Hi! We noticed it\'s been a while since your last order at %s. Here\'s 10%% off to welcome you back — just use code WELCOME10 at checkout."', 'webruno-crm'),
                get_bloginfo('name')
            );
        } elseif ($s['top_customer'] && $s['top_customer']['orders'] >= 2) {
            $badge_sub = sprintf(
                /* translators: 1: customer name, 2: number of orders, 3: currency+amount lifetime value */
                esc_html__('%1$s has ordered %2$d times worth %3$s — Pro auto-tags customers like this as VIP and alerts you the moment they order again.', 'webruno-crm'),
                esc_html($s['top_customer']['name']),
                (int) $s['top_customer']['orders'],
                esc_html($s['currency'] . number_format_i18n($s['top_customer']['total'], 0))
            );
            $meta = __('Auto VIP alert — draft', 'webruno-crm');
            $body = sprintf(
                /* translators: 1: customer name, 2: ordinal order number (e.g. "3rd"), 3: currency+amount lifetime value */
                __('"🎉 %1$s just placed their %2$s order — total lifetime value %3$s. Tagged as VIP automatically."', 'webruno-crm'),
                $s['top_customer']['name'],
                self::ordinal($s['top_customer']['orders']),
                $s['currency'] . number_format_i18n($s['top_customer']['total'], 0)
            );
        } else {
            $badge_sub = esc_html__('As soon as you have repeat or lapsed customers, Pro drafts and sends the follow-up automatically.', 'webruno-crm');
            $meta = __('Automation preview', 'webruno-crm');
            $body = __('"Hi! Thanks for your first order — here\'s 10% off your next one, valid for 30 days."', 'webruno-crm');
        }
        ?>
        <div class="webruno-dash-pro">
            <div class="webruno-dash-pro-badge"><?php esc_html_e('CRM Pro', 'webruno-crm'); ?></div>
            <h3><?php esc_html_e('This is what automation would send right now', 'webruno-crm'); ?></h3>
            <p class="webruno-dash-pro-sub"><?php echo wp_kses_post($badge_sub); ?></p>

            <div class="webruno-dash-pro-locked">
                <div class="webruno-dash-pro-message">
                    <div class="webruno-dash-pro-message-meta"><?php echo esc_html($meta); ?></div>
                    <div class="webruno-dash-pro-message-body"><?php echo esc_html($body); ?></div>
                </div>
                <div class="webruno-dash-pro-overlay">
                    <a href="#webruno-dash-pricing" class="button button-primary">
                        <?php esc_html_e('See pricing below', 'webruno-crm'); ?> ↓
                    </a>
                </div>
            </div>
        </div>
        <?php
    }

    /* ---------------- Rendering: inline pricing (no external redirect) ---------------- */

    /**
     * Purchase decision happens right here, on the page that just built the
     * desire -- sending people to an external pricing page loses momentum.
     * Framing uses the site's own numbers (time/cost of doing this by hand)
     * rather than generic feature bullets.
     */
    private static function render_pricing_inline($s) {
        $weekly_minutes = 5 + (max(0, $s['total_customers']) * 3); // rough: 5 min baseline + 3 min/customer to manually review & message
        $weekly_minutes = min($weekly_minutes, 120); // keep the estimate sane
        if ($weekly_minutes >= 60) {
            /* translators: %s: number of hours, e.g. "1.5 hr" */
            $hours_label = sprintf(_x('%s hr', 'hours, e.g. 1.5 hr', 'webruno-crm'), number_format_i18n($weekly_minutes / 60, 1));
        } else {
            /* translators: %d: number of minutes */
            $hours_label = sprintf(_x('%d min', 'minutes', 'webruno-crm'), $weekly_minutes);
        }
        ?>
        <div class="webruno-dash-pricing" id="webruno-dash-pricing">
            <div class="webruno-dash-pricing-cols">
                <div class="webruno-dash-pricing-col">
                    <div class="webruno-dash-pricing-name"><?php esc_html_e('Free', 'webruno-crm'); ?></div>
                    <div class="webruno-dash-pricing-price">£0</div>
                    <ul class="webruno-dash-pricing-list">
                        <li><?php esc_html_e('This dashboard — orders, customers, revenue', 'webruno-crm'); ?></li>
                        <li><?php esc_html_e('Recommendations, updated live', 'webruno-crm'); ?></li>
                        <li class="webruno-dash-pricing-no"><?php esc_html_e('No automated messages', 'webruno-crm'); ?></li>
                        <li class="webruno-dash-pricing-no"><?php esc_html_e('No VIP tagging', 'webruno-crm'); ?></li>
                    </ul>
                    <div class="webruno-dash-pricing-current"><?php esc_html_e('Your current plan', 'webruno-crm'); ?></div>
                </div>
                <div class="webruno-dash-pricing-col webruno-dash-pricing-col-pro">
                    <div class="webruno-dash-pricing-badge"><?php esc_html_e('Recommended', 'webruno-crm'); ?></div>
                    <div class="webruno-dash-pricing-name"><?php esc_html_e('CRM Pro', 'webruno-crm'); ?></div>
                    <div class="webruno-dash-pricing-price">£12<span>/mo</span></div>
                    <ul class="webruno-dash-pricing-list">
                        <li><?php esc_html_e('Everything in Free', 'webruno-crm'); ?></li>
                        <li><strong><?php esc_html_e('Automatic win-back messages', 'webruno-crm'); ?></strong></li>
                        <li><strong><?php esc_html_e('Automatic VIP tagging & alerts', 'webruno-crm'); ?></strong></li>
                        <li><?php esc_html_e('Up to 5 connected sites', 'webruno-crm'); ?></li>
                        <li><?php esc_html_e('Unlimited history', 'webruno-crm'); ?></li>
                    </ul>
                    <p class="webruno-dash-pricing-roi">
                        <?php
                        printf(
                            /* translators: %s: estimated weekly time, e.g. "17 min" */
                            esc_html__('Doing this by hand for your customer list takes about %s a week. Pro does it automatically, every week, for less than £3.', 'webruno-crm'),
                            esc_html($hours_label)
                        );
                        ?>
                    </p>
                    <a href="https://webruno.com/crm-pricing" class="button button-primary button-hero webruno-dash-pricing-cta" target="_blank" rel="noopener noreferrer">
                        <?php esc_html_e('Upgrade to CRM Pro', 'webruno-crm'); ?> ↗
                    </a>
                </div>
            </div>
        </div>
        <?php
    }

    private static function ordinal($n) {
        $n = (int) $n;
        if (in_array($n % 100, [11, 12, 13], true)) return $n . 'th';
        switch ($n % 10) {
            case 1: return $n . 'st';
            case 2: return $n . 'nd';
            case 3: return $n . 'rd';
            default: return $n . 'th';
        }
    }
}