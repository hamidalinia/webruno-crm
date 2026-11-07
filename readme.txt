=== Webruno CRM ===
Contributors: glboy
Tags: crm, mcp, ai, woocommerce, leads
Requires at least: 5.8
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Sync leads and orders to Webruno CRM and let an AI assistant (Claude, ChatGPT, Cursor) read and manage your content through MCP.

== Description ==

Webruno CRM connects your WordPress site to your Webruno account so you can:

* See a quick summary of synced leads, orders, and posts in wp-admin
* Automatically sync WooCommerce orders (if WooCommerce is active)
* Automatically capture Contact Form 7 / WPForms submissions as leads
* Optionally sync new posts and user registrations
* Open the full CRM panel on webruno.com or webruno.ir in one click
* Receive status updates back from Webruno via a secure webhook
* Connect an AI assistant to your site through MCP: it can read your posts, pages, media and WooCommerce products and, only if you allow it, create and edit content

All sync sources are optional and off by default except leads and orders. The plugin itself has no paid tier and no feature lock. It works together with a Webruno account: CRM sync needs a free account, and managing your site with an AI assistant (MCP) is free for a 14-day trial, after which it needs a paid Webruno plan. Plans and prices are on webruno.com.

= Connect to an AI assistant (MCP) =

After connecting, open Webruno CRM > Connect to AI. That page shows your MCP address (https://panel.webruno.com/customer/mcpCustomer, or panel.webruno.ir for the Iran server) and step-by-step instructions.

1. In Sync Settings, turn on "Reading content". Turn on "Editing posts and pages" only if you want the AI to create or edit content (both are off by default).
2. In Claude: Settings > Connectors > Add custom connector, paste the MCP address, then Connect and sign in to Webruno.
3. In ChatGPT: Settings > Connectors > enable Developer mode, create a connector with the MCP address and OAuth.
4. In Claude Code: run `claude mcp add --transport http webruno <address>`, then /mcp > Authenticate.
5. In Cursor: add {"mcpServers":{"webruno":{"url":"<address>"}}} to .cursor/mcp.json.
6. Ask your AI: "List my Webruno websites".

You stay in control: reading and editing are two separate switches in Webruno CRM > Sync Settings and both are off by default. The AI works with the permissions of the WordPress user who connected the site, never more. Before a change the plugin keeps a revision you can restore.
What the AI can do (only after you switch editing on): create drafts and edit posts, pages, categories and tags, simple and variable WooCommerce products with their variations, navigation menus and the header/footer of block themes. New items are always drafts. Publishing, trashing and changing something that is already live needs a confirmation that the AI asks you for in the chat. Permanent deletion (posts, pages, products, variations, categories, menus) is refused unless the AI sends an explicit confirmation after asking you; trashing stays the recoverable way to remove something.

== Installation ==

1. Install and activate the plugin.
2. Go to Webruno CRM in your admin menu and click "Connect via webruno.com" or "Connect via webruno.ir" (or enter a custom domain/token manually).
3. Approve the connection on Webruno.
4. Configure what to sync under Webruno CRM > Sync Settings.

== Frequently Asked Questions ==

= Do I need a Webruno account? =
Yes, a free account is required to receive synced data and use the full CRM panel.

= Does this require WooCommerce? =
No. WooCommerce order sync is optional and only activates if WooCommerce is already installed.

= Is the AI feature free? =
The plugin is free. Managing your site with an AI assistant is free for a 14-day trial; after that it needs a paid Webruno plan. Your site keeps working and CRM sync is not affected.

= Can the AI change my site without asking? =
No. Reading and editing are off until you switch them on. New items are drafts, and publishing or changing live content needs a confirmation in the chat. Revisions can be restored, and deleting moves items to the trash unless you explicitly confirm permanent deletion.

= Where does my content go when I use AI? =
The AI app you connect (for example Claude or ChatGPT) talks to the Webruno MCP address. Webruno then asks your site for the content the AI requested, using signed requests, and returns it to that AI app. See External services below.

= Is my API token stored securely? =
Yes, it's encrypted at rest using a site-specific key derived from your WordPress salts.

== External services ==

This plugin connects to your chosen Webruno domain (webruno.com, webruno.ir, or a custom domain you provide) to send site data to your CRM account and to let you open the full CRM panel.

* What is sent: order details (WooCommerce orders, if enabled), form submissions from Contact Form 7 / WPForms (if enabled), published post titles/links/excerpts (if enabled), new user names/emails (if enabled), and — only when you manually click "Start Export" on the Bulk Sync page — your existing published posts and WooCommerce products, sent in batches.
* Why: to deliver that data into your own Webruno CRM account for you to view and act on.
* The plugin also makes a periodic read-only request to fetch a summary count (leads/orders/posts) for display in wp-admin, and opens the full CRM in a new browser tab/window when you click "Open full CRM on Webruno" (no data is exchanged via that link beyond your site ID).
* AI assistants (MCP): if you switch on "read content" and connect an AI app to your Webruno MCP address, the Webruno server sends signed requests to your site's REST API and passes the requested content (posts, pages, media, categories, WooCommerce products and, if you allow editing, your edits) to the AI app you connected, which is a third-party service under its own terms (for example Anthropic's Claude or OpenAI's ChatGPT). Nothing is exposed while both switches are off. Requests are signed, time-limited and cannot be replayed.
* Service: Webruno (webruno.com / webruno.ir), operated by Idehweb. Terms of Service: https://webruno.com/terms — Privacy Policy: https://webruno.com/privacy

== Upgrade Notice ==

= 1.1.0 =
New: connect an AI assistant to your site through MCP. Both access switches are off by default after updating.

== Changelog ==

= 1.1.0 =
* New: AI assistant access through MCP (Claude, ChatGPT, Claude Code, Cursor) with a "Connect to AI" page that shows your MCP address and setup steps
* New: signed, read-only content API (posts, pages, categories, media, WooCommerce products); separate editing switch, both off by default
* New: AI editing of posts, pages, categories, tags, simple and variable WooCommerce products, navigation menus and block-theme header/footer, with revisions and confirmation for live changes and permanent deletes
* Security: every request is signed and protected against replay; nonce and audit tables are removed on uninstall
* Fixed: prepared SQL and nonce notes reported by Plugin Check
* Readme: clarified the free trial for AI features and the external services

= 1.0.4 =
* Free-tier CRM dashboard: revenue chart, customer table, top products, VIP tagging, notes, and one-click customer emails
* Fixed WordPress.org plugin check errors: missing translator comments, unescaped output, unprepared SQL, direct filesystem calls
* Bumped tested-up-to and synced version numbers

= 1.0.2 =
* Enqueue bulk sync JS properly instead of inline script tag

= 1.0.0 =
* Initial release: connect flow, native wp-admin summary panel, order/lead/post/user sync, webhook receiver.
