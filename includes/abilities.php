<?php
/**
 * AI Abilities
 *
 * Content abilities for MCP clients, surfaced by the MCP Adapter plugin. Core
 * 7.1 and the AI plugin only register read abilities, so creating or revising
 * content over MCP requires registering it here.
 *
 * Capabilities are the real boundary — see the AI Drafter role in roles.php.
 * These callbacks add a second, independent guard: the set of post types that
 * can be reached is an explicit allow-list rather than everything the caller
 * happens to hold a capability for, and post_status is never taken from
 * client input on create.
 *
 * @package Hoverboard
 */

/**
 * Restrict the MCP endpoint to accounts holding the MCP capability.
 *
 * The adapter defaults this to 'read', which every logged-in user has — so out
 * of the box any account with an application password can reach the MCP
 * server, down to a Subscriber. Requiring a capability only the AI Drafter
 * role carries closes the endpoint to everyone else before a tool is reached.
 *
 * This gates the transport only. The abilities keep their own capability
 * checks, so they still behave correctly wherever else they are invoked from.
 *
 * @param string $capability Capability the adapter will check.
 * @return string Capability to require instead.
 */
function hb_ai_mcp_transport_capability() {
	return HB_AI_MCP_CAPABILITY;
}
add_filter( 'mcp_adapter_default_transport_permission_user_capability', 'hb_ai_mcp_transport_capability' );

/**
 * Post types an MCP client may create and edit.
 *
 * Public, UI-visible types only. That deliberately excludes two groups that a
 * capability check alone would let through:
 *
 * - attachment, which is public but keeps no revisions to roll back to.
 * - The editor infrastructure types. acf-field-group, acf-post-type and
 *   friends declare capability_type 'post' with map_meta_cap false, so plain
 *   edit_posts reaches them; wp_navigation is the same. Those define how the
 *   site is built rather than what it says, and are not content in the sense
 *   meant here.
 *
 * @return array List of post type slugs.
 */
function hb_ai_allowed_post_types() {
	$post_types = get_post_types(
		array(
			'public'  => true,
			'show_ui' => true,
		),
		'names'
	);

	unset( $post_types['attachment'] );

	/**
	 * Filters the post types an MCP client may create and edit.
	 *
	 * @param array $post_types List of post type slugs.
	 */
	return array_values( (array) apply_filters( 'hb_ai_allowed_post_types', $post_types ) );
}

/**
 * Post statuses an MCP client may edit.
 *
 * Published and private content is included: the connected account is trusted
 * to revise live pages, and WordPress revisions provide the rollback path.
 * Trashed content and the internal 'inherit' status are still excluded, so an
 * ability cannot resurrect something deleted or write to an attachment record.
 *
 * @return array List of post status slugs.
 */
function hb_ai_editable_statuses() {
	return array( 'auto-draft', 'draft', 'pending', 'future', 'private', 'publish' );
}

/**
 * Whether the current user may create the given post type.
 *
 * Reads the capability off the post type object rather than assuming
 * edit_posts, so a type declaring its own capability_type is handled.
 *
 * @param string $post_type Post type slug.
 * @return bool Whether the current user may create this post type.
 */
function hb_ai_can_create( $post_type ) {
	if ( ! in_array( $post_type, hb_ai_allowed_post_types(), true ) ) {
		return false;
	}

	$post_type_object = get_post_type_object( $post_type );

	if ( null === $post_type_object ) {
		return false;
	}

	return current_user_can( $post_type_object->cap->edit_posts );
}

/**
 * Build the response returned by the content abilities.
 *
 * @param int $post_id Post ID.
 * @return array Summary of the stored post.
 */
function hb_ai_post_summary( $post_id ) {
	$post = get_post( $post_id );

	return array(
		'post_id'   => (int) $post->ID,
		'post_type' => $post->post_type,
		'status'    => $post->post_status,
		'title'     => $post->post_title,
		'permalink' => get_permalink( $post->ID ),
		'edit_link' => get_edit_post_link( $post->ID, 'raw' ),
	);
}

