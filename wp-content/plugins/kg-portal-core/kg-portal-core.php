<?php
/**
 * Plugin Name: KG Portal Core
 * Description: Core domain, evidence, publication and operational infrastructure for Korea University Study Portal.
 * Version: 0.2.0
 * Requires PHP: 8.3
 * Text Domain: kg-portal-core
 */

defined('ABSPATH') || exit;

define('KGP_CORE_VERSION', '0.2.0');
define('KGP_CORE_PATH', plugin_dir_path(__FILE__));

require_once KGP_CORE_PATH . 'src/Database/FieldCatalog.php';
require_once KGP_CORE_PATH . 'src/Database/Schema.php';

register_activation_hook(__FILE__, ['KGP_Schema', 'activate']);

if (defined('WP_CLI') && WP_CLI) {
    require_once KGP_CORE_PATH . 'src/Cli/SchemaCommand.php';
    WP_CLI::add_command('kgp schema', 'KGP_Schema_Command');
}
