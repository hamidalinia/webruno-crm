<?php
if (!defined('ABSPATH')) exit;

class Webruno_Admin {

    public static function init() {
        add_action('admin_menu', [__CLASS__, 'menu']);
        add_action('admin_init', [__CLASS__, 'register_settings']);
        add_action('admin_enqueue_scripts', [__CLASS__, 'assets']);
        add_action('admin_notices', [__CLASS__, 'maybe_render_upgrade_notice']);
        add_action('admin_init', [__CLASS__, 'handle_dismiss_upgrade_notice']);
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

        wp_enqueue_script(
            'webruno-crm-admin',
            WEBRUNO_CRM_URL . 'assets/admin.js',
            [],
            WEBRUNO_CRM_VERSION,
            true
        );

        wp_localize_script('webruno-crm-admin', 'webrunoCrmAjax', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'pollIntervalMs' => 3000,
            'pollTimeoutMs'  => 600000,
            'nonce'   => wp_create_nonce('webruno_crm_ajax'),
            'strings' => [
                'connecting'        => __('Connecting…', 'webruno-crm'),
                'regionChecking'    => __('Checking which Webruno server fits this site…', 'webruno-crm'),
                'waiting'           => __('Waiting for your approval on Webruno… Sign in there and approve this site.', 'webruno-crm'),
                'openLink'          => __('Open the Webruno approval page', 'webruno-crm'),
                'cancel'            => __('Cancel', 'webruno-crm'),
                'timedOut'          => __('Timed out waiting for approval. Please try again.', 'webruno-crm'),
                'declined'          => __('The connection was declined on Webruno.', 'webruno-crm'),
                'cancelled'         => __('Connection cancelled.', 'webruno-crm'),
                'disconnecting'     => __('Disconnecting…', 'webruno-crm'),
                'genericError'      => __('Something went wrong. Please try again.', 'webruno-crm'),
                'networkError'      => __('Could not reach the server. Check your connection and try again.', 'webruno-crm'),
                'confirmDisconnect' => __('Disconnect this site from Webruno?', 'webruno-crm'),
            ],
        ]);
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

        echo '<div class="wrap webruno-crm-wrap">';
        echo '<h1>' . esc_html__('Webruno CRM', 'webruno-crm') . '</h1>';

        // Live status region: JS fills this in on connect/disconnect/error.
        // Starts hidden -- there is nothing to report until an action runs.
        echo '<div id="webruno-crm-status" class="webruno-crm-status" hidden></div>';
//        echo '<div class="webruno-crm-flex-box">';

