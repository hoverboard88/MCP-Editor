<?php
/**
 * Taxonomy Abilities
 *
 * Lets an MCP client do with categories, tags and custom taxonomies what an
 * editor can from the post screen and the term screens: assign terms to a
 * post (creating new ones inline, as the tag box does), and create, rename,
 * re-parent and delete terms.
 *
 * Capabilities are checked per taxonomy through its own cap object, so a
 * taxonomy with custom capabilities is only reachable if the role holds them.
 * Term assignments and term records are not revisioned; every write returns
 * what it replaced.
 *
 * @package Hoverboard
 */

/**
 * Taxonomies an MCP client may read and assign.
 *
 * Those attached to the allowed post types and shown in the admin UI, which
 * is what an editor sees in the post screen. Internal taxonomies such as
 * nav_menu and wp_theme are not attached to content types and drop out.
 *
 * @return array List of taxonomy slugs.
 */
function hb_ai_allowed_taxonomies() {
	$taxonomies = array();

	foreach ( hb_ai_allowed_post_types() as $post_type ) {
		foreach ( get_object_taxonomies( $post_type, 'objects' ) as $taxonomy ) {
			if ( $taxonomy->show_ui ) {
				$taxonomies[ $taxonomy->name ] = $taxonomy->name;
			}
		}
	}

	/**
	 * Filters the taxonomies an MCP client may read and assign.
	 *
	 * @param array $taxonomies List of taxonomy slugs.
	 */
	return array_values( (array) apply_filters( 'hb_ai_allowed_taxonomies', array_values( $taxonomies ) ) );
}

/**
 * Validate a taxonomy slug.
 *
 * @param string $taxonomy Taxonomy slug.
 * @return WP_Taxonomy|WP_Error The taxonomy, or an error.
 */
function hb_ai_check_taxonomy( $taxonomy ) {
	$taxonomy = (string) $taxonomy;

	if ( ! in_array( $taxonomy, hb_ai_allowed_taxonomies(), true ) ) {
		return new WP_Error(
			'hb_ai_invalid_taxonomy',
			sprintf(
				/* translators: %s: comma-separated list of taxonomy slugs. */
				__( 'That taxonomy cannot be used here. Allowed: %s', 'hb' ),
				implode( ', ', hb_ai_allowed_taxonomies() )
			)
		);
	}

	return get_taxonomy( $taxonomy );
}

/**
 * Whether the current user holds one of a taxonomy's capabilities.
 *
 * @param string $taxonomy Taxonomy slug.
 * @param string $cap      Key of the taxonomy cap object: assign_terms,
 *                         edit_terms, delete_terms or manage_terms.
 * @return bool Whether the capability is held.
 */
function hb_ai_taxonomy_can( $taxonomy, $cap ) {
	$object = get_taxonomy( (string) $taxonomy );

	return false !== $object
		&& in_array( $object->name, hb_ai_allowed_taxonomies(), true )
		&& current_user_can( $object->cap->$cap );
}

/**
 * Validate a term ID as a term in an allowed taxonomy.
 *
 * @param int $term_id Term ID.
 * @return WP_Term|WP_Error The term, or an error.
 */
function hb_ai_check_term( $term_id ) {
	$term = get_term( absint( $term_id ) );

	if ( ! $term instanceof WP_Term ) {
		return new WP_Error( 'hb_ai_not_found', __( 'No term with that ID.', 'hb' ) );
	}

	$taxonomy = hb_ai_check_taxonomy( $term->taxonomy );

	return is_wp_error( $taxonomy ) ? $taxonomy : $term;
}

/**
 * Summarize a term.
 *
 * @param WP_Term $term Term.
 * @return array Term summary.
 */
function hb_ai_term_summary( $term ) {
	return array(
		'term_id'     => (int) $term->term_id,
		'taxonomy'    => $term->taxonomy,
		'name'        => $term->name,
		'slug'        => $term->slug,
		'parent'      => (int) $term->parent,
		'description' => $term->description,
		'count'       => (int) $term->count,
	);
}

/**
 * Schema describing a term summary.
 *
 * @return array JSON Schema definition.
 */
function hb_ai_term_summary_schema() {
	return array(
		'type'       => 'object',
		'properties' => array(
			'term_id'     => array( 'type' => 'integer' ),
			'taxonomy'    => array( 'type' => 'string' ),
			'name'        => array( 'type' => 'string' ),
			'slug'        => array( 'type' => 'string' ),
			'parent'      => array(
				'type'        => 'integer',
				'description' => __( 'Parent term ID, 0 for a top-level term.', 'hb' ),
			),
			'description' => array( 'type' => 'string' ),
			'count'       => array(
				'type'        => 'integer',
				'description' => __( 'Number of published posts using the term.', 'hb' ),
			),
		),
	);
}

