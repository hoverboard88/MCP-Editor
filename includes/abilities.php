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
 * happens to hold a capability for, and post_status is only ever set to the
 * handful of values the abilities name, never passed through from a client.
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
 * The filter's incoming value is ignored, so the callback takes no arguments.
 *
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
 * Whether the current user may publish the given post type.
 *
 * @param string $post_type Post type slug.
 * @return bool Whether the current user may publish this post type.
 */
function hb_ai_can_publish( $post_type ) {
	$post_type_object = get_post_type_object( $post_type );

	return null !== $post_type_object && current_user_can( $post_type_object->cap->publish_posts );
}

/**
 * Parse a client-supplied publish date.
 *
 * Accepts ISO 8601. A date with an offset or Z is converted; one without is
 * taken to be in the site's timezone, as the editor does.
 *
 * @param string $date Date string.
 * @return array|WP_Error Array of local and GMT MySQL dates, or an error.
 */
function hb_ai_parse_date( $date ) {
	$dates = rest_get_date_with_gmt( (string) $date );

	if ( null === $dates ) {
		return new WP_Error(
			'hb_ai_invalid_date',
			__( 'The date must be ISO 8601, for example 2026-12-01T09:00:00.', 'hb' )
		);
	}

	return $dates;
}

/**
 * Post fields that publish content now or at a given date.
 *
 * Status is always 'publish'. WordPress turns that into 'future' itself when
 * the date is ahead of now, which is how scheduling works in the editor too.
 * edit_date stops wp_update_post() discarding the date of a draft that never
 * had one.
 *
 * @param string $date Optional ISO 8601 date. Empty publishes now.
 * @return array|WP_Error Post fields, or an error.
 */
function hb_ai_publish_fields( $date ) {
	if ( '' === $date ) {
		$dates = array( current_time( 'mysql' ), current_time( 'mysql', true ) );
	} else {
		$dates = hb_ai_parse_date( $date );

		if ( is_wp_error( $dates ) ) {
			return $dates;
		}
	}

	return array(
		'post_status'   => 'publish',
		'post_date'     => $dates[0],
		'post_date_gmt' => $dates[1],
		'edit_date'     => true,
	);
}

/**
 * Add the publish date to a post summary.
 *
 * @param int $post_id Post ID.
 * @return array Summary including the date, in site time and GMT.
 */
function hb_ai_post_summary_with_date( $post_id ) {
	$post = get_post( $post_id );

	return array_merge(
		hb_ai_post_summary( $post_id ),
		array(
			'date'     => mysql_to_rfc3339( $post->post_date ),
			'date_gmt' => '0000-00-00 00:00:00' === $post->post_date_gmt ? null : mysql_to_rfc3339( $post->post_date_gmt ) . 'Z',
		)
	);
}

/**
 * Schema for a post summary including its date.
 *
 * @return array JSON Schema definition.
 */
function hb_ai_post_summary_with_date_schema() {
	$schema = hb_ai_post_summary_schema();

	$schema['properties']['date']     = array(
		'type'        => 'string',
		'description' => __( 'Publish date in the site\'s timezone.', 'hb' ),
	);
	$schema['properties']['date_gmt'] = array(
		'type'        => array( 'string', 'null' ),
		'description' => __( 'Publish date in UTC, or null for an undated draft.', 'hb' ),
	);

	return $schema;
}

/**
 * Create a new piece of content.
 *
 * Created as a draft unless status asks for 'publish', in which case it goes
 * live now, or is scheduled when date is in the future.
 *
 * @param array $input Ability input.
 * @return array|WP_Error Summary of the new post, or an error.
 */
