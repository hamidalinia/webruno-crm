<?php
if (!defined('ABSPATH')) exit;

/**
 * Free-tier, manual CRM data: VIP flags, notes, and one-click message
 * sending per customer email. Stored locally as a single option (small
 * dataset -- top-10 customer list, not the full table), keyed by email.
 * No API calls, no account needed. Sending uses wp_mail() -- already built
 * into WordPress, zero extra infrastructure. This is what makes the free
 * tier a real CRM: the user can tag, annotate, and message customers by
 * hand today; Pro's pitch is doing this automatically instead, plus
 * AI-personalized copy (which costs per-use and belongs behind the paywall
 * on cost grounds, not just as an arbitrary feature gate).
 */
class Webruno_Customer_Meta {

    const OPTION_KEY = 'webruno_crm_customer_meta';

    /** Minimum gap between manual sends to the same customer, to prevent accidental spam clicks. */
    const RESEND_COOLDOWN = 24 * HOUR_IN_SECONDS;

    public static function init() {
        add_action('wp_ajax_webruno_crm_save_customer_meta', [__CLASS__, 'ajax_save']);
        add_action('wp_ajax_webruno_crm_export_customers', [__CLASS__, 'ajax_export_csv']);
        add_action('wp_ajax_webruno_crm_send_message', [__CLASS__, 'ajax_send_message']);
    }

    private static function get_all() {
        $data = get_option(self::OPTION_KEY, []);
        return is_array($data) ? $data : [];
    }

    public static function get_for_email($email) {
        $all = self::get_all();
        return wp_parse_args($all[$email] ?? [], [
            'vip'           => false,
            'note'          => '',
            'last_messaged' => 0,
        ]);
    }

    private static function update_for_email($email, $fields) {
        $all = self::get_all();
        $current = wp_parse_args($all[$email] ?? [], [
            'vip' => false, 'note' => '', 'last_messaged' => 0,
        ]);
        $all[$email] = array_merge($current, $fields);

        // Keep this bounded -- it only ever needs to cover customers currently
        // shown in the top-10 table, so cap at a generous 200 entries.
        if (count($all) > 200) {
            $all = array_slice($all, -200, null, true);
        }
        update_option(self::OPTION_KEY, $all, false);
    }

    /** AJAX: save a VIP toggle and/or note for one customer email. */
    public static function ajax_save() {
        check_ajax_referer('webruno_crm_ajax', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Insufficient permissions.', 'webruno-crm')], 403);
        }

        $email = isset($_POST['email']) ? sanitize_email(wp_unslash($_POST['email'])) : '';
        if (!$email || !is_email($email)) {
            wp_send_json_error(['message' => __('Invalid customer email.', 'webruno-crm')], 400);
        }

        $vip  = isset($_POST['vip']) ? rest_sanitize_boolean(wp_unslash($_POST['vip'])) : false;
        $note = isset($_POST['note']) ? sanitize_textarea_field(wp_unslash($_POST['note'])) : '';

        self::update_for_email($email, ['vip' => $vip, 'note' => $note]);

        wp_send_json_success(['saved' => true]);
    }

    /**
     * AJAX: send a one-off message to a customer via wp_mail(). Manual,
     * one-click-per-customer -- this is the free-tier version of the
     * automation Pro runs on a schedule. Includes a cooldown so a
     * double-click can't send twice.
     */
    public static function ajax_send_message() {
        check_ajax_referer('webruno_crm_ajax', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Insufficient permissions.', 'webruno-crm')], 403);
        }

        $email    = isset($_POST['email']) ? sanitize_email(wp_unslash($_POST['email'])) : '';
        $name     = isset($_POST['name']) ? sanitize_text_field(wp_unslash($_POST['name'])) : '';
        $subject  = isset($_POST['subject']) ? sanitize_text_field(wp_unslash($_POST['subject'])) : '';
        $message  = isset($_POST['message']) ? sanitize_textarea_field(wp_unslash($_POST['message'])) : '';

        if (!$email || !is_email($email)) {
            wp_send_json_error(['message' => __('Invalid customer email.', 'webruno-crm')], 400);
        }
        if (!$subject || !$message) {
            wp_send_json_error(['message' => __('Subject and message are required.', 'webruno-crm')], 400);
        }

        $meta = self::get_for_email($email);
        if ($meta['last_messaged'] && (time() - $meta['last_messaged']) < self::RESEND_COOLDOWN) {
            wp_send_json_error([
                'message' => sprintf(
                /* translators: %s: human-readable time until resend is allowed */
                    __('Already messaged this customer recently. Try again in %s.', 'webruno-crm'),
                    human_time_diff(time(), $meta['last_messaged'] + self::RESEND_COOLDOWN)
                ),
            ], 429);
        }

        $sent = wp_mail($email, $subject, $message);

        if (!$sent) {
            wp_send_json_error(['message' => __('Message could not be sent. Check your site\'s email/SMTP setup.', 'webruno-crm')], 500);
        }

        self::update_for_email($email, ['last_messaged' => time()]);

        wp_send_json_success([
            'sent'          => true,
            'last_messaged' => time(),
            'message'       => __('Message sent.', 'webruno-crm'),
        ]);
    }

    /** AJAX: stream the current top-customer list as a CSV download. */
    public static function ajax_export_csv() {
        check_ajax_referer('webruno_crm_ajax', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Insufficient permissions.', 'webruno-crm'), 403);
        }
        if (!class_exists('Webruno_Dashboard') || !method_exists('Webruno_Dashboard', 'get_customers_for_export')) {
            wp_die(esc_html__('Customer data is not available.', 'webruno-crm'));
        }

        $customers = Webruno_Dashboard::get_customers_for_export();

        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="customers-' . gmdate('Y-m-d') . '.csv"');

        // WP_Filesystem has no API for streaming directly to the php://output
        // stream (it operates on real files on disk) -- fopen()/fclose() on
        // php://output is the standard, WP.org-accepted approach for
        // generating a CSV download response. Nothing here touches the
        // actual filesystem.
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
        $out = fopen('php://output', 'w');
        fputcsv($out, ['Name', 'Email', 'Orders', 'Lifetime Value', 'Last Order', 'VIP', 'Note', 'Last Messaged']);

        foreach ($customers as $c) {
            $meta = self::get_for_email($c['email']);
            fputcsv($out, [
                $c['name'],
                $c['email'],
                $c['orders'],
                $c['total'],
                $c['last_order'] ? gmdate('Y-m-d', $c['last_order']) : '',
                $meta['vip'] ? 'Yes' : 'No',
                $meta['note'],
                $meta['last_messaged'] ? gmdate('Y-m-d', $meta['last_messaged']) : '',
            ]);
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- see fopen() note above.
        fclose($out);
        exit;
    }
}