/**
 * The terms assigned to a post, in one taxonomy.
 *
 * @param int    $post_id  Post ID.
 * @param string $taxonomy Taxonomy slug.
 * @return array List of term summaries.
 */
function hb_ai_post_terms_in( $post_id, $taxonomy ) {
	$terms = wp_get_object_terms( $post_id, $taxonomy );

	return is_wp_error( $terms ) ? array() : array_map( 'hb_ai_term_summary', $terms );
}

/**
 * The terms assigned to a post, in every allowed taxonomy of its type.
 *
 * @param WP_Post $post Post.
 * @return array Map of taxonomy slug to list of term summaries.
 */
function hb_ai_post_terms( $post ) {
	$out = array();

	foreach ( get_object_taxonomies( $post->post_type ) as $taxonomy ) {
		if ( in_array( $taxonomy, hb_ai_allowed_taxonomies(), true ) ) {
			$out[ $taxonomy ] = hb_ai_post_terms_in( $post->ID, $taxonomy );
		}
	}

	return $out;
}

/**
 * Resolve a client's list of terms to term IDs, creating any named term that
 * does not exist yet.
 *
 * An integer must be an existing term in the taxonomy. A string is matched by
 * name, then slug; if neither matches, it is created, which is what typing a
 * new tag into the post screen does. Everything is resolved before anything
 * is created, so a bad ID cannot leave stray new terms behind.
 *
 * @param string $taxonomy Taxonomy slug.
 * @param array  $terms    Term IDs and/or names.
 * @return array|WP_Error List of term IDs, or an error.
 */
function hb_ai_resolve_terms( $taxonomy, $terms ) {
	$ids     = array();
	$missing = array();

	foreach ( $terms as $term ) {
		if ( is_int( $term ) || ( is_string( $term ) && ctype_digit( $term ) ) ) {
			$found = get_term( (int) $term, $taxonomy );

			if ( ! $found instanceof WP_Term ) {
				return new WP_Error(
					'hb_ai_not_found',
					/* translators: 1: term ID, 2: taxonomy slug. */
					sprintf( __( 'No term %1$d in %2$s.', 'hb' ), (int) $term, $taxonomy )
				);
			}

			$ids[] = (int) $found->term_id;
			continue;
		}

		$name = trim( (string) $term );

		if ( '' === $name ) {
			continue;
		}

		$found = get_term_by( 'name', $name, $taxonomy );

		if ( ! $found ) {
			$found = get_term_by( 'slug', sanitize_title( $name ), $taxonomy );
		}

		if ( $found ) {
			$ids[] = (int) $found->term_id;
		} else {
			$missing[] = $name;
		}
	}

	if ( $missing && ! hb_ai_taxonomy_can( $taxonomy, 'edit_terms' ) ) {
		return new WP_Error(
			'hb_ai_cannot_create_terms',
			sprintf(
				/* translators: 1: comma-separated list of term names, 2: taxonomy slug. */
				__( 'These terms do not exist in %2$s and cannot be created here: %1$s', 'hb' ),
				implode( ', ', $missing ),
				$taxonomy
			)
		);
	}

	foreach ( array_unique( $missing ) as $name ) {
		$created = wp_insert_term( $name, $taxonomy );

		if ( is_wp_error( $created ) ) {
			return $created;
		}

		$ids[] = (int) $created['term_id'];
	}

	return array_values( array_unique( $ids ) );
}

/**
 * List the taxonomies available to a client.
 *
 * @return array Taxonomy list.
 */
function hb_ai_list_taxonomies() {
	$items = array();

	foreach ( hb_ai_allowed_taxonomies() as $slug ) {
		$taxonomy = get_taxonomy( $slug );

		$items[] = array(
			'taxonomy'       => $slug,
			'label'          => $taxonomy->label,
			'hierarchical'   => (bool) $taxonomy->hierarchical,
			'post_types'     => array_values( array_intersect( $taxonomy->object_type, hb_ai_allowed_post_types() ) ),
			'can_assign'     => current_user_can( $taxonomy->cap->assign_terms ),
			'can_edit_terms' => current_user_can( $taxonomy->cap->edit_terms ),
			'can_delete'     => current_user_can( $taxonomy->cap->delete_terms ),
		);
	}

	return array( 'items' => $items );
}

