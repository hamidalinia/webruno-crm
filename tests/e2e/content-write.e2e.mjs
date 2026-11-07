// TEST ONLY. End-to-end test of the F6b write routes against the TEST WordPress (127.0.0.1:8081, SQLite).
// Independent signer, real signed HTTP requests, real WordPress capabilities and revisions.
// Run:  /home/wordpress/bin/test-up.sh && wp eval-file tests/seed.php && node tests/e2e/content-write.e2e.mjs ; /home/wordpress/bin/test-down.sh
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
const created = [];
const mk = async (json) => { const r = await call({ method: 'POST', route: P + 'posts', json }); if (r.json && r.json.id) created.push(r.json.id); return r; };

const BLOCK = '<!-- wp:paragraph -->\n<p>HELLO-F6B-MARKER</p>\n<!-- /wp:paragraph -->';
const clearAudit = () => wpPhp('global $wpdb; $wpdb->query("DELETE FROM ".Webruno_Content_API::table_audit());');
const noRevisions = (on) => setOpt('f6b_test_no_revisions', on ? 1 : 0);

// ───────── setup ─────────
fs.mkdirSync('/home/wordpress/wp-test/wp-content/mu-plugins', { recursive: true });
fs.writeFileSync('/home/wordpress/wp-test/wp-content/mu-plugins/f6b-test-revisions.php',
    "<?php /* TEST ONLY (F6b e2e): switches revisions off when the option is set; raises the rate limit (this test sends >60 requests a minute) */\n" +
    "add_filter('wp_revisions_to_keep', function ($n) { return get_option('f6b_test_no_revisions') ? 0 : $n; });\n" +
    "add_filter('webruno_crm_content_rate_limit', function ($n) { $v = (int) get_option('f6b_test_rate_limit', 0); return $v > 0 ? $v : $n; });\n");
setOpt('f6b_test_rate_limit', 100000);
noRevisions(false); useUser(1); setRead(1); setWrite(1); clearAudit();

// ───────── 1. create ─────────
let r = await mk({ title: 'F6B created post', content: BLOCK });
ok(r.status === 200 && r.json.created === true && r.json.status === 'draft' && r.json.type === 'post', 'create: always a draft post', r.status + ' ' + r.text.slice(0, 120));
ok(r.json.block_count === 1 && r.json.author, 'create: block count reported, author is the connected user');
const id1 = r.json.id;
let g = await get(id1);
ok(g.status === 200 && g.json.content.includes('<!-- wp:paragraph -->') && g.json.content.includes('HELLO-F6B-MARKER'), 'create: Gutenberg block comments survive');

r = await mk({ title: 'F6B script', content: '<p>ok</p><script>alert(1)</script><iframe src="https://evil.example"></iframe>' });
g = await get(r.json.id);
ok(r.status === 200 && !/<script|<iframe|alert\(1\)/i.test(g.json.content) && g.json.content.includes('<p>ok</p>'), 'create: scripts and iframes are stripped even for an administrator', g.json && g.json.content);

r = await mk({ title: 'F6B page', post_type: 'page', content: BLOCK });
ok(r.status === 200 && r.json.type === 'page' && r.json.status === 'draft', 'create: a page as draft');

ok((await mk({ title: 'x', status: 'publish' })).status === 400, 'create: status is refused (always draft)');
ok((await mk({ title: 'x', author: 2 })).status === 400, 'create: unknown field (author) refused');
ok((await mk({ title: 'x', meta: { a: 1 } })).status === 400, 'create: unknown field (meta) refused');
ok((await mk({ content: 'no title' })).status === 400, 'create: title required');
ok((await mk({ title: '' })).status === 400, 'create: empty title refused');
ok((await mk({ title: 'x'.repeat(301) })).status === 400, 'create: title over 300 characters refused');
ok((await mk({ title: 'x', post_type: 'product' })).status === 400, 'create: post_type product refused');
ok((await mk({ title: 'x', content: 5 })).status === 413, 'create: non-text content refused');
ok((await mk({ title: 'x', content: 'a'.repeat(200001) })).status === 413, 'create: content over the limit -> 413');
ok((await call({ method: 'POST', route: P + 'posts', body: 'title=x', ct: 'application/x-www-form-urlencoded' })).status === 415, 'create: non-JSON body -> 415');
ok((await call({ method: 'POST', route: P + 'posts', body: '[1,2]' })).status === 400, 'create: JSON list refused');
ok((await call({ method: 'POST', route: P + 'posts', body: '{bad' })).status === 400, 'create: invalid JSON refused');

