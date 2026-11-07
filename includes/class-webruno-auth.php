<?php
if (!defined('ABSPATH')) exit;

/**
 * Handles the connect/disconnect handshake between this site and Webruno.
 *
 * Browser-approved connect flow (no credentials ever pass through wp-admin):
 *
 * 1. Admin clicks "Connect" -> AJAX webruno_crm_connect calls POST {panel}/customer/customer/webruno-plugin-connect/start.
 *    Webruno answers with a one-time code, a random challenge and a connectUrl. We keep code + challenge in the
 *    transient `webruno_crm_challenge` (15 minutes). The browser opens connectUrl in a new tab.
 * 2. The admin signs in on Webruno and approves. Webruno then calls
 *    GET /wp-json/webruno-crm/v1/verify?challenge=... on THIS site; we answer only when the challenge matches the
 *    transient (hash_equals), otherwise 404. That proves the person approving controls this WordPress site.
 * 3. wp-admin polls AJAX webruno_crm_connect_poll every few seconds (max 10 minutes). When Webruno has approved,
 *    the poll response carries the API token exactly once; it is stored encrypted and never sent to the browser.
 *
 * The site_id is never exposed publicly any more.
 */
class Webruno_Auth {

    const CHALLENGE_TRANSIENT = 'webruno_crm_challenge';
    const CHALLENGE_TTL       = 900; // 15 minutes, same as the Webruno side

    public static function init() {
        add_action('wp_ajax_webruno_crm_connect', [__CLASS__, 'ajax_connect']);
        add_action('wp_ajax_webruno_crm_connect_poll', [__CLASS__, 'ajax_connect_poll']);
        add_action('wp_ajax_webruno_crm_connect_cancel', [__CLASS__, 'ajax_connect_cancel']);
        add_action('wp_ajax_webruno_crm_manual_connect', [__CLASS__, 'ajax_manual_connect']);
        add_action('wp_ajax_webruno_crm_disconnect', [__CLASS__, 'ajax_disconnect']);
        add_filter('allowed_redirect_hosts', [__CLASS__, 'allow_webruno_host']);
        add_action('rest_api_init', [__CLASS__, 'register_verify_route']);
    }

    /**
     * Public route the Webruno backend calls to confirm that whoever approves a connection controls this site.
     * It answers ONLY while this site has an open connect request AND the supplied challenge equals the one we
     * stored (constant-time comparison). Everything else is a plain 404, and the site_id is never returned.
     */
    public static function register_verify_route() {
        register_rest_route('webruno-crm/v1', '/verify', [
            'methods'             => 'GET',
            'permission_callback' => '__return_true',
            'args'                => [
                'challenge' => ['type' => 'string', 'required' => false],
            ],
            'callback'            => [__CLASS__, 'rest_verify'],
        ]);
    }

    public static function rest_verify($request) {
        $given  = $request->get_param('challenge');
        $stored = get_transient(self::CHALLENGE_TRANSIENT);

        if (!is_string($given) || $given === '' || !is_array($stored) || empty($stored['challenge'])
            || !is_string($stored['challenge']) || !hash_equals($stored['challenge'], $given)) {
            return new WP_Error('rest_no_route', 'No route was found matching the URL and request method.', ['status' => 404]);
        }

        return ['challenge' => $stored['challenge']];
    }

    /** Resolve which domain to talk to: webruno.com, webruno.ir, or a custom self-hosted https URL. */
    public static function get_api_base() {
        $choice = get_option('webruno_crm_domain', 'webruno.com');

        if ($choice === 'custom') {
            $custom = self::sanitize_custom_domain(get_option('webruno_crm_custom_domain', ''));
            return $custom ? $custom : 'https://panel.' . WEBRUNO_CRM_DEFAULT_DOMAIN;
        }

        if ($choice === 'webruno.ir') {
            return 'https://panel.webruno.ir';
        }

        return 'https://panel.webruno.com';
    }