/**
 * List or search the terms of a taxonomy.
 *
 * @param array $input Ability input.
 * @return array|WP_Error Term list, or an error.
 */
function hb_ai_list_terms( array $input ) {
	$taxonomy = hb_ai_check_taxonomy( isset( $input['taxonomy'] ) ? $input['taxonomy'] : '' );

	if ( is_wp_error( $taxonomy ) ) {
		return $taxonomy;
	}

	$limit = isset( $input['limit'] ) ? absint( $input['limit'] ) : 100;
	$args  = array(
		'taxonomy'   => $taxonomy->name,
		'hide_empty' => false,
		'number'     => max( 1, min( 200, $limit ) ),
		'orderby'    => 'name',
	);

	if ( ! empty( $input['search'] ) ) {
		$args['search'] = (string) $input['search'];
	}

	if ( isset( $input['parent'] ) && $taxonomy->hierarchical ) {
		$args['parent'] = absint( $input['parent'] );
	}

	$terms = get_terms( $args );

	if ( is_wp_error( $terms ) ) {
		return $terms;
	}

	return array( 'items' => array_map( 'hb_ai_term_summary', $terms ) );
}

/**
 * Set, add or remove the terms of a post in one taxonomy.
 *
 * @param array $input Ability input.
 * @return array|WP_Error Previous and current terms, or an error.
 */
function hb_ai_set_post_terms( array $input ) {
	$post_id = isset( $input['post_id'] ) ? absint( $input['post_id'] ) : 0;
	$post    = hb_ai_check_field_target( $post_id );

	if ( is_wp_error( $post ) ) {
		return $post;
	}

	$taxonomy = hb_ai_check_taxonomy( isset( $input['taxonomy'] ) ? $input['taxonomy'] : '' );

	if ( is_wp_error( $taxonomy ) ) {
		return $taxonomy;
	}

	if ( ! is_object_in_taxonomy( $post->post_type, $taxonomy->name ) ) {
		return new WP_Error(
			'hb_ai_invalid_taxonomy',
			/* translators: 1: taxonomy slug, 2: post type slug. */
			sprintf( __( 'The taxonomy %1$s is not used by %2$s.', 'hb' ), $taxonomy->name, $post->post_type )
		);
	}

	$mode  = isset( $input['mode'] ) ? (string) $input['mode'] : 'replace';
	$terms = isset( $input['terms'] ) && is_array( $input['terms'] ) ? $input['terms'] : array();

	if ( ! in_array( $mode, array( 'replace', 'add', 'remove' ), true ) ) {
		return new WP_Error( 'hb_ai_invalid_mode', __( 'mode must be replace, add or remove.', 'hb' ) );
	}

	if ( 'remove' === $mode ) {
		// Removing never creates: only match existing terms.
		$ids = array();

		foreach ( $terms as $term ) {
			$found = is_numeric( $term )
				? get_term( (int) $term, $taxonomy->name )
				: get_term_by( 'name', (string) $term, $taxonomy->name );

			if ( $found instanceof WP_Term ) {
				$ids[] = (int) $found->term_id;
			}
		}
	} else {
		$ids = hb_ai_resolve_terms( $taxonomy->name, $terms );

		if ( is_wp_error( $ids ) ) {
			return $ids;
		}
	}

	$previous    = hb_ai_post_terms_in( $post->ID, $taxonomy->name );
	$current_ids = wp_list_pluck( $previous, 'term_id' );

	if ( 'add' === $mode ) {
		$final = array_values( array_unique( array_merge( $current_ids, $ids ) ) );
	} elseif ( 'remove' === $mode ) {
		$final = array_values( array_diff( $current_ids, $ids ) );
	} else {
		$final = $ids;
	}

	// wp_set_post_categories() falls back to the default category when the
	// list ends up empty, as the post screen does; other taxonomies may be
	// left empty.
	if ( 'category' === $taxonomy->name ) {
		$result = wp_set_post_categories( $post->ID, $final );
	} else {
		$result = wp_set_object_terms( $post->ID, $final, $taxonomy->name );
	}

	if ( is_wp_error( $result ) ) {
		return $result;
	}

	return array(
		'post_id'  => (int) $post->ID,
		'taxonomy' => $taxonomy->name,
		'previous' => $previous,
		'terms'    => hb_ai_post_terms_in( $post->ID, $taxonomy->name ),
	);
}

