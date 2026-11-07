// TEST ONLY. End-to-end test of the signed content API against the TEST WordPress (127.0.0.1:8081).
// Independent signer (third implementation) checked against the shared vectors, then real HTTP requests.
// Run:  /home/wordpress/bin/test-up.sh && node tests/e2e/content-api.e2e.mjs ; /home/wordpress/bin/test-down.sh
import http from 'node:http';
import crypto from 'node:crypto';
import fs from 'node:fs';
import { execFileSync } from 'node:child_process';

const HOST = '127.0.0.1', PORT = 8081;
const seed = JSON.parse(fs.readFileSync('/tmp/f6a-seed.json', 'utf8'));
const SECRET = seed._secret, SITE = seed._site_id;
const vectors = JSON.parse(fs.readFileSync(new URL('../fixtures/signing-vectors.json', import.meta.url), 'utf8'));

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
const clearAudit = () => wpPhp('global $wpdb; $wpdb->query("DELETE FROM ".Webruno_Content_API::table_audit());');
const useUser = (id) => setOpt('webruno_crm_content_user_id', id);
const setRead = (v) => setOpt('webruno_crm_content_read', v);
const setWrite = (v) => setOpt('webruno_crm_content_write', v);

const bodies = [];
function call(o = {}) {
    const method = o.method || 'GET', route = o.route || '/webruno/v1/content/site';
    const q = o.query || {}, body = o.body || '';
    const ts = o.ts !== undefined ? String(o.ts) : String(Math.floor(Date.now() / 1000));
    const nonce = o.nonce || crypto.randomBytes(16).toString('hex');
    const signed = sign(o.secret || SECRET, method, route, o.signQuery || q, ts, nonce, o.signBody !== undefined ? o.signBody : body);
    const raw = o.rawQuery !== undefined ? o.rawQuery : ('rest_route=' + ru(route) + Object.keys(q).sort().map((k) => `&${ru(k)}=${ru(q[k])}`).join(''));
    const headers = { 'X-Webruno-Site': o.site || SITE, 'X-Webruno-Timestamp': ts, 'X-Webruno-Nonce': nonce, 'X-Webruno-Signature': o.sig || signed };
    if (body) headers['Content-Length'] = Buffer.byteLength(body);
    return new Promise((resolve) => {
        const r = http.request({ host: HOST, port: PORT, path: '/?' + raw, method, headers }, (res) => {
            let d = ''; res.on('data', (c) => d += c); res.on('end', () => { bodies.push(d); let j = null; try { j = JSON.parse(d); } catch (e) { /* not json */ } resolve({ status: res.statusCode, json: j, text: d, headers: res.headers }); });
        });
        r.on('error', (e) => resolve({ status: 0, json: null, text: String(e) }));
        if (body) r.write(body);
        r.end();
    });
}
const titles = (r) => ((r.json && r.json.items) || []).map((i) => i.title || i.name);
const ids = (r) => ((r.json && r.json.items) || []).map((i) => i.id);

// ───────── 0. independent signer == shared vectors ─────────
for (const v of vectors.vectors) ok(sign(vectors.secret, v.method, v.route, v.query, v.timestamp, v.nonce, v.body) === v.expected_signature, 'vector (test signer): ' + v.name);

// ───────── A. authentication ─────────
useUser(1); setRead(1); setWrite(0); clearAudit();
let r = await call();
ok(r.status === 200 && r.json && r.json.connected_user && r.json.connected_user.id === 1, 'valid signed request -> 200 site info', JSON.stringify(r.json).slice(0, 120));
ok(r.json && r.json.content_access && r.json.content_access.write === false, 'site info: write access reported false while the edit switch is off (default)');
ok(/no-store/.test(String(r.headers['cache-control'])), 'responses are Cache-Control: no-store');

