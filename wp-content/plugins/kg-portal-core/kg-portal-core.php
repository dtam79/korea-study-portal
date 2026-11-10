<?php
/**
 * Plugin Name: KG Portal Core
 * Description: Official-source-driven data platform for the Korea University Study Portal.
 * Version: 0.3.0
 * Requires PHP: 8.3
 * Text Domain: kg-portal-core
 */

defined('ABSPATH') || exit;

if (!defined('KGP_CORE_VERSION')) define('KGP_CORE_VERSION', '0.3.0');
if (!defined('KGP_CORE_PATH')) define('KGP_CORE_PATH', plugin_dir_path(__FILE__));

require_once KGP_CORE_PATH . 'src/Support/DomainException.php';
require_once KGP_CORE_PATH . 'src/Database/FieldCatalog.php';
require_once KGP_CORE_PATH . 'src/Database/Schema.php';
require_once KGP_CORE_PATH . 'src/Support/DB.php';
require_once KGP_CORE_PATH . 'src/Security/Access.php';
require_once KGP_CORE_PATH . 'src/Validation/Validator.php';
require_once KGP_CORE_PATH . 'src/Repository/EntityRepository.php';
require_once KGP_CORE_PATH . 'src/Repository/EvidenceRepository.php';
require_once KGP_CORE_PATH . 'src/Repository/FieldRepository.php';
require_once KGP_CORE_PATH . 'src/Service/PublicReader.php';

register_activation_hook(__FILE__, function () {
    KGP_Schema::activate();
    KGP_Access::install_roles();
});

if (defined('WP_CLI') && WP_CLI) {
    require_once KGP_CORE_PATH . 'src/Cli/SchemaCommand.php';
    require_once KGP_CORE_PATH . 'src/Cli/DomainCommand.php';
    WP_CLI::add_command('kgp schema', 'KGP_Schema_Command');
    WP_CLI::add_command('kgp domain', 'KGP_Domain_Command');
}