// ───────── 2. update, conflict, revisions ─────────
g = await get(id1);
const m1 = g.json.modified_gmt;
ok(g.status === 200 && /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/.test(String(m1)), 'precondition: a single read returns modified_gmt in the shape writes expect', `id=${id1} status=${g.status} ${g.text.slice(0, 220)}`);
r = await call({ method: 'POST', route: P + 'posts/' + id1, json: { expected_modified_gmt: m1, title: 'F6B edited', content: BLOCK.replace('HELLO', 'EDITED') } });
ok(r.status === 200 && r.json.title === 'F6B edited' && Number.isInteger(r.json.revision_before), 'update: 200 with revision_before', r.status + ' ' + r.text.slice(0, 150));
const rev1 = r.json.revision_before;
g = await get(id1);
ok(g.json.content.includes('EDITED-F6B-MARKER') && g.json.title === 'F6B edited', 'update: change is visible on read');

const revs = await call({ route: P + 'posts/' + id1 + '/revisions' });
ok(revs.status === 200 && revs.json.items.some((x) => x.id === rev1) && revs.json.items.every((x) => !('content' in x)), 'revisions: listed, without content');

r = await call({ method: 'POST', route: P + 'posts/' + id1, json: { expected_modified_gmt: m1, title: 'STALE' } });
ok(r.status === 409 && r.json.code === 'webruno_conflict', 'update: stale expected_modified_gmt -> 409', r.status + ' ' + r.text.slice(0, 120));
ok((await get(id1)).json.title === 'F6B edited', 'update: the conflicting write changed nothing');
ok((await call({ method: 'POST', route: P + 'posts/' + id1, json: { title: 'no expected' } })).status === 400, 'update: expected_modified_gmt is required');
ok((await call({ method: 'POST', route: P + 'posts/' + id1, json: { expected_modified_gmt: 'yesterday', title: 'x' } })).status === 400, 'update: malformed expected_modified_gmt refused');
g = await get(id1);
ok((await call({ method: 'POST', route: P + 'posts/' + id1, json: { expected_modified_gmt: g.json.modified_gmt } })).status === 400, 'update: nothing to change -> 400');
ok((await call({ method: 'POST', route: P + 'posts/' + id1, json: { expected_modified_gmt: g.json.modified_gmt, status: 'publish' } })).status === 400, 'update: status cannot be changed here');
ok((await call({ method: 'POST', route: P + 'posts/999999', json: { expected_modified_gmt: g.json.modified_gmt, title: 'x' } })).status === 404, 'update: unknown id -> 404');
ok((await call({ method: 'POST', route: P + 'posts/' + seed.product_public, json: { expected_modified_gmt: g.json.modified_gmt, title: 'x' } })).status === 404, 'update: a product id is not editable here');

// undo: restore the revision that existed before the edit
g = await get(id1);
r = await call({ method: 'POST', route: P + 'posts/' + id1 + '/revisions/' + rev1 + '/restore', json: { expected_modified_gmt: g.json.modified_gmt } });
ok(r.status === 200 && r.json.restored_revision === rev1 && Number.isInteger(r.json.revision_before), 'restore: 200', r.status + ' ' + r.text.slice(0, 150));
g = await get(id1);
ok(g.json.content.includes('HELLO-F6B-MARKER') && g.json.title === 'F6B created post', 'restore: content and title are back to the version before the edit');
const otherPost = created[1];
g = await get(id1);
ok((await call({ method: 'POST', route: P + 'posts/' + otherPost + '/revisions/' + rev1 + '/restore', json: { expected_modified_gmt: (await get(otherPost)).json.modified_gmt } })).status === 404, 'restore: a revision of another post -> 404');
ok((await call({ method: 'POST', route: P + 'posts/' + id1 + '/revisions/999999/restore', json: { expected_modified_gmt: g.json.modified_gmt } })).status === 404, 'restore: unknown revision -> 404');

// ───────── 3. live content needs an explicit confirmation ─────────
g = await get(seed.post_public);
const originalContent = g.json.content, originalTitle = g.json.title;
r = await call({ method: 'POST', route: P + 'posts/' + seed.post_public, json: { expected_modified_gmt: g.json.modified_gmt, title: 'LIVE CHANGE' } });
ok(r.status === 400 && r.json.code === 'webruno_confirm_live_required', 'live post: update without confirm_live -> 400', r.status + ' ' + r.text.slice(0, 100));
ok((await get(seed.post_public)).json.title === originalTitle, 'live post: nothing changed without confirmation');
r = await call({ method: 'POST', route: P + 'posts/' + seed.post_public, json: { expected_modified_gmt: g.json.modified_gmt, title: 'LIVE CHANGE', confirm_live: true } });
ok(r.status === 200 && r.json.title === 'LIVE CHANGE', 'live post: update with confirm_live -> 200');
const liveRev = r.json.revision_before;
g = await get(seed.post_public);
r = await call({ method: 'POST', route: P + 'posts/' + seed.post_public + '/revisions/' + liveRev + '/restore', json: { expected_modified_gmt: g.json.modified_gmt } });
ok(r.status === 400 && r.json.code === 'webruno_confirm_live_required', 'live post: restore also needs confirm_live');
r = await call({ method: 'POST', route: P + 'posts/' + seed.post_public + '/revisions/' + liveRev + '/restore', json: { expected_modified_gmt: g.json.modified_gmt, confirm_live: true } });
g = await get(seed.post_public);
ok(r.status === 200 && g.json.title === originalTitle && g.json.content === originalContent, 'live post: restored byte for byte to what it was', r.status + ' ' + g.json.title);

