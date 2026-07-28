<?php
if (!defined('ABSPATH')) exit;

class Webruno_Admin {

    public static function init() {
        add_action('admin_menu', [__CLASS__, 'menu']);
        add_action('admin_init', [__CLASS__, 'register_settings']);
        add_action('admin_enqueue_scripts', [__CLASS__, 'assets']);
    }

    public static function menu() {
        add_menu_page(
            __('Webruno CRM', 'webruno-crm'),
            __('Webruno CRM', 'webruno-crm'),
            'manage_options',
            'webruno-crm',
            [__CLASS__, 'render_main_page'],
            'dashicons-groups',
            56
        );

        add_submenu_page(
            'webruno-crm',
            __('Sync Settings', 'webruno-crm'),
            __('Sync Settings', 'webruno-crm'),
            'manage_options',
            'webruno-crm-settings',
            [__CLASS__, 'render_settings_page']
        );
    }

    public static function assets($hook) {
        if (strpos($hook, 'webruno-crm') === false) return;
        wp_enqueue_style('webruno-crm-admin', WEBRUNO_CRM_URL . 'assets/admin.css', [], WEBRUNO_CRM_VERSION);
    }

    public static function register_settings() {
        register_setting('webruno_crm_sync_settings', 'webruno_crm_sync_orders', [
            'type' => 'boolean', 'sanitize_callback' => 'rest_sanitize_boolean', 'default' => true,
        ]);
        register_setting('webruno_crm_sync_settings', 'webruno_crm_sync_posts', [
            'type' => 'boolean', 'sanitize_callback' => 'rest_sanitize_boolean', 'default' => false,
        ]);
        register_setting('webruno_crm_sync_settings', 'webruno_crm_sync_leads', [
            'type' => 'boolean', 'sanitize_callback' => 'rest_sanitize_boolean', 'default' => true,
        ]);
        register_setting('webruno_crm_sync_settings', 'webruno_crm_sync_users', [
            'type' => 'boolean', 'sanitize_callback' => 'rest_sanitize_boolean', 'default' => false,
        ]);
    }

    public static function render_main_page() {
        if (!current_user_can('manage_options')) return;

        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only display flags, no state change; the state change itself is nonce-verified in Webruno_Auth::handle_callback()/disconnect().
        $connected    = isset($_GET['connected']) ? sanitize_text_field(wp_unslash($_GET['connected'])) : '';
        $disconnected = isset($_GET['disconnected']) ? sanitize_text_field(wp_unslash($_GET['disconnected'])) : '';
        // phpcs:enable

        if ($connected) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Connected to Webruno successfully.', 'webruno-crm') . '</p></div>';
        }
        if ($disconnected) {
            echo '<div class="notice notice-info is-dismissible"><p>' . esc_html__('Disconnected from Webruno.', 'webruno-crm') . '</p></div>';
        }

        echo '<div class="wrap webruno-crm-wrap">';
        echo '<h1>' . esc_html__('Webruno CRM', 'webruno-crm') . '</h1>';

        if (!Webruno_Auth::is_connected()) {
            self::render_connect_screen();
        } else {
            self::render_embedded_panel();
        }