const now = Math.floor(Date.now() / 1000);
const reject = {};
reject.badSig = await call({ sig: 'a'.repeat(64) });
reject.oldTs = await call({ ts: Math.floor(Date.now() / 1000) - 310 });
reject.futureTs = await call({ ts: Math.floor(Date.now() / 1000) + 310 });
reject.otherSite = await call({ site: '99999999-2222-4333-8444-555555555555' });
reject.wrongSecret = await call({ secret: 'wsk_' + 'X'.repeat(43) });
reject.tamperBody = await call({ body: 'x', signBody: '' });
reject.tamperQuery = await call({ route: '/webruno/v1/content/posts', query: { page: '2' }, signQuery: { page: '1' } });
reject.arrayParam = await call({ rawQuery: 'rest_route=' + ru('/webruno/v1/content/site') + '&a%5B%5D=1' });
reject.plus = await call({ route: '/webruno/v1/content/posts', rawQuery: 'rest_route=' + ru('/webruno/v1/content/posts') + '&search=a+b' });
reject.shortNonce = await call({ nonce: 'short' });
reject.badParamUnauth = await call({ route: '/webruno/v1/content/posts', query: { post_type: 'zzz' }, sig: 'b'.repeat(64) });
const same = JSON.stringify(reject.badSig.json);
for (const [k, v] of Object.entries(reject)) ok(v.status === 401 && JSON.stringify(v.json) === same, 'rejected with the same 401 body: ' + k, v.status + ' ' + v.text.slice(0, 80));
ok(same.includes('webruno_unauthorized') && !/signature|nonce|timestamp|replay|stale/i.test(same), 'rejection text reveals no reason');

const n1 = crypto.randomBytes(16).toString('hex');
const first = await call({ nonce: n1 }), replay = await call({ nonce: n1 });
ok(first.status === 200 && replay.status === 401 && JSON.stringify(replay.json) === same, 'replay of a used nonce -> 401');
ok((await call({ ts: Math.floor(Date.now() / 1000) - 290 })).status === 200, 'timestamp inside the window (-290s) accepted');

setRead(0);
r = await call();
ok(r.status === 403 && r.json.code === 'webruno_read_disabled', 'switch off -> 403 webruno_read_disabled even with a valid signature', r.status + ' ' + r.text.slice(0, 80));
setRead(1);

// write routes: only the four POST routes of F6b exist; everything else is GET
const routes = JSON.parse(wpPhp('$o=[]; foreach (rest_get_server()->get_routes() as $p=>$h) { if (strpos($p,"/webruno/v1/content")===0) { $m=[]; foreach ($h as $x) $m=array_merge($m,array_keys($x["methods"])); $o[$p]=array_values(array_unique($m)); } } echo json_encode($o);') || '{}');
const POST_OK = new Set(['/webruno/v1/content/posts', '/webruno/v1/content/posts/(?P<id>\\d+)', '/webruno/v1/content/posts/(?P<id>\\d+)/status', '/webruno/v1/content/posts/(?P<id>\\d+)/revisions/(?P<rev>\\d+)/restore']);
ok(Object.keys(routes).length >= 10 && Object.entries(routes).every(([p, m]) => m.every((x) => x === 'GET' || (x === 'POST' && POST_OK.has(p)))), 'route table: GET everywhere, POST only on the four post write routes', JSON.stringify(routes));
ok([...POST_OK].every((p) => (routes[p] || []).includes('POST')), 'all four write routes are registered');
clearAudit();
const wr = [];
for (const m of ['PUT', 'PATCH', 'DELETE']) for (const rt of ['/webruno/v1/content/posts', '/webruno/v1/content/site']) wr.push(await call({ method: m, route: rt, body: m === 'DELETE' ? '' : '{"title":"x"}' }));
wr.push(await call({ method: 'POST', route: '/webruno/v1/content/site', body: '{"title":"x"}' }));
ok(wr.every((x) => x.status === 404 || x.status === 405), 'PUT/PATCH/DELETE anywhere and POST on /site -> 404/405', wr.map((x) => x.status).join(','));
const off = await call({ method: 'POST', route: '/webruno/v1/content/posts', body: '{"title":"x"}' });
ok(off.status === 403 && off.json.code === 'webruno_write_disabled', 'signed POST while the edit switch is off -> 403 webruno_write_disabled', off.status + ' ' + off.text.slice(0, 80));
const after = await call({ route: '/webruno/v1/content/posts' });
ok(after.status === 200 && !titles(after).includes('x'), 'nothing was created by the refused writes');

