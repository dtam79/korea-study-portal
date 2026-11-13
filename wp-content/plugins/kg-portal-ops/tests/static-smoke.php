<?php
/** Source contract checks. Pure PHP, no WordPress required. */
$root=dirname(__DIR__);
$files=['kg-portal-ops.php','inc/class-schema.php','inc/class-policy.php','inc/class-monitor.php',
'inc/class-rest.php','inc/class-admin.php','inc/class-cli.php','inc/class-seo.php',
'assets/operator.js','assets/operator.css','README.md','tests/wp-smoke.php'];
foreach($files as $f)if(!is_file($root.'/'.$f))throw new RuntimeException('Missing file: '.$f);
$entry=file_get_contents($root.'/kg-portal-ops.php');
$monitor=file_get_contents($root.'/inc/class-monitor.php');
$seo=file_get_contents($root.'/inc/class-seo.php');
$rest=file_get_contents($root.'/inc/class-rest.php');
$schema=file_get_contents($root.'/inc/class-schema.php');
foreach(['kgpo_scan_due','robots_txt','pre_handle_404','wp_head','class-monitor.php','class-seo.php','Requires Plugins: kg-portal-core'] as $v)if(!str_contains($entry,$v))throw new RuntimeException('Registration missing: '.$v);
foreach(['source_state','source_checks','review_tasks','dbDelta','KEY source_pending'] as $v)if(!str_contains($schema,$v))throw new RuntimeException('Schema missing: '.$v);
foreach(['wp_safe_remote_get','limit_response_size','redirection','reject_unsafe_urls','sslverify','KGP_Evidence_Repository::valid_source','possible_change','source_invalid','next_check_at'] as $v)if(!str_contains($monitor,$v))throw new RuntimeException('Monitoring gate missing: '.$v);
foreach(['KGPC_Public::published','KGP_Identity_Repository::published','KGP_Public_Catalog::university','wp_get_environment_type','DONOTCACHEPAGE','noindex','kgp-sitemap'] as $v)if(!str_contains($seo,$v))throw new RuntimeException('SEO gate missing: '.$v);
foreach(['permission_callback','kgp_manage_assignments','/cache/purge','/tasks/','/scan'] as $v)if(!str_contains($rest,$v) && !str_contains(file_get_contents($root.'/inc/class-policy.php'),$v))throw new RuntimeException('REST gate missing: '.$v);
foreach(['<footer','kgp-footer','get_header()','get_footer()','wp-content/themes/kg-portal-child'] as $v)if(str_contains($entry,$v))throw new RuntimeException('Accidental shared layout mutation.');
echo "PASS: independent plugin, bounded source checks, review queue, approved-only sitemap, cache guard, no public template changes.\n";