/**
 * Validate a parent term for a hierarchical taxonomy.
 *
 * @param WP_Taxonomy $taxonomy  Taxonomy.
 * @param int         $parent_id Parent term ID; 0 for top level.
 * @return int|WP_Error The parent ID, or an error.
 */
function hb_ai_check_parent_term( $taxonomy, $parent_id ) {
	$parent = absint( $parent_id );

	if ( 0 === $parent ) {
		return 0;
	}

	if ( ! $taxonomy->hierarchical ) {
		return new WP_Error( 'hb_ai_not_hierarchical', __( 'That taxonomy has no parent terms.', 'hb' ) );
	}

	if ( ! get_term( $parent, $taxonomy->name ) instanceof WP_Term ) {
		return new WP_Error( 'hb_ai_not_found', __( 'No parent term with that ID in this taxonomy.', 'hb' ) );
	}

	return $parent;
}

/**
 * Create a term.
 *
 * @param array $input Ability input.
 * @return array|WP_Error The new term, or an error.
 */
function hb_ai_create_term( array $input ) {
	$taxonomy = hb_ai_check_taxonomy( isset( $input['taxonomy'] ) ? $input['taxonomy'] : '' );

	if ( is_wp_error( $taxonomy ) ) {
		return $taxonomy;
	}

	$parent = hb_ai_check_parent_term( $taxonomy, isset( $input['parent'] ) ? $input['parent'] : 0 );

	if ( is_wp_error( $parent ) ) {
		return $parent;
	}

	$args = array( 'parent' => $parent );

	foreach ( array( 'slug', 'description' ) as $key ) {
		if ( isset( $input[ $key ] ) ) {
			$args[ $key ] = (string) $input[ $key ];
		}
	}

	$created = wp_insert_term( (string) $input['name'], $taxonomy->name, $args );

	if ( is_wp_error( $created ) ) {
		return $created;
	}

	return hb_ai_term_summary( get_term( $created['term_id'], $taxonomy->name ) );
}

/**
 * Rename, re-slug, re-parent or re-describe a term.
 *
 * @param array $input Ability input.
 * @return array|WP_Error Previous and current term, or an error.
 */
function hb_ai_update_term( array $input ) {
	$term = hb_ai_check_term( isset( $input['term_id'] ) ? $input['term_id'] : 0 );

	if ( is_wp_error( $term ) ) {
		return $term;
	}

	$args = array();

	foreach ( array( 'name', 'slug', 'description' ) as $key ) {
		if ( isset( $input[ $key ] ) ) {
			$args[ $key ] = (string) $input[ $key ];
		}
	}

	if ( isset( $input['parent'] ) ) {
		$parent = hb_ai_check_parent_term( get_taxonomy( $term->taxonomy ), $input['parent'] );

		if ( is_wp_error( $parent ) ) {
			return $parent;
		}

		if ( $parent === (int) $term->term_id || in_array( $parent, array_map( 'intval', (array) get_term_children( $term->term_id, $term->taxonomy ) ), true ) ) {
			return new WP_Error( 'hb_ai_invalid_parent', __( 'A term cannot be its own parent or the child of one of its descendants.', 'hb' ) );
		}

		$args['parent'] = $parent;
	}

	if ( empty( $args ) ) {
		return new WP_Error( 'hb_ai_nothing_to_do', __( 'No name, slug, parent or description was supplied.', 'hb' ) );
	}

	$previous = hb_ai_term_summary( $term );
	$result   = wp_update_term( $term->term_id, $term->taxonomy, $args );

	if ( is_wp_error( $result ) ) {
		return $result;
	}

	return array(
		'previous' => $previous,
		'term'     => hb_ai_term_summary( get_term( $term->term_id, $term->taxonomy ) ),
	);
}

/**
 * Delete a term.
 *
 * The term is removed from every post using it. Children of a hierarchical
 * term move up to its parent, as in the admin.
 *
 * @param array $input Ability input.
 * @return array|WP_Error The deleted term, or an error.
 */
function hb_ai_delete_term( array $input ) {
	$term = hb_ai_check_term( isset( $input['term_id'] ) ? $input['term_id'] : 0 );

	if ( is_wp_error( $term ) ) {
		return $term;
	}

	$summary = hb_ai_term_summary( $term );
	$result  = wp_delete_term( $term->term_id, $term->taxonomy );

	if ( is_wp_error( $result ) ) {
		return $result;
	}

	// 0 is WordPress refusing, which it does for a taxonomy's default term.
	if ( true !== $result ) {
		return new WP_Error( 'hb_ai_cannot_delete', __( 'That term cannot be deleted. It may be the default term for its taxonomy.', 'hb' ) );
	}

	return array( 'deleted' => $summary );
}

