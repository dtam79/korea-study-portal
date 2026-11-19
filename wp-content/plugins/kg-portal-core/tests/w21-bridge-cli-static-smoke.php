<?php
/** Static registration and safety contract; not a replacement for DDEV E2E. */
declare(strict_types=1);
$root=dirname(__DIR__);
$bootstrap=(string)file_get_contents($root.'/kg-portal-core.php');
$cli=(string)file_get_contents($root.'/src/Cli/BridgeCommand.php');
function must(bool $v,string $description):void{if(!$v)throw new RuntimeException('FAIL: '.$description);echo 'PASS: '.$description."\n";}
must(str_contains($bootstrap,"'kgp bridge', 'KGP_Bridge_Command'"),'WP-CLI bridge command registered');
must(str_contains($bootstrap,"Version: 0.4.5")&&str_contains($bootstrap,"'0.4.5'"),'Core version is 0.4.4');
must(str_contains($cli,"str_ends_with(\$host, '.ddev.site')"),'Synthetic smoke restricted to local DDEV hostname');
must(str_contains($cli,'KGP_DB::transaction(')&&str_contains($cli,'KGP_Bridge_Rollback'),'Synthetic data tested inside rollback transaction');
must(str_contains($cli,'KGP_Public_Bridge_Audit::inspect(')&&str_contains($cli,'inspect_languages('),'Both KO/EN card-detail values audited');
must(str_contains($cli,'SKIP: No published real offerings'),'Empty real database remains explicitly unverified');
echo "SUCCESS: W2-1 static CLI safety checks passed.\n";
