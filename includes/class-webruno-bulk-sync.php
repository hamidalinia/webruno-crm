<?php
if (!defined('ABSPATH')) exit;

/**
 * One-time bulk export of existing posts/products, on top of the live
 * event-driven sync in class-webruno-sync.php. This is the original
 * tested paginated-export logic, generalized to use the connected
 * Webruno account (domain + token) instead of separate CRM settings,
 * and with capability checks added to every AJAX handler.
 */
class Webruno_Bulk_Sync {

    public static function init() {
        add_action('admin_menu', [__CLASS__, 'menu']);
        add_action('wp_ajax_webruno_crm_get_posts_count', [__CLASS__, 'ajax_get_posts_count']);
        add_action('wp_ajax_webruno_crm_export_posts', [__CLASS__, 'ajax_export_posts']);
        add_action('wp_ajax_webruno_crm_get_products_count', [__CLASS__, 'ajax_get_products_count']);
        add_action('wp_ajax_webruno_crm_export_products', [__CLASS__, 'ajax_export_products']);
    }

    public static function menu() {
        add_submenu_page(
            'webruno-crm',
            __('Bulk Sync', 'webruno-crm'),
            __('Bulk Sync', 'webruno-crm'),
            'manage_options',
            'webruno-crm-bulk-sync',
            [__CLASS__, 'render_page']
        );
        add_action('admin_enqueue_scripts', [__CLASS__, 'enqueue_assets']);
    }

