<?php
if (!defined('ABSPATH')) exit;

/**
 * Pushes data to Webruno as it happens (event-driven), not bulk export.
 * Every source is optional and only wires up if the setting is on AND
 * (for WooCommerce/CF7/WPForms) the relevant plugin is actually active.
 */
class Webruno_Sync {

    public static function init() {
        if (!Webruno_Auth::is_connected()) return;

        if (get_option('webruno_crm_sync_orders', true) && class_exists('WooCommerce')) {
            add_action('woocommerce_order_status_changed', [__CLASS__, 'push_order'], 10, 1);
        }

        if (get_option('webruno_crm_sync_leads', true)) {
            if (defined('WPCF7_VERSION')) {
                add_action('wpcf7_mail_sent', [__CLASS__, 'push_cf7_lead']);
            }
            if (class_exists('WPForms')) {
                add_action('wpforms_process_complete', [__CLASS__, 'push_wpforms_lead'], 10, 4);
            }
        }

        if (get_option('webruno_crm_sync_posts', false)) {
            add_action('transition_post_status', [__CLASS__, 'push_post'], 10, 3);
        }

        if (get_option('webruno_crm_sync_users', false)) {
            add_action('user_register', [__CLASS__, 'push_user']);
        }
    }

    public static function push_order($order_id) {
        if (!class_exists('WooCommerce')) return;
        $order = wc_get_order($order_id);
        if (!$order) return;

        $payload = [
            'external_id' => $order_id,
            'status'      => $order->get_status(),
            'total'       => $order->get_total(),
            'currency'    => $order->get_currency(),
            'customer'    => [
                'name'  => $order->get_formatted_billing_full_name(),
                'email' => $order->get_billing_email(),
                'phone' => $order->get_billing_phone(),
            ],
        ];
        self::send('order', $payload);
    }

    public static function push_cf7_lead($contact_form) {
        $submission = WPCF7_Submission::get_instance();
        if (!$submission) return;
        $data = $submission->get_posted_data();

        self::send('lead', [
            'source' => 'contact-form-7',
            'form'   => $contact_form->title(),
            'fields' => array_map('sanitize_text_field', $data),
        ]);
    }

    public static function push_wpforms_lead($fields, $entry, $form_data, $entry_id) {
        $flat = [];
        foreach ($fields as $field) {
            $flat[$field['name'] ?: $field['id']] = sanitize_text_field($field['value']);
        }
        self::send('lead', [
            'source' => 'wpforms',
            'form'   => $form_data['settings']['form_title'] ?? '',
            'fields' => $flat,
        ]);
    }

    public static function push_post($new_status, $old_status, $post) {
        if ($new_status !== 'publish' || $old_status === 'publish') return;
        if ($post->post_type !== 'post') return;

        self::send('post', [
            'external_id' => $post->ID,
            'title'       => $post->post_title,
            'link'        => get_permalink($post->ID),
            'excerpt'     => get_the_excerpt($post),
        ]);
    }

    public static function push_user($user_id) {
        $user = get_userdata($user_id);
        if (!$user) return;

        self::send('user', [
            'external_id' => $user_id,
            'name'        => $user->display_name,
            'email'       => $user->user_email,
        ]);
    }

    /** Fire-and-forget async POST to Webruno's ingest API. */
    private static function send($type, $payload) {
        $token = Webruno_Auth::get_token();
        if (!$token) return;

        wp_remote_post(Webruno_Auth::get_api_base() . '/api/ingest/' . rawurlencode($type), [
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Content-Type'  => 'application/json',
                'X-Site-Id'     => get_option('webruno_crm_site_id'),
            ],
            'body'     => wp_json_encode($payload),
            'timeout'  => 8,
            'blocking' => false, // don't slow down the site request waiting on Webruno
        ]);
    }
}
