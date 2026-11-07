<?php
/**
 * Webruno_Content_API — signed, read-only access to this site's content for the Webruno service (F6a).
 *
 * This is an EXTERNAL connection that the site admin must switch on explicitly:
 *  - Nothing works until the site is connected to Webruno AND "Allow Webruno to read content" is switched on.
 *  - Every request carries an HMAC-SHA256 signature made with a secret that only this site and Webruno know.
 *  - Requests run as the WordPress user who connected the site, with exactly that user's permissions (never more).
 *  - Every request is written to a small audit log shown on the settings page.
 *
 * Signed string (identical on the Webruno side; see tests/fixtures/signing-vectors.json):
 *   METHOD "\n" ROUTE "\n" QUERY "\n" TIMESTAMP "\n" NONCE "\n" sha256hex(raw body)
 *   ROUTE  = WordPress REST route without /wp-json (e.g. /webruno/v1/content/posts), from $request->get_route()
 *   QUERY  = query params except rest_route; keys sorted bytewise; rawurlencode(k)=rawurlencode(v); joined by "&"
 *
 * F6a: GET routes (read). F6b: POST routes for posts and pages only (create as draft, update, status, restore a revision),
 * available only when the site admin switched "edit" on. No media upload, no meta, no authors. F6d: terms and WooCommerce products. F6e: variable products with variations and permanent deletes (always behind an explicit confirm_delete: true that the AI must get from the person first).
 */
if (!defined('ABSPATH')) exit;

class Webruno_Content_API {
    const REST_NS      = 'webruno/v1';
    const OPT_SECRET   = 'webruno_crm_signing_secret'; // encrypted, not autoloaded
    const OPT_USER     = 'webruno_crm_content_user_id';
    const OPT_READ     = 'webruno_crm_content_read';
    const OPT_WRITE    = 'webruno_crm_content_write';
    const OPT_DBVER    = 'webruno_crm_content_db_ver';
    const OPT_LASTREJ  = 'webruno_crm_content_last_reject';
    const DB_VER       = '1';
    const CRON_HOOK    = 'webruno_crm_prune_content_tables';
    const SECRET_RE    = '/^wsk_[A-Za-z0-9_-]{32,128}$/';
    const WINDOW       = 300;   // seconds, +/-
    const NONCE_KEEP   = 900;   // nonces older than 15 minutes are pruned (window is 5 minutes)
    const AUDIT_CAP    = 1000;  // rotating log
    const WRITE_AVAILABLE = true;  // F6b: posts and pages, F6d: terms and simple products. The site admin's "edit" switch (default OFF) still has to be on.

    private static $ctx    = null;
    private static $route  = '';
    private static $target = 0;

    public static function init() {
        add_action('rest_api_init', [__CLASS__, 'register_routes']);
        add_action('init', [__CLASS__, 'maybe_install']);
        add_action('init', [__CLASS__, 'schedule_prune']);
        add_action(self::CRON_HOOK, [__CLASS__, 'prune']);
        add_action('admin_post_webruno_crm_content_save', [__CLASS__, 'handle_save']);
    }

    /* ───────────────────────── storage ───────────────────────── */

    public static function table_nonces() { global $wpdb; return $wpdb->prefix . 'webruno_nonces'; }
    public static function table_audit()  { global $wpdb; return $wpdb->prefix . 'webruno_audit'; }

    public static function maybe_install() {
        if (get_option(self::OPT_DBVER) === self::DB_VER) return;
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();
        $n = self::table_nonces();
        $a = self::table_audit();
        dbDelta("CREATE TABLE {$n} (
            nonce_hash char(64) NOT NULL,
            created int(10) unsigned NOT NULL,
            PRIMARY KEY  (nonce_hash),
            KEY created (created)
        ) {$charset};");
        dbDelta("CREATE TABLE {$a} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            ts int(10) unsigned NOT NULL,
            op varchar(40) NOT NULL,
            route varchar(120) NOT NULL,
            target_id bigint(20) unsigned NOT NULL DEFAULT 0,
            result varchar(20) NOT NULL,
            code varchar(60) NOT NULL DEFAULT '',
            http smallint(5) unsigned NOT NULL DEFAULT 0,
            PRIMARY KEY  (id),
            KEY ts (ts)
        ) {$charset};");
        update_option(self::OPT_DBVER, self::DB_VER, false);
    }

    public static function schedule_prune() {
        if (!wp_next_scheduled(self::CRON_HOOK)) wp_schedule_event(time() + 300, 'hourly', self::CRON_HOOK);
    }