function hb_ai_create_content( array $input ) {
	$post_type = isset( $input['post_type'] ) ? (string) $input['post_type'] : 'post';
	$status    = isset( $input['status'] ) ? (string) $input['status'] : 'draft';
	$date      = isset( $input['date'] ) ? trim( (string) $input['date'] ) : '';

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

	if ( ! in_array( $status, array( 'draft', 'publish' ), true ) ) {
		return new WP_Error( 'hb_ai_bad_status', __( 'status must be draft or publish.', 'hb' ) );
	}

	$postarr = array(
		'post_type'    => $post_type,
		'post_status'  => 'draft',
		'post_title'   => wp_slash( (string) $input['title'] ),
		'post_content' => wp_slash( isset( $input['content'] ) ? (string) $input['content'] : '' ),
		'post_excerpt' => wp_slash( isset( $input['excerpt'] ) ? (string) $input['excerpt'] : '' ),
	);

	if ( 'publish' === $status ) {
		$fields = hb_ai_publish_fields( $date );

		if ( is_wp_error( $fields ) ) {
			return $fields;
		}

		$postarr = array_merge( $postarr, $fields );
	} elseif ( '' !== $date ) {
		return new WP_Error( 'hb_ai_date_needs_publish', __( 'date only applies with status publish. Drafts are not dated.', 'hb' ) );
	}

	$post_id = wp_insert_post( $postarr, true );

	if ( is_wp_error( $post_id ) ) {
		return $post_id;
	}

	return hb_ai_post_summary_with_date( $post_id );
}

/**
 * Publish or schedule existing content.
 *
 * Takes a draft, pending or scheduled post live now, or schedules it for a
 * future date. A scheduled post can be rescheduled. Content that is already
 * published is refused: changing a live post's date reorders the site and is
 * not what this is for.
 *
 * @param array $input Ability input.
 * @return array|WP_Error Summary of the post, or an error.
 */
