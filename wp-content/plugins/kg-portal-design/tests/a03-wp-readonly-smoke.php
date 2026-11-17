<?php
/** Read-only A03 entity relationship lookup test.
 * Run: ddev wp --user=<administrator_id> eval-file wp-content/plugins/kg-portal-design/tests/a03-wp-readonly-smoke.php
 */
if (!defined('ABSPATH')) exit("Run via WP-CLI eval-file.\n");
if (!current_user_can('manage_options')) throw new RuntimeException('Run as administrator with --user.');
if (!class_exists('KGP_DB')) throw new RuntimeException('KG Portal Core is required.');
$oldUser = get_current_user_id();
$req = static function (string $type, array $extra = []): array {
    $request = new WP_REST_Request('GET','/kgds/v1/lookup');
    $request->set_param('type',$type);
    foreach ($extra as $key=>$value) $request->set_param($key,$value);
    $response = rest_do_request($request);
    if ($response->get_status()!==200) throw new RuntimeException('Lookup failed: '. $type .' '. wp_json_encode($response->get_data()));
    $result = $response->get_data();
    if (!is_array($result) || !isset($result['items']) || !is_array($result['items'])) {
        throw new RuntimeException('Malformed lookup for '. $type);
    }
    return $result['items'];
};
try {
    foreach (['university','campus','program','intake','offering'] as $type) {
        $items = $req($type);
        echo 'PASS lookup '.$type.': '.count($items)." records on first page\n";
        if (!$items) continue;
        $first = $items[0];
        $id = (int)$first['id'];
        $selected = $req($type,['ids'=>(string)$id]);
        if (count($selected)!==1 || (int)$selected[0]['id']!==$id) throw new RuntimeException('Selected identity mismatch '.$type);
        echo 'PASS selected '.$type." identity\n";
        // A wrong parent must never return an in-scope child. No database rows are changed.
        if (in_array($type,['campus','program','intake','offering'],true)) {
            $uniId = (int)($first['university_id'] ?? 0);
            if ($uniId > 0) {
                $mismatch = $req($type,['ids'=>(string)$id,'university_id'=>(string)($uniId+100000000)]);
                if ($mismatch) throw new RuntimeException('Cross-university lookup filter leaked '.$type);
                echo 'PASS wrong university filter blocked '.$type."\n";
            }
        }
    }
    wp_set_current_user(0);
    $request = new WP_REST_Request('GET','/kgds/v1/lookup');
    $request->set_param('type','university');
    $response = rest_do_request($request);
    if (!in_array($response->get_status(),[401,403],true)) throw new RuntimeException('Anonymous lookup access allowed.');
    echo "PASS anonymous lookup blocked\n";
} finally {
    wp_set_current_user($oldUser);
}
echo "SUCCESS: Read-only A03 lookup relationships passed. Assigned-editor and create/duplicate flows require separate role/browser QA.\n";
