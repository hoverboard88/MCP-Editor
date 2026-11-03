<?php
/**
 * User Roles
 *
 * The role is only registered on sites where this plugin is active, so access
 * stays opt-in per client.
 *
 * @package Hoverboard
 */

/**
 * Version of the AI drafter role definition.
 *
 * Bump this whenever hb_ai_drafter_capabilities() changes. add_role() is a
 * no-op once a role exists, so without a version gate an edited capability
 * list would never reach sites where the role is already registered.
 */
define( 'HB_AI_DRAFTER_ROLE_VERSION', '4' );

/**
 * Capability that gates access to the MCP endpoint.
 *
 * A capability rather than a role check, so access can be granted to another
 * account (an admin debugging the connection, say) without handing out the
 * whole role.
 */
define( 'HB_AI_MCP_CAPABILITY', 'hb_use_mcp' );

/**
 * Capabilities granted to the AI drafter role.
 *
 * Scoped to content: the account can create and revise posts, pages and the
 * custom post types, including published ones, relying on WordPress revisions
 * as the rollback path.
 *
 * Two things are still deliberately withheld:
 *
 * - publish_* and delete_published_*, so the account cannot take new content
 *   live, unpublish what is live, or delete it. Edits to a live page apply
 *   immediately, but its published state is not the account's to change.
 * - Everything outside content. No upload_files, manage_categories,
 *   edit_theme_options or manage_options, so media, taxonomy structure,
 *   templates and settings stay out of reach.
 *
 * @return array Map of capability name to whether it is granted.
 */
function hb_ai_drafter_capabilities() {
	return array(
		'read'                  => true,
		'edit_posts'            => true,
		'edit_others_posts'     => true,
		'edit_published_posts'  => true,
		'edit_private_posts'    => true,
		'read_private_posts'    => true,
		'delete_posts'          => true,
		'edit_pages'            => true,
		'edit_others_pages'     => true,
		'edit_published_pages'  => true,
		'edit_private_pages'    => true,
		'read_private_pages'    => true,
		'delete_pages'          => true,
		HB_AI_MCP_CAPABILITY    => true,
	);
}

/**
 * Register the AI drafter role.
 *
 * Roles are stored in the wp_user_roles option, so this persists in the
 * database independently of this plugin being active. remove_role() runs first
 * so that a changed capability list fully replaces the stored one.
 *
 * @return void
 */
function hb_register_ai_drafter_role() {
	if ( HB_AI_DRAFTER_ROLE_VERSION === get_option( 'hb_ai_drafter_role_version' ) ) {
		return;
	}

	remove_role( 'hb_ai_drafter' );

	add_role(
		'hb_ai_drafter',
		__( 'AI Drafter', 'hb' ),
		hb_ai_drafter_capabilities()
	);

	update_option( 'hb_ai_drafter_role_version', HB_AI_DRAFTER_ROLE_VERSION );
}
add_action( 'init', 'hb_register_ai_drafter_role' );