function hb_ai_publish_content( array $input ) {
	$post_id = isset( $input['post_id'] ) ? absint( $input['post_id'] ) : 0;
	$post    = hb_ai_check_field_target( $post_id );

	if ( is_wp_error( $post ) ) {
		return $post;
	}

	if ( ! in_array( $post->post_status, array( 'auto-draft', 'draft', 'pending', 'future' ), true ) ) {
		return new WP_Error(
			'hb_ai_bad_status',
			sprintf(
				/* translators: %s: post status slug. */
				__( 'Only drafts, pending and scheduled content can be published here; this is "%s".', 'hb' ),
				$post->post_status
			)
		);
	}

	$fields = hb_ai_publish_fields( isset( $input['date'] ) ? trim( (string) $input['date'] ) : '' );

	if ( is_wp_error( $fields ) ) {
		return $fields;
	}

	$result = wp_update_post( array_merge( array( 'ID' => $post->ID ), $fields ), true );

	if ( is_wp_error( $result ) ) {
		return $result;
	}

	return hb_ai_post_summary_with_date( $post->ID );
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

	// Validate every attribute before anything is written.
	$attributes = hb_ai_prepare_attributes( $post, $input );

	if ( is_wp_error( $attributes ) ) {
		return $attributes;
	}

	list( $attribute_changes, $after ) = $attributes;

	$changes = array_merge( $changes, $attribute_changes );

	if ( count( $changes ) < 2 && empty( $after ) ) {
		return new WP_Error( 'hb_ai_nothing_to_do', __( 'Nothing to change was supplied.', 'hb' ) );
	}

	if ( count( $changes ) > 1 ) {
		$result = wp_update_post( $changes, true );

		if ( is_wp_error( $result ) ) {
			return $result;
		}
	}

	foreach ( $after as $write ) {
		$result = $write();

		if ( is_wp_error( $result ) ) {
			return $result;
		}
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

	// Trashed content is only listed when asked for, so it can be restored.
	$statuses = in_array( $status, array_merge( hb_ai_editable_statuses(), array( 'trash' ) ), true )
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
 * Every registered type that stores a value, so the client can edit whatever
 * an editor could in the post screen: structured types (repeater, flexible
 * content, group, clone) and relational ones (image, gallery, relationship,
 * post object, taxonomy, user) included. Only the presentational types, which
 * hold no value, are left out.
 *
 * Values are checked by ACF's own validation before anything is saved, as the
 * post screen does, so required fields, min/max rows and the like still hold.
 *
 * @return array List of ACF field type slugs.
 */
function hb_ai_writable_field_types() {
	$types = function_exists( 'acf_get_field_types' ) ? array_keys( acf_get_field_types() ) : array();
	$types = array_values( array_diff( $types, array( 'message', 'tab', 'accordion' ) ) );

	/**
	 * Filters the ACF field types an MCP client may write.
	 *
	 * @param array $types List of ACF field type slugs.
	 */
	return (array) apply_filters( 'hb_ai_writable_field_types', $types );
}

/**
 * Container field types whose value is made of sub-field values.
 *
 * @return array List of ACF field type slugs.
 */
function hb_ai_acf_container_types() {
	return array( 'repeater', 'group', 'clone', 'flexible_content' );
}

/**
 * Find a sub-field by name or key.
 *
 * @param array  $sub_fields ACF sub-field arrays.
 * @param string $name       Sub-field name or key.
 * @return array|null The sub-field, or null.
 */
function hb_ai_acf_find_sub_field( $sub_fields, $name ) {
	foreach ( (array) $sub_fields as $sub_field ) {
		if ( $name === $sub_field['name'] || $name === $sub_field['key'] ) {
			return $sub_field;
		}
	}

	return null;
}

/**
 * Find a flexible content layout by name.
 *
 * @param array  $field ACF flexible content field.
 * @param string $name  Layout name.
 * @return array|null The layout, or null.
 */
function hb_ai_acf_find_layout( $field, $name ) {
	foreach ( (array) ( isset( $field['layouts'] ) ? $field['layouts'] : array() ) as $layout ) {
		if ( $name === $layout['name'] ) {
			return $layout;
		}
	}

	return null;
}

/**
 * Convert one row or group of sub-field values from names to field keys.
 *
 * @param array  $sub_fields ACF sub-field arrays the row may use.
 * @param mixed  $row        Row value supplied by the client.
 * @param string $path       Location of the row, for error messages.
 * @return array|WP_Error Row keyed by sub-field key, or an error.
 */
function hb_ai_acf_row_to_keys( $sub_fields, $row, $path ) {
	if ( ! is_array( $row ) ) {
		return new WP_Error(
			'hb_ai_invalid_value',
			/* translators: %s: field path. */
			sprintf( __( '"%s" must be an object of sub-field values.', 'hb' ), $path )
		);
	}

	$out = array();

	foreach ( $row as $name => $value ) {
		$sub_field = hb_ai_acf_find_sub_field( $sub_fields, (string) $name );

		if ( null === $sub_field ) {
			return new WP_Error(
				'hb_ai_unknown_field',
				sprintf(
					/* translators: 1: field path, 2: comma-separated list of sub-field names. */
					__( 'No sub-field "%1$s". Available: %2$s', 'hb' ),
					$path . '.' . $name,
					implode( ', ', wp_list_pluck( (array) $sub_fields, 'name' ) )
				)
			);
		}

		$converted = hb_ai_acf_to_keys( $sub_field, $value, $path . '.' . $sub_field['name'] );

		if ( is_wp_error( $converted ) ) {
			return $converted;
		}

		$out[ $sub_field['key'] ] = $converted;
	}

	return $out;
}

/**
 * Convert a client-supplied value, addressed by sub-field names, to the
 * field-key form ACF validates and saves.
 *
 * ACF's save routines accept names for sub-fields, but its validation only
 * looks values up by key; converting first means both see the same thing,
 * and unknown names are reported instead of silently dropped.
 *
 * @param array  $field ACF field.
 * @param mixed  $value Value supplied by the client.
 * @param string $path  Location of the value, for error messages.
 * @return mixed|WP_Error Value in field-key form, or an error.
 */
function hb_ai_acf_to_keys( $field, $value, $path ) {
	if ( ! in_array( $field['type'], hb_ai_acf_container_types(), true ) || null === $value || '' === $value ) {
		return $value;
	}

	if ( 'group' === $field['type'] || 'clone' === $field['type'] ) {
		return hb_ai_acf_row_to_keys( $field['sub_fields'], $value, $path );
	}

	if ( ! is_array( $value ) || ! wp_is_numeric_array( $value ) ) {
		return new WP_Error(
			'hb_ai_invalid_value',
			/* translators: %s: field path. */
			sprintf( __( '"%s" must be an array of rows.', 'hb' ), $path )
		);
	}

	$rows = array();

	foreach ( array_values( $value ) as $i => $row ) {
		$row_path = $path . '[' . $i . ']';

		if ( 'repeater' === $field['type'] ) {
			$converted = hb_ai_acf_row_to_keys( $field['sub_fields'], $row, $row_path );
		} else {
			$layout_name = is_array( $row ) && isset( $row['acf_fc_layout'] ) ? (string) $row['acf_fc_layout'] : '';
			$layout      = hb_ai_acf_find_layout( $field, $layout_name );

			if ( null === $layout ) {
				return new WP_Error(
					'hb_ai_invalid_layout',
					sprintf(
						/* translators: 1: field path, 2: comma-separated list of layout names. */
						__( '"%1$s" needs an acf_fc_layout of one of: %2$s', 'hb' ),
						$row_path,
						implode( ', ', wp_list_pluck( (array) $field['layouts'], 'name' ) )
					)
				);
			}

			unset( $row['acf_fc_layout'] );
			$converted = hb_ai_acf_row_to_keys( $layout['sub_fields'], $row, $row_path );

			if ( ! is_wp_error( $converted ) ) {
				$converted = array_merge( array( 'acf_fc_layout' => $layout['name'] ), $converted );
			}
		}

		if ( is_wp_error( $converted ) ) {
			return $converted;
		}

		$rows[] = $converted;
	}

	return $rows;
}

/**
 * Re-key one stored row or group from field keys to sub-field names.
 *
 * @param array $sub_fields ACF sub-field arrays.
 * @param mixed $row        Stored row, keyed by sub-field key.
 * @return array Row keyed by sub-field name.
 */
function hb_ai_acf_row_to_names( $sub_fields, $row ) {
	$row = is_array( $row ) ? $row : array();
	$out = array();

	foreach ( (array) $sub_fields as $sub_field ) {
		if ( array_key_exists( $sub_field['key'], $row ) ) {
			$value = $row[ $sub_field['key'] ];
		} elseif ( array_key_exists( $sub_field['name'], $row ) ) {
			$value = $row[ $sub_field['name'] ];
		} else {
			$value = null;
		}

		$out[ $sub_field['name'] ] = hb_ai_acf_to_names( $sub_field, $value );
	}

	return $out;
}

/**
 * Convert a stored (unformatted) value to the name-keyed form the client
 * writes back, so get-fields output can be edited and returned as-is.
 *
 * Unformatted values keep IDs as IDs — an image is its attachment ID, a
 * relationship a list of post IDs — which is also what update-fields expects.
 *
 * @param array $field ACF field.
 * @param mixed $value Unformatted stored value.
 * @return mixed Value keyed by sub-field names.
 */
function hb_ai_acf_to_names( $field, $value ) {
	if ( ! in_array( $field['type'], hb_ai_acf_container_types(), true ) ) {
		return $value;
	}

	if ( 'group' === $field['type'] || 'clone' === $field['type'] ) {
		return hb_ai_acf_row_to_names( $field['sub_fields'], $value );
	}

	$rows = array();

	foreach ( is_array( $value ) ? array_values( $value ) : array() as $row ) {
		if ( 'repeater' === $field['type'] ) {
			$rows[] = hb_ai_acf_row_to_names( $field['sub_fields'], $row );
			continue;
		}

		$layout = hb_ai_acf_find_layout( $field, isset( $row['acf_fc_layout'] ) ? (string) $row['acf_fc_layout'] : '' );

		if ( null !== $layout ) {
			$rows[] = array_merge(
				array( 'acf_fc_layout' => $layout['name'] ),
				hb_ai_acf_row_to_names( $layout['sub_fields'], $row )
			);
		}
	}

	return $rows;
}

/**
 * How a field's value should be written, for types where it is not obvious.
 *
 * @param array $field ACF field.
 * @return string Hint, or an empty string.
 */
function hb_ai_acf_value_hint( $field ) {
	$multiple = ! empty( $field['multiple'] );

	switch ( $field['type'] ) {
		case 'image':
		case 'file':
			return __( 'Attachment ID (integer). Use list-images or upload-image to find one.', 'hb' );
		case 'gallery':
			return __( 'Array of attachment IDs.', 'hb' );
		case 'relationship':
			return __( 'Array of post IDs.', 'hb' );
		case 'post_object':
		case 'page_link':
			return $multiple ? __( 'Array of post IDs.', 'hb' ) : __( 'Post ID.', 'hb' );
		case 'taxonomy':
			return __( 'Term ID, or array of term IDs. Use list-terms to find them.', 'hb' );
		case 'user':
			return $multiple ? __( 'Array of user IDs.', 'hb' ) : __( 'User ID.', 'hb' );
		case 'select':
			return $multiple ? __( 'Array of choice values.', 'hb' ) : __( 'One choice value.', 'hb' );
		case 'checkbox':
			return __( 'Array of choice values.', 'hb' );
		case 'true_false':
			return __( 'true or false.', 'hb' );
		case 'date_picker':
			return __( 'Date as Ymd, e.g. 20261231.', 'hb' );
		case 'date_time_picker':
			return __( 'Date and time as Y-m-d H:i:s.', 'hb' );
		case 'time_picker':
			return __( 'Time as H:i:s.', 'hb' );
		case 'link':
			return __( 'Object with url, title and target ("" or "_blank").', 'hb' );
		case 'google_map':
			return __( 'Object with address, lat and lng.', 'hb' );
		case 'repeater':
			return __( 'Array of rows; each row an object of sub-field name to value. The whole list is replaced.', 'hb' );
		case 'flexible_content':
			return __( 'Array of rows; each row an object with acf_fc_layout set to a layout name, plus that layout\'s sub-field values. The whole list is replaced.', 'hb' );
		case 'group':
		case 'clone':
			return __( 'Object of sub-field name to value.', 'hb' );
	}

	return '';
}

/**
 * Describe a field's structure for a client: type, constraints, choices and,
 * for container types, its sub-fields or layouts.
 *
 * @param array $field ACF field.
 * @return array Field description.
 */
function hb_ai_acf_describe( $field ) {
	$out = array(
		'name'     => $field['name'],
		'label'    => isset( $field['label'] ) ? $field['label'] : '',
		'type'     => $field['type'],
		'required' => ! empty( $field['required'] ),
	);

	if ( ! empty( $field['instructions'] ) ) {
		$out['instructions'] = $field['instructions'];
	}

	$hint = hb_ai_acf_value_hint( $field );

	if ( '' !== $hint ) {
		$out['value_format'] = $hint;
	}

	if ( ! empty( $field['choices'] ) && is_array( $field['choices'] ) ) {
		$out['choices'] = array_map( 'strval', array_keys( $field['choices'] ) );
	}

	foreach ( array( 'min', 'max', 'post_type', 'taxonomy' ) as $setting ) {
		if ( isset( $field[ $setting ] ) && '' !== $field[ $setting ] && array() !== $field[ $setting ] ) {
			$out[ $setting ] = $field[ $setting ];
		}
	}

	if ( ! empty( $field['sub_fields'] ) ) {
		$out['sub_fields'] = array_map( 'hb_ai_acf_describe', array_values( $field['sub_fields'] ) );
	}

	if ( 'flexible_content' === $field['type'] && ! empty( $field['layouts'] ) ) {
		$out['layouts'] = array();

		foreach ( $field['layouts'] as $layout ) {
			$out['layouts'][] = array(
				'name'       => $layout['name'],
				'label'      => $layout['label'],
				'sub_fields' => array_map( 'hb_ai_acf_describe', array_values( (array) $layout['sub_fields'] ) ),
			);
		}
	}

	return $out;
}

/**
 * Run ACF's own validation over a set of field-key values.
 *
 * The same checks the post screen runs on save: required fields, min/max
 * rows, number ranges, email and URL formats, and any acf/validate_value
 * filters the site adds.
 *
 * @param array $values Map of field key to [ field, value ].
 * @return true|WP_Error True when valid, or an error listing the problems.
 */
function hb_ai_acf_validate( $values ) {
	if ( ! function_exists( 'acf_validate_value' ) ) {
		return true;
	}

	acf_reset_validation_errors();

	foreach ( $values as $key => $pair ) {
		acf_validate_value( $pair[1], $pair[0], 'acf[' . $key . ']' );
	}

	$errors = acf_get_validation_errors();
	acf_reset_validation_errors();

	if ( empty( $errors ) ) {
		return true;
	}

	$messages = array();

	foreach ( $errors as $error ) {
		$messages[] = $error['message'] . ' (' . $error['input'] . ')';
	}

	return new WP_Error(
		'hb_ai_invalid_value',
		/* translators: %s: list of validation messages. */
		sprintf( __( 'ACF validation failed: %s', 'hb' ), implode( '; ', $messages ) )
	);
}

/**
 * Resolve the ACF fields attached to a post.
 *
 * Scoped to the field groups whose location rules match this specific post, so
 * options page, user and term field groups are never reachable. Top-level
 * fields are returned with their sub-fields nested inside.
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

	foreach ( hb_ai_get_post_fields( $post->ID ) as $field ) {
		$entry = hb_ai_acf_describe( $field );

		$entry['writable'] = in_array( $field['type'], $writable, true );

		// Unformatted, then re-keyed by name: exactly the shape update-fields
		// accepts, so a value can be edited and sent straight back.
		if ( $entry['writable'] ) {
			$entry['value'] = hb_ai_acf_to_names( $field, get_field( $field['key'], $post->ID, false ) );
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
	$prepared  = array();

	// Validate everything before writing anything, so a bad name or value in
	// the batch cannot leave the post half-updated.
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

		$field     = $available[ $name ];
		$converted = hb_ai_acf_to_keys( $field, $value, $name );

		if ( is_wp_error( $converted ) ) {
			return $converted;
		}

		$prepared[ $field['key'] ] = array( $field, $converted, $name );
	}

	$valid = hb_ai_acf_validate( $prepared );

	if ( is_wp_error( $valid ) ) {
		return $valid;
	}

	// Snapshot current state, ACF values included, before overwriting.
	wp_save_post_revision( $post->ID );

	$written = array();

	foreach ( $prepared as $key => $item ) {
		update_field( $key, $item[1], $post->ID );
		$written[] = $item[2];
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
 * _yoast_wpseo_ prefix: the snippet fields, the advanced tab (search
 * visibility, link following, canonical URL, breadcrumb title) and the social
 * previews. Redirects are a Yoast Premium feature and not included.
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
			'seo_title'          => 'title',
			'meta_description'   => 'metadesc',
			'focus_keyphrase'    => 'focuskw',
			'search_visibility'  => 'meta-robots-noindex',
			'follow_links'       => 'meta-robots-nofollow',
			'canonical_url'      => 'canonical',
			'breadcrumb_title'   => 'bctitle',
			'social_title'       => 'opengraph-title',
			'social_description' => 'opengraph-description',
			'x_title'            => 'twitter-title',
			'x_description'      => 'twitter-description',
		)
	);
}

/**
 * Readable values for the Yoast fields stored as codes.
 *
 * Maps input key to a map of readable value to the code Yoast stores. For
 * search_visibility, "default" follows the post type's setting in Yoast.
 *
 * @return array Map of input key to value map.
 */
function hb_ai_seo_choices() {
	return array(
		'search_visibility' => array(
			'default' => '0',
			'noindex' => '1',
			'index'   => '2',
		),
		'follow_links'      => array(
			'follow'   => '0',
			'nofollow' => '1',
		),
	);
}

/**
 * Read the Yoast SEO fields of a post.
 *
 * @param int $post_id Post ID.
 * @return array Map of input key to stored value.
 */
function hb_ai_get_seo_values( $post_id ) {
	$values  = array();
	$choices = hb_ai_seo_choices();

	foreach ( hb_ai_seo_fields() as $input_key => $meta_key ) {
		$value = (string) WPSEO_Meta::get_value( $meta_key, $post_id );

		if ( isset( $choices[ $input_key ] ) ) {
			$readable = array_search( $value, $choices[ $input_key ], true );
			$value    = false === $readable ? $value : $readable;
		}

		$values[ $input_key ] = $value;
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

	$out['terms']      = hb_ai_post_terms( $post );
	$out['attributes'] = hb_ai_get_attributes( $post );

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

	$choices = hb_ai_seo_choices();

	foreach ( $changes as $input_key => $value ) {
		if ( isset( $choices[ $input_key ] ) ) {
			if ( ! isset( $choices[ $input_key ][ (string) $value ] ) ) {
				return new WP_Error(
					'hb_ai_invalid_value',
					sprintf(
						/* translators: 1: field name, 2: comma-separated list of values. */
						__( '%1$s must be one of: %2$s', 'hb' ),
						$input_key,
						implode( ', ', array_keys( $choices[ $input_key ] ) )
					)
				);
			}

			$changes[ $input_key ] = $choices[ $input_key ][ (string) $value ];
		}
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

	$date_property = array(
		'type'        => 'string',
		'description' => sprintf(
			/* translators: %s: timezone name. */
			__( 'Publish date, ISO 8601 (e.g. 2026-12-01T09:00:00). A future date schedules the post. Without an offset, the site timezone (%s) is assumed. Omit to publish now.', 'hb' ),
			wp_timezone_string()
		),
	);

	wp_register_ability(
		'hb/create-content',
		array(
			'label'               => __( 'Create content', 'hb' ),
			'description'         => __( 'Create a new post, page or custom post type entry. Created as a draft unless status is publish; with status publish it goes live now, or is scheduled if date is in the future.', 'hb' ),
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
					'status'    => array(
						'type'        => 'string',
						'enum'        => array( 'draft', 'publish' ),
						'default'     => 'draft',
						'description' => __( 'draft to save for review, publish to take live or schedule.', 'hb' ),
					),
					'date'      => $date_property,
				),
				'required'   => array( 'title' ),
			),
			'output_schema'       => hb_ai_post_summary_with_date_schema(),
			'execute_callback'    => 'hb_ai_create_content',
			'permission_callback' => static function ( array $input ) {
				$post_type = isset( $input['post_type'] ) ? (string) $input['post_type'] : 'post';

				if ( ! hb_ai_can_create( $post_type ) ) {
					return false;
				}

				return ! isset( $input['status'] ) || 'publish' !== $input['status'] || hb_ai_can_publish( $post_type );
			},
			'meta'                => hb_ai_ability_meta(),
		)
	);

	wp_register_ability(
		'hb/update-content',
		array(
			'label'               => __( 'Update content', 'hb' ),
			'description'         => __( 'Revise an existing post, page or custom post type entry: title, body, excerpt, and the post screen settings (slug, author, parent, order, template, discussion, password, sticky, format). Only supplied fields change. Editing published content changes the live site immediately; WordPress keeps a revision of the previous content. get-content shows the current settings and the available templates and formats.', 'hb' ),
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
				) + hb_ai_attribute_properties(),
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
		'hb/publish-content',
		array(
			'label'               => __( 'Publish or schedule content', 'hb' ),
			'description'         => __( 'Publish a draft, pending or scheduled post, page or custom post type entry now, or schedule it for a future date. Also reschedules a scheduled post. Publishing changes the live site; already-published content is refused.', 'hb' ),
			'category'            => 'hb-content',
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'post_id' => array(
						'type'        => 'integer',
						'description' => __( 'ID of the content to publish or schedule.', 'hb' ),
					),
					'date'    => $date_property,
				),
				'required'   => array( 'post_id' ),
			),
			'output_schema'       => hb_ai_post_summary_with_date_schema(),
			'execute_callback'    => 'hb_ai_publish_content',
			'permission_callback' => static function ( array $input ) {
				$post_id = isset( $input['post_id'] ) ? absint( $input['post_id'] ) : 0;
				$post    = get_post( $post_id );

				return null !== $post
					&& current_user_can( 'edit_post', $post_id )
					&& hb_ai_can_publish( $post->post_type );
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
						'enum'        => array_merge( hb_ai_editable_statuses(), array( 'trash' ) ),
						'description' => __( 'Limit results to one status. Omit for all editable statuses; trashed content is only listed with status trash.', 'hb' ),
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

	$seo_choices = hb_ai_seo_choices();

	foreach ( array_keys( hb_ai_seo_fields() ) as $input_key ) {
		$seo_properties[ $input_key ] = array( 'type' => 'string' );

		if ( isset( $seo_choices[ $input_key ] ) ) {
			$seo_properties[ $input_key ]['enum'] = array_keys( $seo_choices[ $input_key ] );
		}
	}

	if ( isset( $seo_properties['search_visibility'] ) ) {
		$seo_properties['search_visibility']['description'] = __( 'Whether search engines may index the page. "default" follows the post type setting in Yoast; "noindex" removes the page from search results.', 'hb' );
	}

	wp_register_ability(
		'hb/get-content',
		array(
			'label'               => __( 'Get content', 'hb' ),
			'description'         => __( 'Read the title, body, excerpt, featured image, taxonomy terms, post settings (slug, author, template and so on) and Yoast SEO fields of a post, page or custom post type entry, including drafts. Use list-content to find the ID.', 'hb' ),
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
						'terms'          => array(
							'type'                 => 'object',
							'description'          => __( 'Assigned terms, keyed by taxonomy slug.', 'hb' ),
							'additionalProperties' => array(
								'type'  => 'array',
								'items' => hb_ai_term_summary_schema(),
							),
						),
						'attributes'     => array(
							'type'        => 'object',
							'description' => __( 'Post screen settings, as update-content accepts them, plus available_templates and formats where they apply.', 'hb' ),
						),
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
				'description'         => __( 'Set Yoast SEO fields on a post: title, meta description, focus keyphrase, search visibility (noindex), link following, canonical URL, breadcrumb title and social previews. Omitted fields are left unchanged; an empty string clears a field. Yoast keeps no revisions, so the previous values are returned.', 'hb' ),
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
			'description'         => __( 'List the ACF custom fields attached to a post with their current values, structure (sub-fields, flexible content layouts, choices) and the value format each expects. Call this before updating fields; values come back in the same shape update-fields accepts.', 'hb' ),
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
								'name'         => array( 'type' => 'string' ),
								'label'        => array( 'type' => 'string' ),
								'type'         => array( 'type' => 'string' ),
								'required'     => array( 'type' => 'boolean' ),
								'writable'     => array( 'type' => 'boolean' ),
								'instructions' => array( 'type' => 'string' ),
								'value_format' => array( 'type' => 'string' ),
								'value'        => array( 'description' => __( 'Current value, in the shape update-fields accepts.', 'hb' ) ),
								'choices'      => array(
									'type'  => 'array',
									'items' => array( 'type' => 'string' ),
								),
								'sub_fields'   => array(
									'type'        => 'array',
									'description' => __( 'Sub-fields of a repeater, group or clone, described the same way.', 'hb' ),
								),
								'layouts'      => array(
									'type'        => 'array',
									'description' => __( 'Flexible content layouts: name, label and sub_fields.', 'hb' ),
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
			'description'         => __( 'Set ACF custom field values on a post, any field type: repeaters and flexible content take the full list of rows (it replaces the existing rows), groups an object, images and relationships IDs. Address sub-fields by name. Call get-fields first for names, structure and formats. ACF validation runs first and nothing is saved if any value fails; a revision is saved before writing, so previous values stay recoverable.', 'hb' ),
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
						'description' => __( 'Map of ACF field name to its new value. Omitted fields are left unchanged.', 'hb' ),
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