// ───────── B. content as administrator ─────────
clearAudit();
const P = '/webruno/v1/content/';
let posts = await call({ route: P + 'posts' });
ok(posts.status === 200 && titles(posts).includes('F6A public post') && titles(posts).includes('F6A admin draft') && titles(posts).includes('F6A admin private'), 'admin: posts list includes public, draft and private');
ok(posts.json.items.every((i) => !('content' in i)), 'list items carry no full content');
const pages = await call({ route: P + 'posts', query: { post_type: 'page' } });
ok(pages.status === 200 && titles(pages).includes('F6A public page') && !titles(pages).includes('F6A public post'), 'pages list contains pages only');
const onlyDraft = await call({ route: P + 'posts', query: { status: 'draft' } });
ok(onlyDraft.status === 200 && onlyDraft.json.items.every((i) => i.status === 'draft'), 'status filter works');
ok((await call({ route: P + 'posts', query: { post_type: 'product' } })).status === 400, 'authenticated invalid post_type -> 400 (not silently post)');
const one = await call({ route: P + 'posts/' + seed.post_public });
ok(one.status === 200 && one.json.content.includes('PUBLIC-BODY') && one.json.categories.some((c) => c.name === 'F6A Cat') && one.json.edit_url, 'get_post: content, categories, edit_url');
ok(one.json.meta && Object.keys(one.json.meta).length === 0, 'meta is empty by default (allowlist empty)');
ok((await call({ route: P + 'posts/' + seed.page_public })).status === 200, 'get_post works for a page');
ok((await call({ route: P + 'posts/999999' })).status === 404, 'unknown id -> 404');
ok((await call({ route: P + 'posts/' + seed.product_public })).status === 404, 'product id on the posts route -> 404');
const terms = await call({ route: P + 'terms', query: { taxonomy: 'category' } });
ok(terms.status === 200 && titles(terms).includes('F6A Cat'), 'terms: category listed');
const media = await call({ route: P + 'media' });
const mt = (media.json.items || []).map((i) => i.mime);
ok(media.status === 200 && mt.includes('image/png') && mt.includes('application/pdf') && !mt.includes('text/plain'), 'media: png and pdf listed, txt never', mt.join(','));
const prods = await call({ route: P + 'products' });
const pp = (prods.json.items || []).find((i) => i.id === seed.product_public);
ok(prods.status === 200 && pp && pp.sku === 'F6A-1' && pp.regular_price === '19.9' || (pp && pp.regular_price === '19.90'), 'products: sample product with sku and price', JSON.stringify(pp));
ok(pp && pp.stock_quantity === 5 && pp.edit_url, 'products: stock and edit url');
const pd = await call({ route: P + 'products/' + seed.product_public });
ok(pd.status === 200 && pd.json.description.includes('PRODUCT-DESC'), 'get_product: description');
ok((await call({ route: P + 'products/' + seed.post_public })).status === 404, 'post id on the products route -> 404');
const prot = await call({ route: P + 'posts/' + seed.post_protected });
ok(prot.status === 200 && prot.json.password_protected === true, 'protected post flagged (admin may read content)');

