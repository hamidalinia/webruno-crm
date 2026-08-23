<?php
if (!defined('ABSPATH')) exit;

/**
 * Handles the connect/disconnect handshake between this site and Webruno.
 *
 * AJAX flow (replaces the old admin-post.php redirect flow):
 * 1. Admin clicks "Connect" in wp-admin -> JS POSTs to wp_ajax_webruno_crm_connect
 * 2. This handler talks to webruno.com/webruno.ir server-to-server (customer
 *    create + site verify), and returns JSON success/failure immediately --
 *    no redirect, no waiting on a callback that may never come.
 * 3. On success we store the token (encrypted-at-rest via WP options + a
 *    site-specific secret) and the JS updates the page in place.
 */
class Webruno_Auth {

    public static function init() {
        add_action('wp_ajax_webruno_crm_connect', [__CLASS__, 'ajax_connect']);
        add_action('wp_ajax_webruno_crm_manual_connect', [__CLASS__, 'ajax_manual_connect']);
        add_action('wp_ajax_webruno_crm_disconnect', [__CLASS__, 'ajax_disconnect']);
        add_filter('allowed_redirect_hosts', [__CLASS__, 'allow_webruno_host']);
        add_action('rest_api_init', [__CLASS__, 'register_verify_route']);
    }

    /**
     * Public, read-only route the Webruno backend calls back to confirm
     * this site really controls the site_id it claims -- see
     * verifyAndCreatePluginWebsite on the backend. Only ever echoes back
     * the site's own locally-generated site_id, nothing sensitive.
     */
    public static function register_verify_route() {
        register_rest_route('webruno-crm/v1', '/verify', [
            'methods'             => 'GET',
            'permission_callback' => '__return_true',
            'callback'            => function () {
                return ['site_id' => get_option('webruno_crm_site_id')];
            },
        ]);
    }

    /** Resolve which domain to talk to: webruno.com, webruno.ir, or a custom self-hosted URL. */
    public static function get_api_base() {
        $choice = get_option('webruno_crm_domain', 'webruno.com');

        if ($choice === 'custom') {
            $custom = get_option('webruno_crm_custom_domain', '');
            return $custom ? untrailingslashit(esc_url_raw($custom)) : 'https://' . WEBRUNO_CRM_DEFAULT_DOMAIN;
        }

        if ($choice === 'webruno.ir') {
            return 'https://panel.webruno.ir';
        }

        return 'https://panel.webruno.com';
    }

    public static function allow_webruno_host($hosts) {
        $hosts[] = wp_parse_url(self::get_api_base(), PHP_URL_HOST);
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

    /**
     * AJAX: one-click connect. Does the full customer-create + site-verify
     * handshake server-to-server and reports back as JSON immediately, so
     * the admin sees a live result instead of a silent click.
     */
    public static function ajax_connect() {
        check_ajax_referer('webruno_crm_ajax', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Insufficient permissions.', 'webruno-crm')], 403);
        }

        $domain = isset($_POST['domain']) ? sanitize_text_field(wp_unslash($_POST['domain'])) : 'webruno.com';
        if (!in_array($domain, ['webruno.com', 'webruno.ir'], true)) {
            $domain = 'webruno.com';
        }
        update_option('webruno_crm_domain', $domain);

        $result = self::perform_connect();

        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }

        wp_send_json_success([
            'message'      => __('Connected to Webruno successfully.', 'webruno-crm'),
            'connected_at' => get_option('webruno_crm_connected_at'),
        ]);
    }

    /** AJAX: manual/custom-domain connect using a pasted token. */
    public static function ajax_manual_connect() {
        check_ajax_referer('webruno_crm_ajax', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Insufficient permissions.', 'webruno-crm')], 403);
        }

        $domain = isset($_POST['domain']) ? sanitize_text_field(wp_unslash($_POST['domain'])) : 'webruno.com';
        if (!in_array($domain, ['webruno.com', 'webruno.ir', 'custom'], true)) {
            $domain = 'webruno.com';
        }
        update_option('webruno_crm_domain', $domain);

        if ($domain === 'custom') {
            $custom = isset($_POST['custom_domain']) ? esc_url_raw(wp_unslash($_POST['custom_domain'])) : '';
            if (!$custom) {
                wp_send_json_error(['message' => __('Please enter a custom domain URL.', 'webruno-crm')]);
            }
            update_option('webruno_crm_custom_domain', $custom);
        }

        $token = isset($_POST['token']) ? sanitize_text_field(wp_unslash($_POST['token'])) : '';
        if (empty($token)) {
            wp_send_json_error(['message' => __('Please enter a valid API token.', 'webruno-crm')]);
        }

        update_option('webruno_crm_api_token', self::encrypt($token), false);
        update_option('webruno_crm_connected_at', current_time('mysql'), false);

        wp_send_json_success([
            'message'      => __('Connected to Webruno successfully.', 'webruno-crm'),
            'connected_at' => get_option('webruno_crm_connected_at'),
        ]);
    }

