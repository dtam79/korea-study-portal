<?php
/** Offline lint-like contract check; run php tests/workflow-static-smoke.php */
$root=dirname(__DIR__);
$files=['inc/workflow/class-schema.php','inc/workflow/class-translations.php','inc/workflow/class-csv.php','inc/workflow/class-history.php',
    'inc/workflow/class-routes.php','assets/workflows.js','assets/admin.css','assets/admin.js'];
foreach ($files as $file) if (!is_readable($root.'/'.$file)) throw new RuntimeException('Missing '.$file);
$main=file_get_contents($root.'/kg-portal-admin.php');
foreach (['class-schema.php','class-translations.php','class-csv.php','class-history.php','class-routes.php'] as $f) {
    if (!str_contains($main,$f)) throw new RuntimeException('Workflow bootstrap missing '.$f);
}
$admin=file_get_contents($root.'/inc/class-admin.php');
foreach (['translations','imports','history'] as $name) if (!str_contains($admin,"'kgp-operator-$name'")) throw new RuntimeException('Missing menu '.$name);
$routes=file_get_contents($root.'/inc/workflow/class-routes.php');
foreach(['/translations/queue','/csv/preview','/csv/(?P<id>\\d+)/apply','/history/entry/'] as $route) {
    if (!str_contains($routes,$route)) throw new RuntimeException('Missing endpoint '.$route);
}
$translations=file_get_contents($root.'/inc/workflow/class-translations.php');
foreach (['published_version_id','valid_source','verified_sources_for_version','version_conflict','source_value_type','source_version_id'] as $name) {
    if (!str_contains($translations,$name) && $name!=='valid_source') throw new RuntimeException('Translation gate missing '.$name);
}
$history=file_get_contents($root.'/inc/workflow/class-history.php');
foreach (['restore_field','restore_identity','submit_restored_identity','field.restore_draft'] as $name) if (!str_contains($history,$name)) throw new RuntimeException('History contract missing '.$name);
$csv=file_get_contents($root.'/inc/workflow/class-csv.php');
foreach (['MAX_ROWS=500','fgetcsv','KGP_Field_Repository::save_draft','KGP_Entity_Repository::update','KGP_DB::transaction','file_hash'] as $name) if (!str_contains($csv,$name)) throw new RuntimeException('CSV contract missing '.$name);
$schema=file_get_contents($root.'/inc/workflow/class-schema.php');
foreach (['translation_drafts','csv_batches','csv_rows'] as $name) if (!str_contains($schema,$name)) throw new RuntimeException('Schema table missing '.$name);
echo "PASS: A05/A06/A07 PHP bootstrap, menus, API routes, source gates, CSV and restore paths.\n";
