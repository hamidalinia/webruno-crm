<?php
if (!defined('ABSPATH')) exit;

/**
 * "Connect to AI" admin page: shows the customer MCP address and step-by-step instructions
 * for connecting it to an AI assistant. Read-only page; it changes no settings.
 */
class Webruno_MCP_Guide {

    const PAGE = 'webruno-crm-ai';

    public static function init() {
        add_action('admin_menu', [__CLASS__, 'menu'], 20);
        add_action('admin_enqueue_scripts', [__CLASS__, 'assets']);
    }

    public static function menu() {
        add_submenu_page(
            'webruno-crm',
            __('Connect to AI', 'webruno-crm'),
            __('Connect to AI', 'webruno-crm'),
            'manage_options',
            self::PAGE,
            [__CLASS__, 'render']
        );
    }

    /** The address people paste into their AI app. Follows the server this site is connected to. */
    public static function mcp_url() {
        return trailingslashit(Webruno_Auth::get_api_base()) . 'customer/mcpCustomer';
    }

    public static function assets($hook) {
        if (strpos((string) $hook, self::PAGE) === false) return;
        wp_register_script('webruno-crm-ai', false, [], WEBRUNO_CRM_VERSION, true);
        wp_enqueue_script('webruno-crm-ai');
        wp_add_inline_script('webruno-crm-ai', "document.addEventListener('click',function(e){var b=e.target.closest('[data-webruno-copy]');if(!b)return;var el=document.getElementById(b.getAttribute('data-webruno-copy'));if(!el)return;var t=el.value||el.textContent;var done=function(){var o=b.textContent;b.textContent=b.getAttribute('data-done');setTimeout(function(){b.textContent=o;},1500);};if(navigator.clipboard&&navigator.clipboard.writeText){navigator.clipboard.writeText(t).then(done);}else{el.select&&el.select();document.execCommand('copy');done();}});");
    }