// ───────── 4. status: publish and trash need confirm, trash is reversible ─────────
g = await get(id1);
r = await call({ method: 'POST', route: P + 'posts/' + id1 + '/status', json: { status: 'publish', expected_modified_gmt: g.json.modified_gmt } });
ok(r.status === 400 && r.json.code === 'webruno_confirm_required', 'status: publish without confirm -> 400');
ok((await call({ method: 'POST', route: P + 'posts/' + id1 + '/status', json: { status: 'banana', expected_modified_gmt: g.json.modified_gmt } })).status === 400, 'status: unknown status refused');
r = await call({ method: 'POST', route: P + 'posts/' + id1 + '/status', json: { status: 'publish', expected_modified_gmt: g.json.modified_gmt, confirm: true } });
ok(r.status === 200 && r.json.status === 'publish' && r.json.previous_status === 'draft', 'status: publish with confirm', r.status + ' ' + r.text.slice(0, 120));
g = await get(id1);
r = await call({ method: 'POST', route: P + 'posts/' + id1 + '/status', json: { status: 'trash', expected_modified_gmt: g.json.modified_gmt, confirm: true } });
ok(r.status === 200 && r.json.status === 'trash', 'status: trash with confirm (not a hard delete)');
ok(wpPhp('echo get_post_status(' + id1 + ');').trim() === 'trash', 'status: the post really is in the WordPress trash');
g = await get(id1);
r = await call({ method: 'POST', route: P + 'posts/' + id1, json: { expected_modified_gmt: g.json.modified_gmt, title: 'in trash' } });
ok(r.status === 409, 'update: an item in the trash cannot be edited');
r = await call({ method: 'POST', route: P + 'posts/' + id1 + '/status', json: { status: 'draft', expected_modified_gmt: g.json.modified_gmt } });
ok(r.status === 200 && r.json.status === 'draft' && r.json.previous_status === 'trash', 'status: back from the trash to draft (undo)', r.status + ' ' + r.text.slice(0, 120));
g = await get(id1);
r = await call({ method: 'POST', route: P + 'posts/' + id1 + '/status', json: { status: 'draft', expected_modified_gmt: g.json.modified_gmt } });
ok(r.status === 200 && r.json.unchanged === true, 'status: same status is a no-op');

// ───────── 5. capabilities of the connected user ─────────
useUser(seed.contributor);
r = await mk({ title: 'F6B by contributor' });
ok(r.status === 200 && r.json.status === 'draft', 'contributor: may create a draft post');
const cid = r.json.id;
g = await get(cid);
r = await call({ method: 'POST', route: P + 'posts/' + cid + '/status', json: { status: 'publish', expected_modified_gmt: g.json.modified_gmt, confirm: true } });
ok(r.status === 403, 'contributor: may NOT publish', String(r.status));
r = await call({ method: 'POST', route: P + 'posts/' + cid, json: { expected_modified_gmt: g.json.modified_gmt, title: 'own draft edited' } });
ok(r.status === 200, 'contributor: may edit their own draft');
g = await get(seed.post_public);
r = await call({ method: 'POST', route: P + 'posts/' + seed.post_public, json: { expected_modified_gmt: g.json ? g.json.modified_gmt : '', title: 'hijack', confirm_live: true } });
ok(r.status === 403, "contributor: may NOT edit someone else's post", String(r.status));
ok((await mk({ title: 'F6B page by contributor', post_type: 'page' })).status === 403, 'contributor: may NOT create a page');
useUser(seed.subscriber);
ok((await mk({ title: 'F6B by subscriber' })).status === 403, 'subscriber: may NOT create anything');
useUser(1);