    /** AJAX: disconnect. */
    public static function ajax_disconnect() {
        check_ajax_referer('webruno_crm_ajax', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Insufficient permissions.', 'webruno-crm')], 403);
        }

        delete_option('webruno_crm_api_token');
        delete_option('webruno_crm_connected_at');

        wp_send_json_success(['message' => __('Disconnected from Webruno.', 'webruno-crm')]);
    }

    /**
     * Does the actual two-step handshake with the Webruno backend:
     * 1) create/reuse Customer -> token
     * 2) verify site ownership + create Website record, authenticated with that token
     * Returns true on success, or a WP_Error with a human-readable message.
     */
    private static function perform_connect() {
        $site_id     = get_option('webruno_crm_site_id');
        $site_url    = home_url('/');
        $admin_user  = wp_get_current_user();
        $admin_email = ($admin_user && $admin_user->user_email) ? $admin_user->user_email : get_bloginfo('admin_email');

        $connect_resp = wp_remote_post(self::get_api_base() . '/customer/customer/webruno-plugin-connect', [
            'headers' => ['Content-Type' => 'application/json'],
            'body'    => wp_json_encode([
                'siteUrl'    => $site_url,
                'adminEmail' => $admin_email,
                'siteId'     => $site_id,
            ]),
            'timeout' => 15,
        ]);
//        $connect_body = json_decode(wp_remote_retrieve_body($connect_resp), true);
//print_r($connect_body);
//die();

        if (is_wp_error($connect_resp)) {
            return new WP_Error(
                'webruno_connect_unreachable',
                sprintf(
                /* translators: %s: error message */
                    __('Could not reach Webruno: %s', 'webruno-crm'),
                    $connect_resp->get_error_message()
                )
            );
        }

        $status = wp_remote_retrieve_response_code($connect_resp);
        $connect_body = json_decode(wp_remote_retrieve_body($connect_resp), true);

        if ($status < 200 || $status >= 300 || empty($connect_body['success']) || empty($connect_body['token'])) {
            return new WP_Error(
                'webruno_connect_failed',
                !empty($connect_body['message'])
                    ? $connect_body['message']
                    /* translators: %d: HTTP status code */
                    : sprintf(__('Connection failed (HTTP %d).', 'webruno-crm'), $status)
            );
        }

        $token = $connect_body['token'];

        $verify_resp = wp_remote_post(self::get_api_base() . '/customer/customer/webruno-plugin-verify', [
            'headers' => [
                'Content-Type' => 'application/json',
                '_id'          => $connect_body['customerId'],
                'token'        => $token,
            ],
            'body'    => wp_json_encode([
                'customerId' => $connect_body['customerId'],
                'siteUrl'    => $site_url,
                'siteId'     => $site_id,
                'siteTitle'  => get_bloginfo('name'),
            ]),
            'timeout' => 15,
        ]);


        if (is_wp_error($verify_resp)) {
            return new WP_Error(
                'webruno_verify_unreachable',
                sprintf(
                    /* translators: %s: underlying error message from the failed HTTP request */
                    __('Could not verify this site with Webruno: %s', 'webruno-crm'),
                    $verify_resp->get_error_message()
                )
            );
        }

        $verify_status = wp_remote_retrieve_response_code($verify_resp);
        $verify_body   = json_decode(wp_remote_retrieve_body($verify_resp), true);

        if ($verify_status < 200 || $verify_status >= 300 || empty($verify_body['success'])) {
            return new WP_Error(
                'webruno_verify_failed',
                !empty($verify_body['message'])
                    ? $verify_body['message']
                    /* translators: %d: HTTP status code */
                    : sprintf(__('Site verification failed (HTTP %d).', 'webruno-crm'), $verify_status)
            );
        }

        update_option('webruno_crm_api_token', self::encrypt($token), false);
        update_option('webruno_crm_customer_id', sanitize_text_field($connect_body['customerId']), false);
        update_option('webruno_crm_connected_at', current_time('mysql'), false);

        return true;
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