/**
 * Register the taxonomy abilities.
 *
 * @return void
 */
function hb_register_ai_taxonomy_abilities() {
	$taxonomies = hb_ai_allowed_taxonomies();

	$readonly = array_merge(
		hb_ai_ability_meta(),
		array(
			'annotations' => array(
				'readonly'    => true,
				'destructive' => false,
				'idempotent'  => true,
			),
		)
	);

	$term_id_permission = static function ( $cap ) {
		return static function ( array $input ) use ( $cap ) {
			$term_id = isset( $input['term_id'] ) ? absint( $input['term_id'] ) : 0;

			return $term_id && current_user_can( $cap, $term_id );
		};
	};

	$taxonomy_property = array(
		'type'        => 'string',
		'enum'        => $taxonomies,
		'description' => __( 'Taxonomy slug, e.g. category or post_tag. Use list-taxonomies to see them all.', 'hb' ),
	);

	wp_register_ability(
		'hb/list-taxonomies',
		array(
			'label'               => __( 'List taxonomies', 'hb' ),
			'description'         => __( 'List the taxonomies (categories, tags and custom taxonomies) that content can be organised by, which post types use each, and what the connected account may do with them.', 'hb' ),
			'category'            => 'hb-content',
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(),
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'items' => array(
						'type'  => 'array',
						'items' => array(
							'type'       => 'object',
							'properties' => array(
								'taxonomy'       => array( 'type' => 'string' ),
								'label'          => array( 'type' => 'string' ),
								'hierarchical'   => array( 'type' => 'boolean' ),
								'post_types'     => array(
									'type'  => 'array',
									'items' => array( 'type' => 'string' ),
								),
								'can_assign'     => array( 'type' => 'boolean' ),
								'can_edit_terms' => array( 'type' => 'boolean' ),
								'can_delete'     => array( 'type' => 'boolean' ),
							),
						),
					),
				),
			),
			'execute_callback'    => 'hb_ai_list_taxonomies',
			'permission_callback' => static function () {
				return current_user_can( 'edit_posts' ) || current_user_can( 'edit_pages' );
			},
			'meta'                => $readonly,
		)
	);

	wp_register_ability(
		'hb/list-terms',
		array(
			'label'               => __( 'List terms', 'hb' ),
			'description'         => __( 'List or search the terms of a taxonomy, alphabetically, including unused ones.', 'hb' ),
			'category'            => 'hb-content',
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'taxonomy' => $taxonomy_property,
					'search'   => array(
						'type'        => 'string',
						'description' => __( 'Optional search on term name.', 'hb' ),
					),
					'parent'   => array(
						'type'        => 'integer',
						'description' => __( 'Hierarchical taxonomies only: list just the direct children of this term ID; 0 for top-level terms.', 'hb' ),
					),
					'limit'    => array(
						'type'    => 'integer',
						'minimum' => 1,
						'maximum' => 200,
						'default' => 100,
					),
				),
				'required'   => array( 'taxonomy' ),
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'items' => array(
						'type'  => 'array',
						'items' => hb_ai_term_summary_schema(),
					),
				),
			),
			'execute_callback'    => 'hb_ai_list_terms',
			'permission_callback' => static function ( array $input ) {
				return hb_ai_taxonomy_can( isset( $input['taxonomy'] ) ? $input['taxonomy'] : '', 'assign_terms' );
			},
			'meta'                => $readonly,
		)
	);

	wp_register_ability(
		'hb/set-post-terms',
		array(
			'label'               => __( 'Set post terms', 'hb' ),
			'description'         => __( 'Assign categories, tags or custom taxonomy terms to a post. mode replace (default) sets exactly the given terms, add keeps existing ones, remove takes the given ones off. Terms are IDs or names; a name that does not exist yet is created, as typing a new tag in the editor does. Published posts change immediately; the previous terms are returned since assignments are not revisioned.', 'hb' ),
			'category'            => 'hb-content',
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'post_id'  => array(
						'type'        => 'integer',
						'description' => __( 'ID of the post to update.', 'hb' ),
					),
					'taxonomy' => $taxonomy_property,
					'terms'    => array(
						'type'        => 'array',
						'items'       => array( 'type' => array( 'integer', 'string' ) ),
						'description' => __( 'Term IDs and/or names.', 'hb' ),
					),
					'mode'     => array(
						'type'    => 'string',
						'enum'    => array( 'replace', 'add', 'remove' ),
						'default' => 'replace',
					),
				),
				'required'   => array( 'post_id', 'taxonomy', 'terms' ),
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'post_id'  => array( 'type' => 'integer' ),
					'taxonomy' => array( 'type' => 'string' ),
					'previous' => array(
						'type'  => 'array',
						'items' => hb_ai_term_summary_schema(),
					),
					'terms'    => array(
						'type'  => 'array',
						'items' => hb_ai_term_summary_schema(),
					),
				),
			),
			'execute_callback'    => 'hb_ai_set_post_terms',
			'permission_callback' => static function ( array $input ) {
				$post_id = isset( $input['post_id'] ) ? absint( $input['post_id'] ) : 0;

				return current_user_can( 'edit_post', $post_id )
					&& hb_ai_taxonomy_can( isset( $input['taxonomy'] ) ? $input['taxonomy'] : '', 'assign_terms' );
			},
			'meta'                => array_merge(
				hb_ai_ability_meta(),
				array(
					'annotations' => array(
						'readonly'    => false,
						'destructive' => false,
						'idempotent'  => true,
					),
				)
			),
		)
	);

	wp_register_ability(
		'hb/create-term',
		array(
			'label'               => __( 'Create term', 'hb' ),
			'description'         => __( 'Create a category, tag or custom taxonomy term.', 'hb' ),
			'category'            => 'hb-content',
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'taxonomy'    => $taxonomy_property,
					'name'        => array( 'type' => 'string' ),
					'slug'        => array(
						'type'        => 'string',
						'description' => __( 'Optional; generated from the name when omitted.', 'hb' ),
					),
					'parent'      => array(
						'type'        => 'integer',
						'description' => __( 'Parent term ID, hierarchical taxonomies only.', 'hb' ),
					),
					'description' => array( 'type' => 'string' ),
				),
				'required'   => array( 'taxonomy', 'name' ),
			),
			'output_schema'       => hb_ai_term_summary_schema(),
			'execute_callback'    => 'hb_ai_create_term',
			'permission_callback' => static function ( array $input ) {
				return hb_ai_taxonomy_can( isset( $input['taxonomy'] ) ? $input['taxonomy'] : '', 'edit_terms' );
			},
			'meta'                => hb_ai_ability_meta(),
		)
	);

	wp_register_ability(
		'hb/update-term',
		array(
			'label'               => __( 'Update term', 'hb' ),
			'description'         => __( 'Rename a term, or change its slug, parent or description. Omitted fields are left unchanged. Changing a slug changes the term archive URL. Terms are not revisioned, so the previous values are returned.', 'hb' ),
			'category'            => 'hb-content',
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'term_id'     => array( 'type' => 'integer' ),
					'name'        => array( 'type' => 'string' ),
					'slug'        => array( 'type' => 'string' ),
					'parent'      => array(
						'type'        => 'integer',
						'description' => __( 'New parent term ID, or 0 to make it top-level.', 'hb' ),
					),
					'description' => array( 'type' => 'string' ),
				),
				'required'   => array( 'term_id' ),
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'previous' => hb_ai_term_summary_schema(),
					'term'     => hb_ai_term_summary_schema(),
				),
			),
			'execute_callback'    => 'hb_ai_update_term',
			'permission_callback' => $term_id_permission( 'edit_term' ),
			'meta'                => hb_ai_ability_meta(),
		)
	);

	wp_register_ability(
		'hb/delete-term',
		array(
			'label'               => __( 'Delete term', 'hb' ),
			'description'         => __( 'Permanently delete a term. It is removed from every post that uses it, and child terms move up to its parent. This cannot be undone; the deleted term\'s details are returned. A taxonomy\'s default term (e.g. Uncategorized) cannot be deleted.', 'hb' ),
			'category'            => 'hb-content',
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'term_id' => array( 'type' => 'integer' ),
				),
				'required'   => array( 'term_id' ),
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'deleted' => hb_ai_term_summary_schema(),
				),
			),
			'execute_callback'    => 'hb_ai_delete_term',
			'permission_callback' => $term_id_permission( 'delete_term' ),
			'meta'                => array_merge(
				hb_ai_ability_meta(),
				array(
					'annotations' => array(
						'readonly'    => false,
						'destructive' => true,
						'idempotent'  => false,
					),
				)
			),
		)
	);
}
add_action( 'wp_abilities_api_init', 'hb_register_ai_taxonomy_abilities' );