// ───────── 6. switches ─────────
setWrite(0);
g = await get(id1);
for (const [label, req] of [
    ['create', { method: 'POST', route: P + 'posts', json: { title: 'F6B off' } }],
    ['update', { method: 'POST', route: P + 'posts/' + id1, json: { expected_modified_gmt: g.json.modified_gmt, title: 'F6B off' } }],
    ['status', { method: 'POST', route: P + 'posts/' + id1 + '/status', json: { status: 'pending', expected_modified_gmt: g.json.modified_gmt } }],
    ['restore', { method: 'POST', route: P + 'posts/' + id1 + '/revisions/' + rev1 + '/restore', json: { expected_modified_gmt: g.json.modified_gmt } }],
]) { const x = await call(req); ok(x.status === 403 && x.json.code === 'webruno_write_disabled', `edit switch off: ${label} -> 403`, x.status + ' ' + x.text.slice(0, 80)); }
ok((await get(id1)).status === 200 && (await call({ route: P + 'posts/' + id1 + '/revisions' })).status === 200, 'edit switch off: reading and listing revisions still work');
ok((await get(id1)).json.title === 'F6B created post', 'edit switch off: nothing was changed');
setWrite(1); setRead(0);
r = await call({ method: 'POST', route: P + 'posts', json: { title: 'F6B read off' } });
ok(r.status === 403 && r.json.code === 'webruno_read_disabled', 'read switch off also stops writing', r.status + ' ' + r.text.slice(0, 80));
ok(wpPhp('echo Webruno_Content_API::write_enabled() ? "on" : "off";').trim() === 'off', 'write_enabled() is false while reading is off');
setRead(1);

// ───────── 7. signatures on write requests ─────────
g = await get(id1);
const okBody = { expected_modified_gmt: g.json.modified_gmt, title: 'F6B signed' };
r = await call({ method: 'POST', route: P + 'posts/' + id1, json: okBody, signBody: JSON.stringify({ ...okBody, title: 'something else' }) });
ok(r.status === 401, 'signature: a body that differs from the signed body -> 401');
const n = crypto.randomBytes(16).toString('hex');
const a = await call({ method: 'POST', route: P + 'posts/' + id1, json: okBody, nonce: n });
const b = await call({ method: 'POST', route: P + 'posts/' + id1, json: okBody, nonce: n });
ok(a.status === 200 && b.status === 401, 'signature: replaying a signed write -> 401 (the edit happens once)', a.status + '/' + b.status);
ok((await call({ method: 'POST', route: P + 'posts/' + id1, json: okBody, sig: 'c'.repeat(64) })).status === 401, 'signature: wrong signature -> 401');

// ───────── 8. revisions off: fail closed ─────────
noRevisions(true);
g = await get(id1);
r = await call({ method: 'POST', route: P + 'posts/' + id1, json: { expected_modified_gmt: g.json.modified_gmt, title: 'no revisions' } });
ok(r.status === 409 && r.json.code === 'webruno_revisions_disabled', 'revisions disabled on the site: update refused (nothing could be undone)', r.status + ' ' + r.text.slice(0, 100));
ok((await get(id1)).json.title !== 'no revisions', 'revisions disabled: nothing was changed');
noRevisions(false);

// ───────── 9. audit log and secrecy ─────────
const audit = JSON.parse(wpPhp('echo json_encode(Webruno_Content_API::recent_audit(200));') || '[]');
const ops = new Set(audit.map((x) => x.op));
ok(['create_post', 'update_post', 'set_status', 'restore_revision', 'list_revisions'].every((o) => ops.has(o)), 'audit: every write operation is logged', [...ops].join(','));
ok(audit.some((x) => x.op === 'update_post' && Number(x.target_id) === id1 && x.result === 'ok'), 'audit: update has its target id and result');
ok(audit.some((x) => x.result === 'denied' && x.code === 'write_disabled'), 'audit: refused writes are logged with a reason code');
const leak = wpPhp('global $wpdb; echo (int) $wpdb->get_var("SELECT COUNT(*) FROM ".Webruno_Content_API::table_audit()." WHERE CONCAT(op,route,result,code) LIKE \'%MARKER%\' OR CONCAT(op,route,result,code) LIKE \'%EDITED%\'");').trim();
ok(leak === '0', 'audit: no content is stored in the log');
ok(!bodies.some((t) => t.includes(SECRET)), `secret appears in none of ${bodies.length} responses`);

// ───────── cleanup of the posts this test created ─────────
for (const id of created) wp('post', 'delete', String(id), '--force');
fs.rmSync('/home/wordpress/wp-test/wp-content/mu-plugins/f6b-test-revisions.php', { force: true });
wp('option', 'delete', 'f6b_test_no_revisions');
wp('option', 'delete', 'f6b_test_rate_limit');
setWrite(0);

console.log(`\n${pass + fail} checks, ${fail} failed`);
process.exit(fail ? 1 : 0);
