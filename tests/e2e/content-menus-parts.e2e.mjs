// TEST ONLY. End-to-end test of the F6c routes (menus, block navigations, header/footer parts) against the TEST WordPress (127.0.0.1:8081, SQLite).
// Independent signer, real signed HTTP requests, real WordPress capabilities and revisions.
// Run:  /home/wordpress/bin/test-up.sh && wp eval-file tests/seed.php && node tests/e2e/content-menus-parts.e2e.mjs ; /home/wordpress/bin/test-down.sh
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
const setRead = (v) => setOpt('webruno_crm_content_read', v);
const setWrite = (v) => setOpt('webruno_crm_content_write', v);

const bodies = [];
function call(o = {}) {
    const method = o.method || 'GET', route = o.route || P + 'site';
    const q = o.query || {};
    const body = o.json !== undefined ? JSON.stringify(o.json) : (o.body || '');
    const ts = String(Math.floor(Date.now() / 1000));
    const nonce = o.nonce || crypto.randomBytes(16).toString('hex');
    const sig = o.sig || sign(SECRET, method, route, q, ts, nonce, o.signBody !== undefined ? o.signBody : body);
    const raw = 'rest_route=' + ru(route) + Object.keys(q).sort().map((k) => `&${ru(k)}=${ru(q[k])}`).join('');
    const headers = { 'X-Webruno-Site': SITE, 'X-Webruno-Timestamp': ts, 'X-Webruno-Nonce': nonce, 'X-Webruno-Signature': sig };
    if (body) { headers['Content-Type'] = o.ct || 'application/json'; headers['Content-Length'] = Buffer.byteLength(body); }
    return new Promise((resolve) => {
        const r = http.request({ host: HOST, port: PORT, path: '/?' + raw, method, headers }, (res) => {
            let d = ''; res.on('data', (c) => d += c); res.on('end', () => { bodies.push(d); let j = null; try { j = JSON.parse(d); } catch (e) { /* not json */ } resolve({ status: res.statusCode, json: j, text: d }); });
        });
        r.on('error', (e) => resolve({ status: 0, json: null, text: String(e) }));
        if (body) r.write(body);
        r.end();
    });
}

const get = (id) => call({ route: P + 'posts/' + id });
const mdl = (id) => call({ route: P + 'menus/' + id });
const setItems = (id, json) => call({ method: 'POST', route: P + 'menus/' + id + '/items', json });
const restoreMenu = (id, json) => call({ method: 'POST', route: P + 'menus/' + id + '/restore', json });

fs.mkdirSync('/home/wordpress/wp-test/wp-content/mu-plugins', { recursive: true });
fs.writeFileSync('/home/wordpress/wp-test/wp-content/mu-plugins/f6c-test.php',
    "<?php /* TEST ONLY (F6c e2e): raises the rate limit */\n" +
    "add_filter('webruno_crm_content_rate_limit', function ($n) { return 100000; });\n");
useUser(1); setRead(1); setWrite(1);

try { wp('menu', 'delete', 'F6C Test Menu'); } catch (e) { /* none left over */ }
const menuId = Number(wp('menu', 'create', 'F6C Test Menu', '--porcelain').trim());
const pageId = Number(wp('post', 'create', '--post_type=page', '--post_title=F6C page', '--post_status=publish', '--porcelain').trim());

// ───────── menus ─────────
let r = await call({ route: P + 'menus' });
ok(r.status === 200 && Array.isArray(r.json.menus) && r.json.menus.some((m) => m.id === menuId), 'menus: list shows the new menu', r.status + ' ' + r.text.slice(0, 120));
r = await mdl(menuId);
ok(r.status === 200 && Array.isArray(r.json.items) && r.json.items.length === 0 && /^[0-9a-f]{40}$/.test(r.json.items_hash), 'menu: empty menu with items_hash');
const h0 = r.json.items_hash;

