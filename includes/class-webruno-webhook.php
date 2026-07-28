<?php
if (!defined('ABSPATH')) exit;

/**
 * Exposes /wp-json/webruno/v1/webhook so Webruno can push changes back
 * (e.g. "mark order #123 as fulfilled", "reply to lead #45").
 * Authenticated via a shared secret sent as X-Webruno-Signature (HMAC of body).
 */
class Webruno_Webhook {

    public static function init() {
        add_action('rest_api_init', [__CLASS__, 'register_routes']);
    }

    public static function register_routes() {
        register_rest_route('webruno/v1', '/webhook', [
            'methods'             => 'POST',
            'callback'            => [__CLASS__, 'handle'],
            'permission_callback' => [__CLASS__, 'verify_signature'],
            'args'                => [
                'event' => ['required' => true, 'type' => 'string'],
                'data'  => ['required' => true, 'type' => 'object'],
            ],
        ]);
    }

    public static function verify_signature(WP_REST_Request $request) {
        $token = Webruno_Auth::get_token();
        if (!$token) return false;

        $signature = $request->get_header('x_webruno_signature');
        if (!$signature) return false;

        $expected = hash_hmac('sha256', $request->get_body(), $token);
        return hash_equals($expected, $signature);
    }

    public static function handle(WP_REST_Request $request) {
        $event = sanitize_text_field($request->get_param('event'));
        $data  = $request->get_param('data');

        switch ($event) {
            case 'order.status_updated':
                self::update_order_status($data);
                break;
            case 'lead.note_added':
                do_action('webruno_crm_lead_note_added', $data);
                break;
            default:
                do_action('webruno_crm_webhook_' . $event, $data);
        }

        return new WP_REST_Response(['received' => true], 200);
    }

    private static function update_order_status($data) {
        if (!class_exists('WooCommerce') || empty($data['external_id']) || empty($data['status'])) return;

        $order = wc_get_order(absint($data['external_id']));
        if (!$order) return;

        $allowed = wc_get_order_statuses();
        $status  = sanitize_text_field($data['status']);
        if (!isset($allowed['wc-' . $status])) return;

        $order->update_status($status, __('Updated via Webruno CRM.', 'webruno-crm'));
    }
}