        echo '</div>';
    }

    private static function render_connect_screen() {
        $connect_com_url = wp_nonce_url(
            add_query_arg('domain', 'webruno.com', admin_url('admin-post.php?action=webruno_crm_connect')),
            'webruno_crm_connect'
        );
        $connect_ir_url = wp_nonce_url(
            add_query_arg('domain', 'webruno.ir', admin_url('admin-post.php?action=webruno_crm_connect')),
            'webruno_crm_connect'
        );
        ?>
        <div class="webruno-crm-connect-box" style="max-width:640px;padding:24px;background:#fff;border:1px solid #ccd0d4;border-radius:6px;">
            <p><?php esc_html_e('Connect this site to your Webruno CRM account to manage leads, orders, and customers without leaving wp-admin.', 'webruno-crm'); ?></p>

            <p>
                <a href="<?php echo esc_url($connect_com_url); ?>" class="button button-primary">
                    <?php esc_html_e('Connect via webruno.com', 'webruno-crm'); ?>
                </a>
                &nbsp;
                <a href="<?php echo esc_url($connect_ir_url); ?>" class="button button-primary">
                    <?php esc_html_e('Connect via webruno.ir', 'webruno-crm'); ?>
                </a>
            </p>

            <p style="margin-top:8px;">
                <?php
                printf(
                    /* translators: %s: signup URL */
                    esc_html__('Don\'t have a Webruno account yet? %s', 'webruno-crm'),
                    '<a href="https://webruno.com/login" target="_blank" rel="noopener noreferrer">' . esc_html__('Sign up or log in', 'webruno-crm') . '</a>'
                );
                ?>
            </p>

            <hr style="margin:20px 0;" />

            <details>
                <summary style="cursor:pointer;font-weight:600;"><?php esc_html_e('Use a custom domain or enter your token manually', 'webruno-crm'); ?></summary>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-top:16px;">
                    <input type="hidden" name="action" value="webruno_crm_manual_connect" />
                    <?php wp_nonce_field('webruno_crm_manual_connect'); ?>

                    <table class="form-table">
                        <tr>
                            <th scope="row"><label for="webruno_crm_domain"><?php esc_html_e('Domain', 'webruno-crm'); ?></label></th>
                            <td>
                                <select name="webruno_crm_domain" id="webruno_crm_domain">
                                    <option value="webruno.com"><?php esc_html_e('webruno.com', 'webruno-crm'); ?></option>
                                    <option value="webruno.ir"><?php esc_html_e('webruno.ir', 'webruno-crm'); ?></option>
                                    <option value="custom"><?php esc_html_e('Custom domain', 'webruno-crm'); ?></option>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="webruno_crm_custom_domain"><?php esc_html_e('Custom domain URL', 'webruno-crm'); ?></label></th>
                            <td>
                                <input type="url" name="webruno_crm_custom_domain" id="webruno_crm_custom_domain" class="regular-text" placeholder="https://crm.yourdomain.com" />
                                <p class="description"><?php esc_html_e('Only needed if you selected "Custom domain" above.', 'webruno-crm'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="webruno_crm_manual_token"><?php esc_html_e('API Token', 'webruno-crm'); ?></label></th>
                            <td>
                                <input type="password" name="webruno_crm_manual_token" id="webruno_crm_manual_token" class="regular-text" autocomplete="off" />
                                <p class="description"><?php esc_html_e('Find this under Settings > API in your Webruno account.', 'webruno-crm'); ?></p>
                            </td>
                        </tr>
                    </table>
                    <?php submit_button(__('Save & Connect', 'webruno-crm')); ?>
                </form>
            </details>
        </div>
        <?php
    }

    private static function render_embedded_panel() {
        $disconnect_url = wp_nonce_url(
            admin_url('admin-post.php?action=webruno_crm_disconnect'),
            'webruno_crm_disconnect'
        );
        $site_id  = get_option('webruno_crm_site_id');
        $panel_url = add_query_arg('site_id', rawurlencode($site_id), Webruno_Auth::get_api_base() . '/panel');
        $stats = self::fetch_stats();
        ?>
        <p>
            <a href="<?php echo esc_url($disconnect_url); ?>" class="button" onclick="return confirm('<?php echo esc_js(__('Disconnect this site from Webruno?', 'webruno-crm')); ?>');">
                <?php esc_html_e('Disconnect', 'webruno-crm'); ?>
            </a>
            <a href="<?php echo esc_url($panel_url); ?>" class="button button-primary" target="_blank" rel="noopener noreferrer">
                <?php esc_html_e('Open full CRM on Webruno', 'webruno-crm'); ?> ↗
            </a>
        </p>

        <div class="webruno-crm-stats" style="display:flex;gap:16px;margin:20px 0;flex-wrap:wrap;">
            <?php foreach (self::stat_cards($stats) as $card) : ?>
                <div style="flex:1;min-width:160px;padding:18px;background:#fff;border:1px solid #ccd0d4;border-radius:6px;">
                    <div style="font-size:26px;font-weight:600;"><?php echo esc_html($card['value']); ?></div>
                    <div style="color:#646970;"><?php echo esc_html($card['label']); ?></div>
                </div>
            <?php endforeach; ?>
        </div>

        <div style="padding:20px;background:#fff;border:1px solid #ccd0d4;border-radius:6px;">
            <h2 style="margin-top:0;"><?php esc_html_e('Want more from this data?', 'webruno-crm'); ?></h2>
            <p><?php esc_html_e('Webruno CRM Pro gives you a full pipeline view, call scripts, automation, and reporting — right alongside the leads and orders this plugin already sends over.', 'webruno-crm'); ?></p>
            <a href="https://webruno.com/pricing" class="button button-primary" target="_blank" rel="noopener noreferrer">
                <?php esc_html_e('See Webruno CRM Pro', 'webruno-crm'); ?> ↗
            </a>
        </div>
        <?php
    }

    /** Pull a small summary from Webruno's API to show natively (no iframe). Fails quietly if unreachable. */
    private static function fetch_stats() {
        $token = Webruno_Auth::get_token();
        if (!$token) return [];

        $cached = get_transient('webruno_crm_stats');
        if ($cached !== false) return $cached;

        $response = wp_remote_get(Webruno_Auth::get_api_base() . '/api/site/stats', [
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'X-Site-Id'     => get_option('webruno_crm_site_id'),
            ],
            'timeout' => 8,
        ]);

        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            return [];
        }

        $data = json_decode(wp_remote_retrieve_body($response), true);
        $data = is_array($data) ? $data : [];
        set_transient('webruno_crm_stats', $data, 5 * MINUTE_IN_SECONDS);

        return $data;
    }

    private static function stat_cards($stats) {
        return [
            ['label' => __('Leads', 'webruno-crm'),  'value' => $stats['leads']  ?? '—'],
            ['label' => __('Orders', 'webruno-crm'), 'value' => $stats['orders'] ?? '—'],
            ['label' => __('Posts synced', 'webruno-crm'), 'value' => $stats['posts'] ?? '—'],
        ];
    }

    public static function render_settings_page() {
        if (!current_user_can('manage_options')) return;
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Webruno CRM — Sync Settings', 'webruno-crm'); ?></h1>
            <p><?php esc_html_e('Choose what this site pushes to Webruno automatically. Nothing here is required — turn on only what applies to you.', 'webruno-crm'); ?></p>
            <form method="post" action="options.php">
                <?php settings_fields('webruno_crm_sync_settings'); ?>
                <table class="form-table">
                    <tr>
                        <th scope="row"><?php esc_html_e('Orders', 'webruno-crm'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="webruno_crm_sync_orders" value="1" <?php checked(get_option('webruno_crm_sync_orders', true)); ?> />
                                <?php esc_html_e('Sync new WooCommerce orders (if WooCommerce is active)', 'webruno-crm'); ?>
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Leads', 'webruno-crm'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="webruno_crm_sync_leads" value="1" <?php checked(get_option('webruno_crm_sync_leads', true)); ?> />
                                <?php esc_html_e('Sync Contact Form 7 / WPForms submissions as leads', 'webruno-crm'); ?>
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Posts', 'webruno-crm'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="webruno_crm_sync_posts" value="1" <?php checked(get_option('webruno_crm_sync_posts', false)); ?> />
                                <?php esc_html_e('Sync published posts', 'webruno-crm'); ?>
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Users', 'webruno-crm'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="webruno_crm_sync_users" value="1" <?php checked(get_option('webruno_crm_sync_users', false)); ?> />
                                <?php esc_html_e('Sync new user registrations', 'webruno-crm'); ?>
                            </label>
                        </td>
                    </tr>
                </table>
                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }
}