    public static function enqueue_assets($hook) {
        if (strpos($hook, 'webruno-crm-bulk-sync') === false) return;

        wp_enqueue_script(
            'webruno-crm-bulk-sync',
            WEBRUNO_CRM_URL . 'assets/bulk-sync.js',
            ['jquery'],
            WEBRUNO_CRM_VERSION,
            true
        );

        wp_localize_script('webruno-crm-bulk-sync', 'webrunoCrmBulk', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce('webruno_crm_bulk_nonce'),
            'i18n'    => [
                'starting'     => __('Starting…', 'webruno-crm'),
                'nothing'      => __('Nothing to export.', 'webruno-crm'),
                'done'         => __('Done.', 'webruno-crm'),
                'networkError' => __('Network error, stopped.', 'webruno-crm'),
            ],
        ]);
    }

    private static function guard() {
        check_ajax_referer('webruno_crm_bulk_nonce', 'security');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Insufficient permissions.', 'webruno-crm'), 403);
        }
        if (!Webruno_Auth::is_connected()) {
            wp_send_json_error(__('Connect this site to Webruno first.', 'webruno-crm'));
        }
    }

    public static function ajax_get_posts_count() {
        self::guard();
        $total = count(get_posts(['post_type' => 'post', 'post_status' => 'publish', 'posts_per_page' => -1, 'fields' => 'ids']));
        wp_send_json_success(['total' => $total]);
    }

    public static function ajax_export_posts() {
        self::guard();

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce already verified in self::guard() above via check_ajax_referer().
        $offset     = isset($_POST['offset']) ? absint($_POST['offset']) : 0;
        $batch_size = 50;

        $posts = get_posts(['post_type' => 'post', 'post_status' => 'publish', 'posts_per_page' => $batch_size, 'offset' => $offset]);

        if (empty($posts)) {
            wp_send_json_success(['exported' => 0, 'complete' => true, 'message' => __('No more posts to export.', 'webruno-crm')]);
        }

        $export_data = [];
        foreach ($posts as $post) {
            $thumbnail_url = has_post_thumbnail($post->ID) ? wp_get_attachment_url(get_post_thumbnail_id($post->ID)) : '';
            $author = get_userdata($post->post_author);

            $export_data[] = [
                'external_id'   => $post->ID,
                'title'         => $post->post_title,
                'slug'          => $post->post_name,
                'excerpt'       => $post->post_excerpt,
                'link'          => get_permalink($post->ID),
                'publish_date'  => $post->post_date,
                'thumbnail'     => $thumbnail_url,
                'categories'    => wp_get_post_categories($post->ID, ['fields' => 'names']),
                'tags'          => wp_get_post_tags($post->ID, ['fields' => 'names']),
                'author_name'   => $author ? $author->display_name : '',
            ];
        }

        $result = self::send_batch('post', $export_data);
        if (is_wp_error($result)) {
            wp_send_json_error($result->get_error_message());
        }

        $next_offset = $offset + count($posts);
        wp_send_json_success([
            'exported'    => count($export_data),
            'next_offset' => $next_offset,
            'complete'    => count($posts) < $batch_size,
            /* translators: %d: number of posts exported so far */
            'message'     => sprintf(__('Exported %d posts so far.', 'webruno-crm'), $next_offset),
        ]);
    }

    public static function ajax_get_products_count() {
        self::guard();
        if (!class_exists('WooCommerce')) {
            wp_send_json_success(['total' => 0]);
        }
        $total = count(get_posts(['post_type' => 'product', 'post_status' => 'publish', 'posts_per_page' => -1, 'fields' => 'ids']));
        wp_send_json_success(['total' => $total]);
    }

    public static function ajax_export_products() {
        self::guard();

        if (!class_exists('WooCommerce')) {
            wp_send_json_success(['exported' => 0, 'complete' => true, 'message' => __('WooCommerce is not active.', 'webruno-crm')]);
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce already verified in self::guard() above via check_ajax_referer().
        $offset     = isset($_POST['offset']) ? absint($_POST['offset']) : 0;
        $batch_size = 20;

        $products = get_posts(['post_type' => 'product', 'post_status' => 'publish', 'posts_per_page' => $batch_size, 'offset' => $offset]);

        if (empty($products)) {
            wp_send_json_success(['exported' => 0, 'complete' => true, 'message' => __('No more products to export.', 'webruno-crm')]);
        }

        $export_data = [];
        foreach ($products as $product) {
            $product_obj = wc_get_product($product->ID);
            if (!$product_obj) continue;

            $categories = wp_list_pluck(wp_get_post_terms($product->ID, 'product_cat'), 'name');

            $export_data[] = [
                'external_id' => $product->ID,
                'title'       => $product->post_title,
                'sku'         => $product_obj->get_sku() ?: ('PROD-' . $product->ID),
                'price'       => floatval($product_obj->get_price()),
                'quantity'    => $product_obj->get_stock_quantity(),
                'in_stock'    => $product_obj->is_in_stock(),
                'thumbnail'   => wp_get_attachment_url($product_obj->get_image_id()),
                'category'    => $categories,
            ];
        }

        $result = self::send_batch('product', $export_data);
        if (is_wp_error($result)) {
            wp_send_json_error($result->get_error_message());
        }

        $next_offset = $offset + count($products);
        wp_send_json_success([
            'exported'    => count($export_data),
            'next_offset' => $next_offset,
            'complete'    => count($products) < $batch_size,
            /* translators: %d: number of products exported so far */
            'message'     => sprintf(__('Exported %d products so far.', 'webruno-crm'), $next_offset),
        ]);
    }

    private static function send_batch($type, $items) {
        $token = Webruno_Auth::get_token();
        if (!$token) return new WP_Error('webruno_not_connected', __('Not connected to Webruno.', 'webruno-crm'));

        $response = wp_remote_post(Webruno_Auth::get_api_base() . '/api/import/' . rawurlencode($type), [
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Content-Type'  => 'application/json',
                'X-Site-Id'     => get_option('webruno_crm_site_id'),
            ],
            'body'    => wp_json_encode(['items' => $items]),
            'timeout' => 30,
        ]);

        if (is_wp_error($response)) return $response;

        $code = wp_remote_retrieve_response_code($response);
        if ($code !== 200) {
            return new WP_Error('webruno_api_error', sprintf(
                /* translators: %d: HTTP response code */
                __('Webruno returned an error (HTTP %d).', 'webruno-crm'), $code
            ));
        }

        return true;
    }

    public static function render_page() {
        if (!current_user_can('manage_options')) return;

        if (!Webruno_Auth::is_connected()) {
            echo '<div class="wrap"><h1>' . esc_html__('Bulk Sync', 'webruno-crm') . '</h1>';
            echo '<p>' . esc_html__('Connect this site to Webruno first from the main Webruno CRM page.', 'webruno-crm') . '</p></div>';
            return;
        }
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Bulk Sync', 'webruno-crm'); ?></h1>
            <p><?php esc_html_e('Send your existing content to Webruno once. New content syncs automatically going forward based on your Sync Settings.', 'webruno-crm'); ?></p>

            <div class="webruno-bulk-block" data-kind="posts" style="margin:20px 0;padding:16px;background:#fff;border:1px solid #ccd0d4;border-radius:6px;">
                <h2><?php esc_html_e('Posts', 'webruno-crm'); ?></h2>
                <button class="button button-primary webruno-bulk-start"><?php esc_html_e('Start Posts Export', 'webruno-crm'); ?></button>
                <div class="webruno-bulk-progress" style="display:none;margin-top:12px;">
                    <div style="background:#f0f0f1;border-radius:4px;overflow:hidden;height:20px;">
                        <div class="webruno-bulk-fill" style="height:100%;width:0;background:#2271b1;"></div>
                    </div>
                    <p class="webruno-bulk-status"></p>
                </div>
            </div>

            <?php if (class_exists('WooCommerce')) : ?>
            <div class="webruno-bulk-block" data-kind="products" style="margin:20px 0;padding:16px;background:#fff;border:1px solid #ccd0d4;border-radius:6px;">
                <h2><?php esc_html_e('Products', 'webruno-crm'); ?></h2>
                <button class="button button-primary webruno-bulk-start"><?php esc_html_e('Start Products Export', 'webruno-crm'); ?></button>
                <div class="webruno-bulk-progress" style="display:none;margin-top:12px;">
                    <div style="background:#f0f0f1;border-radius:4px;overflow:hidden;height:20px;">
                        <div class="webruno-bulk-fill" style="height:100%;width:0;background:#2271b1;"></div>
                    </div>
                    <p class="webruno-bulk-status"></p>
                </div>
            </div>
            <?php endif; ?>
        </div>
        <?php
    }
}
