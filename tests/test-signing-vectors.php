<?php
/**
 * TEST ONLY. Shared signing vectors: the SAME file is read by the tenant's JS test.
 * Run: /home/wordpress/bin/wp eval-file tests/test-signing-vectors.php
 * Exercises the real Webruno_Content_API::canonical()/sign() (the functions authenticate() uses).
 */
if (!defined('ABSPATH')) exit;

$file = __DIR__ . '/fixtures/signing-vectors.json';
$data = json_decode((string) file_get_contents($file), true);
$fail = 0; $n = 0;
$check = function ($label, $got, $want) use (&$fail, &$n) {
    $n++;
    if ($got === $want) { echo "ok   $label\n"; return; }
    $fail++; echo "FAIL $label\n  got:  " . var_export($got, true) . "\n  want: " . var_export($want, true) . "\n";
};

if (!is_array($data) || empty($data['vectors']) || count($data['vectors']) < 3) { echo "FAIL fixture missing or has fewer than 3 vectors\n"; exit(1); }

foreach ($data['vectors'] as $v) {
    $query = isset($v['query']) && is_array($v['query']) ? $v['query'] : [];
    unset($query['rest_route']); // authenticate() never passes rest_route into canonical()
    $query = array_map('strval', $query);
    $canonical = Webruno_Content_API::canonical($v['method'], $v['route'], $query, $v['timestamp'], $v['nonce'], $v['body']);
    $check($v['name'] . ' / canonical', $canonical, $v['expected_canonical']);
    $check($v['name'] . ' / body sha256', hash('sha256', $v['body']), $v['expected_body_sha256']);
    $check($v['name'] . ' / signature', Webruno_Content_API::sign($data['secret'], $canonical), $v['expected_signature']);
}

// the shared "rejected" list: the real parser must refuse these raw query strings
$ref = new ReflectionMethod('Webruno_Content_API', 'parse_query');
$ref->setAccessible(true);
foreach ($data['rejected_queries'] as $r) {
    $_SERVER['QUERY_STRING'] = $r['raw_query'];
    $check('rejected: ' . $r['name'], $ref->invoke(null), false);
}
foreach (['a=1&a=2' => 'duplicate key', 'q=a+b' => 'plus sign', '=x' => 'empty key'] as $raw => $why) {
    $_SERVER['QUERY_STRING'] = $raw;
    $check('rejected: ' . $why, $ref->invoke(null), false);
}
$_SERVER['QUERY_STRING'] = "rest_route=%2Fwebruno%2Fv1%2Fcontent%2Fposts&post_type=page&search=caf%C3%A9%20(x)!'*&page=2";
$check('accepted: rest_route dropped, values decoded', $ref->invoke(null), ['post_type' => 'page', 'search' => "café (x)!'*", 'page' => '2']);
$_SERVER['QUERY_STRING'] = '';
$check('accepted: empty query', $ref->invoke(null), []);

echo "\n$n checks, $fail failed\n";
exit($fail ? 1 : 0);
