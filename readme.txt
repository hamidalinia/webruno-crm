=== Webruno CRM ===
Contributors: glboy
Tags: crm, leads, sync, woocommerce, contact form 7
Requires at least: 5.8
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Connect your site to Webruno CRM. Send leads, orders, and customers to your Webruno account without leaving wp-admin.

== Description ==

Webruno CRM connects your WordPress site to your Webruno account so you can:

* See a quick summary of synced leads, orders, and posts in wp-admin
* Automatically sync WooCommerce orders (if WooCommerce is active)
* Automatically capture Contact Form 7 / WPForms submissions as leads
* Optionally sync new posts and user registrations
* Open the full CRM panel on webruno.com or webruno.ir in one click
* Receive status updates back from Webruno via a secure webhook

All sync sources are optional and off by default except leads and orders, and every feature included in this plugin is fully usable for free — there is no paid tier or feature gate inside the plugin itself. A free Webruno account is required to receive and view synced data.

== Installation ==

1. Install and activate the plugin.
2. Go to Webruno CRM in your admin menu and click "Connect via webruno.com" or "Connect via webruno.ir" (or enter a custom domain/token manually).
3. Approve the connection on Webruno.
4. Configure what to sync under Webruno CRM > Sync Settings.

== Connect to an AI assistant (MCP) ==

After connecting, open Webruno CRM > Connect to AI. That page shows your MCP address (https://panel.webruno.com/customer/mcpCustomer, or panel.webruno.ir for the Iran server) and step-by-step instructions.

1. In Sync Settings, turn on "Reading content". Turn on "Editing posts and pages" only if you want the AI to create or edit content (both are off by default).
2. In Claude: Settings > Connectors > Add custom connector, paste the MCP address, then Connect and sign in to Webruno.
3. In ChatGPT: Settings > Connectors > enable Developer mode, create a connector with the MCP address and OAuth.
4. In Claude Code: run `claude mcp add --transport http webruno <address>`, then /mcp > Authenticate.
5. In Cursor: add {"mcpServers":{"webruno":{"url":"<address>"}}} to .cursor/mcp.json.
6. Ask your AI: "List my Webruno websites".

What the AI can do (only after you switch editing on): create drafts and edit posts, pages, categories and tags, simple and variable WooCommerce products with their variations, navigation menus and the header/footer of block themes. New items are always drafts. Publishing, trashing and changing something that is already live needs a confirmation that the AI asks you for in the chat. Permanent deletion (posts, pages, products, variations, categories, menus) is refused unless the AI sends an explicit confirmation after asking you; trashing stays the recoverable way to remove something.

== Frequently Asked Questions ==

= Do I need a Webruno account? =
Yes, a free account is required to receive synced data and use the full CRM panel.

= Does this require WooCommerce? =
No. WooCommerce order sync is optional and only activates if WooCommerce is already installed.

= Is my API token stored securely? =
Yes, it's encrypted at rest using a site-specific key derived from your WordPress salts.

== External services ==

This plugin connects to your chosen Webruno domain (webruno.com, webruno.ir, or a custom domain you provide) to send site data to your CRM account and to let you open the full CRM panel.

* What is sent: order details (WooCommerce orders, if enabled), form submissions from Contact Form 7 / WPForms (if enabled), published post titles/links/excerpts (if enabled), new user names/emails (if enabled), and — only when you manually click "Start Export" on the Bulk Sync page — your existing published posts and WooCommerce products, sent in batches.
* Why: to deliver that data into your own Webruno CRM account for you to view and act on.
* The plugin also makes a periodic read-only request to fetch a summary count (leads/orders/posts) for display in wp-admin, and opens the full CRM in a new browser tab/window when you click "Open full CRM on Webruno" (no data is exchanged via that link beyond your site ID).
* Service: Webruno (webruno.com / webruno.ir), operated by Idehweb. Terms of Service: https://webruno.com/terms — Privacy Policy: https://webruno.com/privacy

== Changelog ==

= 1.0.4 =
* Free-tier CRM dashboard: revenue chart, customer table, top products, VIP tagging, notes, and one-click customer emails
* Fixed WordPress.org plugin check errors: missing translator comments, unescaped output, unprepared SQL, direct filesystem calls
* Bumped tested-up-to and synced version numbers

= 1.0.2 =
* Enqueue bulk sync JS properly instead of inline script tag

= 1.0.0 =
* Initial release: connect flow, native wp-admin summary panel, order/lead/post/user sync, webhook receiver.