const items = [
    { title: 'Home', url: '/' },
    { type: 'post_type', object: 'page', object_id: pageId, title: 'About us' },
    { title: 'Sub link', url: 'https://example.com/x', parent_index: 1, target: '_blank' },
];
r = await setItems(menuId, { items, expected_items_hash: h0 });
ok(r.status === 400 && /confirm/i.test(r.text), 'set items: refused without confirm', r.status + ' ' + r.text.slice(0, 120));
r = await setItems(menuId, { items, expected_items_hash: 'a'.repeat(40), confirm: true });
ok(r.status === 409, 'set items: wrong hash is a conflict', String(r.status));
r = await setItems(menuId, { items: [{ title: 'Bad', url: 'javascript:alert(1)' }], expected_items_hash: h0, confirm: true });
ok(r.status === 400, 'set items: javascript: url refused', String(r.status));
r = await setItems(menuId, { items: [{ title: 'A', url: '/', parent_index: 0 }], expected_items_hash: h0, confirm: true });
ok(r.status === 400, 'set items: parent_index must point to an earlier item', String(r.status));
r = await setItems(menuId, { items: [{ title: 'A', url: '/', evil: 1 }], expected_items_hash: h0, confirm: true });
ok(r.status === 400, 'set items: unknown field refused', String(r.status));
r = await setItems(menuId, { items: [{ type: 'post_type', object: 'page', object_id: 999999 }], expected_items_hash: h0, confirm: true });
ok(r.status === 400, 'set items: missing linked page refused', String(r.status));
r = await mdl(menuId);
ok(r.json.items.length === 0 && r.json.items_hash === h0, 'refused changes left the menu untouched');

r = await setItems(menuId, { items, expected_items_hash: h0, confirm: true });
ok(r.status === 200 && r.json.saved === true && r.json.items.length === 3, 'set items: saved three items', r.status + ' ' + r.text.slice(0, 160));
let g;
const byTitle = Object.fromEntries(r.json.items.map((i) => [i.title, i]));
ok(byTitle['Sub link'] && byTitle['Sub link'].parent === byTitle['About us'].id && byTitle['Sub link'].target === '_blank', 'set items: parent and target kept');
ok(byTitle['About us'].type === 'post_type' && byTitle['About us'].object_id === pageId, 'set items: page link kept');
const h1 = r.json.items_hash;

r = await setItems(menuId, { items: [{ title: 'Only', url: '/only' }], expected_items_hash: h0, confirm: true });
ok(r.status === 409, 'set items: stale hash after a change is a conflict');

setWrite(0);
r = await setItems(menuId, { items: [{ title: 'Only', url: '/only' }], expected_items_hash: h1, confirm: true });
ok(r.status === 403 && /webruno_write_disabled/.test(r.text), 'set items: refused while edit switch is off', String(r.status));
setWrite(1);

r = await restoreMenu(menuId, { expected_items_hash: h1, confirm: true });
ok(r.status === 200 && r.json.restored === true && r.json.items.length === 0, 'restore: previous (empty) state is back', r.status + ' ' + r.text.slice(0, 120));
r = await restoreMenu(menuId, { expected_items_hash: r.json.items_hash, confirm: true });
ok(r.status === 404, 'restore: nothing left to restore', String(r.status));

