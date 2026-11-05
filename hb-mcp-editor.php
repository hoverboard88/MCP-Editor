<?php
/**
 * Plugin Name: MCP Editor
 * Description: AI Drafter role and content abilities (create, edit, images, ACF and Yoast fields) for MCP clients connected through the MCP Adapter plugin.
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

require __DIR__ . '/includes/roles.php';
require __DIR__ . '/includes/abilities.php';
require __DIR__ . '/includes/media.php';