    /** Custom domains must be https. Returns the cleaned URL or '' when it is not acceptable. */
    private static function sanitize_custom_domain($url) {
        $url = esc_url_raw((string) $url, ['https']);
        if (!$url) return '';
        $parts = wp_parse_url($url);
        if (empty($parts['scheme']) || $parts['scheme'] !== 'https' || empty($parts['host'])) return '';
        return untrailingslashit($url);
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

    /** Shared permission + nonce check for all AJAX handlers. */
    private static function guard() {
        check_ajax_referer('webruno_crm_ajax', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Insufficient permissions.', 'webruno-crm')], 403);
        }
    }

    /**
     * AJAX step 1: ask Webruno to start a connection and hand the browser the approval link.
     * The one-time code and challenge stay on the server (transient); only the link goes to the browser.
     */
    public static function ajax_connect() {
        self::guard();

        $domain = isset($_POST['domain']) ? sanitize_text_field(wp_unslash($_POST['domain'])) : 'webruno.com';
        if (!in_array($domain, ['webruno.com', 'webruno.ir'], true)) {
            $domain = 'webruno.com';
        }
        update_option('webruno_crm_domain', $domain);

        $result = self::start_connect();

        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }

        wp_send_json_success([
            'status'      => 'pending',
            'connect_url' => $result['connect_url'],
            'expires_in'  => self::CHALLENGE_TTL,
            'message'     => __('Approve the connection in the Webruno tab that just opened.', 'webruno-crm'),
        ]);
    }

    /** AJAX step 2 (repeated by the browser): has the admin approved on Webruno yet? */
    public static function ajax_connect_poll() {
        self::guard();

        $state = get_transient(self::CHALLENGE_TRANSIENT);
        if (!is_array($state) || empty($state['code']) || empty($state['challenge'])) {
            wp_send_json_success(['status' => 'expired', 'message' => __('The connection request timed out. Please start again.', 'webruno-crm')]);
        }

        $resp = wp_remote_post(self::get_api_base() . '/customer/customer/webruno-plugin-connect/poll', [
            'headers'     => ['Content-Type' => 'application/json'],
            'body'        => wp_json_encode(['code' => $state['code'], 'challenge' => $state['challenge']]),
            'timeout'     => 15,
            'redirection' => 0,
        ]);

        if (is_wp_error($resp)) {
            // transient network trouble: keep waiting, the browser polls again
            wp_send_json_success(['status' => 'pending']);
        }

        $http = (int) wp_remote_retrieve_response_code($resp);
        $body = json_decode(wp_remote_retrieve_body($resp), true);
        $body = is_array($body) ? $body : [];
        $status = isset($body['status']) ? (string) $body['status'] : '';

        if ($http === 404 || $status === 'invalid') {
            delete_transient(self::CHALLENGE_TRANSIENT);
            wp_send_json_success(['status' => 'expired', 'message' => __('The connection request is no longer valid. Please start again.', 'webruno-crm')]);
        }

        if ($http >= 500 || $http === 429) {
            wp_send_json_success(['status' => 'pending']);
        }

        if ($http !== 200 || empty($body['success'])) {
            wp_send_json_success(['status' => 'pending']);
        }

        if ($status === 'approved' && !empty($body['token']) && is_string($body['token'])) {
            update_option('webruno_crm_api_token', self::encrypt($body['token']), false);
            if (!empty($body['customerId'])) {
                update_option('webruno_crm_customer_id', sanitize_text_field((string) $body['customerId']), false);
            }
            update_option('webruno_crm_connected_at', current_time('mysql'), false);
            delete_transient(self::CHALLENGE_TRANSIENT);

            wp_send_json_success([
                'status'       => 'approved',
                'message'      => __('Connected to Webruno successfully.', 'webruno-crm'),
                'connected_at' => get_option('webruno_crm_connected_at'),
            ]);
        }

        if ($status === 'declined') {
            delete_transient(self::CHALLENGE_TRANSIENT);
            wp_send_json_success(['status' => 'declined', 'message' => __('The connection was declined on Webruno.', 'webruno-crm')]);
        }

        if ($status === 'expired' || $status === 'consumed') {
            delete_transient(self::CHALLENGE_TRANSIENT);
            wp_send_json_success(['status' => 'expired', 'message' => __('The connection request timed out. Please start again.', 'webruno-crm')]);
        }

        wp_send_json_success(['status' => 'pending']);
    }

    /** AJAX: the admin cancelled while waiting. Closing the request also stops /verify from answering. */
    public static function ajax_connect_cancel() {
        self::guard();
        delete_transient(self::CHALLENGE_TRANSIENT);
        wp_send_json_success(['status' => 'cancelled']);
    }

