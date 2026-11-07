// TEST ONLY. End-to-end test of F6d/F6e against the TEST WordPress (127.0.0.1:8081, SQLite): categories, simple and
// variable products, variations and the confirmed permanent deletes. Real signed HTTP requests, real WooCommerce.
// Run:  /home/wordpress/bin/test-up.sh && wp eval-file tests/seed.php && node tests/e2e/content-variable-delete.e2e.mjs ; /home/wordpress/bin/test-down.sh
import http from 'node:http';
import crypto from 'node:crypto';
import fs from 'node:fs';
import { execFileSync } from 'node:child_process';

const HOST = '127.0.0.1', PORT = 8081;
const seed = JSON.parse(fs.readFileSync('/tmp/f6a-seed.json', 'utf8'));
const SECRET = seed._secret, SITE = seed._site_id;
const P = '/webruno/v1/content/';

let pass = 0, fail = 0;
const ok = (c, label, extra = '') => { if (c) { pass++; console.log('ok   ' + label); } else { fail++; console.log('FAIL ' + label + ' ' + extra); } };

const ru = (s) => encodeURIComponent(String(s)).replace(/[!'()*]/g, (c) => '%' + c.charCodeAt(0).toString(16).toUpperCase());
const cq = (q) => Object.keys(q).filter((k) => k !== 'rest_route').sort().map((k) => `${ru(k)}=${ru(q[k])}`).join('&');
const sha = (b) => crypto.createHash('sha256').update(b).digest('hex');
const sign = (secret, m, route, q, ts, nonce, body) =>
    crypto.createHmac('sha256', secret).update([m.toUpperCase(), route, cq(q), ts, nonce, sha(body)].join('\n')).digest('hex');

const wp = (...a) => { try { return execFileSync('flock', ['-w', '180', '/tmp/heavy.lock', 'nice', '-n', '10', '/home/wordpress/bin/wp', ...a], { encoding: 'utf8', stdio: ['ignore', 'pipe', 'ignore'] }); } catch (e) { return String(e.stdout || ''); } };
const wpPhp = (code) => wp('eval', code);
const setOpt = (k, v) => wp('option', 'update', k, String(v));
const useUser = (id) => setOpt('webruno_crm_content_user_id', id);

const bodies = [];
function call(o = {}) {
    const method = o.method || 'GET', route = o.route || P + 'site';
    const q = o.query || {};
    const body = o.json !== undefined ? JSON.stringify(o.json) : (o.body || '');
    const ts = String(Math.floor(Date.now() / 1000));
    const nonce = crypto.randomBytes(16).toString('hex');
    const sig = sign(SECRET, method, route, q, ts, nonce, body);
    const raw = 'rest_route=' + ru(route) + Object.keys(q).sort().map((k) => `&${ru(k)}=${ru(q[k])}`).join('');
    const headers = { 'X-Webruno-Site': SITE, 'X-Webruno-Timestamp': ts, 'X-Webruno-Nonce': nonce, 'X-Webruno-Signature': sig };
    if (body) { headers['Content-Type'] = 'application/json'; headers['Content-Length'] = Buffer.byteLength(body); }
    return new Promise((resolve) => {
        const r = http.request({ host: HOST, port: PORT, path: '/?' + raw, method, headers }, (res) => {
            let d = ''; res.on('data', (c) => d += c); res.on('end', () => { bodies.push(d); let j = null; try { j = JSON.parse(d); } catch (e) { /* not json */ } resolve({ status: res.statusCode, json: j, text: d }); });
        });
        r.on('error', (e) => resolve({ status: 0, json: null, text: String(e) }));
        if (body) r.write(body);
        r.end();
    });
}
const post = (route, json) => call({ method: 'POST', route: P + route, json });
const get = (route, query) => call({ route: P + route, query });
const short = (r) => r.status + ' ' + String(r.text).slice(0, 160);

// ───────── setup ─────────
fs.mkdirSync('/home/wordpress/wp-test/wp-content/mu-plugins', { recursive: true });
fs.writeFileSync('/home/wordpress/wp-test/wp-content/mu-plugins/f6e-test.php',
    "<?php /* TEST ONLY (F6e e2e): raises the rate limit */\nadd_filter('webruno_crm_content_rate_limit', function ($n) { return 100000; });\n");
setOpt('webruno_crm_content_read', 1); setOpt('webruno_crm_content_write', 1); useUser(1);
const madeProducts = [], madePosts = [], madeTerms = [];

// ───────── 1. categories: create, delete needs confirmation ─────────
let r = await post('terms', { taxonomy: 'product_cat', name: 'F6E Shoes' });
ok(r.status === 200 && r.json.created === true, 'category: create product_cat', short(r));
const catId = r.json.id; madeTerms.push(catId);
r = await post('terms/' + catId + '/delete', { expected_version: r.json.version });
ok(r.status === 400 && r.json.code === 'webruno_confirm_delete_required', 'category delete: refused without confirm_delete', short(r));
const termNow = (await get('terms', { taxonomy: 'product_cat', search: 'F6E Shoes' })).json;
const ver = termNow.items.find((t) => t.id === catId);
r = await post('terms/' + catId + '/delete', { expected_version: 'a'.repeat(40), confirm_delete: true });
ok(r.status === 409, 'category delete: stale version -> 409', short(r));
const defCat = Number(wpPhp('echo (int) get_option("default_product_cat");').trim());
if (defCat) {
    const dv = (await get('terms', { taxonomy: 'product_cat', per_page: '50' })).json.items.find((t) => t.id === defCat);
    r = await post('terms/' + defCat + '/delete', { expected_version: dv.version, confirm_delete: true });
    ok(r.status === 403 || r.status === 409, 'category delete: the default category is protected', short(r));
}
// keep a product in the category to see it unassigned later
r = await post('products', { name: 'F6E Simple', regular_price: '10.00', category_ids: [catId] });
ok(r.status === 200 && r.json.type === 'simple' && r.json.status === 'draft', 'simple product: create as draft', short(r));
const simpleId = r.json.id; madeProducts.push(simpleId);
r = await post('terms/' + catId + '/delete', { expected_version: ver.version, confirm_delete: true });
ok(r.status === 200 && r.json.deleted === true, 'category delete: with confirm_delete -> deleted', short(r));
ok(wpPhp('echo term_exists(' + catId + ', "product_cat") ? "yes" : "no";').trim() === 'no', 'category delete: really gone');
madeTerms.length = 0;

// ───────── 2. variable product: create ─────────
const ATTRS = [
    { name: 'Color', options: ['Red', 'Blue'], variation: true },
    { name: 'Size', options: ['S', 'M'], variation: true },
    { name: 'Material', options: ['Cotton'], visible: true, variation: false },
];
r = await post('products', { type: 'variable', name: 'F6E Variable', regular_price: '5' });
ok(r.status === 400, 'variable: a price on the product itself is refused', short(r));
r = await post('products', { type: 'grouped', name: 'x' });
ok(r.status === 400, 'create: unknown type refused', short(r));
r = await post('products', { name: 'x', attributes: ATTRS });
ok(r.status === 400, 'create: attributes on a simple product refused', short(r));
r = await post('products', { type: 'variable', name: 'x', attributes: [{ name: 'Color', options: [] }] });
ok(r.status === 400, 'attributes: empty options refused', short(r));
r = await post('products', { type: 'variable', name: 'x', attributes: [{ name: 'Color', options: ['A|B'] }] });
ok(r.status === 400, 'attributes: | in a value refused', short(r));
r = await post('products', { type: 'variable', name: 'x', attributes: [{ name: 'Color', options: ['A'] }, { name: 'color', options: ['B'] }] });
ok(r.status === 400, 'attributes: duplicate names refused', short(r));
r = await post('products', { type: 'variable', name: 'x', attributes: [{ name: 'Color', options: ['A'], colour: 1 }] });
ok(r.status === 400, 'attributes: unknown field refused', short(r));
r = await post('products', { type: 'variable', name: 'F6E Variable', attributes: ATTRS });
ok(r.status === 200 && r.json.type === 'variable' && r.json.status === 'draft' && r.json.created === true, 'variable: created as a draft', short(r));
ok(Array.isArray(r.json.attribute_specs) && r.json.attribute_specs.length === 3 && r.json.attribute_specs[0].options.join() === 'Red,Blue', 'variable: attribute_specs returned', JSON.stringify(r.json.attribute_specs));
const varId = r.json.id; madeProducts.push(varId);

// ───────── 3. variations ─────────
const vr = (route, json) => post('products/' + varId + '/' + route, json);
r = await vr('variations', { attributes: { Color: 'Red', Size: 'S' }, regular_price: '20.00', sku: 'F6E-RED-S', manage_stock: true, stock_quantity: 5 });
ok(r.status === 200 && r.json.created === true && r.json.variation.attributes.Color === 'Red' && r.json.variation.regular_price === '20.00', 'variation: create', short(r));
const v1 = r.json.variation;
r = await vr('variations', { attributes: { Color: 'Red', Size: 'S' }, regular_price: '1' });
ok(r.status === 409, 'variation: the same combination twice -> 409', short(r));
r = await vr('variations', { attributes: { Color: 'Green', Size: 'S' } });
ok(r.status === 400, 'variation: a value that is not an option refused', short(r));
r = await vr('variations', { attributes: { Color: 'Red' } });
ok(r.status === 400, 'variation: every variation attribute needs a value', short(r));
r = await vr('variations', { attributes: { Color: 'Red', Size: 'S', Material: 'Cotton' } });
ok(r.status === 400, 'variation: an attribute that is not used for variations refused', short(r));
r = await vr('variations', { attributes: { Color: 'Blue', Size: 'M' }, regular_price: '10', sale_price: '12' });
ok(r.status === 400, 'variation: sale_price above regular_price refused', short(r));
r = await vr('variations', { attributes: { Color: 'Blue', Size: 'M' }, regular_price: '30.00', sku: 'F6E-RED-S' });
ok(r.status === 409, 'variation: a SKU that is already used -> 409', short(r));
r = await vr('variations', { attributes: { Color: 'Blue', Size: 'M' }, regular_price: '30.00', enabled: true, evil: 1 });
ok(r.status === 400, 'variation: unknown field refused', short(r));
r = await vr('variations', { attributes: { Color: 'Blue', Size: 'M' }, regular_price: '30.00' });
ok(r.status === 200, 'variation: second variation', short(r));
const v2 = r.json.variation;
let g = await get('products/' + varId);
ok(g.status === 200 && g.json.type === 'variable' && g.json.variation_items.length === 2 && g.json.variations === 2, 'read: product lists its variations', short(g));
ok(g.json.price === '20.00' || g.json.price === '20', 'read: product price follows the cheapest variation', g.json.price);
g = await get('products/' + varId + '/variations');
ok(g.status === 200 && g.json.items.length === 2 && g.json.attribute_specs.length === 3, 'read: variations route', short(g));
g = await get('products/' + simpleId + '/variations');
ok(g.status === 400, 'read: variations of a simple product -> 400', short(g));
r = await post('products/' + simpleId + '/variations', { attributes: { Color: 'Red' } });
ok(r.status === 400, 'variation: cannot be added to a simple product', short(r));

// ───────── 4. update variation ─────────
r = await vr('variations/' + v1.id, { expected_modified_gmt: '2001-01-01T00:00:00Z', regular_price: '25.00' });
ok(r.status === 409, 'variation update: stale expected_modified_gmt -> 409', short(r));
r = await vr('variations/' + v1.id, { expected_modified_gmt: v1.modified_gmt, regular_price: '25.00', stock_quantity: 9 });
ok(r.status === 200 && r.json.variation.regular_price === '25.00' && r.json.previous.regular_price === '20.00' && r.json.previous.stock_quantity === 5, 'variation update: new values + previous values returned', short(r));
const v1b = r.json.variation;
r = await vr('variations/' + v1.id, { expected_modified_gmt: v1b.modified_gmt, attributes: { Color: 'Blue', Size: 'M' } });
ok(r.status === 409, 'variation update: moving onto an existing combination -> 409', short(r));
r = await vr('variations/' + v1.id, { expected_modified_gmt: v1b.modified_gmt, enabled: false });
ok(r.status === 200 && r.json.variation.enabled === false && r.json.previous.enabled === true, 'variation update: disable', short(r));
r = await vr('variations/' + v2.id, {});
ok(r.status === 400, 'variation update: needs expected_modified_gmt', short(r));
r = await vr('variations/99999999', { expected_modified_gmt: v1.modified_gmt, regular_price: '1' });
ok(r.status === 404, 'variation update: unknown variation -> 404', short(r));

// ───────── 5. parent attributes: protect variations ─────────
g = await get('products/' + varId);
r = await post('products/' + varId, { expected_modified_gmt: g.json.modified_gmt, attributes: [{ name: 'Color', options: ['Red'], variation: true }, { name: 'Size', options: ['S', 'M'], variation: true }] });
ok(r.status === 409, 'product update: removing a value that a variation uses -> 409', short(r));
r = await post('products/' + varId, { expected_modified_gmt: g.json.modified_gmt, attributes: [{ name: 'Color', options: ['Red', 'Blue', 'Green'], variation: true }, { name: 'Size', options: ['S', 'M'], variation: true }] });
ok(r.status === 200 && r.json.attribute_specs[0].options.length === 3, 'product update: adding a value is fine', short(r));
ok(Number(r.json.previous_state_saved === true) === 1, 'product update: previous state saved');
g = await get('products/' + varId);
r = await post('products/' + varId, { expected_modified_gmt: g.json.modified_gmt, regular_price: '9' });
ok(r.status === 400, 'product update: price on a variable product refused', short(r));
r = await post('products/' + varId, { expected_modified_gmt: g.json.modified_gmt, name: 'F6E Variable renamed' });
ok(r.status === 200 && r.json.name === 'F6E Variable renamed', 'product update: rename a variable product', short(r));
g = await get('products/' + varId);
r = await post('products/' + varId + '/restore', { expected_modified_gmt: g.json.modified_gmt });
ok(r.status === 200 && r.json.restored === true && r.json.name === 'F6E Variable', 'product restore: undo works for variable products', short(r));

// ───────── 6. live product: changes need confirm_live ─────────
g = await get('products/' + varId);
r = await post('products/' + varId + '/status', { status: 'publish', expected_modified_gmt: g.json.modified_gmt });
ok(r.status === 400 && r.json.code === 'webruno_confirm_required', 'publish: needs confirm', short(r));
r = await post('products/' + varId + '/status', { status: 'publish', expected_modified_gmt: g.json.modified_gmt, confirm: true });
ok(r.status === 200 && r.json.status === 'publish', 'publish: with confirm', short(r));
g = await get('products/' + varId + '/variations');
const live2 = g.json.items.find((x) => x.id === v2.id);
r = await vr('variations/' + v2.id, { expected_modified_gmt: live2.modified_gmt, regular_price: '31.00' });
ok(r.status === 400 && r.json.code === 'webruno_confirm_live_required', 'live: a variation change needs confirm_live', short(r));
r = await vr('variations/' + v2.id, { expected_modified_gmt: live2.modified_gmt, regular_price: '31.00', confirm_live: true });
ok(r.status === 200 && r.json.variation.regular_price === '31.00', 'live: variation change with confirm_live', short(r));
r = await vr('variations', { attributes: { Color: 'Green', Size: 'S' }, regular_price: '40' });
ok(r.status === 400 && r.json.code === 'webruno_confirm_live_required', 'live: a new variation needs confirm_live', short(r));
r = await vr('variations', { attributes: { Color: 'Green', Size: 'S' }, regular_price: '40', confirm_live: true });
ok(r.status === 200, 'live: new variation with confirm_live', short(r));
const v3 = r.json.variation;

// ───────── 7. delete variation ─────────
g = await get('products/' + varId + '/variations');
const cur3 = g.json.items.find((x) => x.id === v3.id);
r = await vr('variations/' + v3.id + '/delete', { expected_modified_gmt: cur3.modified_gmt });
ok(r.status === 400 && r.json.code === 'webruno_confirm_delete_required', 'variation delete: refused without confirm_delete', short(r));
ok(wpPhp('echo get_post_status(' + v3.id + ') ?: "none";').trim() !== 'none', 'variation delete: nothing was deleted by the refusal');
r = await vr('variations/' + v3.id + '/delete', { expected_modified_gmt: '2001-01-01T00:00:00Z', confirm_delete: true });
ok(r.status === 409, 'variation delete: stale expected_modified_gmt -> 409', short(r));
r = await vr('variations/' + v3.id + '/delete', { expected_modified_gmt: cur3.modified_gmt, confirm_delete: true });
ok(r.status === 200 && r.json.deleted === true && r.json.variation.id === v3.id, 'variation delete: with confirm_delete', short(r));
ok(wpPhp('echo get_post_status(' + v3.id + ') ?: "none";').trim() === 'none', 'variation delete: really gone');
g = await get('products/' + varId + '/variations');
ok(g.json.items.length === 2, 'variation delete: the list shrank', short(g));

// ───────── 8. delete product (with its variations) ─────────
g = await get('products/' + varId);
r = await post('products/' + varId + '/delete', { expected_modified_gmt: g.json.modified_gmt });
ok(r.status === 400 && r.json.code === 'webruno_confirm_delete_required', 'product delete: refused without confirm_delete', short(r));
r = await post('products/' + varId + '/delete', { expected_modified_gmt: g.json.modified_gmt, confirm_delete: 'yes' });
ok(r.status === 400, 'product delete: confirm_delete must be the boolean true', short(r));
r = await post('products/' + varId + '/delete', { expected_modified_gmt: g.json.modified_gmt, confirm_delete: true });
ok(r.status === 200 && r.json.deleted === true && r.json.type === 'variable', 'product delete: with confirm_delete', short(r));
ok(wpPhp('echo get_post_status(' + varId + ') ?: "none";').trim() === 'none' && wpPhp('echo get_post_status(' + v1.id + ') ?: "none";').trim() === 'none', 'product delete: product and its variations are gone');
madeProducts.splice(madeProducts.indexOf(varId), 1);
r = await post('products/' + varId + '/delete', { expected_modified_gmt: g.json.modified_gmt, confirm_delete: true });
ok(r.status === 404, 'product delete: a second time -> 404', short(r));

// ───────── 9. delete post / page ─────────
r = await post('posts', { title: 'F6E to delete', content: '<p>x</p>' });
const delPost = r.json.id; madePosts.push(delPost);
r = await post('posts/' + delPost + '/delete', { expected_modified_gmt: r.json.modified_gmt });
ok(r.status === 400 && r.json.code === 'webruno_confirm_delete_required', 'post delete: refused without confirm_delete', short(r));
g = await get('posts/' + delPost);
r = await post('posts/' + delPost + '/delete', { expected_modified_gmt: g.json.modified_gmt, confirm_delete: true });
ok(r.status === 200 && r.json.deleted === true && r.json.title === 'F6E to delete', 'post delete: with confirm_delete', short(r));
ok(wpPhp('echo get_post_status(' + delPost + ') ?: "none";').trim() === 'none', 'post delete: really gone (not even in the trash)');
r = await post('posts', { title: 'F6E page to delete', post_type: 'page' });
const delPage = r.json.id; madePosts.push(delPage);
r = await post('posts/' + delPage + '/delete', { expected_modified_gmt: r.json.modified_gmt, confirm_delete: true });
ok(r.status === 200 && r.json.type === 'page', 'page delete: works for pages', short(r));
r = await post('posts/' + seed.page_public + '/delete', { expected_modified_gmt: '2001-01-01T00:00:00Z', confirm_delete: true });
ok(r.status === 409, 'post delete: stale expected_modified_gmt -> 409 (seeded page untouched)', short(r));

// ───────── 10. delete menu ─────────
const menuId = Number(wpPhp('$id = wp_create_nav_menu("F6E Menu " . wp_generate_password(5, false)); wp_update_nav_menu_item($id, 0, ["menu-item-title" => "Home", "menu-item-url" => "http://127.0.0.1:8081/", "menu-item-status" => "publish", "menu-item-type" => "custom"]); echo $id;').trim());
g = await get('menus/' + menuId);
ok(g.status === 200 && g.json.items.length === 1, 'menu: precondition (one item)', short(g));
r = await post('menus/' + menuId + '/delete', { expected_items_hash: g.json.items_hash });
ok(r.status === 400 && r.json.code === 'webruno_confirm_delete_required', 'menu delete: refused without confirm_delete', short(r));
r = await post('menus/' + menuId + '/delete', { expected_items_hash: 'b'.repeat(40), confirm_delete: true });
ok(r.status === 409, 'menu delete: stale hash -> 409', short(r));
r = await post('menus/' + menuId + '/delete', { expected_items_hash: g.json.items_hash, confirm_delete: true });
ok(r.status === 200 && r.json.deleted === true && r.json.previous_items.length === 1, 'menu delete: with confirm_delete (previous items returned)', short(r));
ok(wpPhp('echo wp_get_nav_menu_object(' + menuId + ') ? "yes" : "no";').trim() === 'no', 'menu delete: really gone');

// ───────── 11. switches and permissions ─────────
setOpt('webruno_crm_content_write', 0);
g = await get('products/' + simpleId);
r = await post('products/' + simpleId + '/delete', { expected_modified_gmt: g.json.modified_gmt, confirm_delete: true });
ok(r.status === 403 && r.json.code === 'webruno_write_disabled', 'switch: deleting needs the admin\'s edit switch', short(r));
r = await post('products/' + simpleId + '/variations', { attributes: { a: 'b' } });
ok(r.status === 403, 'switch: variation writes need the edit switch', short(r));
setOpt('webruno_crm_content_write', 1);
useUser(seed.contributor);
r = await post('products/' + simpleId + '/delete', { expected_modified_gmt: g.json.modified_gmt, confirm_delete: true });
ok(r.status === 403 || r.status === 404, 'permissions: a contributor cannot delete products', short(r));
r = await post('posts/' + seed.page_public + '/delete', { expected_modified_gmt: '2001-01-01T00:00:00Z', confirm_delete: true });
ok(r.status === 403, 'permissions: a contributor cannot delete a page', short(r));
useUser(1);
ok(wpPhp('echo get_post_status(' + simpleId + ') ?: "none";').trim() !== 'none', 'permissions: nothing was deleted by the refused requests');

// ───────── 12. audit and secrecy ─────────
const audit = JSON.parse(wpPhp('echo json_encode(Webruno_Content_API::recent_audit(300));') || '[]');
const ops = new Set(audit.map((x) => x.op));
ok(['delete_post', 'delete_product', 'delete_variation', 'delete_term', 'delete_menu', 'create_variation', 'update_variation', 'variations'].every((o) => ops.has(o)), 'audit: every new operation is logged', [...ops].join(','));
ok(audit.some((x) => x.op === 'delete_product' && x.result === 'denied') || audit.some((x) => x.op === 'delete_product' && x.result === 'error'), 'audit: refused deletes are logged');
ok(!bodies.some((t) => t.includes(SECRET)), `secret appears in none of ${bodies.length} responses`);

// ───────── cleanup ─────────
for (const id of madeProducts) wp('post', 'delete', String(id), '--force');
for (const id of madePosts) wp('post', 'delete', String(id), '--force');
fs.rmSync('/home/wordpress/wp-test/wp-content/mu-plugins/f6e-test.php', { force: true });
setOpt('webruno_crm_content_write', 0);

console.log(`\n${pass + fail} checks, ${fail} failed`);
process.exit(fail ? 1 : 0);
