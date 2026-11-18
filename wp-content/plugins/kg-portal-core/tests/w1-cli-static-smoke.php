<?php
/** Offline check for command registration and access restrictions. */
$plugin = file_get_contents(__DIR__ . '/../kg-portal-core.php');
$command = file_get_contents(__DIR__ . '/../src/Cli/IntegrityCommand.php');
$report = file_get_contents(__DIR__ . '/../src/Service/IntegrityAudit.php');
if (!str_contains($plugin, "WP_CLI::add_command('kgp integrity', 'KGP_Integrity_Command')")) {
    throw new RuntimeException('Missing wp kgp integrity command registration.');
}
if (!str_contains($plugin, "require_once KGP_CORE_PATH . 'src/Service/IntegrityAudit.php'")) {
    throw new RuntimeException('Missing audit loading.');
}
if (!str_contains($command, "current_user_can('manage_options')")) {
    throw new RuntimeException('Administrator privilege guard missing.');
}
if (!str_contains($report, 'KGP_Evidence_Repository::valid_source') ||
    !str_contains($report, 'KGP_Evidence_Repository::verified_sources_for_version')) {
    throw new RuntimeException('Published evidence guard missing.');
}
if (str_contains($report, 'INSERT INTO') || str_contains($report,'UPDATE ') || str_contains($report,'DELETE FROM')) {
    throw new RuntimeException('Read-only audit must not write to DB.');
}
fwrite(STDOUT,"PASS: CLI registration, admin guard, verified source checks and read-only policy\n");