/**
 * Schema describing the summary returned by the content abilities.
 *
 * @return array JSON Schema definition.
 */
function hb_ai_post_summary_schema() {
	return array(
		'type'       => 'object',
		'properties' => array(
			'post_id'   => array(
				'type'        => 'integer',
				'description' => __( 'ID of the stored post.', 'hb' ),
			),
			'post_type' => array(
				'type'        => 'string',
				'description' => __( 'Post type of the stored post.', 'hb' ),
			),
			'status'    => array(
				'type'        => 'string',
				'description' => __( 'Status of the stored post.', 'hb' ),
			),
			'title'     => array(
				'type'        => 'string',
				'description' => __( 'Title of the stored post.', 'hb' ),
			),
			'permalink' => array(
				'type'        => 'string',
				'description' => __( 'Public URL of the post.', 'hb' ),
			),
			'edit_link' => array(
				'type'        => 'string',
				'description' => __( 'Admin URL for a human to review the post.', 'hb' ),
			),
		),
	);
}

/**
 * Shared meta for the content abilities.
 *
 * The mcp key is what the MCP Adapter reads to decide whether an ability is
 * reachable over MCP; without public => true it stays unexposed.
 *
 * show_in_rest stays false on purpose. It controls the core Abilities REST
 * API, which can run an ability at /wp/v2/abilities/<name>/run — a separate
 * door that the MCP transport capability does not guard. Leaving it off keeps
 * MCP the only route in.
 *
 * @return array Ability meta.
 */
function hb_ai_ability_meta() {
	return array(
		'show_in_rest' => false,
		'mcp'          => array(
			'public' => true,
			'type'   => 'tool',
		),
		'annotations'  => array(
			'readonly'    => false,
			'destructive' => false,
			'idempotent'  => false,
		),
	);
}

/**
 * Create a new piece of content.
 *
 * New content is always created unpublished. Taking it live is a separate,
 * human step — the role carries no publish_* capability, so a status passed
 * by a client would be refused by WordPress anyway.
 *
 * @param array $input Ability input.
 * @return array|WP_Error Summary of the new post, or an error.
 */
function hb_ai_create_content( array $input ) {
	$post_type = isset( $input['post_type'] ) ? (string) $input['post_type'] : 'post';

	if ( ! in_array( $post_type, hb_ai_allowed_post_types(), true ) ) {
		return new WP_Error(
			'hb_ai_invalid_post_type',
			sprintf(
				/* translators: %s: comma-separated list of post type slugs. */
				__( 'That post type cannot be created here. Allowed: %s', 'hb' ),
				implode( ', ', hb_ai_allowed_post_types() )
			)
		);
	}

	$post_id = wp_insert_post(
		array(
			'post_type'    => $post_type,
			'post_status'  => 'draft',
			'post_title'   => wp_slash( (string) $input['title'] ),
			'post_content' => wp_slash( isset( $input['content'] ) ? (string) $input['content'] : '' ),
			'post_excerpt' => wp_slash( isset( $input['excerpt'] ) ? (string) $input['excerpt'] : '' ),
		),
		true
	);

	if ( is_wp_error( $post_id ) ) {
		return $post_id;
	}

	return hb_ai_post_summary( $post_id );
}

/**
 * Update existing content.
 *
 * Published content is in scope. An edit to a live page takes effect
 * immediately; WordPress stores a revision first, so the previous version
 * stays recoverable from the editor.
 *
 * @param array $input Ability input.
 * @return array|WP_Error Summary of the updated post, or an error.
 */
