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
 * F6a registers GET routes only. There is no write route in this version.
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
    const WRITE_AVAILABLE = false; // F6b flips this; until then the "edit" switch is shown but cannot be turned on

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
        $wpdb->query($wpdb->prepare('DELETE FROM ' . self::table_nonces() . ' WHERE created < %d', time() - self::NONCE_KEEP));
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

    /* ───────────────────────── audit log ───────────────────────── */

    private static function audit($op, $route, $target, $result, $code, $http) {
        global $wpdb;
        $prev = $wpdb->suppress_errors(true);
        $ok = $wpdb->insert(self::table_audit(), [
            'ts' => time(), 'op' => substr((string) $op, 0, 40), 'route' => substr((string) $route, 0, 120),
            'target_id' => (int) $target, 'result' => substr((string) $result, 0, 20),
            'code' => substr((string) $code, 0, 60), 'http' => (int) $http,
        ], ['%d', '%s', '%s', '%d', '%s', '%s', '%d']);
        if ($ok && $wpdb->insert_id > self::AUDIT_CAP) {
            $wpdb->query($wpdb->prepare('DELETE FROM ' . self::table_audit() . ' WHERE id <= %d', $wpdb->insert_id - self::AUDIT_CAP));
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
        $rows = $wpdb->get_results($wpdb->prepare('SELECT ts, op, route, target_id, result, code, http FROM ' . self::table_audit() . ' ORDER BY id DESC LIMIT %d', (int) $limit), ARRAY_A);
        $wpdb->suppress_errors($prev);
        return is_array($rows) ? $rows : [];
    }

    /* ───────────────────────── authentication ───────────────────────── */

    /** Returns ['query' => [...]] on success or ['reason' => '...'] (internal reason, never sent to the caller). */
    private static function authenticate($request) {
        $method = strtoupper((string) $request->get_method());
        if ($method !== 'GET') return ['reason' => 'method'];

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
        $res = $wpdb->query($wpdb->prepare('INSERT INTO ' . self::table_nonces() . ' (nonce_hash, created) VALUES (%s, %d)', hash('sha256', $site . "\n" . $nonce), time()));
        $wpdb->suppress_errors($prev);
        return $res === 1;
    }

    private static function over_rate_limit() {
        global $wpdb;
        // TODO(owner): confirm. Default 60 requests per minute per site (adjustable with the filter; 0 = no limit).
        $limit = (int) apply_filters('webruno_crm_content_rate_limit', 60);
        if ($limit <= 0) return false;
        $prev = $wpdb->suppress_errors(true);
        $n = $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . self::table_audit() . ' WHERE ts >= %d AND result <> %s', time() - 60, 'rejected'));
        $wpdb->suppress_errors($prev);
        return (int) $n >= $limit;
    }

    private static function err($code, $status, $message) {
        return new WP_Error($code, $message, ['status' => $status]);
    }

    /** permission_callback for every route: authenticate, rate limit, switch, user. The handler runs only after this. */
    public static function permit($request) {
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
            'date_gmt' => self::iso($p->post_date_gmt), 'modified_gmt' => self::iso($p->post_modified_gmt),
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
            if (!$p || !in_array($p->post_type, ['post', 'page'], true)) return self::err('webruno_not_found', 404, __('Not found.', 'webruno-crm'));
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
                            'parent' => (int) $t->parent, 'count' => (int) $t->count, 'description' => wp_strip_all_tags($t->description)];
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
            'modified_gmt' => self::iso($p->post_modified_gmt),
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
            return $out;
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
            // The edit switch cannot be turned on in this version (no editing feature exists yet).
            if (!self::WRITE_AVAILABLE) update_option(self::OPT_WRITE, '0', false);
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
                            <input type="checkbox" name="webruno_content_write" value="1" disabled="disabled" />
                            <?php esc_html_e('Allow Webruno to edit content', 'webruno-crm'); ?>
                        </label>
                        <p class="description"><?php esc_html_e('Not available in this version: Webruno cannot change anything on your site.', 'webruno-crm'); ?></p>
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