// ───────── header / footer parts ─────────
r = await call({ route: P + 'template-parts' });
const blockTheme = r.json && r.json.block_theme === true;
ok(r.status === 200, 'parts: list answers', String(r.status));
if (!blockTheme) {
    ok(r.json.items.length === 0 && /classic/i.test(r.json.note), 'parts: classic theme is reported honestly');
} else {
    ok(r.json.items.some((i) => i.slug === 'footer') && r.json.items.some((i) => i.slug === 'header'), 'parts: header and footer are listed');
    const wasCustom = (r.json.items.find((i) => i.slug === 'footer') || {}).customized;
    r = await call({ method: 'POST', route: P + 'template-parts/footer/customize', json: {} });
    ok(r.status === 200 && r.json.id > 0 && r.json.type === 'wp_template_part', 'parts: footer customize gives an id', r.status + ' ' + r.text.slice(0, 120));
    const fid = r.json.id;
    ok(wasCustom ? r.json.created === false : r.json.created === true, 'parts: first customize creates the copy only when needed');
    r = await call({ method: 'POST', route: P + 'template-parts/footer/customize', json: {} });
    ok(r.status === 200 && r.json.id === fid && r.json.created === false, 'parts: customize again is idempotent');
    r = await call({ method: 'POST', route: P + 'template-parts/does-not-exist/customize', json: {} });
    ok(r.status === 404, 'parts: unknown part is 404');

    g = await get(fid);
    ok(g.status === 200 && g.json.type === 'wp_template_part' && g.json.content.length > 0, 'part: readable like a post');
    const original = g.json.content;
    const mod = g.json.modified_gmt;
    const NEW = '<!-- wp:paragraph -->\n<p>F6C-FOOTER-MARKER</p>\n<!-- /wp:paragraph -->\n<!-- wp:html -->\n<svg class="x" viewBox="0 0 24 24" aria-hidden="true"><path d="M1 1h2"/></svg><script>alert(1)</script>\n<!-- /wp:html -->';
    r = await call({ method: 'POST', route: P + 'posts/' + fid, json: { expected_modified_gmt: mod, content: NEW } });
    ok(r.status === 400 && /confirm_live/.test(r.text), 'part: editing a live part needs confirm_live', r.status + ' ' + r.text.slice(0, 120));
    r = await call({ method: 'POST', route: P + 'posts/' + fid, json: { expected_modified_gmt: mod, slug: 'x', confirm_live: true } });
    ok(r.status === 400, 'part: slug cannot be changed', String(r.status));
    r = await call({ method: 'POST', route: P + 'posts/' + fid, json: { expected_modified_gmt: mod, content: NEW, confirm_live: true } });
    ok(r.status === 200 && r.json.revision_before > 0, 'part: update with confirm_live keeps a revision', r.status + ' ' + r.text.slice(0, 120));
    const revBefore = r.json.revision_before;
    g = await get(fid);
    ok(g.json.content.includes('F6C-FOOTER-MARKER') && g.json.content.includes('<svg') && g.json.content.includes('<path'), 'part: block markup and inline svg survive');
    ok(!g.json.content.includes('<script'), 'part: script tags are removed');
    r = await call({ method: 'POST', route: P + 'posts/' + fid + '/status', json: { status: 'trash', expected_modified_gmt: g.json.modified_gmt, confirm: true } });
    ok(r.status === 404, 'part: status route does not accept parts', String(r.status));
    r = await call({ route: P + 'posts/' + fid + '/revisions' });
    ok(r.status === 200 && r.json.items.some((x) => x.id === revBefore), 'part: revisions are listed');
    r = await call({ method: 'POST', route: P + 'posts/' + fid + '/revisions/' + revBefore + '/restore', json: { expected_modified_gmt: g.json.modified_gmt, confirm_live: true } });
    g = await get(fid);
    ok(r.status === 200 && !g.json.content.includes('F6C-FOOTER-MARKER') && g.json.content === original, 'part: restore brings the original back exactly', r.status);

    useUser(seed.contributor);
    r = await call({ method: 'POST', route: P + 'template-parts/footer/customize', json: {} });
    ok(r.status === 403, 'parts: a user without edit_theme_options is refused', String(r.status));
    r = await call({ route: P + 'menus' });
    ok(r.status === 403, 'menus: a user without edit_theme_options is refused', String(r.status));
    useUser(1);
    if (!wasCustom) wp('post', 'delete', String(fid), '--force');
}

ok(!bodies.some((t) => t.includes(SECRET)), `secret appears in none of ${bodies.length} responses`);

// ───────── cleanup ─────────
wp('menu', 'delete', String(menuId));
wp('post', 'delete', String(pageId), '--force');
fs.rmSync('/home/wordpress/wp-test/wp-content/mu-plugins/f6c-test.php', { force: true });
setWrite(0);

console.log(`\n${pass + fail} checks, ${fail} failed`);
process.exit(fail ? 1 : 0);