function hb_ai_update_content( array $input ) {
	$post_id = isset( $input['post_id'] ) ? absint( $input['post_id'] ) : 0;
	$post    = get_post( $post_id );

	if ( null === $post ) {
		return new WP_Error( 'hb_ai_not_found', __( 'No post with that ID.', 'hb' ) );
	}

	if ( ! in_array( $post->post_type, hb_ai_allowed_post_types(), true ) ) {
		return new WP_Error(
			'hb_ai_invalid_post_type',
			__( 'That post type cannot be edited here.', 'hb' )
		);
	}

	if ( ! in_array( $post->post_status, hb_ai_editable_statuses(), true ) ) {
		return new WP_Error(
			'hb_ai_bad_status',
			sprintf(
				/* translators: %s: post status slug. */
				__( 'Content with the status "%s" cannot be edited here.', 'hb' ),
				$post->post_status
			)
		);
	}

	$changes = array( 'ID' => $post->ID );

	if ( isset( $input['title'] ) ) {
		$changes['post_title'] = wp_slash( (string) $input['title'] );
	}

	if ( isset( $input['content'] ) ) {
		$changes['post_content'] = wp_slash( (string) $input['content'] );
	}

	if ( isset( $input['excerpt'] ) ) {
		$changes['post_excerpt'] = wp_slash( (string) $input['excerpt'] );
	}

	if ( count( $changes ) < 2 ) {
		return new WP_Error( 'hb_ai_nothing_to_do', __( 'No title, content or excerpt was supplied.', 'hb' ) );
	}

	$result = wp_update_post( $changes, true );

	if ( is_wp_error( $result ) ) {
		return $result;
	}

	return hb_ai_post_summary( $post->ID );
}

/**
 * List content the current user can edit.
 *
 * Gives a client a way to find the ID that hb/update-content needs.
 *
 * @param array $input Ability input.
 * @return array List of content summaries.
 */
function hb_ai_list_content( array $input ) {
	$post_type = isset( $input['post_type'] ) ? (string) $input['post_type'] : '';
	$status    = isset( $input['status'] ) ? (string) $input['status'] : '';
	$search    = isset( $input['search'] ) ? (string) $input['search'] : '';

	$post_types = in_array( $post_type, hb_ai_allowed_post_types(), true )
		? array( $post_type )
		: hb_ai_allowed_post_types();

	$statuses = in_array( $status, hb_ai_editable_statuses(), true )
		? array( $status )
		: hb_ai_editable_statuses();

	$args = array(
		'post_type'              => $post_types,
		'post_status'            => $statuses,
		'posts_per_page'         => 50,
		'orderby'                => 'modified',
		'order'                  => 'DESC',
		'ignore_sticky_posts'    => true,
		'no_found_rows'          => true,
		'update_post_term_cache' => false,
		'update_post_meta_cache' => false,
	);

	if ( '' !== $search ) {
		$args['s'] = $search;
	}

	$query = new WP_Query( $args );

	$items = array();

	foreach ( $query->posts as $post ) {
		if ( ! current_user_can( 'edit_post', $post->ID ) ) {
			continue;
		}

		$items[] = hb_ai_post_summary( $post->ID );
	}

	return array( 'items' => $items );
}

/**
 * ACF field types an MCP client may write.
 *
 * Scalar types only. The structured types — repeater, flexible_content, group,
 * link, google_map — take shaped arrays that are easy to corrupt from a flat
 * value, and the relational ones (post_object, relationship, taxonomy, user,
 * image, file, gallery) take IDs that a client cannot easily verify. Images
 * reach a post through hb/set-featured-image instead. password is never
 * writable.
 *
 * @return array List of ACF field type slugs.
 */
function hb_ai_writable_field_types() {
	/**
	 * Filters the ACF field types an MCP client may write.
	 *
	 * @param array $types List of ACF field type slugs.
	 */
	return (array) apply_filters(
		'hb_ai_writable_field_types',
		array(
			'text',
			'textarea',
			'wysiwyg',
			'number',
			'range',
			'email',
			'url',
			'select',
			'radio',
			'button_group',
			'true_false',
			'date_picker',
			'date_time_picker',
			'time_picker',
			'color_picker',
		)
	);
}

/**
 * Resolve the ACF fields attached to a post.
 *
 * Scoped to the field groups whose location rules match this specific post, so
 * options page, user and term field groups are never reachable. Only
 * top-level fields are returned; sub-fields belong to structured types that
 * are not writable here anyway.
 *
 * @param int $post_id Post ID.
 * @return array Map of field name to ACF field array.
 */