        if (!Webruno_Auth::is_connected()) {
            self::render_connect_screen();
        } else {
            self::render_embedded_panel();
        }

//        echo '</div>';
        echo '</div>';
    }

    private static function render_connect_screen() {
        // Show what's already on the site BEFORE asking for a connection.
        Webruno_Dashboard::render();
        ?>
        <div class="webruno-crm-connect-box" style="max-width:640px;padding:24px;background:#fff;border:1px solid #ccd0d4;border-radius:6px;">
            <p><strong><?php esc_html_e('Connect to get this on autopilot:', 'webruno-crm'); ?></strong>
                <?php esc_html_e('automatic follow-ups, repeat-buyer tags, and cold-lead reminders — without leaving wp-admin.', 'webruno-crm'); ?></p>

            <?php $region_state = Webruno_Region::get_state(); ?>
            <p>
                <button type="button" class="button button-primary button-hero webruno-crm-connect-btn" id="webruno-crm-connect-main" data-domain="<?php echo esc_attr($region_state['domain']); ?>">
                    <?php esc_html_e('Connect & Create Free Webruno Account', 'webruno-crm'); ?>
                </button>
            </p>
            <p class="description"><?php esc_html_e('Free to connect. No credit card required. A Webruno page opens in a new tab where you sign in (or sign up) and approve this site.', 'webruno-crm'); ?></p>

            <div id="webruno-crm-region" style="margin-top:12px;padding:10px 12px;background:#f6f7f7;border:1px solid #dcdcde;border-radius:4px;">
                <p style="margin:0 0 4px;">
                    <?php esc_html_e('Webruno server for this site:', 'webruno-crm'); ?>
                    <strong id="webruno-crm-region-host"><?php echo esc_html(wp_parse_url($region_state['base'], PHP_URL_HOST)); ?></strong>
                </p>
                <p class="description" id="webruno-crm-region-reasons" style="margin:0 0 6px;"><?php echo esc_html(implode(' ', Webruno_Region::describe_reasons($region_state['reasons']))); ?></p>
                <p class="description" id="webruno-crm-region-message" style="margin:0 0 6px;color:#b32d2e;"><?php echo esc_html($region_state['message']); ?></p>
                <p style="margin:0;">
                    <?php esc_html_e('Change manually:', 'webruno-crm'); ?>
                    <button type="button" class="button-link webruno-crm-region-set" data-region="ir"><?php esc_html_e('Iran (webruno.ir)', 'webruno-crm'); ?></button> |
                    <button type="button" class="button-link webruno-crm-region-set" data-region="intl"><?php esc_html_e('International (webruno.com)', 'webruno-crm'); ?></button> |
                    <button type="button" class="button-link webruno-crm-region-set" data-region="auto"><?php esc_html_e('Detect automatically', 'webruno-crm'); ?></button>
                </p>
            </div>

            <hr style="margin:20px 0;" />

            <details>
                <summary style="cursor:pointer;font-weight:600;"><?php esc_html_e('Use a custom domain or enter your token manually', 'webruno-crm'); ?></summary>
                <form id="webruno-crm-manual-connect-form" style="margin-top:16px;">
                    <table class="form-table">
                        <tr>
                            <th scope="row"><label for="webruno_crm_domain"><?php esc_html_e('Domain', 'webruno-crm'); ?></label></th>
                            <td>
                                <select name="webruno_crm_domain" id="webruno_crm_domain">
                                    <option value="webruno.com"><?php esc_html_e('webruno.com', 'webruno-crm'); ?></option>
                                    <option value="webruno.ir"><?php esc_html_e('webruno.ir', 'webruno-crm'); ?></option>
                                    <option value="custom"><?php esc_html_e('Custom domain (https only)', 'webruno-crm'); ?></option>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="webruno_crm_custom_domain"><?php esc_html_e('Custom domain URL', 'webruno-crm'); ?></label></th>
                            <td>
                                <input type="url" id="webruno_crm_custom_domain" class="regular-text" placeholder="https://crm.yourdomain.com" />
                                <p class="description"><?php esc_html_e('Only needed if you selected "Custom domain" above.', 'webruno-crm'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="webruno_crm_manual_token"><?php esc_html_e('API Token', 'webruno-crm'); ?></label></th>
                            <td>
                                <input type="password" id="webruno_crm_manual_token" class="regular-text" autocomplete="off" />
                                <p class="description"><?php esc_html_e('Find this under Settings > API in your Webruno account.', 'webruno-crm'); ?></p>
                            </td>
                        </tr>
                    </table>
                    <p class="submit">
                        <button type="submit" class="button button-primary"><?php esc_html_e('Save & Connect', 'webruno-crm'); ?></button>
                    </p>
                </form>
            </details>
        </div>
        <?php
    }

    private static function render_embedded_panel() {
        ?>
        <p>
            <button type="button" class="button webruno-crm-disconnect-btn">
                <?php esc_html_e('Disconnect', 'webruno-crm'); ?>
            </button>
            <a href="<?php echo esc_url(self::panel_url()); ?>" class="button button-primary" target="_blank" rel="noopener noreferrer">
                <?php esc_html_e('Open full CRM on Webruno', 'webruno-crm'); ?> ↗
            </a>
        </p>

        <?php
        // Full local dashboard: real numbers computed from this site's own
        // data (orders, revenue, repeat/stale customers, leads, new users)
        // plus the rule-based recommendations. Same renderer used pre-connect,
        // so numbers never regress to "—" placeholders once connected.
        Webruno_Dashboard::render();
        ?>

        <div style="padding:20px;background:#fff;border:1px solid #ccd0d4;border-radius:6px;margin-top:20px;">
            <h2 style="margin-top:0;"><?php esc_html_e('Want more from this data?', 'webruno-crm'); ?></h2>
            <p><?php esc_html_e('Webruno CRM Pro gives you a full pipeline view, call scripts, automation, and reporting — right alongside the leads and orders this plugin already sends over.', 'webruno-crm'); ?></p>
            <a href="https://webruno.com/crm-pricing" class="button button-primary" target="_blank" rel="noopener noreferrer">
                <?php esc_html_e('See Webruno CRM Pro', 'webruno-crm'); ?> ↗
            </a>
        </div>
        <?php
    }

    private static function panel_url() {
        // Never put the API token in a URL (it would end up in browser history, server logs and referrers).
        // The admin signs in to Webruno with their own account in the browser.
        return Webruno_Auth::get_api_base();
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
            <?php if (class_exists('Webruno_Content_API')) { Webruno_Content_API::render_settings_section(); } ?>
        </div>
        <?php
    }

    /**
     * Optional, dismissible admin notice pointing at Pro -- shown only on
     * Webruno CRM's own screens, only when there's a real, specific reason
     * (at least one stale or repeat customer), and only after the user has
     * connected. Informational, not a blocker: never appears on other
     * wp-admin screens, never disables plugin functionality, and dismissing
     * it persists per-user.
     */
    public static function maybe_render_upgrade_notice() {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!$screen || strpos($screen->id, 'webruno-crm') === false) return;
        if (!current_user_can('manage_options')) return;
        if (!class_exists('Webruno_Auth') || !Webruno_Auth::is_connected()) return;
        if (get_user_meta(get_current_user_id(), 'webruno_crm_dismissed_upgrade_notice', true)) return;
        if (!class_exists('Webruno_Dashboard') || !class_exists('WooCommerce')) return;
        if (!method_exists('Webruno_Dashboard', 'get_upgrade_signal')) return;

        $signal = Webruno_Dashboard::get_upgrade_signal();
        if (!$signal) return;

        $dismiss_url = wp_nonce_url(
            add_query_arg('webruno_crm_dismiss_upgrade_notice', '1'),
            'webruno_crm_dismiss_upgrade_notice'
        );
        ?>
        <div class="notice notice-info is-dismissible webruno-crm-upgrade-notice">
            <p>
                <strong><?php esc_html_e('Webruno CRM:', 'webruno-crm'); ?></strong>
                <?php echo esc_html($signal); ?>
                <a href="https://webruno.com/crm-pricing" target="_blank" rel="noopener noreferrer"><?php esc_html_e('See CRM Pro', 'webruno-crm'); ?></a>
            </p>
        </div>
        <?php
    }

    /** Handles the dismiss-upgrade-notice link so it stays dismissed for this admin user. */
    public static function handle_dismiss_upgrade_notice() {
        if (!isset($_GET['webruno_crm_dismiss_upgrade_notice'])) return;
        if (!current_user_can('manage_options')) return;
        check_admin_referer('webruno_crm_dismiss_upgrade_notice');
        update_user_meta(get_current_user_id(), 'webruno_crm_dismissed_upgrade_notice', 1);
    }
}