// ───────── C. capabilities (connected user is a contributor / subscriber) ─────────
clearAudit(); useUser(seed.contributor);
posts = await call({ route: P + 'posts' });
const pt = titles(posts);
ok(posts.status === 200 && pt.includes('F6A public post') && !pt.includes('F6A admin draft') && !pt.includes('F6A admin private'), 'contributor: no other users\' drafts or private posts in the list', pt.join('|'));
ok((await call({ route: P + 'posts/' + seed.post_draft })).status === 403, 'contributor: admin draft by id -> 403');
ok((await call({ route: P + 'posts/' + seed.post_private })).status === 403, 'contributor: admin private by id -> 403');
const cp = await call({ route: P + 'posts/' + seed.post_protected });
ok(cp.status === 200 && cp.json.content === '', 'contributor: password-protected content withheld');
ok((await call({ route: P + 'posts', query: { post_type: 'page' } })).status === 403, 'contributor: pages list -> 403 (no edit_pages)');
ok((await call({ route: P + 'media' })).status === 403, 'contributor: media -> 403 (no upload_files)');
ok((await call({ route: P + 'products' })).status === 403, 'contributor: products -> 403 (no edit_products)');
clearAudit(); useUser(seed.subscriber);
ok((await call({ route: P + 'posts' })).status === 403, 'subscriber: posts -> 403');
ok((await call({ route: P + 'site' })).status === 200, 'subscriber: site info still 200');
useUser(0);
r = await call();
ok(r.status === 403 && r.json.code === 'webruno_user_unavailable', 'missing connected user -> 403 webruno_user_unavailable');
useUser(1);

// ───────── D. nonce store fails closed ─────────
clearAudit();
wpPhp('global $wpdb; $wpdb->query("DROP TABLE ".Webruno_Content_API::table_nonces());');
r = await call();
ok(r.status === 401, 'nonce table missing -> request refused (fail closed)', String(r.status));
wpPhp('delete_option("webruno_crm_content_db_ver");');
r = await call();
ok(r.status === 200, 'table is re-created on the next request and requests work again', String(r.status));
wpPhp('global $wpdb; $wpdb->query($wpdb->prepare("INSERT INTO ".Webruno_Content_API::table_nonces()." (nonce_hash, created) VALUES (%s,%d)", "old-test-row", time()-3600)); Webruno_Content_API::prune();');
const left = wpPhp('global $wpdb; echo (int)$wpdb->get_var("SELECT COUNT(*) FROM ".Webruno_Content_API::table_nonces()." WHERE nonce_hash=\'old-test-row\'");').trim();
ok(left === '0', 'prune removes nonces older than 15 minutes');

// ───────── E. rate limit and log rotation ─────────
clearAudit();
const codes = [];
for (let i = 0; i < 64; i++) codes.push((await call()).status);
ok(codes.slice(0, 60).every((c) => c === 200) && codes.slice(60).every((c) => c === 429), 'rate limit: 60 requests pass, then 429', codes.join(',').slice(-40));
clearAudit();
const rot = wpPhp('$m=new ReflectionMethod("Webruno_Content_API","audit"); $m->setAccessible(true); for($i=0;$i<1010;$i++){ $m->invoke(null,"rot","/r",$i,"ok","",200);} global $wpdb; echo (int)$wpdb->get_var("SELECT COUNT(*) FROM ".Webruno_Content_API::table_audit());').trim();
ok(Number(rot) === 1000, 'audit log is capped at 1000 rows (rotating)', rot);
clearAudit();
await call({ route: P + 'posts/' + seed.post_public });
const log = wpPhp('global $wpdb; echo json_encode($wpdb->get_results("SELECT * FROM ".Webruno_Content_API::table_audit(), ARRAY_A));');
ok(log.includes('"op":"post"') && log.includes(String(seed.post_public)) && !log.includes(SECRET) && !log.includes('wsk_'), 'audit row has operation and target, and no secret');

// ───────── F. no secret anywhere ─────────
ok(bodies.length > 100 && bodies.every((b) => !b.includes(SECRET) && !b.includes('wsk_')), 'secret appears in none of ' + bodies.length + ' responses');
const phplog = fs.existsSync('/home/wordpress/wp-test/wp-content/debug.log') ? fs.readFileSync('/home/wordpress/wp-test/wp-content/debug.log', 'utf8') : '';
ok(!phplog.includes(SECRET), 'secret not in the WordPress debug log');

console.log(`\n${pass + fail} checks, ${fail} failed`);
process.exit(fail ? 1 : 0);
