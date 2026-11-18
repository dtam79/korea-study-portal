<?php
/** Ensure the two-university CLI is registered and restricted. */
declare(strict_types=1);
$base=dirname(__DIR__);
$plugin=file_get_contents($base.'/kg-portal-core.php');
$command=file_get_contents($base.'/src/Cli/PilotCommand.php');
$readiness=file_get_contents($base.'/src/Service/PilotReadiness.php');
foreach ([
    "WP_CLI::add_command('kgp pilot', 'KGP_Pilot_Command')"=> $plugin,
    "'src/Service/PilotReadiness.php'"=>$plugin,
    "'src/Cli/PilotCommand.php'"=>$plugin,
    'str_ends_with($host, \'.ddev.site\')'=>$command,
    "current_user_can('manage_options')"=>$command,
    "KGP_DB::transaction"=>$command,
    "new KGP_Pilot_Rollback"=> str_replace('throw new KGP_Pilot_Rollback','new KGP_Pilot_Rollback',$command),
    'KGP_Evidence_Repository::invalidate_source'=>$command,
    'KGP_Pilot_Readiness::check'=>$command,
    'KGP_Identity_Repository::published'=>$readiness,
    'KGP_Public_Catalog::offering'=>$readiness,
] as $needle=>$content) {
    if (!str_contains($content,$needle)) throw new RuntimeException('Missing W1-2 guard: '.$needle);
}
foreach (['KGP_DB::insert','KGP_DB::update','START TRANSACTION','INSERT INTO','DELETE FROM','UPDATE '] as $forbidden) {
    if (str_contains($readiness,$forbidden)) throw new RuntimeException('Read-only readiness must not write: '.$forbidden);
}
if (!str_contains($command,'@subcommand readiness') || !str_contains($command,'@subcommand smoke')) {
    throw new RuntimeException('W1-2 command documentation incomplete');
}
echo "PASS: W1-2 CLI registration, admin/DDEV guards, rollback and read-only audit\n";