function hb_ai_get_post_fields( $post_id ) {
	if ( ! function_exists( 'acf_get_field_groups' ) ) {
		return array();
	}

	$fields = array();

	foreach ( acf_get_field_groups( array( 'post_id' => $post_id ) ) as $group ) {
		foreach ( (array) acf_get_fields( $group['key'] ) as $field ) {
			if ( empty( $field['name'] ) ) {
				continue;
			}

			$fields[ $field['name'] ] = $field;
		}
	}

	return $fields;
}

/**
 * Validate that a post can be reached by the content abilities.
 *
 * @param int $post_id Post ID.
 * @return WP_Post|WP_Error The post, or an error explaining the refusal.
 */
function hb_ai_check_field_target( $post_id ) {
	$post = get_post( $post_id );

	if ( null === $post ) {
		return new WP_Error( 'hb_ai_not_found', __( 'No post with that ID.', 'hb' ) );
	}

	if ( ! in_array( $post->post_type, hb_ai_allowed_post_types(), true ) ) {
		return new WP_Error( 'hb_ai_invalid_post_type', __( 'That post type cannot be edited here.', 'hb' ) );
	}

	if ( ! in_array( $post->post_status, hb_ai_editable_statuses(), true ) ) {
		return new WP_Error( 'hb_ai_bad_status', __( 'Content with that status cannot be edited here.', 'hb' ) );
	}

	return $post;
}

/**
 * List the ACF fields attached to a post, with their current values.
 *
 * @param array $input Ability input.
 * @return array|WP_Error Field list, or an error.
 */
function hb_ai_get_fields( array $input ) {
	$post_id = isset( $input['post_id'] ) ? absint( $input['post_id'] ) : 0;
	$post    = hb_ai_check_field_target( $post_id );

	if ( is_wp_error( $post ) ) {
		return $post;
	}

	$writable = hb_ai_writable_field_types();
	$out      = array();

	foreach ( hb_ai_get_post_fields( $post->ID ) as $name => $field ) {
		$is_writable = in_array( $field['type'], $writable, true );

		$entry = array(
			'name'     => $name,
			'label'    => isset( $field['label'] ) ? $field['label'] : '',
			'type'     => $field['type'],
			'writable' => $is_writable,
		);

		// Values are returned only for the types that can be written back, so
		// the payload stays small and structured data is not round-tripped
		// through a client that cannot reconstruct it.
		if ( $is_writable ) {
			$value          = get_field( $name, $post->ID );
			$entry['value'] = is_scalar( $value ) || null === $value ? $value : wp_json_encode( $value );
		}

		if ( ! empty( $field['choices'] ) && is_array( $field['choices'] ) ) {
			$entry['choices'] = array_keys( $field['choices'] );
		}

		$out[] = $entry;
	}

	return array(
		'post_id' => (int) $post->ID,
		'fields'  => $out,
	);
}

/**
 * Write ACF field values on a post.
 *
 * A revision is taken before anything is written. ACF hooks WordPress
 * revisions (includes/revisions.php) and copies field values onto the
 * revision, but only when WordPress creates one — and update_field() on its
 * own does not. Without this snapshot the previous values would simply be
 * overwritten with no way back.
 *
 * @param array $input Ability input.
 * @return array|WP_Error Summary of what was written, or an error.
 */