    public static function prune() {
        global $wpdb;
        $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}webruno_nonces WHERE created < %d", time() - self::NONCE_KEEP)); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table, must not be cached
    }

    /** Called by Webruno_Auth after the panel approved the connection. Consent is per connection: both switches start OFF. */
    public static function store_connection($secret, $user_id) {
        if (!is_string($secret) || !preg_match(self::SECRET_RE, $secret)) { self::clear_connection(); return false; }
        update_option(self::OPT_SECRET, Webruno_Auth::encrypt($secret), false);
        update_option(self::OPT_USER, (int) $user_id, false);
        update_option(self::OPT_READ, '0', false);
        update_option(self::OPT_WRITE, '0', false);
        self::audit_admin('connected', 'ok', 'switches_off');
        return true;
    }

    /** Disconnect / manual token / panel without secret: remove the key and switch everything off (fail closed). */
    public static function clear_connection() {
        delete_option(self::OPT_SECRET);
        delete_option(self::OPT_USER);
        update_option(self::OPT_READ, '0', false);
        update_option(self::OPT_WRITE, '0', false);
    }

    private static function get_secret() {
        $enc = get_option(self::OPT_SECRET, '');
        if (!is_string($enc) || $enc === '') return '';
        $plain = Webruno_Auth::decrypt($enc);
        return (is_string($plain) && preg_match(self::SECRET_RE, $plain)) ? $plain : '';
    }

    public static function has_key()      { return self::get_secret() !== ''; }
    public static function read_enabled() { return get_option(self::OPT_READ, '0') === '1'; }
    public static function write_enabled() { return self::WRITE_AVAILABLE && self::read_enabled() && get_option(self::OPT_WRITE, '0') === '1'; }

    /* ───────────────────────── audit log ───────────────────────── */

    private static function audit($op, $route, $target, $result, $code, $http) {
        global $wpdb;
        $prev = $wpdb->suppress_errors(true);
        $ok = $wpdb->insert(self::table_audit(), [ // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table
            'ts' => time(), 'op' => substr((string) $op, 0, 40), 'route' => substr((string) $route, 0, 120),
            'target_id' => (int) $target, 'result' => substr((string) $result, 0, 20),
            'code' => substr((string) $code, 0, 60), 'http' => (int) $http,
        ], ['%d', '%s', '%s', '%d', '%s', '%s', '%d']);
        if ($ok && $wpdb->insert_id > self::AUDIT_CAP) {
            $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}webruno_audit WHERE id <= %d", $wpdb->insert_id - self::AUDIT_CAP)); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table, must not be cached
        }
        $wpdb->suppress_errors($prev);
    }

    private static function audit_admin($op, $result, $code) {
        self::audit('admin:' . $op, '', get_current_user_id(), $result, $code, 0);
    }

    private static function log($op, $result, $code, $http) {
        self::audit($op, self::$route, self::$target, $result, $code, $http);
    }

    /** Failed authentication is logged at most once a minute so that noise cannot push real entries out of the log. */
    private static function log_rejected($reason) {
        $last = (int) get_option(self::OPT_LASTREJ, 0);
        if (time() - $last < 60) return;
        update_option(self::OPT_LASTREJ, time(), false);
        self::audit('auth', self::$route, 0, 'rejected', $reason, 401);
    }

    public static function recent_audit($limit = 20) {
        global $wpdb;
        $prev = $wpdb->suppress_errors(true);
        $rows = $wpdb->get_results($wpdb->prepare("SELECT ts, op, route, target_id, result, code, http FROM {$wpdb->prefix}webruno_audit ORDER BY id DESC LIMIT %d", (int) $limit), ARRAY_A); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table, must not be cached
        $wpdb->suppress_errors($prev);
        return is_array($rows) ? $rows : [];
    }

    /* ───────────────────────── authentication ───────────────────────── */

    /** Returns ['query' => [...]] on success or ['reason' => '...'] (internal reason, never sent to the caller). */
    private static function authenticate($request) {
        $method = strtoupper((string) $request->get_method());
        if ($method !== 'GET' && $method !== 'POST') return ['reason' => 'method'];

        $secret  = self::get_secret();
        $site_id = (string) get_option('webruno_crm_site_id', '');
        if ($secret === '' || $site_id === '') return ['reason' => 'not_connected'];

        $h_site  = (string) $request->get_header('x-webruno-site');
        $h_ts    = (string) $request->get_header('x-webruno-timestamp');
        $h_nonce = (string) $request->get_header('x-webruno-nonce');
        $h_sig   = (string) $request->get_header('x-webruno-signature');

        if (!hash_equals($site_id, $h_site)) return ['reason' => 'site_mismatch'];
        if (!preg_match('/^\d{1,12}$/', $h_ts)) return ['reason' => 'bad_timestamp'];
        if (abs(time() - (int) $h_ts) > self::WINDOW) return ['reason' => 'stale_timestamp'];
        if (!preg_match('/^[A-Za-z0-9_-]{16,64}$/', $h_nonce)) return ['reason' => 'bad_nonce'];
        if (!preg_match('/^[0-9a-f]{64}$/', $h_sig)) return ['reason' => 'bad_signature_format'];

        $body = (string) $request->get_body();
        // TODO(owner): confirm. 1 MB JSON body cap (default), adjustable with the filter.
        if (strlen($body) > (int) apply_filters('webruno_crm_content_max_body', 1048576)) return ['reason' => 'body_too_large'];

        $query = self::parse_query();
        if ($query === false) return ['reason' => 'bad_query'];

        $canonical = self::canonical($method, (string) $request->get_route(), $query, $h_ts, $h_nonce, $body);
        $expected  = hash_hmac('sha256', $canonical, $secret);
        if (!hash_equals($expected, $h_sig)) return ['reason' => 'bad_signature'];

        // Only a request with a valid signature may use up a nonce (so garbage cannot fill the table).
        if (!self::reserve_nonce($h_site, $h_nonce)) return ['reason' => 'replay'];

        return ['query' => $query];
    }

    /**
     * Raw query string, parsed by hand: $_GET renames keys (dots, spaces) and decodes "+" as a space, which could make
     * the signed value differ from the value a handler sees. Anything ambiguous is refused.
     * @return array|false
     */
    private static function parse_query() {
        $raw = isset($_SERVER['QUERY_STRING']) ? (string) wp_unslash($_SERVER['QUERY_STRING']) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
        if ($raw === '') return [];
        if (strpos($raw, '+') !== false) return false;
        $out = [];
        foreach (explode('&', $raw) as $pair) {
            if ($pair === '') continue;
            $pos = strpos($pair, '=');
            $k = rawurldecode($pos === false ? $pair : substr($pair, 0, $pos));
            $v = $pos === false ? '' : rawurldecode(substr($pair, $pos + 1));
            if ($k === '' || strpbrk($k, '[]') !== false) return false; // no array parameters
            if ($k === 'rest_route') continue;
            if (array_key_exists($k, $out)) return false;               // no repeated parameters
            $out[$k] = $v;
        }
        return $out;
    }

    /** The exact string that is signed. Public so the shared-vector test exercises the real code path. */
    public static function canonical($method, $route, array $query, $timestamp, $nonce, $body) {
        return implode("\n", [strtoupper((string) $method), (string) $route, self::canonical_query($query), (string) $timestamp, (string) $nonce, hash('sha256', (string) $body)]);
    }

    public static function sign($secret, $canonical) {
        return hash_hmac('sha256', $canonical, $secret);
    }

    private static function canonical_query(array $query) {
        ksort($query, SORT_STRING);
        $parts = [];
        foreach ($query as $k => $v) $parts[] = rawurlencode((string) $k) . '=' . rawurlencode((string) $v);
        return implode('&', $parts);
    }

    /** Atomic, persistent, independent of any object cache: a plain INSERT into a table with a primary key. Fail closed. */
    private static function reserve_nonce($site, $nonce) {
        global $wpdb;
        $prev = $wpdb->suppress_errors(true);
        $res = $wpdb->query($wpdb->prepare("INSERT INTO {$wpdb->prefix}webruno_nonces (nonce_hash, created) VALUES (%s, %d)", hash('sha256', $site . "\n" . $nonce), time())); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table, must not be cached
        $wpdb->suppress_errors($prev);
        return $res === 1;
    }

    private static function over_rate_limit() {
        global $wpdb;
        // TODO(owner): confirm. Default 60 requests per minute per site (adjustable with the filter; 0 = no limit).
        $limit = (int) apply_filters('webruno_crm_content_rate_limit', 60);
        if ($limit <= 0) return false;
        $prev = $wpdb->suppress_errors(true);
        $n = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}webruno_audit WHERE ts >= %d AND result <> %s", time() - 60, 'rejected')); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table, must not be cached
        $wpdb->suppress_errors($prev);
        return (int) $n >= $limit;
    }

    private static function err($code, $status, $message) {
        return new WP_Error($code, $message, ['status' => $status]);
    }

    /** permission_callback for every route: authenticate, rate limit, switch, user. The handler runs only after this. */
    public static function permit($request) { return self::gate($request, false); }

    /** Same as permit(), and the site admin's "edit" switch must be on too. */
    public static function permit_write($request) { return self::gate($request, true); }

    private static function gate($request, $need_write) {
        self::$ctx = null;
        self::$target = 0;
        self::$route = (string) $request->get_route();

        $auth = self::authenticate($request);
        if (isset($auth['reason'])) {
            self::log_rejected($auth['reason']);
            // Same answer for every kind of failure: nothing about the reason is revealed.
            return self::err('webruno_unauthorized', 401, __('Request not accepted.', 'webruno-crm'));
        }

        if (self::over_rate_limit()) {
            self::log('request', 'denied', 'rate_limited', 429);
            return self::err('webruno_rate_limited', 429, __('Too many requests. Try again in a minute.', 'webruno-crm'));
        }
        if (!self::read_enabled()) {
            self::log('request', 'denied', 'read_disabled', 403);
            return self::err('webruno_read_disabled', 403, __('The site admin has not allowed Webruno to read content (WordPress > Webruno CRM > Sync Settings).', 'webruno-crm'));
        }
        if ($need_write && !self::write_enabled()) {
            self::log('request', 'denied', 'write_disabled', 403);
            return self::err('webruno_write_disabled', 403, __('The site admin has not allowed Webruno to edit content (WordPress > Webruno CRM > Sync Settings).', 'webruno-crm'));
        }
        $uid  = (int) get_option(self::OPT_USER, 0);
        $user = $uid > 0 ? get_userdata($uid) : false;
        if (!$user) {
            self::log('request', 'denied', 'user_unavailable', 403);
            return self::err('webruno_user_unavailable', 403, __('The WordPress user who connected this site no longer exists. Reconnect the plugin.', 'webruno-crm'));
        }
        wp_set_current_user($uid); // everything below runs with exactly this user's capabilities
        self::$ctx = ['uid' => $uid, 'query' => $auth['query']];
        return true;
    }

    private static function respond($op, $fn) {
        $out = $fn(self::$ctx['query']);
        if (is_wp_error($out)) {
            $data = $out->get_error_data();
            $st = (is_array($data) && isset($data['status'])) ? (int) $data['status'] : 500;
            self::log($op, in_array($st, [401, 403, 429], true) ? 'denied' : 'error', $out->get_error_code(), $st);
            return $out;
        }
        self::log($op, 'ok', '', 200);
        $resp = rest_ensure_response($out);
        $resp->header('Cache-Control', 'no-store');
        return $resp;
    }

    /* ───────────────────────── routes ───────────────────────── */

    public static function register_routes() {
        $routes = [
            '/content/site'                      => 'route_site',
            '/content/posts'                     => 'route_posts',
            '/content/posts/(?P<id>\d+)'         => 'route_post',
            '/content/terms'                     => 'route_terms',
            '/content/media'                     => 'route_media',
            '/content/products'                  => 'route_products',
            '/content/products/(?P<id>\d+)'      => 'route_product',
        ];
        foreach ($routes as $path => $cb) {
            // GET only. Parameters are validated inside the handlers AFTER authentication, so strangers learn nothing
            // from WordPress' own parameter errors.
            register_rest_route(self::REST_NS, $path, [
                'methods'             => 'GET',
                'callback'            => [__CLASS__, $cb],
                'permission_callback' => [__CLASS__, 'permit'],
            ]);
        }

        // F6b write routes. Same signature scheme; additionally need the admin's "edit" switch.
        $w = function ($path, $method, $cb, $perm) {
            register_rest_route(self::REST_NS, $path, ['methods' => $method, 'callback' => [__CLASS__, $cb], 'permission_callback' => [__CLASS__, $perm]]);
        };
        $w('/content/posts', 'POST', 'route_create_post', 'permit_write');
        $w('/content/posts/(?P<id>\d+)', 'POST', 'route_update_post', 'permit_write');
        $w('/content/posts/(?P<id>\d+)/status', 'POST', 'route_set_status', 'permit_write');
        $w('/content/posts/(?P<id>\d+)/revisions', 'GET', 'route_list_revisions', 'permit');
        $w('/content/posts/(?P<id>\d+)/revisions/(?P<rev>\d+)/restore', 'POST', 'route_restore_revision', 'permit_write');
        // F6d: terms (create / rename) and WooCommerce simple products (draft create, edit with undo, status).
        $w('/content/terms', 'POST', 'route_create_term', 'permit_write');
        $w('/content/terms/(?P<id>\\d+)', 'POST', 'route_update_term', 'permit_write');
        $w('/content/products', 'POST', 'route_create_product', 'permit_write');
        $w('/content/products/(?P<id>\\d+)', 'POST', 'route_update_product', 'permit_write');
        $w('/content/products/(?P<id>\\d+)/restore', 'POST', 'route_restore_product', 'permit_write');
        $w('/content/products/(?P<id>\\d+)/status', 'POST', 'route_set_product_status', 'permit_write');
        // F6e: variations of variable products and permanent deletes (every delete needs confirm_delete: true).
        $w('/content/products/(?P<id>\d+)/variations', 'GET', 'route_variations', 'permit');
        $w('/content/products/(?P<id>\d+)/variations', 'POST', 'route_create_variation', 'permit_write');
        $w('/content/products/(?P<id>\d+)/variations/(?P<vid>\d+)', 'POST', 'route_update_variation', 'permit_write');
        $w('/content/products/(?P<id>\d+)/variations/(?P<vid>\d+)/delete', 'POST', 'route_delete_variation', 'permit_write');
        $w('/content/products/(?P<id>\d+)/delete', 'POST', 'route_delete_product', 'permit_write');
        $w('/content/posts/(?P<id>\d+)/delete', 'POST', 'route_delete_post', 'permit_write');
        $w('/content/terms/(?P<id>\d+)/delete', 'POST', 'route_delete_term', 'permit_write');
        $w('/content/menus/(?P<id>\d+)/delete', 'POST', 'route_delete_menu', 'permit_write');
        // F6c: classic menus, block navigations and header/footer parts of block themes.
        $w('/content/menus', 'GET', 'route_menus', 'permit');
        $w('/content/menus/(?P<id>\d+)', 'GET', 'route_menu', 'permit');
        $w('/content/menus/(?P<id>\d+)/items', 'POST', 'route_set_menu_items', 'permit_write');
        $w('/content/menus/(?P<id>\d+)/restore', 'POST', 'route_restore_menu', 'permit_write');
        $w('/content/template-parts', 'GET', 'route_parts', 'permit');
        $w('/content/template-parts/(?P<slug>[A-Za-z0-9_-]{1,100})/customize', 'POST', 'route_part_customize', 'permit_write');
    }

    /* ───────────────────────── helpers ───────────────────────── */

    /** null = absent, false = present but not allowed, string = ok */
    private static function enum($q, $key, array $allowed) {
        if (!isset($q[$key]) || $q[$key] === '') return null;
        return in_array($q[$key], $allowed, true) ? $q[$key] : false;
    }

    private static function paging($q) {
        $page = isset($q['page']) ? (int) $q['page'] : 1;
        $per  = isset($q['per_page']) ? (int) $q['per_page'] : 20;
        return [max(1, min(1000, $page)), max(1, min(50, $per))];
    }

    private static function search_term($q) {
        if (!isset($q['search']) || $q['search'] === '') return '';
        return sanitize_text_field(mb_substr((string) $q['search'], 0, 100));
    }

    /**
     * The "last modified" time of a post, in the ISO form that reads return and writes must send back (expected_modified_gmt).
     * WordPress leaves post_modified_gmt at '0000-00-00 00:00:00' for a brand-new draft (its date is "floating"), so in that
     * case it is derived from post_modified (site time). Reads and the conflict check both use this, so they always agree.
     */
    private static function modified_iso($p) {
        $v = self::iso($p->post_modified_gmt);
        if ($v) return $v;
        $local = (string) $p->post_modified;
        if ($local === '' || strpos($local, '0000') === 0) return null;
        return self::iso(get_gmt_from_date($local));
    }

    private static function iso($mysql_gmt) {
        if (!is_string($mysql_gmt) || $mysql_gmt === '' || strpos($mysql_gmt, '0000') === 0) return null;
        $t = strtotime($mysql_gmt . ' UTC');
        return $t ? gmdate('Y-m-d\TH:i:s\Z', $t) : null;
    }

    private static function readable_statuses($pt) {
        $s = ['publish'];
        if (current_user_can($pt->cap->read_private_posts)) $s[] = 'private';
        if (current_user_can($pt->cap->edit_others_posts)) { $s[] = 'draft'; $s[] = 'pending'; $s[] = 'future'; }
        return $s;
    }

    private static function page_result(array $items, $total, $page, $per) {
        return ['items' => $items, 'total' => (int) $total, 'page' => (int) $page, 'per_page' => (int) $per];
    }

    private static function post_summary($p) {
        $hidden = post_password_required($p) && !current_user_can('edit_post', $p->ID);
        $src = $p->post_excerpt !== '' ? $p->post_excerpt : $p->post_content;
        $author = get_userdata((int) $p->post_author);
        return [
            'id' => (int) $p->ID, 'type' => $p->post_type, 'status' => $p->post_status,
            'title' => wp_strip_all_tags($p->post_title), 'slug' => $p->post_name,
            'excerpt' => $hidden ? '' : wp_trim_words(wp_strip_all_tags(strip_shortcodes($src)), 55),
            'password_protected' => (bool) ($p->post_password !== ''),
            'date_gmt' => self::iso($p->post_date_gmt), 'modified_gmt' => self::modified_iso($p),
            'author' => $author ? $author->display_name : null,
            'parent' => (int) $p->post_parent,
            'permalink' => get_permalink($p),
            'edit_url' => get_edit_post_link($p->ID, 'raw'),
        ];
    }

    private static function allowed_meta($id) {
        // TODO(owner): which meta keys may Webruno read? Empty by default; keys are added with this filter only.
        $keys = (array) apply_filters('webruno_crm_content_meta_allowlist', []);
        $out = [];
        foreach ($keys as $k) {
            if (is_string($k) && $k !== '' && metadata_exists('post', $id, $k)) {
                $v = get_post_meta($id, $k, true);
                $out[$k] = is_scalar($v) ? (string) $v : null;
            }
        }
        return (object) $out;
    }

    /* ───────────────────────── handlers (read only) ───────────────────────── */

    public static function route_site($request) {
        return self::respond('site', function ($q) {
            global $wp_version;
            $user = wp_get_current_user();
            $posts = wp_count_posts('post');
            $pages = wp_count_posts('page');
            return [
                'plugin_version' => defined('WEBRUNO_CRM_VERSION') ? WEBRUNO_CRM_VERSION : '',
                'wp_version' => $wp_version,
                'name' => wp_strip_all_tags(get_bloginfo('name')),
                'url' => home_url('/'),
                'language' => get_locale(),
                'timezone' => function_exists('wp_timezone_string') ? wp_timezone_string() : (string) get_option('timezone_string'),
                'woocommerce' => class_exists('WooCommerce') ? ['active' => true, 'version' => defined('WC_VERSION') ? WC_VERSION : ''] : ['active' => false],
                'published' => ['posts' => (int) $posts->publish, 'pages' => (int) $pages->publish],
                'connected_user' => ['id' => (int) $user->ID, 'name' => $user->display_name, 'roles' => array_values((array) $user->roles)],
                'content_access' => ['read' => true, 'write' => (self::WRITE_AVAILABLE && get_option(self::OPT_WRITE, '0') === '1')],
            ];
        });
    }

    public static function route_posts($request) {
        return self::respond('posts', function ($q) {
            $type = self::enum($q, 'post_type', ['post', 'page']);
            if ($type === false) return self::err('webruno_bad_request', 400, __('post_type must be post or page.', 'webruno-crm'));
            $type = $type ?: 'post';
            $pt = get_post_type_object($type);
            if (!$pt || !current_user_can($pt->cap->edit_posts)) return self::err('webruno_forbidden', 403, __('The connected WordPress user may not list this content.', 'webruno-crm'));

            $statuses = self::readable_statuses($pt);
            $want = self::enum($q, 'status', ['publish', 'draft', 'pending', 'private', 'future']);
            if ($want === false) return self::err('webruno_bad_request', 400, __('Unknown status.', 'webruno-crm'));
            if ($want !== null) $statuses = in_array($want, $statuses, true) ? [$want] : [];

            list($page, $per) = self::paging($q);
            if (!$statuses) return self::page_result([], 0, $page, $per);

            $args = ['post_type' => $type, 'post_status' => $statuses, 'posts_per_page' => $per, 'paged' => $page,
                     'orderby' => 'modified', 'order' => 'DESC', 'ignore_sticky_posts' => true];
            $s = self::search_term($q);
            if ($s !== '') $args['s'] = $s;
            $query = new WP_Query($args);

            $items = [];
            foreach ($query->posts as $p) {
                if (!current_user_can('read_post', $p->ID)) continue; // belt and braces
                $items[] = self::post_summary($p);
            }
            return self::page_result($items, $query->found_posts, $page, $per);
        });
    }

    public static function route_post($request) {
        return self::respond('post', function ($q) use ($request) {
            $params = $request->get_url_params();
            $id = isset($params['id']) ? (int) $params['id'] : 0;
            self::$target = $id;
            $p = $id > 0 ? get_post($id) : null;
            if (!$p || !in_array($p->post_type, array_merge(['post', 'page'], self::TYPES_BLOCK), true)) return self::err('webruno_not_found', 404, __('Not found.', 'webruno-crm'));
            if (!current_user_can('read_post', $id)) return self::err('webruno_forbidden', 403, __('The connected WordPress user may not read this item.', 'webruno-crm'));

            $out = self::post_summary($p);
            $hidden = post_password_required($p) && !current_user_can('edit_post', $id);
            $max = (int) apply_filters('webruno_crm_content_max_chars', 200000);
            $content = $hidden ? '' : (string) $p->post_content;
            $out['content'] = mb_substr($content, 0, $max);
            $out['content_truncated'] = mb_strlen($content) > $max;
            $out['categories'] = [];
            $out['tags'] = [];
            if ($p->post_type === 'post') {
                foreach ((array) wp_get_post_terms($id, 'category') as $t) { if (!is_wp_error($t)) $out['categories'][] = ['id' => (int) $t->term_id, 'name' => $t->name]; }
                foreach ((array) wp_get_post_terms($id, 'post_tag') as $t) { if (!is_wp_error($t)) $out['tags'][] = ['id' => (int) $t->term_id, 'name' => $t->name]; }
            }
            $out['meta'] = self::allowed_meta($id);
            return $out;
        });
    }

    public static function route_terms($request) {
        return self::respond('terms', function ($q) {
            $tax = self::enum($q, 'taxonomy', ['category', 'post_tag', 'product_cat', 'product_tag']);
            if ($tax === false) return self::err('webruno_bad_request', 400, __('Unknown taxonomy.', 'webruno-crm'));
            $tax = $tax ?: 'category';
            $obj = taxonomy_exists($tax) ? get_taxonomy($tax) : null;
            if (!$obj) return self::err('webruno_not_found', 404, __('Not found.', 'webruno-crm'));
            if (!current_user_can($obj->cap->assign_terms)) return self::err('webruno_forbidden', 403, __('The connected WordPress user may not list these terms.', 'webruno-crm'));

            list($page, $per) = self::paging($q);
            $args = ['taxonomy' => $tax, 'hide_empty' => false, 'number' => $per, 'offset' => ($page - 1) * $per, 'orderby' => 'name'];
            $s = self::search_term($q);
            if ($s !== '') $args['search'] = $s;
            $terms = get_terms($args);
            if (is_wp_error($terms)) return self::err('webruno_error', 500, __('Could not read terms.', 'webruno-crm'));
            $total = wp_count_terms(['taxonomy' => $tax, 'hide_empty' => false, 'search' => $s]);

            $items = [];
            foreach ($terms as $t) {
                $items[] = ['id' => (int) $t->term_id, 'name' => $t->name, 'slug' => $t->slug, 'taxonomy' => $t->taxonomy,
                            'parent' => (int) $t->parent, 'count' => (int) $t->count, 'description' => wp_strip_all_tags($t->description),
                            'version' => self::term_version($t)]; // needed as expected_version to rename or delete the term
            }
            return self::page_result($items, is_wp_error($total) ? count($items) : (int) $total, $page, $per);
        });
    }

    public static function route_media($request) {
        return self::respond('media', function ($q) {
            if (!current_user_can('upload_files')) return self::err('webruno_forbidden', 403, __('The connected WordPress user may not list media.', 'webruno-crm'));
            $all = ['image/jpeg', 'image/png', 'image/webp', 'image/gif', 'application/pdf'];
            $m = self::enum($q, 'mime', ['image', 'application/pdf']);
            if ($m === false) return self::err('webruno_bad_request', 400, __('mime must be image or application/pdf.', 'webruno-crm'));
            $mimes = $m === 'image' ? ['image/jpeg', 'image/png', 'image/webp', 'image/gif'] : ($m === 'application/pdf' ? ['application/pdf'] : $all);

            list($page, $per) = self::paging($q);
            $args = ['post_type' => 'attachment', 'post_status' => 'inherit', 'post_mime_type' => $mimes, 'posts_per_page' => $per,
                     'paged' => $page, 'orderby' => 'date', 'order' => 'DESC'];
            $s = self::search_term($q);
            if ($s !== '') $args['s'] = $s;
            $query = new WP_Query($args);

            $items = [];
            foreach ($query->posts as $p) {
                if (!current_user_can('read_post', $p->ID)) continue;
                $meta = wp_get_attachment_metadata($p->ID);
                $items[] = [
                    'id' => (int) $p->ID, 'title' => wp_strip_all_tags($p->post_title), 'mime' => $p->post_mime_type,
                    'url' => wp_get_attachment_url($p->ID),
                    'alt' => wp_strip_all_tags((string) get_post_meta($p->ID, '_wp_attachment_image_alt', true)),
                    'width' => is_array($meta) && isset($meta['width']) ? (int) $meta['width'] : null,
                    'height' => is_array($meta) && isset($meta['height']) ? (int) $meta['height'] : null,
                    'filesize' => is_array($meta) && isset($meta['filesize']) ? (int) $meta['filesize'] : null,
                    'date_gmt' => self::iso($p->post_date_gmt), 'parent' => (int) $p->post_parent,
                    'edit_url' => get_edit_post_link($p->ID, 'raw'),
                ];
            }
            return self::page_result($items, $query->found_posts, $page, $per);
        });
    }

    private static function wc_ready() {
        return class_exists('WooCommerce') && function_exists('wc_get_product');
    }

    private static function product_summary($p, $product) {
        $cats = [];
        foreach ((array) wp_get_post_terms($p->ID, 'product_cat') as $t) { if (!is_wp_error($t)) $cats[] = ['id' => (int) $t->term_id, 'name' => $t->name]; }
        $img = $product->get_image_id();
        return [
            'id' => (int) $p->ID, 'name' => wp_strip_all_tags($product->get_name()), 'slug' => $p->post_name,
            'type' => $product->get_type(), 'status' => $p->post_status, 'sku' => (string) $product->get_sku(),
            'price' => (string) $product->get_price(), 'regular_price' => (string) $product->get_regular_price(),
            'sale_price' => (string) $product->get_sale_price(), 'on_sale' => (bool) $product->is_on_sale(),
            'stock_status' => (string) $product->get_stock_status(), 'manage_stock' => (bool) $product->get_manage_stock(),
            'stock_quantity' => $product->get_stock_quantity(),
            'categories' => $cats,
            'image_url' => $img ? wp_get_attachment_url($img) : null,
            'modified_gmt' => self::modified_iso($p),
            'permalink' => get_permalink($p), 'edit_url' => get_edit_post_link($p->ID, 'raw'),
        ];
    }

    public static function route_products($request) {
        return self::respond('products', function ($q) {
            if (!self::wc_ready()) return self::err('webruno_woocommerce_missing', 404, __('WooCommerce is not active on this site.', 'webruno-crm'));
            $pt = get_post_type_object('product');
            if (!$pt || !current_user_can($pt->cap->edit_posts)) return self::err('webruno_forbidden', 403, __('The connected WordPress user may not list products.', 'webruno-crm'));

            $statuses = self::readable_statuses($pt);
            $want = self::enum($q, 'status', ['publish', 'draft', 'pending', 'private']);
            if ($want === false) return self::err('webruno_bad_request', 400, __('Unknown status.', 'webruno-crm'));
            if ($want !== null) $statuses = in_array($want, $statuses, true) ? [$want] : [];

            list($page, $per) = self::paging($q);
            if (!$statuses) return self::page_result([], 0, $page, $per);
            $args = ['post_type' => 'product', 'post_status' => $statuses, 'posts_per_page' => $per, 'paged' => $page,
                     'orderby' => 'modified', 'order' => 'DESC'];
            $s = self::search_term($q);
            if ($s !== '') $args['s'] = $s;
            $query = new WP_Query($args);

            $items = [];
            foreach ($query->posts as $p) {
                if (!current_user_can('read_post', $p->ID)) continue;
                $product = wc_get_product($p->ID);
                if ($product) $items[] = self::product_summary($p, $product);
            }
            return self::page_result($items, $query->found_posts, $page, $per);
        });
    }

    public static function route_product($request) {
        return self::respond('product', function ($q) use ($request) {
            if (!self::wc_ready()) return self::err('webruno_woocommerce_missing', 404, __('WooCommerce is not active on this site.', 'webruno-crm'));
            $params = $request->get_url_params();
            $id = isset($params['id']) ? (int) $params['id'] : 0;
            self::$target = $id;
            $p = $id > 0 ? get_post($id) : null;
            if (!$p || $p->post_type !== 'product') return self::err('webruno_not_found', 404, __('Not found.', 'webruno-crm'));
            if (!current_user_can('read_post', $id)) return self::err('webruno_forbidden', 403, __('The connected WordPress user may not read this product.', 'webruno-crm'));
            $product = wc_get_product($id);
            if (!$product) return self::err('webruno_not_found', 404, __('Not found.', 'webruno-crm'));

            $out = self::product_summary($p, $product);
            $out['short_description'] = wp_kses_post($product->get_short_description());
            $out['description'] = wp_kses_post($product->get_description());
            $attrs = [];
            foreach ($product->get_attributes() as $key => $attr) {
                if (is_object($attr) && method_exists($attr, 'get_visible') && !$attr->get_visible()) continue;
                $attrs[] = ['name' => wc_attribute_label($key), 'value' => $product->get_attribute($key)];
            }
            $out['attributes'] = $attrs;
            $out['variations'] = method_exists($product, 'get_children') ? count((array) $product->get_children()) : 0;
            return array_merge($out, self::variable_extras($product));
        });
    }

    /* ───────────────────────── write routes (F6b): posts and pages only ───────────────────────── */

    /** Reads and validates the JSON body of a write request. Unknown fields are refused. */
    private static function json_body($request, array $allowed) {
        $ct = (string) $request->get_header('content-type');
        if (stripos($ct, 'application/json') !== 0) return self::err('webruno_bad_request', 415, __('The request body must be JSON (Content-Type: application/json).', 'webruno-crm'));
        $data = json_decode((string) $request->get_body(), true);
        if (!is_array($data) || ($data && array_values($data) === $data)) return self::err('webruno_bad_request', 400, __('The request body must be a JSON object.', 'webruno-crm'));
        foreach (array_keys($data) as $k) {
            if (!in_array($k, $allowed, true)) {
                /* translators: %s: field name */
                return self::err('webruno_bad_request', 400, sprintf(__('Unknown field: %s', 'webruno-crm'), sanitize_key((string) $k)));
            }
        }
        return $data;
    }

    private static function max_chars() {
        // TODO(owner): confirm. Default 200000 characters of content per write (filter: webruno_crm_content_max_chars).
        return (int) apply_filters('webruno_crm_content_max_chars', 200000);
    }

    /** Output filter for everything Webruno writes: no scripts, no iframes, even for administrators. Gutenberg block comments survive. */
    private static function clean_content($content) {
        // wp_kses_post() removes the tags but keeps what was inside them as visible text ("alert(1)"). Drop those elements whole first.
        $content = preg_replace('#<(script|style|iframe|object|embed|noscript)\b[^>]*>.*?</\1\s*>#is', '', (string) $content);
        // an unclosed opener would still leave its payload behind: remove it up to the end
        $content = preg_replace('#<(script|style|iframe|object|embed|noscript)\b.*$#is', '', (string) $content);
        return wp_kses_post((string) $content);
    }

    private static function block_count($content) {
        $n = 0;
        foreach (parse_blocks((string) $content) as $b) { if (!empty($b['blockName'])) $n++; }
        return $n;
    }

    /** Validates title / content / excerpt / slug that are present in $data. Returns array of wp_insert_post fields or WP_Error. */
    private static function text_fields(array $data, $block = false) {
        $out = [];
        if (array_key_exists('title', $data)) {
            if (!is_string($data['title']) || trim($data['title']) === '' || mb_strlen($data['title']) > 300) return self::err('webruno_bad_request', 400, __('title must be a non-empty text of at most 300 characters.', 'webruno-crm'));
            $out['post_title'] = sanitize_text_field($data['title']);
        }
        if (array_key_exists('content', $data)) {
            if (!is_string($data['content']) || mb_strlen($data['content']) > self::max_chars()) return self::err('webruno_too_large', 413, __('content must be text within the size limit.', 'webruno-crm'));
            $out['post_content'] = $block ? self::clean_block_content($data['content']) : self::clean_content($data['content']);
        }
        if (array_key_exists('excerpt', $data)) {
            if (!is_string($data['excerpt']) || mb_strlen($data['excerpt']) > 1000) return self::err('webruno_bad_request', 400, __('excerpt must be text of at most 1000 characters.', 'webruno-crm'));
            $out['post_excerpt'] = sanitize_textarea_field($data['excerpt']);
        }
        if (array_key_exists('slug', $data)) {
            if (!is_string($data['slug']) || mb_strlen($data['slug']) > 200) return self::err('webruno_bad_request', 400, __('slug must be text of at most 200 characters.', 'webruno-crm'));
            $out['post_name'] = sanitize_title($data['slug']);
        }
        return $out;
    }

    /** Optimistic concurrency: the caller must send the modified time it last read. */
    private static function check_expected($p, array $data) {
        $e = isset($data['expected_modified_gmt']) ? $data['expected_modified_gmt'] : null;
        if (!is_string($e) || !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $e)) {
            return self::err('webruno_bad_request', 400, __('expected_modified_gmt (the modified_gmt you got when you last read this item) is required.', 'webruno-crm'));
        }
        $cur = self::modified_iso($p);
        if ($cur !== $e) {
            /* translators: %s: ISO date */
            return self::err('webruno_conflict', 409, sprintf(__('This item was changed on the site after you read it (it is now modified %s). Read it again before editing.', 'webruno-crm'), (string) $cur));
        }
        return true;
    }

    /** Loads a post or page for writing and checks the connected user's capability. */
    private static function writable_post($request, $cap = 'edit_post', $blocks = true) {
        $params = $request->get_url_params();
        $id = isset($params['id']) ? (int) $params['id'] : 0;
        self::$target = $id;
        $p = $id > 0 ? get_post($id) : null;
        $types = $blocks ? array_merge(['post', 'page'], self::TYPES_BLOCK) : ['post', 'page'];
        if (!$p || !in_array($p->post_type, $types, true)) return self::err('webruno_not_found', 404, __('Not found.', 'webruno-crm'));
        if (!current_user_can($cap, $id)) return self::err('webruno_forbidden', 403, __('The connected WordPress user may not change this item.', 'webruno-crm'));
        return $p;
    }

    private static function live_status($p) { return in_array($p->post_status, ['publish', 'private', 'future'], true); }

    private static function require_live_confirmation($p, array $data) {
        if (self::live_status($p) && (!isset($data['confirm_live']) || $data['confirm_live'] !== true)) {
            return self::err('webruno_confirm_live_required', 400, __('This item is live on the site. Ask the person to confirm the change, then send confirm_live: true.', 'webruno-crm'));
        }
        return true;
    }

    /** Saves the current state as a revision so the change can be undone. Returns the revision id or false (writes are refused then). */
    private static function snapshot($p) {
        if (!post_type_supports($p->post_type, 'revisions') || (int) wp_revisions_to_keep($p) === 0) return false;
        wp_save_post_revision($p->ID);
        $revs = wp_get_post_revisions($p->ID, ['posts_per_page' => 1, 'order' => 'DESC', 'orderby' => 'date ID', 'check_enabled' => false]);
        $latest = $revs ? reset($revs) : null;
        return $latest ? (int) $latest->ID : false;
    }

    private static function revisions_off_error() {
        return self::err('webruno_revisions_disabled', 409, __('Revisions are turned off for this content on the site, so changes could not be undone. Webruno does not edit without revisions.', 'webruno-crm'));
    }

    private static function write_result($p, array $extra = []) {
        $p = get_post($p->ID);
        $out = self::post_summary($p);
        $out['block_count'] = self::block_count($p->post_content);
        return array_merge($out, self::post_terms_out($p), $extra);
    }

    public static function route_create_post($request) {
        return self::respond('create_post', function ($q) use ($request) {
            $data = self::json_body($request, ['post_type', 'title', 'content', 'excerpt', 'slug', 'status', 'category_ids', 'tag_ids']);
            if (is_wp_error($data)) return $data;
            if (isset($data['status'])) return self::err('webruno_bad_request', 400, __('New items are always created as drafts. Publish with the status route after the person confirmed.', 'webruno-crm'));
            $type = isset($data['post_type']) ? $data['post_type'] : 'post';
            if (!in_array($type, ['post', 'page'], true)) return self::err('webruno_bad_request', 400, __('post_type must be post or page.', 'webruno-crm'));
            $pt = get_post_type_object($type);
            if (!$pt || !current_user_can($pt->cap->create_posts)) return self::err('webruno_forbidden', 403, __('The connected WordPress user may not create this content.', 'webruno-crm'));
            if (!isset($data['title'])) return self::err('webruno_bad_request', 400, __('title is required.', 'webruno-crm'));
            $f = self::text_fields($data);
            if (is_wp_error($f)) return $f;
            $terms = self::post_term_input($data, $type);
            if (is_wp_error($terms)) return $terms;
            $f['post_type']   = $type;
            $f['post_status'] = 'draft';
            $f['post_author'] = get_current_user_id();
            $id = wp_insert_post(wp_slash($f), true);
            if (is_wp_error($id) || !$id) return self::err('webruno_error', 500, __('Could not create the item.', 'webruno-crm'));
            self::$target = (int) $id;
            foreach ($terms as $tax => $ids) wp_set_object_terms((int) $id, $ids, $tax, false);
            return self::write_result(get_post($id), ['created' => true]);
        });
    }

    public static function route_update_post($request) {
        return self::respond('update_post', function ($q) use ($request) {
            $p = self::writable_post($request);
            if (is_wp_error($p)) return $p;
            $data = self::json_body($request, ['expected_modified_gmt', 'title', 'content', 'excerpt', 'slug', 'confirm_live', 'category_ids', 'tag_ids']);
            if (is_wp_error($data)) return $data;
            if (isset($data['slug']) && in_array($p->post_type, self::TYPES_BLOCK, true)) return self::err('webruno_bad_request', 400, __('The slug of a header, footer or navigation cannot be changed.', 'webruno-crm'));
            if ($p->post_status === 'trash') return self::err('webruno_conflict', 409, __('This item is in the trash. Restore it first (status route).', 'webruno-crm'));
            $ok = self::check_expected($p, $data);
            if (is_wp_error($ok)) return $ok;
            $ok = self::require_live_confirmation($p, $data);
            if (is_wp_error($ok)) return $ok;
            $f = self::text_fields($data, in_array($p->post_type, self::TYPES_BLOCK, true));
            if (is_wp_error($f)) return $f;
            $terms = self::post_term_input($data, $p->post_type);
            if (is_wp_error($terms)) return $terms;
            if (!$f && !$terms) return self::err('webruno_bad_request', 400, __('Nothing to change: send title, content, excerpt, slug, category_ids or tag_ids.', 'webruno-crm'));

            $before = self::snapshot($p);
            if (!$before) return self::revisions_off_error();
            $previous_terms = $terms ? self::post_terms_out($p) : [];
            if ($f) {
                $f['ID'] = $p->ID;
                $res = wp_update_post(wp_slash($f), true);
                if (is_wp_error($res) || !$res) return self::err('webruno_error', 500, __('Could not update the item.', 'webruno-crm'));
            }
            foreach ($terms as $tax => $ids) wp_set_object_terms((int) $p->ID, $ids, $tax, false);
            // categories and tags are not part of a revision: the previous ones are returned so they can be set back by hand
            return self::write_result($p, ['revision_before' => $before] + ($terms ? ['previous_terms' => $previous_terms] : []));
        });
    }

    public static function route_set_status($request) {
        return self::respond('set_status', function ($q) use ($request) {
            $p = self::writable_post($request, 'edit_post', false);
            if (is_wp_error($p)) return $p;
            return self::apply_status($request, $p);
        });
    }

    /** draft / pending / publish / trash for a post, page or product (the caller already checked the item and edit_post). */
    private static function apply_status($request, $p) {
        $data = self::json_body($request, ['status', 'expected_modified_gmt', 'confirm']);
        if (is_wp_error($data)) return $data;
        $target = isset($data['status']) ? $data['status'] : null;
        if (!in_array($target, ['draft', 'pending', 'publish', 'trash'], true)) return self::err('webruno_bad_request', 400, __('status must be draft, pending, publish or trash.', 'webruno-crm'));
        $ok = self::check_expected($p, $data);
        if (is_wp_error($ok)) return $ok;
        // Publishing, trashing, or taking a live item offline (live -> draft/pending) all change what visitors see.
        $changes_visibility = in_array($target, ['publish', 'trash'], true) || (self::live_status($p) && $p->post_status !== $target);
        if ($changes_visibility && (!isset($data['confirm']) || $data['confirm'] !== true)) {
            return self::err('webruno_confirm_required', 400, __('Publishing or trashing needs the person\'s confirmation. Ask them, then send confirm: true.', 'webruno-crm'));
        }
        $pt = get_post_type_object($p->post_type);
        if ($target === 'publish' && !current_user_can($pt->cap->publish_posts)) return self::err('webruno_forbidden', 403, __('The connected WordPress user may not publish this content.', 'webruno-crm'));
        if ($target === 'trash' && !current_user_can('delete_post', $p->ID)) return self::err('webruno_forbidden', 403, __('The connected WordPress user may not trash this item.', 'webruno-crm'));
        $prev_status = $p->post_status;
        if ($prev_status === $target) return self::write_result($p, ['unchanged' => true]);

        if ($p->post_status === 'trash') {
            wp_untrash_post($p->ID);
            $p = get_post($p->ID);
        }
        if ($target === 'trash') {
            if (!wp_trash_post($p->ID)) return self::err('webruno_error', 500, __('Could not trash the item.', 'webruno-crm'));
        } elseif ($p->post_status !== $target) {
            $res = wp_update_post(['ID' => $p->ID, 'post_status' => $target], true);
            if (is_wp_error($res) || !$res) return self::err('webruno_error', 500, __('Could not change the status.', 'webruno-crm'));
        }
        return self::write_result($p, ['previous_status' => $prev_status]);
    }

    public static function route_list_revisions($request) {
        return self::respond('list_revisions', function ($q) use ($request) {
            $p = self::writable_post($request);
            if (is_wp_error($p)) return $p;
            $items = [];
            foreach (wp_get_post_revisions($p->ID, ['posts_per_page' => 20, 'order' => 'DESC', 'orderby' => 'date ID', 'check_enabled' => false]) as $r) {
                $a = get_userdata((int) $r->post_author);
                $items[] = [
                    'id' => (int) $r->ID, 'date_gmt' => self::iso($r->post_date_gmt),
                    'author' => $a ? $a->display_name : null, 'title' => wp_strip_all_tags($r->post_title),
                    'content_chars' => mb_strlen((string) $r->post_content), 'autosave' => (bool) wp_is_post_autosave($r),
                ];
            }
            return ['post_id' => (int) $p->ID, 'modified_gmt' => self::modified_iso($p), 'items' => $items];
        });
    }

    public static function route_restore_revision($request) {
        return self::respond('restore_revision', function ($q) use ($request) {
            $p = self::writable_post($request);
            if (is_wp_error($p)) return $p;
            $params = $request->get_url_params();
            // wp_get_post_revision() takes its first argument by reference: it must be a variable, not an expression.
            $rev_id = isset($params['rev']) ? (int) $params['rev'] : 0;
            $rev = wp_get_post_revision($rev_id);
            if (!$rev || (int) $rev->post_parent !== (int) $p->ID) return self::err('webruno_not_found', 404, __('Not found.', 'webruno-crm'));
            $data = self::json_body($request, ['expected_modified_gmt', 'confirm_live']);
            if (is_wp_error($data)) return $data;
            if ($p->post_status === 'trash') return self::err('webruno_conflict', 409, __('This item is in the trash. Restore it first (status route).', 'webruno-crm'));
            $ok = self::check_expected($p, $data);
            if (is_wp_error($ok)) return $ok;
            $ok = self::require_live_confirmation($p, $data);
            if (is_wp_error($ok)) return $ok;
            $before = self::snapshot($p);
            if (!$before) return self::revisions_off_error();
            if (!wp_restore_post_revision($rev->ID)) return self::err('webruno_error', 500, __('Could not restore the revision.', 'webruno-crm'));
            return self::write_result($p, ['revision_before' => $before, 'restored_revision' => (int) $rev->ID]);
        });
    }

    /* ───────────────────────── terms, products and post categories (F6d) ─────────────────────────
     * Same safety model as posts: the admin's "edit" switch -> the connected user's capabilities -> new products are
     * always drafts -> every change of an existing product first saves its previous state (undo with the restore route)
     * -> changing something live needs the person's confirmation. Terms can be created, renamed and (F6e) deleted after confirmation.
     * Simple and variable products can be edited (grouped and external products are refused with a clear message).
     */

    const OPT_PROD_SNAP = 'webruno_crm_product_snapshots'; // not autoloaded; the last 5 states of each product
    const WRITE_TAXES   = ['category', 'post_tag', 'product_cat', 'product_tag'];

    private static function term_version($t) {
        return sha1((string) wp_json_encode([(string) $t->name, (string) $t->slug, (int) $t->parent, (string) $t->description]));
    }

    private static function term_row($t, array $extra = []) {
        return array_merge([
            'id' => (int) $t->term_id, 'name' => $t->name, 'slug' => $t->slug, 'taxonomy' => $t->taxonomy,
            'parent' => (int) $t->parent, 'count' => (int) $t->count, 'description' => wp_strip_all_tags($t->description),
            'version' => self::term_version($t),
        ], $extra);
    }

    /** The taxonomy object a write may touch, or WP_Error. */
    private static function write_taxonomy($tax) {
        if (!is_string($tax) || !in_array($tax, self::WRITE_TAXES, true)) {
            return self::err('webruno_bad_request', 400, __('taxonomy must be category, post_tag, product_cat or product_tag.', 'webruno-crm'));
        }
        if (strpos($tax, 'product_') === 0 && !self::wc_ready()) {
            return self::err('webruno_woocommerce_missing', 404, __('WooCommerce is not active on this site.', 'webruno-crm'));
        }
        $obj = taxonomy_exists($tax) ? get_taxonomy($tax) : null;
        if (!$obj) return self::err('webruno_not_found', 404, __('Not found.', 'webruno-crm'));
        return $obj;
    }

    /** A JSON list of positive whole numbers (unique), at most $max long. Returns the list or WP_Error. */
    private static function id_list($v, $max, $key) {
        if (!is_array($v) || ($v && array_values($v) !== $v) || count($v) > $max) {
            /* translators: 1: field name, 2: maximum number of entries */
            return self::err('webruno_bad_request', 400, sprintf(__('%1$s must be a list of at most %2$d IDs.', 'webruno-crm'), sanitize_key((string) $key), (int) $max));
        }
        $out = [];
        foreach ($v as $id) {
            if (!is_int($id) || $id <= 0 || $id > 2147483647) {
                /* translators: %s: field name */
                return self::err('webruno_bad_request', 400, sprintf(__('%s must contain only positive whole numbers.', 'webruno-crm'), sanitize_key((string) $key)));
            }
            $out[$id] = $id;
        }
        return array_values($out);
    }

    /** name / slug / description of a term. Returns the checked fields that are present, or WP_Error. */
    private static function term_text_fields(array $data) {
        $out = [];
        if (array_key_exists('name', $data)) {
            if (!is_string($data['name']) || trim($data['name']) === '' || mb_strlen($data['name']) > 200) return self::err('webruno_bad_request', 400, __('name must be a non-empty text of at most 200 characters.', 'webruno-crm'));
            $out['name'] = sanitize_text_field($data['name']);
            if ($out['name'] === '') return self::err('webruno_bad_request', 400, __('name must be a non-empty text of at most 200 characters.', 'webruno-crm'));
        }
        if (array_key_exists('slug', $data)) {
            if (!is_string($data['slug']) || mb_strlen($data['slug']) > 200) return self::err('webruno_bad_request', 400, __('slug must be text of at most 200 characters.', 'webruno-crm'));
            $out['slug'] = sanitize_title($data['slug']);
            if ($out['slug'] === '') return self::err('webruno_bad_request', 400, __('slug has no usable characters.', 'webruno-crm'));
        }
        if (array_key_exists('description', $data)) {
            if (!is_string($data['description']) || mb_strlen($data['description']) > 1000) return self::err('webruno_bad_request', 400, __('description must be text of at most 1000 characters.', 'webruno-crm'));
            $out['description'] = sanitize_textarea_field($data['description']);
        }
        return $out;
    }

    /** The parent of a term: null = not sent, otherwise 0 or an existing term of the same (hierarchical) taxonomy. */
    private static function parent_term(array $data, $tax_obj) {
        if (!array_key_exists('parent', $data)) return null;
        if (!is_int($data['parent']) || $data['parent'] < 0) return self::err('webruno_bad_request', 400, __('parent must be 0 or the ID of an existing category.', 'webruno-crm'));
        if ($data['parent'] === 0) return 0;
        if (!$tax_obj->hierarchical) return self::err('webruno_bad_request', 400, __('Only categories can have a parent.', 'webruno-crm'));
        $pt = get_term($data['parent'], $tax_obj->name);
        if (!$pt || is_wp_error($pt)) return self::err('webruno_bad_request', 400, __('The parent category was not found.', 'webruno-crm'));
        return (int) $data['parent'];
    }

    public static function route_create_term($request) {
        return self::respond('create_term', function ($q) use ($request) {
            $data = self::json_body($request, ['taxonomy', 'name', 'slug', 'parent', 'description']);
            if (is_wp_error($data)) return $data;
            $obj = self::write_taxonomy(isset($data['taxonomy']) ? $data['taxonomy'] : null);
            if (is_wp_error($obj)) return $obj;
            if (!current_user_can($obj->cap->manage_terms)) return self::err('webruno_forbidden', 403, __('The connected WordPress user may not create these terms.', 'webruno-crm'));
            if (!isset($data['name'])) return self::err('webruno_bad_request', 400, __('name is required.', 'webruno-crm'));
            $f = self::term_text_fields($data);
            if (is_wp_error($f)) return $f;
            $parent = self::parent_term($data, $obj);
            if (is_wp_error($parent)) return $parent;
            $args = [];
            if (isset($f['slug'])) $args['slug'] = $f['slug'];
            if (isset($f['description'])) $args['description'] = $f['description'];
            if ($parent) $args['parent'] = $parent;
            $res = wp_insert_term(wp_slash($f['name']), $obj->name, wp_slash($args));
            if (is_wp_error($res)) {
                if ($res->get_error_code() === 'term_exists') return self::err('webruno_conflict', 409, __('A term with this name or slug already exists here.', 'webruno-crm'));
                return self::err('webruno_error', 500, __('Could not create the term.', 'webruno-crm'));
            }
            self::$target = (int) $res['term_id'];
            $t = get_term((int) $res['term_id'], $obj->name);
            if (!$t || is_wp_error($t)) return self::err('webruno_error', 500, __('Could not create the term.', 'webruno-crm'));
            return self::term_row($t, ['created' => true]);
        });
    }

    public static function route_update_term($request) {
        return self::respond('update_term', function ($q) use ($request) {
            $params = $request->get_url_params();
            $id = isset($params['id']) ? (int) $params['id'] : 0;
            self::$target = $id;
            $t = $id > 0 ? get_term($id) : null;
            if (!$t || is_wp_error($t) || !in_array($t->taxonomy, self::WRITE_TAXES, true)) return self::err('webruno_not_found', 404, __('Not found.', 'webruno-crm'));
            $obj = self::write_taxonomy($t->taxonomy);
            if (is_wp_error($obj)) return $obj;
            if (!current_user_can('edit_term', $id)) return self::err('webruno_forbidden', 403, __('The connected WordPress user may not change this term.', 'webruno-crm'));
            $data = self::json_body($request, ['expected_version', 'name', 'slug', 'parent', 'description', 'confirm_live']);
            if (is_wp_error($data)) return $data;
            if (!isset($data['expected_version']) || !is_string($data['expected_version']) || !preg_match('/^[0-9a-f]{40}$/', $data['expected_version'])) {
                return self::err('webruno_bad_request', 400, __('expected_version (the version you got when you last read this term) is required.', 'webruno-crm'));
            }
            if ($data['expected_version'] !== self::term_version($t)) return self::err('webruno_conflict', 409, __('This term was changed on the site after you read it. Read it again before editing.', 'webruno-crm'));
            $f = self::term_text_fields($data);
            if (is_wp_error($f)) return $f;
            $parent = self::parent_term($data, $obj);
            if (is_wp_error($parent)) return $parent;
            if ($parent !== null && $parent === $id) return self::err('webruno_bad_request', 400, __('A category cannot be its own parent.', 'webruno-crm'));
            if (!$f && $parent === null) return self::err('webruno_bad_request', 400, __('Nothing to change: send name, slug, parent or description.', 'webruno-crm'));
            $args = $f;
            if ($parent !== null) $args['parent'] = $parent;
            $previous = [];
            foreach ($args as $k => $v) { if ((string) $t->$k !== (string) $v) $previous[$k] = ($k === 'parent') ? (int) $t->$k : (string) $t->$k; }
            if (!$previous) return self::term_row($t, ['unchanged' => true]);
            $visible = isset($previous['name']) || isset($previous['slug']) || isset($previous['parent']);
            if ($visible && (int) $t->count > 0 && (!isset($data['confirm_live']) || $data['confirm_live'] !== true)) {
                /* translators: %d: number of items */
                return self::err('webruno_confirm_live_required', 400, sprintf(__('This term is used by %d items on the site, so the change is visible to visitors. Ask the person to confirm, then send confirm_live: true.', 'webruno-crm'), (int) $t->count));
            }
            $res = wp_update_term($id, $t->taxonomy, wp_slash($args));
            if (is_wp_error($res)) {
                if ($res->get_error_code() === 'term_exists' || $res->get_error_code() === 'duplicate_term_slug') return self::err('webruno_conflict', 409, __('A term with this name or slug already exists here.', 'webruno-crm'));
                return self::err('webruno_error', 500, __('Could not update the term.', 'webruno-crm'));
            }
            $fresh = get_term($id, $t->taxonomy);
            return self::term_row($fresh, ['previous' => $previous]);
        });
    }

    /** category_ids / tag_ids of a post write. Returns [taxonomy => ids] for the keys that were sent, or WP_Error. */
    private static function post_term_input(array $data, $post_type) {
        $out = [];
        foreach (['category_ids' => 'category', 'tag_ids' => 'post_tag'] as $key => $tax) {
            if (!array_key_exists($key, $data)) continue;
            if ($post_type !== 'post') return self::err('webruno_bad_request', 400, __('category_ids and tag_ids are only for posts, not for pages.', 'webruno-crm'));
            $ids = self::id_list($data[$key], 50, $key);
            if (is_wp_error($ids)) return $ids;
            $obj = get_taxonomy($tax);
            if (!$obj || !current_user_can($obj->cap->assign_terms)) return self::err('webruno_forbidden', 403, __('The connected WordPress user may not assign these terms.', 'webruno-crm'));
            foreach ($ids as $tid) {
                $t = get_term($tid, $tax);
                if (!$t || is_wp_error($t)) return self::err('webruno_bad_request', 400, __('A category or tag in the list was not found.', 'webruno-crm'));
            }
            $out[$tax] = $ids;
        }
        return $out;
    }

    private static function post_terms_out($p) {
        if ($p->post_type !== 'post') return [];
        $cats = [];
        $tags = [];
        foreach ((array) wp_get_post_terms($p->ID, 'category') as $t) { if (!is_wp_error($t)) $cats[] = ['id' => (int) $t->term_id, 'name' => $t->name]; }
        foreach ((array) wp_get_post_terms($p->ID, 'post_tag') as $t) { if (!is_wp_error($t)) $tags[] = ['id' => (int) $t->term_id, 'name' => $t->name]; }
        return ['categories' => $cats, 'tags' => $tags];
    }

    /* ── products (WooCommerce: simple and variable products) ── */

    private static function product_state($product) {
        $cats = array_map('intval', (array) $product->get_category_ids('edit'));
        sort($cats);
        $state = [
            'name' => (string) $product->get_name('edit'), 'slug' => (string) $product->get_slug('edit'),
            'description' => (string) $product->get_description('edit'), 'short_description' => (string) $product->get_short_description('edit'),
            'regular_price' => (string) $product->get_regular_price('edit'), 'sale_price' => (string) $product->get_sale_price('edit'),
            'sku' => (string) $product->get_sku('edit'), 'manage_stock' => (bool) $product->get_manage_stock('edit'),
            'stock_quantity' => $product->get_stock_quantity('edit') === null ? null : (int) $product->get_stock_quantity('edit'),
            'stock_status' => (string) $product->get_stock_status('edit'), 'category_ids' => $cats,
        ];
        if ($product->is_type('variable')) {
            // a variable product has no price of its own; its own attributes are part of its state so an undo can put them back
            unset($state['regular_price'], $state['sale_price']);
            $state['attributes'] = self::attr_specs($product);
        }
        return $state;
    }

    /** Applies the fields present in $s and saves. Returns true or WP_Error (WooCommerce refusals such as a duplicate SKU). */
    private static function product_apply($product, array $s) {
        try {
            if (array_key_exists('name', $s)) $product->set_name($s['name']);
            if (array_key_exists('slug', $s)) $product->set_slug($s['slug']);
            if (array_key_exists('description', $s)) $product->set_description($s['description']);
            if (array_key_exists('short_description', $s)) $product->set_short_description($s['short_description']);
            if (array_key_exists('regular_price', $s)) $product->set_regular_price($s['regular_price']);
            if (array_key_exists('sale_price', $s)) $product->set_sale_price($s['sale_price']);
            if (array_key_exists('sku', $s)) $product->set_sku($s['sku']);
            if (array_key_exists('manage_stock', $s)) $product->set_manage_stock($s['manage_stock']);
            if (array_key_exists('stock_quantity', $s)) $product->set_stock_quantity($s['stock_quantity']);
            if (array_key_exists('stock_status', $s)) $product->set_stock_status($s['stock_status']);
            if (array_key_exists('category_ids', $s)) $product->set_category_ids($s['category_ids']);
            if (array_key_exists('attributes', $s)) $product->set_attributes(self::attrs_merge($product, $s['attributes']));
            $product->save();
            if ($product->is_type('variable')) {
                WC_Product_Variable::sync($product->get_id());
                wc_delete_product_transients($product->get_id());
            }
        } catch (Exception $e) {
            return self::err('webruno_conflict', 409, __('WooCommerce did not accept the values (for example the SKU is already used by another product).', 'webruno-crm'));
        }
        return true;
    }

    private static function price_text($v, $key) {
        if (!is_string($v) || ($v !== '' && !preg_match('/^\d{1,10}(\.\d{1,4})?$/', $v))) {
            /* translators: %s: field name */
            return self::err('webruno_bad_request', 400, sprintf(__('%s must be a price like 19.90 (digits, optional decimals) or an empty text to clear it.', 'webruno-crm'), sanitize_key((string) $key)));
        }
        return $v;
    }

    /** Checks the product fields that are present. Returns the cleaned fields or WP_Error. */
    private static function product_fields(array $data) {
        $out = [];
        if (array_key_exists('name', $data)) {
            if (!is_string($data['name']) || trim($data['name']) === '' || mb_strlen($data['name']) > 300) return self::err('webruno_bad_request', 400, __('name must be a non-empty text of at most 300 characters.', 'webruno-crm'));
            $out['name'] = sanitize_text_field($data['name']);
            if ($out['name'] === '') return self::err('webruno_bad_request', 400, __('name must be a non-empty text of at most 300 characters.', 'webruno-crm'));
        }
        if (array_key_exists('slug', $data)) {
            if (!is_string($data['slug']) || mb_strlen($data['slug']) > 200) return self::err('webruno_bad_request', 400, __('slug must be text of at most 200 characters.', 'webruno-crm'));
            $out['slug'] = sanitize_title($data['slug']);
        }
        foreach (['description' => self::max_chars(), 'short_description' => 5000] as $k => $max) {
            if (!array_key_exists($k, $data)) continue;
            if (!is_string($data[$k]) || mb_strlen($data[$k]) > $max) return self::err('webruno_too_large', 413, __('description and short_description must be text within the size limit.', 'webruno-crm'));
            $out[$k] = self::clean_content($data[$k]);
        }
        foreach (['regular_price', 'sale_price'] as $k) {
            if (!array_key_exists($k, $data)) continue;
            $v = self::price_text($data[$k], $k);
            if (is_wp_error($v)) return $v;
            $out[$k] = $v;
        }
        if (array_key_exists('sku', $data)) {
            if (!is_string($data['sku']) || mb_strlen($data['sku']) > 100) return self::err('webruno_bad_request', 400, __('sku must be text of at most 100 characters.', 'webruno-crm'));
            $out['sku'] = sanitize_text_field($data['sku']);
        }
        if (array_key_exists('manage_stock', $data)) {
            if (!is_bool($data['manage_stock'])) return self::err('webruno_bad_request', 400, __('manage_stock must be true or false.', 'webruno-crm'));
            $out['manage_stock'] = $data['manage_stock'];
        }
        if (array_key_exists('stock_quantity', $data)) {
            if (!is_int($data['stock_quantity']) || $data['stock_quantity'] < 0 || $data['stock_quantity'] > 1000000) return self::err('webruno_bad_request', 400, __('stock_quantity must be a whole number from 0 to 1000000.', 'webruno-crm'));
            $out['stock_quantity'] = $data['stock_quantity'];
        }
        if (array_key_exists('stock_status', $data)) {
            if (!in_array($data['stock_status'], ['instock', 'outofstock', 'onbackorder'], true)) return self::err('webruno_bad_request', 400, __('stock_status must be instock, outofstock or onbackorder.', 'webruno-crm'));
            $out['stock_status'] = $data['stock_status'];
        }
        if (array_key_exists('category_ids', $data)) {
            $ids = self::id_list($data['category_ids'], 50, 'category_ids');
            if (is_wp_error($ids)) return $ids;
            $obj = get_taxonomy('product_cat');
            if (!$obj || !current_user_can($obj->cap->assign_terms)) return self::err('webruno_forbidden', 403, __('The connected WordPress user may not assign product categories.', 'webruno-crm'));
            foreach ($ids as $tid) {
                $t = get_term($tid, 'product_cat');
                if (!$t || is_wp_error($t)) return self::err('webruno_bad_request', 400, __('A product category in the list was not found.', 'webruno-crm'));
            }
            sort($ids);
            $out['category_ids'] = $ids;
        }
        if (array_key_exists('attributes', $data)) {
            $a = self::attrs_input($data['attributes']);
            if (is_wp_error($a)) return $a;
            $out['attributes'] = $a;
        }
        return $out;
    }

    private static function sale_below_regular(array $state) {
        if ($state['regular_price'] === '' || $state['sale_price'] === '') return true;
        return (float) $state['sale_price'] < (float) $state['regular_price'];
    }

    private static function product_full($id) {
        $p = get_post((int) $id);
        $product = $p ? wc_get_product($p->ID) : null;
        if (!$p || !$product) return [];
        $out = self::product_summary($p, $product);
        $out['short_description'] = wp_kses_post($product->get_short_description());
        $out['description'] = wp_kses_post($product->get_description());
        return array_merge($out, self::variable_extras($product));
    }

    /** Loads a simple product for writing: returns [post, product] or WP_Error. */
    private static function writable_product($request) {
        if (!self::wc_ready()) return self::err('webruno_woocommerce_missing', 404, __('WooCommerce is not active on this site.', 'webruno-crm'));
        $params = $request->get_url_params();
        $id = isset($params['id']) ? (int) $params['id'] : 0;
        self::$target = $id;
        $p = $id > 0 ? get_post($id) : null;
        if (!$p || $p->post_type !== 'product') return self::err('webruno_not_found', 404, __('Not found.', 'webruno-crm'));
        if (!current_user_can('edit_post', $id)) return self::err('webruno_forbidden', 403, __('The connected WordPress user may not change this product.', 'webruno-crm'));
        $product = wc_get_product($id);
        if (!$product) return self::err('webruno_not_found', 404, __('Not found.', 'webruno-crm'));
        return [$p, $product];
    }

    private static function prod_snap_push($id, array $state) {
        $all = get_option(self::OPT_PROD_SNAP, []);
        if (!is_array($all)) $all = [];
        $list = (isset($all[$id]) && is_array($all[$id])) ? $all[$id] : [];
        array_unshift($list, ['ts' => time(), 'state' => $state]);
        $all[$id] = array_slice($list, 0, 5);
        update_option(self::OPT_PROD_SNAP, $all, false);
    }

    private static function prod_snap_pop($id) {
        $all = get_option(self::OPT_PROD_SNAP, []);
        if (!is_array($all) || empty($all[$id]) || !is_array($all[$id])) return null;
        $first = array_shift($all[$id]);
        update_option(self::OPT_PROD_SNAP, $all, false);
        return (is_array($first) && isset($first['state']) && is_array($first['state'])) ? $first['state'] : null;
    }

    public static function route_create_product($request) {
        return self::respond('create_product', function ($q) use ($request) {
            if (!self::wc_ready()) return self::err('webruno_woocommerce_missing', 404, __('WooCommerce is not active on this site.', 'webruno-crm'));
            $data = self::json_body($request, ['type', 'name', 'slug', 'description', 'short_description', 'regular_price', 'sale_price', 'sku', 'manage_stock', 'stock_quantity', 'stock_status', 'category_ids', 'attributes']);
            if (is_wp_error($data)) return $data;
            $pt = get_post_type_object('product');
            if (!$pt || !current_user_can($pt->cap->create_posts)) return self::err('webruno_forbidden', 403, __('The connected WordPress user may not create products.', 'webruno-crm'));
            if (!isset($data['name'])) return self::err('webruno_bad_request', 400, __('name is required.', 'webruno-crm'));
            $f = self::product_fields($data);
            if (is_wp_error($f)) return $f;
            $merged = array_merge(['regular_price' => '', 'sale_price' => ''], $f);
            if (!self::sale_below_regular($merged)) return self::err('webruno_bad_request', 400, __('sale_price must be lower than regular_price.', 'webruno-crm'));
            $type = isset($data['type']) ? $data['type'] : 'simple';
            if (!in_array($type, ['simple', 'variable'], true)) return self::err('webruno_bad_request', 400, __('type must be simple or variable.', 'webruno-crm'));
            if ($type === 'simple' && isset($f['attributes'])) return self::err('webruno_bad_request', 400, __('attributes are only for variable products (send type: variable).', 'webruno-crm'));
            if ($type === 'variable' && (isset($f['regular_price']) || isset($f['sale_price']))) return self::err('webruno_bad_request', 400, __('A variable product has no price of its own: set the prices on its variations.', 'webruno-crm'));
            $product = $type === 'variable' ? new WC_Product_Variable() : new WC_Product_Simple();
            $product->set_status('draft');
            $res = self::product_apply($product, $f);
            if (is_wp_error($res)) return $res;
            self::$target = (int) $product->get_id();
            return array_merge(self::product_full($product->get_id()), ['created' => true]);
        });
    }

    public static function route_update_product($request) {
        return self::respond('update_product', function ($q) use ($request) {
            $loaded = self::writable_product($request);
            if (is_wp_error($loaded)) return $loaded;
            list($p, $product) = $loaded;
            $data = self::json_body($request, ['expected_modified_gmt', 'name', 'slug', 'description', 'short_description', 'regular_price', 'sale_price', 'sku', 'manage_stock', 'stock_quantity', 'stock_status', 'category_ids', 'attributes', 'confirm_live']);
            if (is_wp_error($data)) return $data;
            if ($p->post_status === 'trash') return self::err('webruno_conflict', 409, __('This product is in the trash. Restore it first (status route).', 'webruno-crm'));
            if (!$product->is_type(['simple', 'variable'])) return self::err('webruno_bad_request', 400, __('Only simple and variable products can be edited through Webruno (grouped and external products are not supported).', 'webruno-crm'));
            $ok = self::check_expected($p, $data);
            if (is_wp_error($ok)) return $ok;
            $ok = self::require_live_confirmation($p, $data);
            if (is_wp_error($ok)) return $ok;
            $f = self::product_fields($data);
            if (is_wp_error($f)) return $f;
            if (!$f) return self::err('webruno_bad_request', 400, __('Nothing to change: send at least one product field.', 'webruno-crm'));
            $variable = $product->is_type('variable');
            if (!$variable && isset($f['attributes'])) return self::err('webruno_bad_request', 400, __('attributes are only for variable products.', 'webruno-crm'));
            if ($variable && (isset($f['regular_price']) || isset($f['sale_price']))) return self::err('webruno_bad_request', 400, __('A variable product has no price of its own: change the prices on its variations.', 'webruno-crm'));
            if ($variable && isset($f['attributes'])) {
                $bad = self::attrs_conflict($product, $f['attributes']);
                if ($bad) {
                    /* translators: %d: variation ID */
                    return self::err('webruno_conflict', 409, sprintf(__('Variation %d still uses an attribute or value that would no longer exist. Change or delete that variation first.', 'webruno-crm'), (int) $bad));
                }
            }
            $before = self::product_state($product);
            if (!self::sale_below_regular(array_merge(['regular_price' => '', 'sale_price' => ''], $before, $f))) return self::err('webruno_bad_request', 400, __('sale_price must be lower than regular_price.', 'webruno-crm'));
            self::prod_snap_push($p->ID, $before);
            $res = self::product_apply($product, $f);
            if (is_wp_error($res)) { self::prod_snap_pop($p->ID); return $res; }
            return array_merge(self::product_full($p->ID), ['previous_state_saved' => true]);
        });
    }

    public static function route_restore_product($request) {
        return self::respond('restore_product', function ($q) use ($request) {
            $loaded = self::writable_product($request);
            if (is_wp_error($loaded)) return $loaded;
            list($p, $product) = $loaded;
            $data = self::json_body($request, ['expected_modified_gmt', 'confirm_live']);
            if (is_wp_error($data)) return $data;
            if ($p->post_status === 'trash') return self::err('webruno_conflict', 409, __('This product is in the trash. Restore it first (status route).', 'webruno-crm'));
            if (!$product->is_type(['simple', 'variable'])) return self::err('webruno_bad_request', 400, __('Only simple and variable products can be edited through Webruno (grouped and external products are not supported).', 'webruno-crm'));
            $ok = self::check_expected($p, $data);
            if (is_wp_error($ok)) return $ok;
            $ok = self::require_live_confirmation($p, $data);
            if (is_wp_error($ok)) return $ok;
            $state = self::prod_snap_pop($p->ID);
            if ($state === null) return self::err('webruno_not_found', 404, __('Nothing to restore for this product.', 'webruno-crm'));
            $res = self::product_apply($product, $state);
            if (is_wp_error($res)) { self::prod_snap_push($p->ID, $state); return $res; }
            return array_merge(self::product_full($p->ID), ['restored' => true]);
        });
    }

    public static function route_set_product_status($request) {
        return self::respond('set_product_status', function ($q) use ($request) {
            $loaded = self::writable_product($request);
            if (is_wp_error($loaded)) return $loaded;
            return self::apply_status($request, $loaded[0]);
        });
    }

    /* ───────────────────────── variable products, variations and permanent deletes (F6e) ─────────────────────────
     * Variable products: the product has its own attributes (name + list of values, optionally used for variations);
     * every variation fixes one value for each variation attribute and carries its own price, SKU and stock.
     * Only the product's own (custom) attributes are managed; global attributes of the shop are kept as they are.
     * Permanent deletes never happen without confirm_delete: true. The caller (the AI assistant) must ask the person
     * in the chat first; the plugin refuses the request otherwise. Moving to the trash stays available (status route).
     */

    const ATTR_MAX      = 10;
    const OPT_MAX       = 50;
    const VARIATION_MAX = 100;
    const VAR_FIELDS    = ['attributes', 'regular_price', 'sale_price', 'sku', 'manage_stock', 'stock_quantity', 'stock_status', 'enabled', 'confirm_live'];

    private static function require_delete_confirmation(array $data, $what) {
        if (!isset($data['confirm_delete']) || $data['confirm_delete'] !== true) {
            /* translators: %s: what would be deleted */
            return self::err('webruno_confirm_delete_required', 400, sprintf(__('This permanently deletes %s and cannot be undone. Ask the person to confirm the deletion, then send confirm_delete: true.', 'webruno-crm'), $what));
        }
        return true;
    }

    /** The editable attributes of a product (its own, non-global ones) in the form the write routes accept. */
    private static function attr_specs($product) {
        $out = [];
        foreach ((array) $product->get_attributes() as $a) {
            if (!is_object($a) || !method_exists($a, 'is_taxonomy') || $a->is_taxonomy()) continue;
            $out[] = [
                'name' => (string) $a->get_name(),
                'options' => array_values(array_map('strval', (array) $a->get_options())),
                'visible' => (bool) $a->get_visible(),
                'variation' => (bool) $a->get_variation(),
            ];
        }
        return $out;
    }

    private static function attrs_bad($msg) {
        return self::err('webruno_bad_request', 400, $msg);
    }

    /** Checks a JSON list of attributes [{name, options, visible?, variation?}]. Returns the cleaned list or WP_Error. */
    private static function attrs_input($v) {
        if (!is_array($v) || ($v && array_values($v) !== $v) || count($v) > self::ATTR_MAX) {
            /* translators: %d: maximum number of attributes */
            return self::attrs_bad(sprintf(__('attributes must be a list of at most %d attributes.', 'webruno-crm'), self::ATTR_MAX));
        }
        $out = [];
        $keys = [];
        foreach ($v as $a) {
            if (!is_array($a) || ($a && array_values($a) === $a)) return self::attrs_bad(__('Every attribute must be an object with name and options.', 'webruno-crm'));
            foreach (array_keys($a) as $k) {
                if (!in_array($k, ['name', 'options', 'visible', 'variation'], true)) return self::attrs_bad(__('Unknown field in an attribute (allowed: name, options, visible, variation).', 'webruno-crm'));
            }
            if (!isset($a['name']) || !is_string($a['name']) || trim($a['name']) === '' || mb_strlen($a['name']) > 100) return self::attrs_bad(__('Every attribute needs a name of at most 100 characters.', 'webruno-crm'));
            $name = sanitize_text_field($a['name']);
            $key = sanitize_title($name);
            if ($name === '' || $key === '' || strpos($key, 'pa_') === 0 || isset($keys[$key])) return self::attrs_bad(__('Attribute names must be different from each other, must not start with pa_ and need usable characters.', 'webruno-crm'));
            $keys[$key] = true;
            if (!isset($a['options']) || !is_array($a['options']) || !$a['options'] || array_values($a['options']) !== $a['options'] || count($a['options']) > self::OPT_MAX) {
                /* translators: %d: maximum number of values */
                return self::attrs_bad(sprintf(__('options must be a list of 1 to %d values.', 'webruno-crm'), self::OPT_MAX));
            }
            $opts = [];
            foreach ($a['options'] as $o) {
                if (!is_string($o) || trim($o) === '' || mb_strlen($o) > 100 || strpos($o, '|') !== false) return self::attrs_bad(__('Every option must be a text of at most 100 characters without the | character.', 'webruno-crm'));
                $o = sanitize_text_field($o);
                if ($o === '' || in_array($o, $opts, true)) return self::attrs_bad(__('The options of an attribute must be different from each other.', 'webruno-crm'));
                $opts[] = $o;
            }
            $vis = array_key_exists('visible', $a) ? $a['visible'] : true;
            $var = array_key_exists('variation', $a) ? $a['variation'] : false;
            if (!is_bool($vis) || !is_bool($var)) return self::attrs_bad(__('visible and variation must be true or false.', 'webruno-crm'));
            $out[] = ['name' => $name, 'options' => $opts, 'visible' => $vis, 'variation' => $var];
        }
        return $out;
    }

    /** The product's global attributes are kept; its own attributes are replaced by $list. */
    private static function attrs_merge($product, array $list) {
        $attrs = [];
        foreach ((array) $product->get_attributes() as $a) {
            if (is_object($a) && method_exists($a, 'is_taxonomy') && $a->is_taxonomy()) $attrs[] = $a;
        }
        $pos = count($attrs);
        foreach ($list as $a) {
            $o = new WC_Product_Attribute();
            $o->set_id(0);
            $o->set_name($a['name']);
            $o->set_options($a['options']);
            $o->set_position($pos++);
            $o->set_visible($a['visible']);
            $o->set_variation($a['variation']);
            $attrs[] = $o;
        }
        return $attrs;
    }

    /** The ID of a variation that would break if the product's own attributes became $list, or 0. */
    private static function attrs_conflict($product, array $list) {
        $by = [];
        foreach ($list as $a) $by[sanitize_title($a['name'])] = $a['options'];
        $global = [];
        foreach ((array) $product->get_attributes() as $k => $a) {
            if (is_object($a) && method_exists($a, 'is_taxonomy') && $a->is_taxonomy()) $global[$k] = true;
        }
        foreach ((array) $product->get_children() as $cid) {
            $v = wc_get_product($cid);
            if (!$v) continue;
            foreach ((array) $v->get_attributes('edit') as $k => $val) {
                if ($val === '' || isset($global[$k])) continue;
                if (!isset($by[$k]) || !in_array((string) $val, $by[$k], true)) return (int) $cid;
            }
        }
        return 0;
    }

    private static function variation_labels($product) {
        $labels = [];
        foreach (self::attr_specs($product) as $a) $labels[sanitize_title($a['name'])] = $a['name'];
        return $labels;
    }

    private static function variation_row($v, array $labels) {
        $post = get_post($v->get_id());
        $attrs = [];
        foreach ((array) $v->get_attributes('edit') as $k => $val) $attrs[isset($labels[$k]) ? $labels[$k] : (string) $k] = (string) $val;
        return [
            'id' => (int) $v->get_id(), 'attributes' => $attrs, 'sku' => (string) $v->get_sku('edit'),
            'regular_price' => (string) $v->get_regular_price('edit'), 'sale_price' => (string) $v->get_sale_price('edit'),
            'price' => (string) $v->get_price(), 'on_sale' => (bool) $v->is_on_sale(),
            'manage_stock' => (bool) $v->get_manage_stock('edit'),
            'stock_quantity' => $v->get_stock_quantity('edit') === null ? null : (int) $v->get_stock_quantity('edit'),
            'stock_status' => (string) $v->get_stock_status('edit'),
            'enabled' => $post ? $post->post_status === 'publish' : false,
            'modified_gmt' => $post ? self::modified_iso($post) : null,
        ];
    }

    private static function variation_rows($product) {
        $labels = self::variation_labels($product);
        $rows = [];
        foreach (array_slice((array) $product->get_children(), 0, self::VARIATION_MAX) as $cid) {
            $v = wc_get_product($cid);
            if ($v && $v->is_type('variation')) $rows[] = self::variation_row($v, $labels);
        }
        return $rows;
    }

    /** Extra fields of a product read/write result: its own attributes, and for a variable product its variations. */
    private static function variable_extras($product) {
        $out = ['attribute_specs' => self::attr_specs($product)];
        if ($product->is_type('variable')) $out['variation_items'] = self::variation_rows($product);
        return $out;
    }

    /** {"Color": "Red"} -> ['color' => 'Red'], for the variation attributes of $product. Every variation attribute needs a value. */
    private static function variation_attrs_input($input, $product) {
        if (!is_array($input) || ($input && array_values($input) === $input)) {
            return self::err('webruno_bad_request', 400, __('attributes must be an object such as {"Color": "Red"}.', 'webruno-crm'));
        }
        $specs = [];
        foreach (self::attr_specs($product) as $a) {
            if ($a['variation']) $specs[sanitize_title($a['name'])] = $a;
        }
        if (!$specs) return self::err('webruno_bad_request', 400, __('This product has no attribute marked variation: true. Set its attributes first.', 'webruno-crm'));
        $out = [];
        foreach ($input as $name => $val) {
            $key = sanitize_title((string) $name);
            if (!isset($specs[$key])) return self::err('webruno_bad_request', 400, __('Unknown variation attribute. Use the names listed in attribute_specs (variation: true).', 'webruno-crm'));
            if (!is_string($val) || !in_array($val, $specs[$key]['options'], true)) return self::err('webruno_bad_request', 400, __('The value of a variation attribute must be exactly one of its options (as text).', 'webruno-crm'));
            $out[$key] = $val;
        }
        foreach ($specs as $key => $a) {
            if (!isset($out[$key])) return self::err('webruno_bad_request', 400, __('attributes must contain a value for every variation attribute.', 'webruno-crm'));
        }
        return $out;
    }

    private static function variation_duplicate($product, array $attrs, $skip_id) {
        ksort($attrs);
        foreach ((array) $product->get_children() as $cid) {
            if ((int) $cid === (int) $skip_id) continue;
            $v = wc_get_product($cid);
            if (!$v) continue;
            $a = array_map('strval', (array) $v->get_attributes('edit'));
            ksort($a);
            if ($a === $attrs) return true;
        }
        return false;
    }

    private static function enabled_input(array $data) {
        if (!array_key_exists('enabled', $data)) return null;
        if (!is_bool($data['enabled'])) return self::err('webruno_bad_request', 400, __('enabled must be true or false.', 'webruno-crm'));
        return $data['enabled'];
    }

    private static function sync_variable($product_id) {
        WC_Product_Variable::sync((int) $product_id);
        wc_delete_product_transients((int) $product_id);
    }

    /** A variable product that is not in the trash, for writing: [post, product] or WP_Error. */
    private static function variable_parent($request) {
        $loaded = self::writable_product($request);
        if (is_wp_error($loaded)) return $loaded;
        list($p, $product) = $loaded;
        if ($p->post_status === 'trash') return self::err('webruno_conflict', 409, __('This product is in the trash. Restore it first (status route).', 'webruno-crm'));
        if (!$product->is_type('variable')) return self::err('webruno_bad_request', 400, __('Variations exist only on variable products.', 'webruno-crm'));
        return [$p, $product];
    }

    /** [post, product, variation, variation post] or WP_Error. */
    private static function writable_variation($request) {
        $loaded = self::variable_parent($request);
        if (is_wp_error($loaded)) return $loaded;
        list($p, $product) = $loaded;
        $params = $request->get_url_params();
        $vid = isset($params['vid']) ? (int) $params['vid'] : 0;
        self::$target = $vid;
        $v = $vid > 0 ? wc_get_product($vid) : null;
        $vp = $v ? get_post($vid) : null;
        if (!$v || !$vp || !$v->is_type('variation') || (int) $v->get_parent_id() !== (int) $p->ID) return self::err('webruno_not_found', 404, __('Not found.', 'webruno-crm'));
        return [$p, $product, $v, $vp];
    }

    public static function route_variations($request) {
        return self::respond('variations', function ($q) use ($request) {
            if (!self::wc_ready()) return self::err('webruno_woocommerce_missing', 404, __('WooCommerce is not active on this site.', 'webruno-crm'));
            $params = $request->get_url_params();
            $id = isset($params['id']) ? (int) $params['id'] : 0;
            self::$target = $id;
            $p = $id > 0 ? get_post($id) : null;
            if (!$p || $p->post_type !== 'product') return self::err('webruno_not_found', 404, __('Not found.', 'webruno-crm'));
            if (!current_user_can('read_post', $id)) return self::err('webruno_forbidden', 403, __('The connected WordPress user may not read this product.', 'webruno-crm'));
            $product = wc_get_product($id);
            if (!$product) return self::err('webruno_not_found', 404, __('Not found.', 'webruno-crm'));
            if (!$product->is_type('variable')) return self::err('webruno_bad_request', 400, __('Variations exist only on variable products.', 'webruno-crm'));
            return ['product_id' => $id, 'attribute_specs' => self::attr_specs($product), 'items' => self::variation_rows($product)];
        });
    }

    public static function route_create_variation($request) {
        return self::respond('create_variation', function ($q) use ($request) {
            $loaded = self::variable_parent($request);
            if (is_wp_error($loaded)) return $loaded;
            list($p, $product) = $loaded;
            $data = self::json_body($request, self::VAR_FIELDS);
            if (is_wp_error($data)) return $data;
            $ok = self::require_live_confirmation($p, $data);
            if (is_wp_error($ok)) return $ok;
            if (!isset($data['attributes'])) return self::err('webruno_bad_request', 400, __('attributes is required: a value for every attribute marked variation: true.', 'webruno-crm'));
            $attrs = self::variation_attrs_input($data['attributes'], $product);
            if (is_wp_error($attrs)) return $attrs;
            $en = self::enabled_input($data);
            if (is_wp_error($en)) return $en;
            $d = $data;
            unset($d['attributes']);
            $f = self::product_fields($d);
            if (is_wp_error($f)) return $f;
            if (!self::sale_below_regular(array_merge(['regular_price' => '', 'sale_price' => ''], $f))) return self::err('webruno_bad_request', 400, __('sale_price must be lower than regular_price.', 'webruno-crm'));
            if (count((array) $product->get_children()) >= self::VARIATION_MAX) {
                /* translators: %d: maximum number of variations */
                return self::err('webruno_conflict', 409, sprintf(__('A product can have at most %d variations through Webruno.', 'webruno-crm'), self::VARIATION_MAX));
            }
            if (self::variation_duplicate($product, $attrs, 0)) return self::err('webruno_conflict', 409, __('A variation with exactly these attribute values already exists.', 'webruno-crm'));

            $v = new WC_Product_Variation();
            $v->set_parent_id($p->ID);
            $v->set_attributes($attrs);
            $v->set_status($en === false ? 'private' : 'publish');
            $res = self::product_apply($v, $f);
            if (is_wp_error($res)) return $res;
            self::$target = (int) $v->get_id();
            self::sync_variable($p->ID);
            $fresh = wc_get_product($p->ID);
            $row = self::variation_row(wc_get_product($v->get_id()), self::variation_labels($fresh));
            return ['created' => true, 'product_id' => (int) $p->ID, 'variation' => $row];
        });
    }

    public static function route_update_variation($request) {
        return self::respond('update_variation', function ($q) use ($request) {
            $loaded = self::writable_variation($request);
            if (is_wp_error($loaded)) return $loaded;
            list($p, $product, $v, $vp) = $loaded;
            $data = self::json_body($request, array_merge(['expected_modified_gmt'], self::VAR_FIELDS));
            if (is_wp_error($data)) return $data;
            // expected_modified_gmt is the modified_gmt of the VARIATION (as returned in variation_items)
            $ok = self::check_expected($vp, $data);
            if (is_wp_error($ok)) return $ok;
            $ok = self::require_live_confirmation($p, $data);
            if (is_wp_error($ok)) return $ok;
            $attrs = null;
            if (array_key_exists('attributes', $data)) {
                $attrs = self::variation_attrs_input($data['attributes'], $product);
                if (is_wp_error($attrs)) return $attrs;
                if (self::variation_duplicate($product, $attrs, $v->get_id())) return self::err('webruno_conflict', 409, __('A variation with exactly these attribute values already exists.', 'webruno-crm'));
            }
            $en = self::enabled_input($data);
            if (is_wp_error($en)) return $en;
            $d = $data;
            unset($d['attributes']);
            $f = self::product_fields($d);
            if (is_wp_error($f)) return $f;
            if (!$f && $attrs === null && $en === null) return self::err('webruno_bad_request', 400, __('Nothing to change: send attributes, a price, sku, stock fields or enabled.', 'webruno-crm'));
            $cur = self::product_state($v);
            if (!self::sale_below_regular(array_merge(['regular_price' => '', 'sale_price' => ''], array_intersect_key($cur, ['regular_price' => 1, 'sale_price' => 1]), $f))) {
                return self::err('webruno_bad_request', 400, __('sale_price must be lower than regular_price.', 'webruno-crm'));
            }
            $labels = self::variation_labels($product);
            $prev = [];
            foreach ($f as $k => $_) { if (array_key_exists($k, $cur)) $prev[$k] = $cur[$k]; }
            if ($attrs !== null) {
                $old = [];
                foreach ((array) $v->get_attributes('edit') as $k => $val) $old[isset($labels[$k]) ? $labels[$k] : (string) $k] = (string) $val;
                $prev['attributes'] = $old;
                $v->set_attributes($attrs);
            }
            if ($en !== null) {
                $prev['enabled'] = ($vp->post_status === 'publish');
                $v->set_status($en ? 'publish' : 'private');
            }
            $res = self::product_apply($v, $f);
            if (is_wp_error($res)) return $res;
            self::sync_variable($p->ID);
            $fresh = wc_get_product($p->ID);
            return ['product_id' => (int) $p->ID, 'variation' => self::variation_row(wc_get_product($v->get_id()), self::variation_labels($fresh)), 'previous' => $prev];
        });
    }

    public static function route_delete_variation($request) {
        return self::respond('delete_variation', function ($q) use ($request) {
            $loaded = self::writable_variation($request);
            if (is_wp_error($loaded)) return $loaded;
            list($p, $product, $v, $vp) = $loaded;
            $data = self::json_body($request, ['expected_modified_gmt', 'confirm_delete']);
            if (is_wp_error($data)) return $data;
            if (!current_user_can('delete_post', $vp->ID)) return self::err('webruno_forbidden', 403, __('The connected WordPress user may not delete this variation.', 'webruno-crm'));
            $ok = self::check_expected($vp, $data);
            if (is_wp_error($ok)) return $ok;
            $ok = self::require_delete_confirmation($data, __('this variation', 'webruno-crm'));
            if (is_wp_error($ok)) return $ok;
            $row = self::variation_row($v, self::variation_labels($product));
            if (!$v->delete(true)) return self::err('webruno_error', 500, __('Could not delete the variation.', 'webruno-crm'));
            self::sync_variable($p->ID);
            return ['deleted' => true, 'product_id' => (int) $p->ID, 'variation' => $row];
        });
    }

    public static function route_delete_product($request) {
        return self::respond('delete_product', function ($q) use ($request) {
            $loaded = self::writable_product($request);
            if (is_wp_error($loaded)) return $loaded;
            list($p, $product) = $loaded;
            $data = self::json_body($request, ['expected_modified_gmt', 'confirm_delete']);
            if (is_wp_error($data)) return $data;
            if (!current_user_can('delete_post', $p->ID)) return self::err('webruno_forbidden', 403, __('The connected WordPress user may not delete this product.', 'webruno-crm'));
            $ok = self::check_expected($p, $data);
            if (is_wp_error($ok)) return $ok;
            $ok = self::require_delete_confirmation($data, __('this product (and all its variations)', 'webruno-crm'));
            if (is_wp_error($ok)) return $ok;
            $row = ['id' => (int) $p->ID, 'name' => wp_strip_all_tags($product->get_name()), 'type' => $product->get_type(), 'status' => $p->post_status];
            if (!$product->delete(true)) return self::err('webruno_error', 500, __('Could not delete the product.', 'webruno-crm'));
            $all = get_option(self::OPT_PROD_SNAP, []);
            if (is_array($all) && isset($all[$row['id']])) { unset($all[$row['id']]); update_option(self::OPT_PROD_SNAP, $all, false); }
            return ['deleted' => true] + $row;
        });
    }

    public static function route_delete_post($request) {
        return self::respond('delete_post', function ($q) use ($request) {
            $p = self::writable_post($request, 'delete_post', false);
            if (is_wp_error($p)) return $p;
            $data = self::json_body($request, ['expected_modified_gmt', 'confirm_delete']);
            if (is_wp_error($data)) return $data;
            $ok = self::check_expected($p, $data);
            if (is_wp_error($ok)) return $ok;
            $ok = self::require_delete_confirmation($data, __('this post or page (with its revisions and comments)', 'webruno-crm'));
            if (is_wp_error($ok)) return $ok;
            $row = ['id' => (int) $p->ID, 'type' => $p->post_type, 'title' => wp_strip_all_tags($p->post_title), 'status' => $p->post_status];
            if (!wp_delete_post($p->ID, true)) return self::err('webruno_error', 500, __('Could not delete the item.', 'webruno-crm'));
            return ['deleted' => true] + $row;
        });
    }

    public static function route_delete_term($request) {
        return self::respond('delete_term', function ($q) use ($request) {
            $params = $request->get_url_params();
            $id = isset($params['id']) ? (int) $params['id'] : 0;
            self::$target = $id;
            $t = $id > 0 ? get_term($id) : null;
            if (!$t || is_wp_error($t) || !in_array($t->taxonomy, self::WRITE_TAXES, true)) return self::err('webruno_not_found', 404, __('Not found.', 'webruno-crm'));
            $obj = self::write_taxonomy($t->taxonomy);
            if (is_wp_error($obj)) return $obj;
            if (!current_user_can('delete_term', $id)) return self::err('webruno_forbidden', 403, __('The connected WordPress user may not delete this term.', 'webruno-crm'));
            $data = self::json_body($request, ['expected_version', 'confirm_delete']);
            if (is_wp_error($data)) return $data;
            if (!isset($data['expected_version']) || !is_string($data['expected_version']) || !preg_match('/^[0-9a-f]{40}$/', $data['expected_version'])) {
                return self::err('webruno_bad_request', 400, __('expected_version (the version you got when you last read this term) is required.', 'webruno-crm'));
            }
            if ($data['expected_version'] !== self::term_version($t)) return self::err('webruno_conflict', 409, __('This term was changed on the site after you read it. Read it again before deleting.', 'webruno-crm'));
            $default = ($t->taxonomy === 'category' && (int) get_option('default_category') === $id) || ($t->taxonomy === 'product_cat' && (int) get_option('default_product_cat') === $id);
            if ($default) return self::err('webruno_conflict', 409, __('This is the default category of the site and cannot be deleted.', 'webruno-crm'));
            /* translators: %d: number of items */
            $what = sprintf(__('this category or tag (it is used by %d items, which will lose it)', 'webruno-crm'), (int) $t->count);
            $ok = self::require_delete_confirmation($data, $what);
            if (is_wp_error($ok)) return $ok;
            $row = self::term_row($t);
            $res = wp_delete_term($id, $t->taxonomy);
            if (is_wp_error($res) || !$res) return self::err('webruno_error', 500, __('Could not delete the term.', 'webruno-crm'));
            return ['deleted' => true, 'term' => $row];
        });
    }

    public static function route_delete_menu($request) {
        return self::respond('delete_menu', function ($q) use ($request) {
            $menu = self::loaded_menu($request);
            if (is_wp_error($menu)) return $menu;
            $data = self::json_body($request, ['expected_items_hash', 'confirm_delete']);
            if (is_wp_error($data)) return $data;
            $cur = self::menu_items_of((int) $menu->term_id);
            if (!isset($data['expected_items_hash']) || !is_string($data['expected_items_hash']) || !preg_match('/^[0-9a-f]{40}$/', $data['expected_items_hash'])) {
                return self::err('webruno_bad_request', 400, __('expected_items_hash (the items_hash you got when you last read this menu) is required.', 'webruno-crm'));
            }
            if ($data['expected_items_hash'] !== self::menu_hash($cur)) return self::err('webruno_conflict', 409, __('This menu was changed on the site after you read it. Read it again before deleting.', 'webruno-crm'));
            $ok = self::require_delete_confirmation($data, __('this menu and all its items', 'webruno-crm'));
            if (is_wp_error($ok)) return $ok;
            $res = wp_delete_nav_menu((int) $menu->term_id);
            if (is_wp_error($res) || !$res) return self::err('webruno_error', 500, __('Could not delete the menu.', 'webruno-crm'));
            return ['deleted' => true, 'id' => (int) $menu->term_id, 'name' => $menu->name, 'previous_items' => $cur];
        });
    }

    /* ───────────────────────── menus and header/footer parts (F6c) ───────────────────────── */

    const MENU_MAX_ITEMS = 200;
    const OPT_MENU_SNAP  = 'webruno_crm_menu_snapshots'; // not autoloaded; the last 5 states of each menu
    const TYPES_BLOCK    = ['wp_template_part', 'wp_navigation'];

    private static function theme_caps_error() {
        if (!current_user_can('edit_theme_options')) return self::err('webruno_forbidden', 403, __('The connected WordPress user may not change menus or theme parts.', 'webruno-crm'));
        return true;
    }

    /** Block markup (header, footer, navigation): like clean_content(), but inline SVG icons (social links, logos) survive. */
    private static function clean_block_content($content) {
        $content = preg_replace('#<(script|style|iframe|object|embed|noscript)\b[^>]*>.*?</\1\s*>#is', '', (string) $content);
        $content = preg_replace('#<(script|style|iframe|object|embed|noscript)\b.*$#is', '', (string) $content);
        $allowed = wp_kses_allowed_html('post');
        $svg_attrs = ['class' => true, 'id' => true, 'viewbox' => true, 'width' => true, 'height' => true, 'fill' => true, 'stroke' => true,
            'd' => true, 'points' => true, 'x' => true, 'y' => true, 'cx' => true, 'cy' => true, 'r' => true, 'rx' => true, 'ry' => true,
            'x1' => true, 'y1' => true, 'x2' => true, 'y2' => true, 'transform' => true, 'xmlns' => true, 'role' => true,
            'aria-hidden' => true, 'aria-label' => true, 'focusable' => true, 'fill-rule' => true, 'clip-rule' => true,
            'stroke-width' => true, 'stroke-linecap' => true, 'stroke-linejoin' => true, 'opacity' => true];
        foreach (['svg', 'g', 'path', 'circle', 'rect', 'line', 'polyline', 'polygon', 'ellipse', 'title'] as $tag) $allowed[$tag] = $svg_attrs;
        return wp_kses((string) $content, $allowed);
    }

    private static function menu_item_row($it) {
        return [
            'id' => (int) $it->ID, 'title' => wp_strip_all_tags((string) $it->title), 'url' => (string) $it->url,
            'parent' => (int) $it->menu_item_parent, 'order' => (int) $it->menu_order,
            'type' => (string) $it->type, 'object' => (string) $it->object, 'object_id' => (int) $it->object_id,
            'target' => (string) $it->target,
        ];
    }

    private static function menu_items_of($menu_id) {
        $items = wp_get_nav_menu_items($menu_id, ['post_status' => 'any', 'update_post_term_cache' => false]);
        $rows = [];
        if (is_array($items)) { foreach ($items as $it) $rows[] = self::menu_item_row($it); }
        return $rows;
    }

    private static function menu_hash(array $rows) { return sha1((string) wp_json_encode($rows)); }

    /** Rows with real item IDs -> a list with parent_index (position of the parent in the list). Used for snapshots and for applying. */
    private static function rows_to_spec(array $rows) {
        $pos = [];
        foreach ($rows as $i => $r) $pos[$r['id']] = $i;
        $out = [];
        foreach ($rows as $r) {
            $out[] = ['title' => $r['title'], 'url' => $r['url'], 'type' => $r['type'], 'object' => $r['object'], 'object_id' => $r['object_id'],
                      'target' => $r['target'], 'parent_index' => ($r['parent'] > 0 && isset($pos[$r['parent']])) ? $pos[$r['parent']] : null];
        }
        return $out;
    }

    /** Validates the items the caller sent. Returns the normalised list or WP_Error. */
    private static function menu_spec_from_input($items) {
        if (!is_array($items) || ($items && array_values($items) !== $items)) return self::err('webruno_bad_request', 400, __('items must be a list.', 'webruno-crm'));
        if (count($items) > self::MENU_MAX_ITEMS) return self::err('webruno_too_large', 413, __('Too many menu items.', 'webruno-crm'));
        $public_types = array_diff(get_post_types(['public' => true]), ['attachment']);
        $public_taxes = get_taxonomies(['public' => true]);
        $out = [];
        foreach ($items as $i => $it) {
            if (!is_array($it)) return self::err('webruno_bad_request', 400, __('Each menu item must be an object.', 'webruno-crm'));
            foreach (array_keys($it) as $k) {
                if (!in_array($k, ['title', 'url', 'type', 'object', 'object_id', 'target', 'parent_index'], true)) {
                    /* translators: %s: field name */
                    return self::err('webruno_bad_request', 400, sprintf(__('Unknown menu item field: %s', 'webruno-crm'), sanitize_key((string) $k)));
                }
            }
            $type = isset($it['type']) ? $it['type'] : 'custom';
            if (!in_array($type, ['custom', 'post_type', 'taxonomy'], true)) return self::err('webruno_bad_request', 400, __('type must be custom, post_type or taxonomy.', 'webruno-crm'));
            $title = (isset($it['title']) && is_string($it['title'])) ? trim(sanitize_text_field($it['title'])) : '';
            if (mb_strlen($title) > 200) return self::err('webruno_bad_request', 400, __('A menu item title may have at most 200 characters.', 'webruno-crm'));
            $url = '';
            $object = '';
            $object_id = 0;
            if ($type === 'custom') {
                if ($title === '') return self::err('webruno_bad_request', 400, __('A custom link needs a title.', 'webruno-crm'));
                $raw = (isset($it['url']) && is_string($it['url'])) ? trim($it['url']) : '';
                $url = ($raw !== '' && mb_strlen($raw) <= 2000) ? esc_url_raw($raw, ['http', 'https', 'mailto', 'tel']) : '';
                if ($url === '') return self::err('webruno_bad_request', 400, __('A custom link needs a valid url (https://, a path starting with /, #anchor, mailto: or tel:).', 'webruno-crm'));
                $object = 'custom';
            } else {
                $object = (isset($it['object']) && is_string($it['object'])) ? $it['object'] : '';
                $object_id = (isset($it['object_id']) && is_int($it['object_id'])) ? $it['object_id'] : 0;
                if ($object_id <= 0) return self::err('webruno_bad_request', 400, __('object_id must be a positive whole number.', 'webruno-crm'));
                if ($type === 'post_type') {
                    $p = in_array($object, $public_types, true) ? get_post($object_id) : null;
                    if (!$p || $p->post_type !== $object || $p->post_status === 'trash') return self::err('webruno_bad_request', 400, __('A linked page or post was not found.', 'webruno-crm'));
                } else {
                    $t = in_array($object, $public_taxes, true) ? get_term($object_id, $object) : null;
                    if (!$t || is_wp_error($t)) return self::err('webruno_bad_request', 400, __('A linked category or tag was not found.', 'webruno-crm'));
                }
            }
            $parent = null;
            if (isset($it['parent_index'])) {
                if (!is_int($it['parent_index']) || $it['parent_index'] < 0 || $it['parent_index'] >= $i) return self::err('webruno_bad_request', 400, __('parent_index must be the position of an earlier item in the list (the first item is 0).', 'webruno-crm'));
                $parent = $it['parent_index'];
            }
            $out[] = ['title' => $title, 'url' => $url, 'type' => $type, 'object' => $object, 'object_id' => $object_id,
                      'target' => (isset($it['target']) && $it['target'] === '_blank') ? '_blank' : '', 'parent_index' => $parent];
        }
        return $out;
    }

    private static function menu_apply($menu_id, array $spec) {
        $ids = [];
        foreach ($spec as $i => $s) {
            $args = [
                'menu-item-type' => $s['type'], 'menu-item-status' => 'publish', 'menu-item-position' => $i + 1,
                'menu-item-parent-id' => ($s['parent_index'] !== null && isset($ids[$s['parent_index']])) ? $ids[$s['parent_index']] : 0,
                'menu-item-target' => $s['target'],
            ];
            if ($s['type'] === 'custom') {
                $args['menu-item-title'] = $s['title'];
                $args['menu-item-url'] = $s['url'];
            } else {
                $args['menu-item-object'] = $s['object'];
                $args['menu-item-object-id'] = $s['object_id'];
                if ($s['title'] !== '') $args['menu-item-title'] = $s['title'];
            }
            $id = wp_update_nav_menu_item($menu_id, 0, $args);
            if (is_wp_error($id) || !$id) return false;
            $ids[$i] = (int) $id;
        }
        return true;
    }

    /** Replaces all items of a menu. If anything fails half way, the previous items are put back. */
    private static function menu_replace($menu_id, array $spec) {
        $old_spec = self::rows_to_spec(self::menu_items_of($menu_id));
        foreach (self::menu_items_of($menu_id) as $r) wp_delete_post($r['id'], true);
        if (self::menu_apply($menu_id, $spec)) return true;
        foreach (self::menu_items_of($menu_id) as $r) wp_delete_post($r['id'], true);
        self::menu_apply($menu_id, $old_spec);
        return false;
    }

    private static function menu_snap_push($menu_id, array $spec) {
        $all = get_option(self::OPT_MENU_SNAP, []);
        if (!is_array($all)) $all = [];
        $list = (isset($all[$menu_id]) && is_array($all[$menu_id])) ? $all[$menu_id] : [];
        array_unshift($list, ['ts' => time(), 'spec' => $spec]);
        $all[$menu_id] = array_slice($list, 0, 5);
        update_option(self::OPT_MENU_SNAP, $all, false);
    }

    /** Returns the most recent saved state of the menu and removes it from the list, or null. */
    private static function menu_snap_pop($menu_id) {
        $all = get_option(self::OPT_MENU_SNAP, []);
        if (!is_array($all) || empty($all[$menu_id]) || !is_array($all[$menu_id])) return null;
        $first = array_shift($all[$menu_id]);
        update_option(self::OPT_MENU_SNAP, $all, false);
        return (is_array($first) && isset($first['spec']) && is_array($first['spec'])) ? $first['spec'] : null;
    }

    private static function loaded_menu($request) {
        $params = $request->get_url_params();
        $id = isset($params['id']) ? (int) $params['id'] : 0;
        self::$target = $id;
        $ok = self::theme_caps_error();
        if (is_wp_error($ok)) return $ok;
        $menu = $id > 0 ? wp_get_nav_menu_object($id) : false;
        if (!$menu || is_wp_error($menu)) return self::err('webruno_not_found', 404, __('Not found.', 'webruno-crm'));
        return $menu;
    }

    private static function menu_result($menu, array $extra = []) {
        $rows = self::menu_items_of((int) $menu->term_id);
        return array_merge(['id' => (int) $menu->term_id, 'name' => $menu->name, 'items' => $rows, 'items_hash' => self::menu_hash($rows)], $extra);
    }

    public static function route_menus($request) {
        return self::respond('menus', function ($q) {
            $ok = self::theme_caps_error();
            if (is_wp_error($ok)) return $ok;
            $locs = get_nav_menu_locations();
            $reg  = get_registered_nav_menus();
            $menus = [];
            foreach (wp_get_nav_menus() as $m) {
                $where = [];
                foreach ((array) $locs as $loc => $mid) { if ((int) $mid === (int) $m->term_id) $where[] = isset($reg[$loc]) ? $reg[$loc] : $loc; }
                $menus[] = ['id' => (int) $m->term_id, 'name' => $m->name, 'slug' => $m->slug, 'item_count' => (int) $m->count, 'locations' => $where];
            }
            $navs = [];
            foreach (get_posts(['post_type' => 'wp_navigation', 'post_status' => ['publish', 'draft'], 'numberposts' => 50, 'orderby' => 'title', 'order' => 'ASC']) as $n) {
                $navs[] = ['id' => (int) $n->ID, 'title' => wp_strip_all_tags($n->post_title), 'status' => $n->post_status, 'modified_gmt' => self::modified_iso($n)];
            }
            return ['block_theme' => (bool) wp_is_block_theme(), 'menus' => $menus, 'navigations' => $navs,
                    'note' => 'menus: classic menus, edited with the menu items routes. navigations: block theme menus, edited like a post (content is block markup).'];
        });
    }

    public static function route_menu($request) {
        return self::respond('menu', function ($q) use ($request) {
            $menu = self::loaded_menu($request);
            if (is_wp_error($menu)) return $menu;
            return self::menu_result($menu);
        });
    }

    public static function route_set_menu_items($request) {
        return self::respond('set_menu_items', function ($q) use ($request) {
            $menu = self::loaded_menu($request);
            if (is_wp_error($menu)) return $menu;
            $data = self::json_body($request, ['items', 'expected_items_hash', 'confirm']);
            if (is_wp_error($data)) return $data;
            if (!isset($data['confirm']) || $data['confirm'] !== true) return self::err('webruno_confirm_required', 400, __('Changing a menu changes what visitors see. Ask the person to confirm, then send confirm: true.', 'webruno-crm'));
            $cur = self::menu_items_of((int) $menu->term_id);
            if (!isset($data['expected_items_hash']) || !is_string($data['expected_items_hash']) || !preg_match('/^[0-9a-f]{40}$/', $data['expected_items_hash'])) {
                return self::err('webruno_bad_request', 400, __('expected_items_hash (the items_hash you got when you last read this menu) is required.', 'webruno-crm'));
            }
            if ($data['expected_items_hash'] !== self::menu_hash($cur)) return self::err('webruno_conflict', 409, __('This menu was changed on the site after you read it. Read it again before editing.', 'webruno-crm'));
            if (!array_key_exists('items', $data)) return self::err('webruno_bad_request', 400, __('items is required (the complete new list of menu items).', 'webruno-crm'));
            $spec = self::menu_spec_from_input($data['items']);
            if (is_wp_error($spec)) return $spec;
            self::menu_snap_push((int) $menu->term_id, self::rows_to_spec($cur));
            if (!self::menu_replace((int) $menu->term_id, $spec)) return self::err('webruno_error', 500, __('Could not save the menu. The previous items were put back.', 'webruno-crm'));
            return self::menu_result($menu, ['saved' => true, 'previous_state_saved' => true]);
        });
    }

    public static function route_restore_menu($request) {
        return self::respond('restore_menu', function ($q) use ($request) {
            $menu = self::loaded_menu($request);
            if (is_wp_error($menu)) return $menu;
            $data = self::json_body($request, ['expected_items_hash', 'confirm']);
            if (is_wp_error($data)) return $data;
            if (!isset($data['confirm']) || $data['confirm'] !== true) return self::err('webruno_confirm_required', 400, __('Restoring a menu changes what visitors see. Ask the person to confirm, then send confirm: true.', 'webruno-crm'));
            $cur = self::menu_items_of((int) $menu->term_id);
            if (!isset($data['expected_items_hash']) || !is_string($data['expected_items_hash']) || $data['expected_items_hash'] !== self::menu_hash($cur)) {
                return self::err('webruno_conflict', 409, __('This menu was changed on the site after you read it. Read it again before restoring.', 'webruno-crm'));
            }
            $spec = self::menu_snap_pop((int) $menu->term_id);
            if ($spec === null) return self::err('webruno_not_found', 404, __('Nothing to restore for this menu.', 'webruno-crm'));
            if (!self::menu_replace((int) $menu->term_id, $spec)) return self::err('webruno_error', 500, __('Could not restore the menu. The current items were kept.', 'webruno-crm'));
            return self::menu_result($menu, ['restored' => true]);
        });
    }

    /** Header, footer and other template parts of a block theme. Classic themes have none: the answer says so. */
    public static function route_parts($request) {
        return self::respond('parts', function ($q) {
            $ok = self::theme_caps_error();
            if (is_wp_error($ok)) return $ok;
            if (!wp_is_block_theme()) {
                return ['block_theme' => false, 'items' => [], 'note' => 'This site uses a classic (non-block) theme. Header and footer of classic themes, and of Elementor or other page builders, cannot be edited through this connection.'];
            }
            $items = [];
            foreach (get_block_templates([], 'wp_template_part') as $t) {
                $id = isset($t->wp_id) ? (int) $t->wp_id : 0;
                $p  = $id > 0 ? get_post($id) : null;
                $items[] = ['slug' => (string) $t->slug, 'title' => wp_strip_all_tags((string) $t->title), 'area' => (string) $t->area,
                            'customized' => $id > 0, 'id' => $id > 0 ? $id : null, 'modified_gmt' => $p ? self::modified_iso($p) : null];
            }
            return ['block_theme' => true, 'theme' => get_stylesheet(), 'items' => $items,
                    'note' => 'To edit a part: call customize for its slug (gives its id), read it as a post, then update it. Parts that are not customized yet have no id.'];
        });
    }

    /** Makes sure the part has its own copy in the database (a copy of the theme file the first time) and returns its id. */
    public static function route_part_customize($request) {
        return self::respond('part_customize', function ($q) use ($request) {
            $ok = self::theme_caps_error();
            if (is_wp_error($ok)) return $ok;
            $data = self::json_body($request, []);
            if (is_wp_error($data)) return $data;
            if (!wp_is_block_theme()) return self::err('webruno_bad_request', 400, __('This site does not use a block theme.', 'webruno-crm'));
            $params = $request->get_url_params();
            $slug = isset($params['slug']) ? sanitize_key((string) $params['slug']) : '';
            $theme = get_stylesheet();
            $tpl = $slug !== '' ? get_block_template($theme . '//' . $slug, 'wp_template_part') : null;
            if (!$tpl) return self::err('webruno_not_found', 404, __('Not found.', 'webruno-crm'));
            $existing = isset($tpl->wp_id) ? (int) $tpl->wp_id : 0;
            if ($existing > 0 && get_post($existing)) {
                self::$target = $existing;
                return array_merge(self::post_summary(get_post($existing)), ['created' => false]);
            }
            $id = wp_insert_post(wp_slash([
                'post_type' => 'wp_template_part', 'post_name' => $slug, 'post_title' => (string) $tpl->title,
                'post_content' => (string) $tpl->content, 'post_status' => 'publish', 'post_author' => get_current_user_id(),
            ]), true);
            if (is_wp_error($id) || !$id) return self::err('webruno_error', 500, __('Could not prepare the part for editing.', 'webruno-crm'));
            wp_set_post_terms($id, $theme, 'wp_theme');
            if (!empty($tpl->area)) wp_set_post_terms($id, (string) $tpl->area, 'wp_template_part_area');
            self::$target = (int) $id;
            return array_merge(self::post_summary(get_post($id)), ['created' => true]);
        });
    }

    /* ───────────────────────── settings page section ───────────────────────── */

    public static function handle_save() {
        if (!current_user_can('manage_options')) wp_die(esc_html__('Insufficient permissions.', 'webruno-crm'), '', ['response' => 403]);
        check_admin_referer('webruno_crm_content_save');
        if (self::has_key()) {
            $read = (isset($_POST['webruno_content_read']) && '1' === sanitize_text_field(wp_unslash($_POST['webruno_content_read']))) ? '1' : '0';
            if ($read !== get_option(self::OPT_READ, '0')) {
                update_option(self::OPT_READ, $read, false);
                self::audit_admin('switch_read', 'ok', $read === '1' ? 'on' : 'off');
            }
            // Editing needs reading too: turning read off turns edit off.
            $write = (self::WRITE_AVAILABLE && $read === '1' && isset($_POST['webruno_content_write']) && '1' === sanitize_text_field(wp_unslash($_POST['webruno_content_write']))) ? '1' : '0';
            if ($write !== get_option(self::OPT_WRITE, '0')) {
                update_option(self::OPT_WRITE, $write, false);
                self::audit_admin('switch_write', 'ok', $write === '1' ? 'on' : 'off');
            }
        }
        wp_safe_redirect(add_query_arg('webruno_content_saved', '1', admin_url('admin.php?page=webruno-crm-settings')));
        exit;
    }

    public static function render_settings_section() {
        if (!current_user_can('manage_options')) return;
        $has_key = self::has_key();
        $uid  = (int) get_option(self::OPT_USER, 0);
        $user = $uid > 0 ? get_userdata($uid) : false;
        ?>
        <hr />
        <h2><?php esc_html_e('Content access for Webruno (external connection)', 'webruno-crm'); ?></h2>
        <?php if (isset($_GET['webruno_content_saved'])) : // phpcs:ignore WordPress.Security.NonceVerification ?>
            <div class="notice notice-success inline"><p><?php esc_html_e('Settings saved.', 'webruno-crm'); ?></p></div>
        <?php endif; ?>
        <p><?php esc_html_e('If you switch this on, the Webruno service — and AI assistants you connect to your Webruno account, such as Claude — can read content from this site: posts, pages, media list, categories and WooCommerce products. Content is sent to Webruno only when you or your assistant asks for it. Requests are signed, run with the permissions of the WordPress user below, and are listed in the log underneath. This is off by default; you can turn it off at any time.', 'webruno-crm'); ?></p>
        <?php if (!$has_key) : ?>
            <div class="notice notice-warning inline"><p><?php esc_html_e('Not available yet: connect this site to Webruno from the Webruno CRM page first (a content-access key is created during the connection).', 'webruno-crm'); ?></p></div>
        <?php endif; ?>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <input type="hidden" name="action" value="webruno_crm_content_save" />
            <?php wp_nonce_field('webruno_crm_content_save'); ?>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><?php esc_html_e('Read content', 'webruno-crm'); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" name="webruno_content_read" value="1" <?php checked(self::read_enabled()); ?> <?php disabled(!$has_key); ?> />
                            <?php esc_html_e('Allow Webruno to read content', 'webruno-crm'); ?>
                        </label>
                        <?php if ($user) : ?>
                            <p class="description"><?php echo esc_html(sprintf(
                                /* translators: %s: WordPress user display name */
                                __('Requests run as: %s (only what this user is allowed to see).', 'webruno-crm'), $user->display_name)); ?></p>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Edit content', 'webruno-crm'); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" name="webruno_content_write" value="1" <?php checked(self::write_enabled()); ?> <?php disabled(!$has_key); ?> />
                            <?php esc_html_e('Allow Webruno to edit content', 'webruno-crm'); ?>
                        </label>
                        <p class="description"><?php esc_html_e('Webruno (and AI assistants you connect) can then create drafts and edit the title, text, excerpt and address of posts and pages, publish them or move them to the trash. They can create and edit simple and variable WooCommerce products with their variations, and categories and tags. They can also change your navigation menus, and the header, footer and navigation of a block theme. Permanent deletion of posts, pages, products, variations, categories and menus is possible only when the assistant has asked you in the chat and you confirmed it. Nothing else on your site can be changed. Before every edit of a post, page, header, footer or block navigation a revision of the current version is saved so you can undo it (Revisions). Before a menu is changed its previous state is kept so Webruno can put it back. Scripts are always removed from what is written. Needs "Read content" to be on.', 'webruno-crm'); ?></p>
                    </td>
                </tr>
            </table>
            <?php submit_button(__('Save content access', 'webruno-crm'), 'secondary', 'submit', true, $has_key ? null : ['disabled' => 'disabled']); ?>
        </form>

        <h3><?php esc_html_e('Recent requests', 'webruno-crm'); ?></h3>
        <?php $rows = self::recent_audit(20); ?>
        <?php if (!$rows) : ?>
            <p><?php esc_html_e('No requests yet.', 'webruno-crm'); ?></p>
        <?php else : ?>
            <table class="widefat striped" style="max-width:900px">
                <thead><tr>
                    <th><?php esc_html_e('Time', 'webruno-crm'); ?></th><th><?php esc_html_e('Operation', 'webruno-crm'); ?></th>
                    <th><?php esc_html_e('Item', 'webruno-crm'); ?></th><th><?php esc_html_e('Result', 'webruno-crm'); ?></th>
                    <th><?php esc_html_e('Detail', 'webruno-crm'); ?></th>
                </tr></thead>
                <tbody>
                <?php foreach ($rows as $r) : ?>
                    <tr>
                        <td><?php echo esc_html(wp_date('Y-m-d H:i:s', (int) $r['ts'])); ?></td>
                        <td><?php echo esc_html($r['op']); ?></td>
                        <td><?php echo (int) $r['target_id'] > 0 ? esc_html((string) (int) $r['target_id']) : '&mdash;'; ?></td>
                        <td><?php echo esc_html($r['result'] . ((int) $r['http'] ? ' (' . (int) $r['http'] . ')' : '')); ?></td>
                        <td><?php echo esc_html($r['code']); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <p class="description"><?php esc_html_e('The last 1000 requests are kept. No keys or content are stored in this log.', 'webruno-crm'); ?></p>
        <?php endif;
    }
}
