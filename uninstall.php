<?php
// Fires only on actual "Delete" from the Plugins screen, not on deactivate.
if (!defined('WP_UNINSTALL_PLUGIN')) exit;

delete_option('webruno_crm_api_token');
delete_option('webruno_crm_site_id');
delete_option('webruno_crm_connected_at');
delete_option('webruno_crm_domain');
delete_option('webruno_crm_custom_domain');
delete_option('webruno_crm_sync_orders');
delete_option('webruno_crm_sync_posts');
delete_option('webruno_crm_sync_leads');
delete_option('webruno_crm_sync_users');