    /** AJAX: manual/custom-domain connect using a pasted token. https only. */
    public static function ajax_manual_connect() {
        self::guard();

        $domain = isset($_POST['domain']) ? sanitize_text_field(wp_unslash($_POST['domain'])) : 'webruno.com';
        if (!in_array($domain, ['webruno.com', 'webruno.ir', 'custom'], true)) {
            $domain = 'webruno.com';
        }

        if ($domain === 'custom') {
            $raw    = isset($_POST['custom_domain']) ? trim((string) wp_unslash($_POST['custom_domain'])) : '';
            $custom = self::sanitize_custom_domain($raw);
            if (!$custom) {
                wp_send_json_error(['message' => __('Please enter a custom domain URL that starts with https://', 'webruno-crm')]);
            }
            update_option('webruno_crm_custom_domain', $custom);
        }

        $token = isset($_POST['token']) ? sanitize_text_field(wp_unslash($_POST['token'])) : '';
        if (empty($token)) {
            wp_send_json_error(['message' => __('Please enter a valid API token.', 'webruno-crm')]);
        }

        update_option('webruno_crm_domain', $domain);
        update_option('webruno_crm_api_token', self::encrypt($token), false);
        update_option('webruno_crm_connected_at', current_time('mysql'), false);

        wp_send_json_success([
            'status'       => 'approved',
            'message'      => __('Connected to Webruno successfully.', 'webruno-crm'),
            'connected_at' => get_option('webruno_crm_connected_at'),
        ]);
    }

    /** AJAX: disconnect. */
    public static function ajax_disconnect() {
        self::guard();

        delete_option('webruno_crm_api_token');
        delete_option('webruno_crm_connected_at');
        delete_option('webruno_crm_customer_id');
        delete_transient(self::CHALLENGE_TRANSIENT);

        wp_send_json_success(['message' => __('Disconnected from Webruno.', 'webruno-crm')]);
    }

    /**
     * Starts a connect request with Webruno and stores the code/challenge for the poll and verify steps.
     * Returns ['connect_url' => ...] or a WP_Error with a human-readable message.
     */
    private static function start_connect() {
        $base    = self::get_api_base();
        $site_id = get_option('webruno_crm_site_id');

        if (strpos($base, 'https://') !== 0) {
            return new WP_Error('webruno_connect_insecure', __('Webruno must be reached over https.', 'webruno-crm'));
        }

        $resp = wp_remote_post($base . '/customer/customer/webruno-plugin-connect/start', [
            'headers'     => ['Content-Type' => 'application/json'],
            'body'        => wp_json_encode([
                'siteUrl'   => home_url('/'),
                'siteId'    => $site_id,
                'siteTitle' => get_bloginfo('name'),
            ]),
            'timeout'     => 15,
            'redirection' => 0,
        ]);

        if (is_wp_error($resp)) {
            return new WP_Error(
                'webruno_connect_unreachable',
                sprintf(
                    /* translators: %s: error message */
                    __('Could not reach Webruno: %s', 'webruno-crm'),
                    $resp->get_error_message()
                )
            );
        }

        $status = (int) wp_remote_retrieve_response_code($resp);
        $body   = json_decode(wp_remote_retrieve_body($resp), true);
        $body   = is_array($body) ? $body : [];

        if ($status < 200 || $status >= 300 || empty($body['success']) || empty($body['code']) || empty($body['challenge']) || empty($body['connectUrl'])) {
            return new WP_Error(
                'webruno_connect_failed',
                !empty($body['message']) && is_string($body['message'])
                    ? $body['message']
                    /* translators: %d: HTTP status code */
                    : sprintf(__('Connection failed (HTTP %d).', 'webruno-crm'), $status)
            );
        }

        // The approval link must stay on the Webruno host we are talking to (never an arbitrary URL).
        $connect_url = esc_url_raw((string) $body['connectUrl'], ['https']);
        $link_host   = wp_parse_url($connect_url, PHP_URL_HOST);
        $api_host    = wp_parse_url($base, PHP_URL_HOST);
        if (!$connect_url || !$link_host || !$api_host || strtolower($link_host) !== strtolower($api_host)) {
            return new WP_Error('webruno_connect_bad_link', __('Webruno returned an unexpected approval link.', 'webruno-crm'));
        }

        set_transient(self::CHALLENGE_TRANSIENT, [
            'code'      => (string) $body['code'],
            'challenge' => (string) $body['challenge'],
            'started'   => time(),
        ], self::CHALLENGE_TTL);

        return ['connect_url' => $connect_url];
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