    public static function render() {
        if (!current_user_can('manage_options')) return;

        $url       = self::mcp_url();
        $connected = Webruno_Auth::is_connected();
        $read_on   = class_exists('Webruno_Content_API') && Webruno_Content_API::read_enabled();
        $write_on  = class_exists('Webruno_Content_API') && Webruno_Content_API::write_enabled();
        $settings  = admin_url('admin.php?page=webruno-crm-settings');
        $cli       = 'claude mcp add --transport http webruno ' . $url;
        $json      = wp_json_encode(['mcpServers' => ['webruno' => ['url' => $url]]], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Connect Webruno to your AI assistant', 'webruno-crm'); ?></h1>
            <p><?php esc_html_e('Let an AI assistant (Claude, ChatGPT, Cursor and other MCP apps) manage your site for you: read your content and, if you allow it, create and edit posts and pages.', 'webruno-crm'); ?></p>

            <?php if (!$connected) : ?>
                <div class="notice notice-warning inline"><p>
                    <?php esc_html_e('This site is not connected to Webruno yet. Connect it first, then come back to this page.', 'webruno-crm'); ?>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=webruno-crm')); ?>"><?php esc_html_e('Connect now', 'webruno-crm'); ?></a>
                </p></div>
            <?php endif; ?>

            <h2><?php esc_html_e('1. Your MCP address', 'webruno-crm'); ?></h2>
            <p>
                <input type="text" id="webruno-mcp-url" class="large-text code" style="max-width:560px" readonly value="<?php echo esc_attr($url); ?>" />
                <button type="button" class="button" data-webruno-copy="webruno-mcp-url" data-done="<?php esc_attr_e('Copied', 'webruno-crm'); ?>"><?php esc_html_e('Copy', 'webruno-crm'); ?></button>
            </p>
            <p class="description"><?php esc_html_e('You sign in with your Webruno account in your browser the first time you connect. No password or token is pasted anywhere.', 'webruno-crm'); ?></p>

            <h2><?php esc_html_e('2. Decide what the AI may do on this site', 'webruno-crm'); ?></h2>
            <p>
                <?php
                printf(
                    /* translators: 1: Reading status, 2: Editing status */
                    esc_html__('Reading content: %1$s — Editing posts and pages: %2$s.', 'webruno-crm'),
                    '<strong>' . esc_html($read_on ? __('ON', 'webruno-crm') : __('OFF', 'webruno-crm')) . '</strong>',
                    '<strong>' . esc_html($write_on ? __('ON', 'webruno-crm') : __('OFF', 'webruno-crm')) . '</strong>'
                );
                ?>
                <a href="<?php echo esc_url($settings); ?>"><?php esc_html_e('Change these switches', 'webruno-crm'); ?></a>
            </p>
            <p class="description"><?php esc_html_e('Both are off by default. Turn on reading first; editing needs reading. Every change keeps a revision you can restore.', 'webruno-crm'); ?></p>

            <h2><?php esc_html_e('3. Add the address to your AI app', 'webruno-crm'); ?></h2>

            <h3><?php esc_html_e('Claude (claude.ai or Claude Desktop)', 'webruno-crm'); ?></h3>
            <ol>
                <li><?php esc_html_e('Open Settings, then Connectors.', 'webruno-crm'); ?></li>
                <li><?php esc_html_e('Click "Add custom connector".', 'webruno-crm'); ?></li>
                <li><?php esc_html_e('Paste the MCP address above and save.', 'webruno-crm'); ?></li>
                <li><?php esc_html_e('Click Connect, sign in to Webruno and approve.', 'webruno-crm'); ?></li>
            </ol>

            <h3><?php esc_html_e('ChatGPT', 'webruno-crm'); ?></h3>
            <ol>
                <li><?php esc_html_e('Open Settings, then Connectors, and enable Developer mode (Advanced).', 'webruno-crm'); ?></li>
                <li><?php esc_html_e('Create a new connector, paste the MCP address and choose OAuth as authentication.', 'webruno-crm'); ?></li>
                <li><?php esc_html_e('Sign in to Webruno and approve.', 'webruno-crm'); ?></li>
            </ol>

            <h3><?php esc_html_e('Claude Code (terminal)', 'webruno-crm'); ?></h3>
            <p>
                <input type="text" id="webruno-mcp-cli" class="large-text code" style="max-width:760px" readonly value="<?php echo esc_attr($cli); ?>" />
                <button type="button" class="button" data-webruno-copy="webruno-mcp-cli" data-done="<?php esc_attr_e('Copied', 'webruno-crm'); ?>"><?php esc_html_e('Copy', 'webruno-crm'); ?></button>
            </p>
            <p class="description"><?php esc_html_e('Then run /mcp inside Claude Code and choose Authenticate.', 'webruno-crm'); ?></p>

            <h3><?php esc_html_e('Cursor and other apps with an MCP config file', 'webruno-crm'); ?></h3>
            <p><?php esc_html_e('Add this to your MCP configuration file (for Cursor: .cursor/mcp.json), then authenticate when asked:', 'webruno-crm'); ?></p>
            <pre id="webruno-mcp-json" class="code" style="background:#fff;border:1px solid #ccd0d4;padding:12px;max-width:560px;overflow:auto"><?php echo esc_html($json); ?></pre>
            <p><button type="button" class="button" data-webruno-copy="webruno-mcp-json" data-done="<?php esc_attr_e('Copied', 'webruno-crm'); ?>"><?php esc_html_e('Copy', 'webruno-crm'); ?></button></p>

            <h2><?php esc_html_e('4. Try it', 'webruno-crm'); ?></h2>
            <p><?php esc_html_e('Ask your AI: "List my Webruno websites", then "Show the latest posts on my WordPress site" or "Create a draft post about …".', 'webruno-crm'); ?></p>

            <h2><?php esc_html_e('Troubleshooting', 'webruno-crm'); ?></h2>
            <ul style="list-style:disc;margin-left:20px">
                <li><?php esc_html_e('The AI says it cannot read the site: turn on "Reading content" in Sync Settings.', 'webruno-crm'); ?></li>
                <li><?php esc_html_e('The AI can read but not edit: turn on "Editing posts and pages" in Sync Settings.', 'webruno-crm'); ?></li>
                <li><?php esc_html_e('Sign-in fails: make sure you use the same Webruno account that connected this site.', 'webruno-crm'); ?></li>
            </ul>
        </div>
        <?php
    }
}