function hb_ai_update_fields( array $input ) {
	$post_id = isset( $input['post_id'] ) ? absint( $input['post_id'] ) : 0;
	$post    = hb_ai_check_field_target( $post_id );

	if ( is_wp_error( $post ) ) {
		return $post;
	}

	$values = isset( $input['fields'] ) && is_array( $input['fields'] ) ? $input['fields'] : array();

	if ( empty( $values ) ) {
		return new WP_Error( 'hb_ai_nothing_to_do', __( 'No field values were supplied.', 'hb' ) );
	}

	$available = hb_ai_get_post_fields( $post->ID );
	$writable  = hb_ai_writable_field_types();

	// Validate everything before writing anything, so a bad name in the batch
	// cannot leave the post half-updated.
	foreach ( $values as $name => $value ) {
		if ( ! isset( $available[ $name ] ) ) {
			return new WP_Error(
				'hb_ai_unknown_field',
				sprintf(
					/* translators: 1: field name, 2: comma-separated list of field names. */
					__( 'No ACF field "%1$s" on this post. Available: %2$s', 'hb' ),
					$name,
					implode( ', ', array_keys( $available ) )
				)
			);
		}

		if ( ! in_array( $available[ $name ]['type'], $writable, true ) ) {
			return new WP_Error(
				'hb_ai_field_not_writable',
				sprintf(
					/* translators: 1: field name, 2: ACF field type. */
					__( 'The field "%1$s" is of type "%2$s", which cannot be written here.', 'hb' ),
					$name,
					$available[ $name ]['type']
				)
			);
		}

		if ( ! is_scalar( $value ) && null !== $value ) {
			return new WP_Error(
				'hb_ai_invalid_value',
				sprintf(
					/* translators: %s: field name. */
					__( 'The value for "%s" must be a simple string, number or boolean.', 'hb' ),
					$name
				)
			);
		}
	}

	// Snapshot current state, ACF values included, before overwriting.
	wp_save_post_revision( $post->ID );

	$written = array();

	foreach ( $values as $name => $value ) {
		update_field( $available[ $name ]['key'], $value, $post->ID );
		$written[] = $name;
	}

	return array(
		'post_id' => (int) $post->ID,
		'updated' => $written,
		'summary' => hb_ai_post_summary( $post->ID ),
	);
}

/**
 * Yoast SEO fields an MCP client may read and write.
 *
 * Maps the ability's input key to the Yoast meta key, without the
 * _yoast_wpseo_ prefix. Limited to the snippet fields; the advanced ones
 * (noindex, canonical, redirects) can take a page out of search results and
 * stay a human decision.
 *
 * @return array Map of input key to Yoast meta key.
 */
function hb_ai_seo_fields() {
	/**
	 * Filters the Yoast SEO fields an MCP client may read and write.
	 *
	 * @param array $fields Map of input key to Yoast meta key.
	 */
	return (array) apply_filters(
		'hb_ai_seo_fields',
		array(
			'seo_title'        => 'title',
			'meta_description' => 'metadesc',
			'focus_keyphrase'  => 'focuskw',
		)
	);
}

/**
 * Read the Yoast SEO fields of a post.
 *
 * @param int $post_id Post ID.
 * @return array Map of input key to stored value.
 */
function hb_ai_get_seo_values( $post_id ) {
	$values = array();

	foreach ( hb_ai_seo_fields() as $input_key => $meta_key ) {
		$values[ $input_key ] = (string) WPSEO_Meta::get_value( $meta_key, $post_id );
	}

	return $values;
}

/**
 * Read a single piece of content, whatever its status.
 *
 * Drafts are not publicly viewable, so without this a client can list them but
 * never see what they say.
 *
 * @param array $input Ability input.
 * @return array|WP_Error The post's content, or an error.
 */
function hb_ai_get_content( array $input ) {
	$post_id = isset( $input['post_id'] ) ? absint( $input['post_id'] ) : 0;
	$post    = hb_ai_check_field_target( $post_id );

	if ( is_wp_error( $post ) ) {
		return $post;
	}

	$out = array_merge(
		hb_ai_post_summary( $post->ID ),
		array(
			'content'  => $post->post_content,
			'excerpt'  => $post->post_excerpt,
			'modified' => $post->post_modified,
		)
	);

	if ( post_type_supports( $post->post_type, 'thumbnail' ) ) {
		$out['featured_image'] = hb_ai_image_summary( get_post_thumbnail_id( $post->ID ) );
	}

	if ( class_exists( 'WPSEO_Meta' ) ) {
		$out['seo'] = hb_ai_get_seo_values( $post->ID );
	}

	return $out;
}

