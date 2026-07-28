<?php
if (!defined('ABSPATH')) exit;

/**
 * Handles the connect/disconnect handshake between this site and Webruno.
 *
 * Flow:
 * 1. Admin clicks "Connect to Webruno" -> we build a signed redirect URL to webruno.com
 * 2. User approves on webruno.com -> webruno redirects back to admin-post.php?action=webruno_crm_callback&token=...
 * 3. We store the token (encrypted-at-rest via WP options + a site-specific secret) and mark as connected
 */
class Webruno_Auth {

    public static function init() {
        add_action('admin_post_webruno_crm_connect', [__CLASS__, 'start_connect']);
        add_action('admin_post_webruno_crm_callback', [__CLASS__, 'handle_callback']);
        add_action('admin_post_webruno_crm_disconnect', [__CLASS__, 'disconnect']);
        add_action('admin_post_webruno_crm_manual_connect', [__CLASS__, 'manual_connect']);
        add_filter('allowed_redirect_hosts', [__CLASS__, 'allow_webruno_host']);
    }

    /** Resolve which domain to talk to: webruno.com, webruno.ir, or a custom self-hosted URL. */
    public static function get_api_base() {
        $choice = get_option('webruno_crm_domain', 'webruno.com');

        if ($choice === 'custom') {
            $custom = get_option('webruno_crm_custom_domain', '');
            return $custom ? untrailingslashit(esc_url_raw($custom)) : 'https://' . WEBRUNO_CRM_DEFAULT_DOMAIN;
        }

        if ($choice === 'webruno.ir') {
            return 'https://webruno.ir';
        }

        return 'https://webruno.com';
    }

    public static function allow_webruno_host($hosts) {
        $hosts[] = wp_parse_url(self::get_api_base(), PHP_URL_HOST);
        // Also allow-list both known first-party domains regardless of current selection.
        $hosts[] = 'webruno.com';
        $hosts[] = 'webruno.ir';
        return $hosts;
    }

    public static function is_connected() {
        return (bool) get_option('webruno_crm_api_token');
    }

    public static function get_token() {
        $encrypted = get_option('webruno_crm_api_token');
        if (!$encrypted) return '';
        return self::decrypt($encrypted);
    }

    public static function start_connect() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Insufficient permissions.', 'webruno-crm'), 403);
        }
        check_admin_referer('webruno_crm_connect');

        $domain = isset($_GET['domain']) ? sanitize_text_field(wp_unslash($_GET['domain'])) : 'webruno.com';
        if (!in_array($domain, ['webruno.com', 'webruno.ir'], true)) {
            $domain = 'webruno.com';
        }
        update_option('webruno_crm_domain', $domain);

        $site_id  = get_option('webruno_crm_site_id');
        $site_url = home_url('/');
        $return   = admin_url('admin-post.php?action=webruno_crm_callback');

        $connect_url = add_query_arg([
            'site_id'      => $site_id,
            'site_url'     => rawurlencode($site_url),
            'site_name'    => rawurlencode(get_bloginfo('name')),
            'return_url'   => rawurlencode($return),
            'state'        => wp_create_nonce('webruno_crm_state'),
        ], self::get_api_base() . '/login');

        wp_safe_redirect(esc_url_raw($connect_url));
        exit;
    }

    public static function handle_callback() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Insufficient permissions.', 'webruno-crm'), 403);
        }

        $state = isset($_GET['state']) ? sanitize_text_field(wp_unslash($_GET['state'])) : '';
        if (!wp_verify_nonce($state, 'webruno_crm_state')) {
            wp_die(esc_html__('Invalid or expired connection request. Please try connecting again.', 'webruno-crm'));
        }

        $token = isset($_GET['token']) ? sanitize_text_field(wp_unslash($_GET['token'])) : '';
        if (empty($token)) {
            wp_die(esc_html__('Webruno did not return a valid token.', 'webruno-crm'));
        }

        update_option('webruno_crm_api_token', self::encrypt($token), false);
        update_option('webruno_crm_connected_at', current_time('mysql'), false);

        wp_safe_redirect(admin_url('admin.php?page=webruno-crm&connected=1'));
        exit;
    }

    public static function disconnect() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Insufficient permissions.', 'webruno-crm'), 403);
        }
        check_admin_referer('webruno_crm_disconnect');

        delete_option('webruno_crm_api_token');
        delete_option('webruno_crm_connected_at');

        wp_safe_redirect(admin_url('admin.php?page=webruno-crm&disconnected=1'));
        exit;
    }

    public static function manual_connect() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Insufficient permissions.', 'webruno-crm'), 403);
        }
        check_admin_referer('webruno_crm_manual_connect');

        $domain = isset($_POST['webruno_crm_domain']) ? sanitize_text_field(wp_unslash($_POST['webruno_crm_domain'])) : 'webruno.com';
        if (!in_array($domain, ['webruno.com', 'webruno.ir', 'custom'], true)) {
            $domain = 'webruno.com';
        }
        update_option('webruno_crm_domain', $domain);

        if ($domain === 'custom') {
            $custom = isset($_POST['webruno_crm_custom_domain']) ? esc_url_raw(wp_unslash($_POST['webruno_crm_custom_domain'])) : '';
            update_option('webruno_crm_custom_domain', $custom);
        }

        $token = isset($_POST['webruno_crm_manual_token']) ? sanitize_text_field(wp_unslash($_POST['webruno_crm_manual_token'])) : '';
        if (empty($token)) {
            wp_die(esc_html__('Please enter a valid API token.', 'webruno-crm'));
        }

        update_option('webruno_crm_api_token', self::encrypt($token), false);
        update_option('webruno_crm_connected_at', current_time('mysql'), false);

        wp_safe_redirect(admin_url('admin.php?page=webruno-crm&connected=1'));
        exit;
    }

    /** Simple reversible encryption using a per-site key derived from wp_salt(). Not for high-security secrets, but avoids plaintext-in-DB storage. */
    private static function cipher_key() {
        return substr(hash('sha256', wp_salt('auth')), 0, 32);
    }

    public static function encrypt($value) {
        if (!function_exists('openssl_encrypt')) return base64_encode($value);
        $iv = openssl_random_pseudo_bytes(16);
        $encrypted = openssl_encrypt($value, 'aes-256-cbc', self::cipher_key(), 0, $iv);
        return base64_encode($iv . $encrypted);
    }

    public static function decrypt($value) {
        if (!function_exists('openssl_decrypt')) return base64_decode($value);
        $raw = base64_decode($value);
        $iv = substr($raw, 0, 16);
        $encrypted = substr($raw, 16);
        return openssl_decrypt($encrypted, 'aes-256-cbc', self::cipher_key(), 0, $iv);
    }
}
