<?php
/**
 * Plugin Name: MCP Editor
 * Description: AI Drafter role and content abilities (create, edit, ACF and Yoast fields) for MCP clients connected through the MCP Adapter plugin.
 * Author: Hoverboard
 * Author URI: https://hoverboardstudios.com
 * Version: 1.0.0
 * Requires at least: 7.1
 *
 * @package Hoverboard
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/plugin-update-checker/plugin-update-checker.php';

$hb_mcp_editor_update_checker = YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
	'https://github.com/hoverboard88/MCP-Editor/',
	__FILE__,
	'hb-mcp-editor'
);
$hb_mcp_editor_update_checker->setBranch( 'main' );

// The repository is private, so update checks need a GitHub token with read
// access to its contents. Define it in wp-config.php, never in this file.
if ( defined( 'HB_MCP_EDITOR_GITHUB_TOKEN' ) ) {
	$hb_mcp_editor_update_checker->setAuthentication( HB_MCP_EDITOR_GITHUB_TOKEN );
}

require __DIR__ . '/includes/roles.php';
require __DIR__ . '/includes/abilities.php';