/**
 * Write Yoast SEO fields on a post.
 *
 * Yoast meta is not revisioned, so the previous values are returned alongside
 * the new ones; that response is the only record of what was replaced. Yoast
 * sanitizes each key on write and its meta watcher rebuilds the indexable, so
 * the change reaches the rendered head without a separate save.
 *
 * @param array $input Ability input.
 * @return array|WP_Error Previous and current values, or an error.
 */
function hb_ai_update_seo( array $input ) {
	$post_id = isset( $input['post_id'] ) ? absint( $input['post_id'] ) : 0;
	$post    = hb_ai_check_field_target( $post_id );

	if ( is_wp_error( $post ) ) {
		return $post;
	}

	$fields  = hb_ai_seo_fields();
	$changes = array_intersect_key( $input, $fields );

	if ( empty( $changes ) ) {
		return new WP_Error(
			'hb_ai_nothing_to_do',
			sprintf(
				/* translators: %s: comma-separated list of field names. */
				__( 'No SEO fields were supplied. Accepted: %s', 'hb' ),
				implode( ', ', array_keys( $fields ) )
			)
		);
	}

	$previous = hb_ai_get_seo_values( $post->ID );

	foreach ( $changes as $input_key => $value ) {
		WPSEO_Meta::set_value( $fields[ $input_key ], (string) $value, $post->ID );
	}

	return array(
		'post_id'  => (int) $post->ID,
		'previous' => $previous,
		'seo'      => hb_ai_get_seo_values( $post->ID ),
	);
}

/**
 * Register the ability category used by the content abilities.
 *
 * @return void
 */
function hb_register_ai_ability_category() {
	wp_register_ability_category(
		'hb-content',
		array(
			'label'       => __( 'Hoverboard Content', 'hb' ),
			'description' => __( 'Abilities that create and revise site content.', 'hb' ),
		)
	);
}
add_action( 'wp_abilities_api_categories_init', 'hb_register_ai_ability_category' );

/**
 * Register the content abilities.
 *
 * @return void
 */
