<?php
/**
 * Plugin Name: Webruno CRM
 * Plugin URI: https://webruno.com
 * Description: Connect your site to Webruno CRM — sync orders, leads, posts, and users, and manage your CRM panel right inside wp-admin.
 * Version: 1.1.0
 * Author: Hamid Alinia - idehweb
 * Author URI: https://idehweb.com
 * Text Domain: webruno-crm
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

if (!defined('ABSPATH')) exit;

define('WEBRUNO_CRM_VERSION', '1.1.0');
define('WEBRUNO_CRM_DIR', plugin_dir_path(__FILE__));
define('WEBRUNO_CRM_URL', plugin_dir_url(__FILE__));
define('WEBRUNO_CRM_DEFAULT_DOMAIN', 'webruno.com'); // fallback if nothing is configured yet

require_once WEBRUNO_CRM_DIR . 'includes/class-webruno-region.php';
require_once WEBRUNO_CRM_DIR . 'includes/class-webruno-auth.php';
require_once WEBRUNO_CRM_DIR . 'includes/class-webruno-dashboard.php';
require_once WEBRUNO_CRM_DIR . 'includes/class-webruno-customer-meta.php';
require_once WEBRUNO_CRM_DIR . 'includes/class-webruno-admin.php';
require_once WEBRUNO_CRM_DIR . 'includes/class-webruno-sync.php';
require_once WEBRUNO_CRM_DIR . 'includes/class-webruno-bulk-sync.php';
require_once WEBRUNO_CRM_DIR . 'includes/class-webruno-webhook.php';
require_once WEBRUNO_CRM_DIR . 'includes/class-webruno-content-api.php';
require_once WEBRUNO_CRM_DIR . 'includes/class-webruno-mcp-guide.php';

add_action('plugins_loaded', 'webruno_crm_init');
function webruno_crm_init() {
    Webruno_Auth::init();
    Webruno_Customer_Meta::init();
    Webruno_Admin::init();
    Webruno_Sync::init();
    Webruno_Bulk_Sync::init();
    Webruno_Webhook::init();
    Webruno_Content_API::init();
    Webruno_MCP_Guide::init();
}

register_deactivation_hook(__FILE__, function () {
    wp_clear_scheduled_hook('webruno_crm_prune_content_tables');
});

// Clean, standard activation/deactivation — no artificial feature gating.
register_activation_hook(__FILE__, function () {
    if (!get_option('webruno_crm_site_id')) {
        add_option('webruno_crm_site_id', wp_generate_uuid4());
    }
});