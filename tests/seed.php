<?php
/**
 * TEST ONLY — never shipped (exclude tests/ from the release zip).
 * Seeds the TEST WordPress (127.0.0.1:8081, SQLite) with users, content and a content-API connection.
 * Run: /home/wordpress/bin/wp eval-file tests/seed.php
 * Writes the created ids to /tmp/f6a-seed.json (ids and the fake test secret only; nothing real).
 */
if (!defined('ABSPATH')) exit;
if (!defined('DB_NAME') || strpos(DB_NAME, 'wptest_') !== 0) { fwrite(STDERR, "refusing: not the wptest_ database\n"); exit(1); }
if (strpos(home_url(), '127.0.0.1:8081') === false) { fwrite(STDERR, "refusing: not the local test site\n"); exit(1); }

$ids = [];

// previous seed rows
$old = get_posts(['post_type' => ['post', 'page', 'product', 'attachment'], 'post_status' => 'any', 'numberposts' => -1,
                  'meta_key' => '_f6a_seed', 'meta_value' => '1', 'fields' => 'ids']);
foreach ($old as $oid) wp_delete_post($oid, true);

$user = function ($login, $role) {
    $u = get_user_by('login', $login);
    if ($u) return (int) $u->ID;
    return (int) wp_insert_user(['user_login' => $login, 'user_pass' => wp_generate_password(24), 'user_email' => $login . '@example.test', 'role' => $role]);
};
$ids['admin']       = 1;
$ids['contributor'] = $user('f6a_contributor', 'contributor');
$ids['subscriber']  = $user('f6a_subscriber', 'subscriber');

$mk = function ($key, array $args) use (&$ids) {
    $id = wp_insert_post(array_merge(['post_type' => 'post', 'post_author' => 1], $args), true);
    if (is_wp_error($id)) { fwrite(STDERR, $key . ': ' . $id->get_error_message() . "\n"); exit(1); }
    update_post_meta($id, '_f6a_seed', '1');
    $ids[$key] = (int) $id;
};

$cat = wp_insert_term('F6A Cat', 'category');
$cat_id = is_wp_error($cat) ? (int) get_term_by('name', 'F6A Cat', 'category')->term_id : (int) $cat['term_id'];

$mk('post_public',  ['post_title' => 'F6A public post', 'post_status' => 'publish', 'post_content' => '<p>PUBLIC-BODY</p><script>alert(1)</script>', 'post_category' => [$cat_id]]);
$mk('post_draft',   ['post_title' => 'F6A admin draft', 'post_status' => 'draft', 'post_content' => 'ADMIN-DRAFT-SECRET']);
$mk('post_private', ['post_title' => 'F6A admin private', 'post_status' => 'private', 'post_content' => 'ADMIN-PRIVATE-SECRET']);
$mk('post_contrib_draft', ['post_title' => 'F6A contributor draft', 'post_status' => 'draft', 'post_author' => $ids['contributor'], 'post_content' => 'CONTRIB-DRAFT']);
$mk('post_protected', ['post_title' => 'F6A protected', 'post_status' => 'publish', 'post_password' => 'pw-test', 'post_content' => 'PROTECTED-BODY']);
$mk('page_public',  ['post_type' => 'page', 'post_title' => 'F6A public page', 'post_status' => 'publish', 'post_content' => 'PAGE-BODY']);
$mk('page_draft',   ['post_type' => 'page', 'post_title' => 'F6A draft page', 'post_status' => 'draft', 'post_content' => 'PAGE-DRAFT-SECRET']);

// media: one png, one pdf (allowed) and one txt (must never be listed)
$up = wp_upload_dir();
wp_mkdir_p($up['path']);
$files = [
    'f6a-test.png' => ['image/png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==')],
    'f6a-test.pdf' => ['application/pdf', "%PDF-1.1\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n"],
    'f6a-test.txt' => ['text/plain', "not allowed in the content API\n"],
];
foreach ($files as $name => $spec) {
    $path = trailingslashit($up['path']) . $name;
    file_put_contents($path, $spec[1]);
    $aid = wp_insert_attachment(['post_mime_type' => $spec[0], 'post_title' => 'F6A ' . $name, 'post_status' => 'inherit', 'post_author' => 1], $path);
    update_post_meta($aid, '_f6a_seed', '1');
    $ids['media_' . pathinfo($name, PATHINFO_EXTENSION)] = (int) $aid;
}

// WooCommerce products
if (class_exists('WC_Product_Simple')) {
    $p = new WC_Product_Simple();
    $p->set_name('F6A product'); $p->set_status('publish'); $p->set_regular_price('19.90'); $p->set_sku('F6A-1');
    $p->set_manage_stock(true); $p->set_stock_quantity(5); $p->set_description('<p>PRODUCT-DESC</p>');
    $ids['product_public'] = (int) $p->save();
    $d = new WC_Product_Simple();
    $d->set_name('F6A draft product'); $d->set_status('draft'); $d->set_regular_price('5');
    $ids['product_draft'] = (int) $d->save();
    foreach (['product_public', 'product_draft'] as $k) update_post_meta($ids[$k], '_f6a_seed', '1');
}

// content API connection with a FAKE secret (valid format, not a real key); switches start OFF as in production
$secret = 'wsk_' . str_repeat('T', 43);
$ok = Webruno_Content_API::store_connection($secret, 1);
$ids['_connected'] = (bool) $ok;
$ids['_site_id']   = (string) get_option('webruno_crm_site_id');
$ids['_secret']    = $secret;

file_put_contents('/tmp/f6a-seed.json', wp_json_encode($ids));
echo 'seeded ' . count($ids) . " keys\n";
