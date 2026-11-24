<?php
/** Real WordPress, read-only: verify aliases are present after wp rewrite flush. */
if (!defined('ABSPATH')) { exit(2); }
$rules = get_option('rewrite_rules', []);
if (!is_array($rules) || !$rules) { throw new RuntimeException('WordPress rewrite rules unavailable'); }
foreach (['policies/(privacy|terms|about)', 'policies/?'] as $part) {
    $found = false;
    foreach ($rules as $pattern => $target) {
        if (str_contains($pattern, $part) && str_contains((string)$target, 'kgpc_view=')) { $found = true; break; }
    }
    if (!$found) throw new RuntimeException('Missing policy alias rewrite rule: '.$part);
}
echo "PASS: WordPress rewrite rules register v1.2 /policies/ aliases. Approved content still controls HTTP 200.\n";