function hb_register_ai_abilities() {
	$allowed = hb_ai_allowed_post_types();

	wp_register_ability(
		'hb/create-content',
		array(
			'label'               => __( 'Create content', 'hb' ),
			'description'         => __( 'Create a new post, page or custom post type entry. New content is always created as a draft; a human must publish it.', 'hb' ),
			'category'            => 'hb-content',
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'post_type' => array(
						'type'        => 'string',
						'enum'        => $allowed,
						'default'     => 'post',
						'description' => __( 'Post type to create.', 'hb' ),
					),
					'title'     => array(
						'type'        => 'string',
						'description' => __( 'Title of the new content.', 'hb' ),
					),
					'content'   => array(
						'type'        => 'string',
						'description' => __( 'Block markup or HTML for the body.', 'hb' ),
					),
					'excerpt'   => array(
						'type'        => 'string',
						'description' => __( 'Optional excerpt.', 'hb' ),
					),
				),
				'required'   => array( 'title' ),
			),
			'output_schema'       => hb_ai_post_summary_schema(),
			'execute_callback'    => 'hb_ai_create_content',
			'permission_callback' => static function ( array $input ) {
				return hb_ai_can_create( isset( $input['post_type'] ) ? (string) $input['post_type'] : 'post' );
			},
			'meta'                => hb_ai_ability_meta(),
		)
	);

	wp_register_ability(
		'hb/update-content',
		array(
			'label'               => __( 'Update content', 'hb' ),
			'description'         => __( 'Revise an existing post, page or custom post type entry. Editing published content changes the live site immediately; WordPress keeps a revision of the previous version.', 'hb' ),
			'category'            => 'hb-content',
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'post_id' => array(
						'type'        => 'integer',
						'description' => __( 'ID of the content to revise.', 'hb' ),
					),
					'title'   => array(
						'type'        => 'string',
						'description' => __( 'Replacement title. Omit to leave unchanged.', 'hb' ),
					),
					'content' => array(
						'type'        => 'string',
						'description' => __( 'Replacement body. Omit to leave unchanged.', 'hb' ),
					),
					'excerpt' => array(
						'type'        => 'string',
						'description' => __( 'Replacement excerpt. Omit to leave unchanged.', 'hb' ),
					),
				),
				'required'   => array( 'post_id' ),
			),
			'output_schema'       => hb_ai_post_summary_schema(),
			'execute_callback'    => 'hb_ai_update_content',
			'permission_callback' => static function ( array $input ) {
				$post_id = isset( $input['post_id'] ) ? absint( $input['post_id'] ) : 0;

				return current_user_can( 'edit_post', $post_id );
			},
			'meta'                => hb_ai_ability_meta(),
		)
	);

	wp_register_ability(
		'hb/list-content',
		array(
			'label'               => __( 'List content', 'hb' ),
			'description'         => __( 'List posts, pages and custom post type entries the connected account can edit, most recently modified first.', 'hb' ),
			'category'            => 'hb-content',
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'post_type' => array(
						'type'        => 'string',
						'enum'        => $allowed,
						'description' => __( 'Limit results to one post type. Omit for all.', 'hb' ),
					),
					'status'    => array(
						'type'        => 'string',
						'enum'        => hb_ai_editable_statuses(),
						'description' => __( 'Limit results to one status. Omit for all editable statuses.', 'hb' ),
					),
					'search'    => array(
						'type'        => 'string',
						'description' => __( 'Optional keyword search.', 'hb' ),
					),
				),
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'items' => array(
						'type'  => 'array',
						'items' => hb_ai_post_summary_schema(),
					),
				),
			),
			'execute_callback'    => 'hb_ai_list_content',
			'permission_callback' => static function () {
				return current_user_can( 'edit_posts' ) || current_user_can( 'edit_pages' );
			},
			'meta'                => array_merge(
				hb_ai_ability_meta(),
				array(
					'annotations' => array(
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
					),
				)
			),
		)
	);

	$seo_properties = array();

	foreach ( array_keys( hb_ai_seo_fields() ) as $input_key ) {
		$seo_properties[ $input_key ] = array( 'type' => 'string' );
	}

	wp_register_ability(
		'hb/get-content',
		array(
			'label'               => __( 'Get content', 'hb' ),
			'description'         => __( 'Read the title, body, excerpt, featured image and Yoast SEO fields of a post, page or custom post type entry, including drafts. Use list-content to find the ID.', 'hb' ),
			'category'            => 'hb-content',
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'post_id' => array(
						'type'        => 'integer',
						'description' => __( 'ID of the content to read.', 'hb' ),
					),
				),
				'required'   => array( 'post_id' ),
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array_merge(
					hb_ai_post_summary_schema()['properties'],
					array(
						'content'        => array( 'type' => 'string' ),
						'excerpt'        => array( 'type' => 'string' ),
						'modified'       => array( 'type' => 'string' ),
						'featured_image' => hb_ai_image_summary_schema(),
						'seo'            => array(
							'type'       => 'object',
							'properties' => $seo_properties,
						),
					)
				),
			),
			'execute_callback'    => 'hb_ai_get_content',
			'permission_callback' => static function ( array $input ) {
				$post_id = isset( $input['post_id'] ) ? absint( $input['post_id'] ) : 0;

				return current_user_can( 'edit_post', $post_id );
			},
			'meta'                => array_merge(
				hb_ai_ability_meta(),
				array(
					'annotations' => array(
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
					),
				)
			),
		)
	);

	if ( class_exists( 'WPSEO_Meta' ) ) {
		wp_register_ability(
			'hb/update-seo',
			array(
				'label'               => __( 'Update SEO fields', 'hb' ),
				'description'         => __( 'Set the Yoast SEO title, meta description or focus keyphrase of a post. Omitted fields are left unchanged; an empty string clears a field. Yoast keeps no revisions, so the previous values are returned.', 'hb' ),
				'category'            => 'hb-content',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array_merge(
						array(
							'post_id' => array(
								'type'        => 'integer',
								'description' => __( 'ID of the post to update.', 'hb' ),
							),
						),
						$seo_properties
					),
					'required'   => array( 'post_id' ),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'post_id'  => array( 'type' => 'integer' ),
						'previous' => array(
							'type'       => 'object',
							'properties' => $seo_properties,
						),
						'seo'      => array(
							'type'       => 'object',
							'properties' => $seo_properties,
						),
					),
				),
				'execute_callback'    => 'hb_ai_update_seo',
				'permission_callback' => static function ( array $input ) {
					$post_id = isset( $input['post_id'] ) ? absint( $input['post_id'] ) : 0;

					return current_user_can( 'edit_post', $post_id );
				},
				'meta'                => hb_ai_ability_meta(),
			)
		);
	}

	// The field abilities are only meaningful with ACF present.
	if ( ! function_exists( 'acf_get_field_groups' ) ) {
		return;
	}

	wp_register_ability(
		'hb/get-fields',
		array(
			'label'               => __( 'Get custom fields', 'hb' ),
			'description'         => __( 'List the ACF custom fields attached to a post, with their current values. Call this before updating fields to learn which names and types exist.', 'hb' ),
			'category'            => 'hb-content',
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'post_id' => array(
						'type'        => 'integer',
						'description' => __( 'ID of the post whose fields to read.', 'hb' ),
					),
				),
				'required'   => array( 'post_id' ),
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'post_id' => array( 'type' => 'integer' ),
					'fields'  => array(
						'type'  => 'array',
						'items' => array(
							'type'       => 'object',
							'properties' => array(
								'name'     => array( 'type' => 'string' ),
								'label'    => array( 'type' => 'string' ),
								'type'     => array( 'type' => 'string' ),
								'writable' => array( 'type' => 'boolean' ),
								'value'    => array( 'type' => array( 'string', 'number', 'boolean', 'null' ) ),
								'choices'  => array(
									'type'  => 'array',
									'items' => array( 'type' => 'string' ),
								),
							),
						),
					),
				),
			),
			'execute_callback'    => 'hb_ai_get_fields',
			'permission_callback' => static function ( array $input ) {
				$post_id = isset( $input['post_id'] ) ? absint( $input['post_id'] ) : 0;

				return current_user_can( 'edit_post', $post_id );
			},
			'meta'                => array_merge(
				hb_ai_ability_meta(),
				array(
					'annotations' => array(
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
					),
				)
			),
		)
	);

	wp_register_ability(
		'hb/update-fields',
		array(
			'label'               => __( 'Update custom fields', 'hb' ),
			'description'         => __( 'Set ACF custom field values on a post. Only simple field types can be written; call get-fields first to see which. A revision is saved before writing, so previous values stay recoverable.', 'hb' ),
			'category'            => 'hb-content',
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'post_id' => array(
						'type'        => 'integer',
						'description' => __( 'ID of the post to update.', 'hb' ),
					),
					'fields'  => array(
						'type'        => 'object',
						'description' => __( 'Map of ACF field name to its new value.', 'hb' ),
					),
				),
				'required'   => array( 'post_id', 'fields' ),
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'post_id' => array( 'type' => 'integer' ),
					'updated' => array(
						'type'  => 'array',
						'items' => array( 'type' => 'string' ),
					),
					'summary' => hb_ai_post_summary_schema(),
				),
			),
			'execute_callback'    => 'hb_ai_update_fields',
			'permission_callback' => static function ( array $input ) {
				$post_id = isset( $input['post_id'] ) ? absint( $input['post_id'] ) : 0;

				return current_user_can( 'edit_post', $post_id );
			},
			'meta'                => hb_ai_ability_meta(),
		)
	);
}
add_action( 'wp_abilities_api_init', 'hb_register_ai_abilities' );
