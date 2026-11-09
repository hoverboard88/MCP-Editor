<?php
/**
 * Post Attributes and Status
 *
 * The rest of what an editor can change from the post screen: slug, author,
 * parent, order, template, discussion settings, password, sticky and post
 * format (applied through hb/update-content), plus unpublishing, making
 * private, trashing and restoring (hb/set-status).
 *
 * Each setting is validated against what the post type supports and what the
 * account may do, so a client gets a clear refusal rather than a silent no-op.
 *
 * @package Hoverboard
 */

/**
 * Input schema properties for the post attributes, merged into
 * hb/update-content.
 *
 * @return array JSON Schema properties.
 */
function hb_ai_attribute_properties() {
	return array(
		'slug'           => array(
			'type'        => 'string',
			'description' => __( 'URL slug. Changing it on published content changes the live URL.', 'hb' ),
		),
		'author'         => array(
			'type'        => array( 'integer', 'string' ),
			'description' => __( 'Author user ID or login. Use list-authors to find one.', 'hb' ),
		),
		'parent'         => array(
			'type'        => 'integer',
			'description' => __( 'Parent post ID for hierarchical types such as pages; 0 for top level.', 'hb' ),
		),
		'menu_order'     => array(
			'type'        => 'integer',
			'description' => __( 'Order among siblings (page attributes).', 'hb' ),
		),
		'template'       => array(
			'type'        => 'string',
			'description' => __( 'Template file or slug; "default" for the default template. get-content lists the available ones.', 'hb' ),
		),
		'comment_status' => array(
			'type' => 'string',
			'enum' => array( 'open', 'closed' ),
		),
		'ping_status'    => array(
			'type' => 'string',
			'enum' => array( 'open', 'closed' ),
		),
		'password'       => array(
			'type'        => 'string',
			'description' => __( 'Password-protect the content; an empty string removes protection. Not allowed on private content.', 'hb' ),
		),
		'sticky'         => array(
			'type'        => 'boolean',
			'description' => __( 'Stick a post to the top of the blog. Posts only.', 'hb' ),
		),
		'format'         => array(
			'type'        => 'string',
			'enum'        => array_keys( get_post_format_strings() ),
			'description' => __( 'Post format, where the theme supports formats. "standard" clears it.', 'hb' ),
		),
	);
}

/**
 * Templates available to a post.
 *
 * The same list WordPress validates page_template against on save.
 *
 * @param WP_Post $post Post.
 * @return array Map of template file or slug to name.
 */
function hb_ai_available_templates( $post ) {
	return (array) wp_get_theme()->get_page_templates( $post, $post->post_type );
}

/**
 * Resolve an author from an ID or login.
 *
 * @param int|string $author User ID or login.
 * @return WP_User|false The user, or false.
 */
function hb_ai_resolve_user( $author ) {
	if ( is_int( $author ) || ( is_string( $author ) && ctype_digit( $author ) ) ) {
		return get_user_by( 'id', (int) $author );
	}

	return get_user_by( 'login', (string) $author );
}

/**
 * Validate the attribute inputs for a post.
 *
 * Nothing is written here. The result is the set of wp_update_post() fields
 * plus the writes that go through their own APIs (sticky, format), run only
 * once every input has passed.
 *
 * @param WP_Post $post  Post being updated.
 * @param array   $input Ability input.
 * @return array|WP_Error [ post fields, callbacks ], or an error.
 */
function hb_ai_prepare_attributes( $post, array $input ) {
	$type    = get_post_type_object( $post->post_type );
	$changes = array();
	$after   = array();

	if ( isset( $input['slug'] ) ) {
		$slug = sanitize_title( (string) $input['slug'] );

		if ( '' === $slug ) {
			return new WP_Error( 'hb_ai_invalid_slug', __( 'The slug is empty once sanitized.', 'hb' ) );
		}

		$changes['post_name'] = $slug;
	}

	if ( isset( $input['author'] ) ) {
		if ( ! current_user_can( $type->cap->edit_others_posts ) ) {
			return new WP_Error( 'hb_ai_forbidden', __( 'This account cannot change the author of this content.', 'hb' ) );
		}

		$user = hb_ai_resolve_user( $input['author'] );

		if ( ! $user ) {
			return new WP_Error( 'hb_ai_not_found', __( 'No user with that ID or login.', 'hb' ) );
		}

		if ( ! user_can( $user, $type->cap->edit_posts ) ) {
			return new WP_Error( 'hb_ai_invalid_author', __( 'That user cannot author this type of content.', 'hb' ) );
		}

		$changes['post_author'] = $user->ID;
	}

	if ( isset( $input['parent'] ) ) {
		$parent_id = absint( $input['parent'] );

		if ( ! is_post_type_hierarchical( $post->post_type ) ) {
			return new WP_Error( 'hb_ai_not_hierarchical', __( 'This type of content has no parent.', 'hb' ) );
		}

		if ( $parent_id ) {
			$parent = get_post( $parent_id );

			if ( null === $parent || $parent->post_type !== $post->post_type ) {
				return new WP_Error( 'hb_ai_invalid_parent', __( 'The parent must be an existing entry of the same type.', 'hb' ) );
			}

			if ( $parent_id === $post->ID || in_array( $post->ID, get_post_ancestors( $parent_id ), true ) ) {
				return new WP_Error( 'hb_ai_invalid_parent', __( 'Content cannot be its own parent or the child of one of its descendants.', 'hb' ) );
			}
		}

		$changes['post_parent'] = $parent_id;
	}

	if ( isset( $input['menu_order'] ) ) {
		if ( ! post_type_supports( $post->post_type, 'page-attributes' ) ) {
			return new WP_Error( 'hb_ai_unsupported', __( 'This type of content has no order setting.', 'hb' ) );
		}

		$changes['menu_order'] = (int) $input['menu_order'];
	}

	if ( isset( $input['template'] ) ) {
		$template  = (string) $input['template'];
		$templates = hb_ai_available_templates( $post );

		if ( '' === $template ) {
			$template = 'default';
		}

		if ( 'default' !== $template && ! isset( $templates[ $template ] ) ) {
			return new WP_Error(
				'hb_ai_invalid_template',
				sprintf(
					/* translators: %s: comma-separated list of templates. */
					__( 'That template is not available. Available: default, %s', 'hb' ),
					implode( ', ', array_keys( $templates ) )
				)
			);
		}

		$changes['page_template'] = $template;
	}

	foreach ( array(
		'comment_status' => 'comments',
		'ping_status'    => 'trackbacks',
	) as $key => $feature ) {
		if ( ! isset( $input[ $key ] ) ) {
			continue;
		}

		if ( ! post_type_supports( $post->post_type, $feature ) ) {
			return new WP_Error(
				'hb_ai_unsupported',
				/* translators: %s: setting name. */
				sprintf( __( 'This type of content does not support %s.', 'hb' ), $key )
			);
		}

		if ( ! in_array( $input[ $key ], array( 'open', 'closed' ), true ) ) {
			/* translators: %s: setting name. */
			return new WP_Error( 'hb_ai_invalid_value', sprintf( __( '%s must be open or closed.', 'hb' ), $key ) );
		}

		$changes[ $key ] = $input[ $key ];
	}

	if ( isset( $input['password'] ) ) {
		$password = (string) $input['password'];

		if ( '' !== $password && 'private' === $post->post_status ) {
			return new WP_Error( 'hb_ai_invalid_password', __( 'Private content cannot also be password protected.', 'hb' ) );
		}

		$changes['post_password'] = $password;
	}

	if ( isset( $input['sticky'] ) ) {
		if ( 'post' !== $post->post_type ) {
			return new WP_Error( 'hb_ai_unsupported', __( 'Only posts can be sticky.', 'hb' ) );
		}

		if ( ! current_user_can( $type->cap->edit_others_posts ) ) {
			return new WP_Error( 'hb_ai_forbidden', __( 'This account cannot make posts sticky.', 'hb' ) );
		}

		$sticky  = (bool) $input['sticky'];
		$after[] = static function () use ( $post, $sticky ) {
			if ( $sticky ) {
				stick_post( $post->ID );
			} else {
				unstick_post( $post->ID );
			}

			return true;
		};
	}

	if ( isset( $input['format'] ) ) {
		$format = (string) $input['format'];

		if ( ! post_type_supports( $post->post_type, 'post-formats' ) ) {
			return new WP_Error( 'hb_ai_unsupported', __( 'This type of content has no post formats.', 'hb' ) );
		}

		if ( ! array_key_exists( $format, get_post_format_strings() ) ) {
			return new WP_Error(
				'hb_ai_invalid_value',
				/* translators: %s: comma-separated list of formats. */
				sprintf( __( 'Unknown format. Allowed: %s', 'hb' ), implode( ', ', array_keys( get_post_format_strings() ) ) )
			);
		}

		$after[] = static function () use ( $post, $format ) {
			$result = set_post_format( $post, 'standard' === $format ? '' : $format );

			return is_wp_error( $result ) ? $result : true;
		};
	}

	return array( $changes, $after );
}

/**
 * The current attribute values of a post, plus the options a client needs to
 * change them (available templates and formats).
 *
 * @param WP_Post $post Post.
 * @return array Attribute values.
 */
function hb_ai_get_attributes( $post ) {
	$author = get_userdata( (int) $post->post_author );

	$out = array(
		'slug'     => $post->post_name,
		'author'   => array(
			'id'   => (int) $post->post_author,
			'name' => $author ? $author->display_name : '',
		),
		'password' => $post->post_password,
	);

	if ( is_post_type_hierarchical( $post->post_type ) ) {
		$out['parent'] = (int) $post->post_parent;
	}

	if ( post_type_supports( $post->post_type, 'page-attributes' ) ) {
		$out['menu_order'] = (int) $post->menu_order;
	}

	$templates = hb_ai_available_templates( $post );

	if ( $templates ) {
		$slug                       = get_page_template_slug( $post );
		$out['template']            = $slug ? $slug : 'default';
		$out['available_templates'] = $templates;
	}

	if ( post_type_supports( $post->post_type, 'comments' ) ) {
		$out['comment_status'] = $post->comment_status;
	}

	if ( post_type_supports( $post->post_type, 'trackbacks' ) ) {
		$out['ping_status'] = $post->ping_status;
	}

	if ( 'post' === $post->post_type ) {
		$out['sticky'] = is_sticky( $post->ID );
	}

	if ( post_type_supports( $post->post_type, 'post-formats' ) ) {
		$format         = get_post_format( $post );
		$out['format']  = $format ? $format : 'standard';
		$theme_formats  = get_theme_support( 'post-formats' );
		$out['formats'] = array_merge( array( 'standard' ), is_array( $theme_formats ) ? (array) $theme_formats[0] : array() );
	}

	return $out;
}

/**
 * List users who can author a post type.
 *
 * @param array $input Ability input.
 * @return array|WP_Error User list, or an error.
 */
function hb_ai_list_authors( array $input ) {
	$post_type = isset( $input['post_type'] ) ? (string) $input['post_type'] : 'post';

	if ( ! in_array( $post_type, hb_ai_allowed_post_types(), true ) ) {
		return new WP_Error( 'hb_ai_invalid_post_type', __( 'That post type cannot be used here.', 'hb' ) );
	}

	$args = array(
		'capability' => get_post_type_object( $post_type )->cap->edit_posts,
		'number'     => 100,
		'orderby'    => 'display_name',
		'fields'     => array( 'ID', 'display_name', 'user_login' ),
	);

	if ( ! empty( $input['search'] ) ) {
		$args['search']         = '*' . (string) $input['search'] . '*';
		$args['search_columns'] = array( 'user_login', 'display_name', 'user_nicename' );
	}

	$items = array();

	foreach ( get_users( $args ) as $user ) {
		$items[] = array(
			'id'    => (int) $user->ID,
			'name'  => $user->display_name,
			'login' => $user->user_login,
		);
	}

	return array( 'items' => $items );
}

/**
 * Change the status of content: unpublish to draft or pending, make private,
 * trash, or restore from the trash.
 *
 * Publishing and scheduling stay with hb/publish-content, which handles dates.
 *
 * @param array $input Ability input.
 * @return array|WP_Error Summary of the post, or an error.
 */
function hb_ai_set_status( array $input ) {
	$post_id = isset( $input['post_id'] ) ? absint( $input['post_id'] ) : 0;
	$post    = get_post( $post_id );
	$target  = isset( $input['status'] ) ? (string) $input['status'] : '';

	if ( null === $post || ! in_array( $post->post_type, hb_ai_allowed_post_types(), true ) ) {
		return new WP_Error( 'hb_ai_not_found', __( 'No editable content with that ID.', 'hb' ) );
	}

	if ( ! in_array( $post->post_status, array_merge( hb_ai_editable_statuses(), array( 'trash' ) ), true ) ) {
		/* translators: %s: post status slug. */
		return new WP_Error( 'hb_ai_bad_status', sprintf( __( 'Content with the status "%s" cannot be changed here.', 'hb' ), $post->post_status ) );
	}

	if ( 'trash' === $post->post_status && 'restore' !== $target ) {
		return new WP_Error( 'hb_ai_bad_status', __( 'That content is in the trash. Restore it first.', 'hb' ) );
	}

	$previous = $post->post_status;

	switch ( $target ) {
		case 'restore':
			if ( 'trash' !== $post->post_status ) {
				return new WP_Error( 'hb_ai_bad_status', __( 'That content is not in the trash.', 'hb' ) );
			}

			if ( ! wp_untrash_post( $post->ID ) ) {
				return new WP_Error( 'hb_ai_failed', __( 'The content could not be restored.', 'hb' ) );
			}
			break;

		case 'trash':
			// With the trash disabled, wp_trash_post() deletes permanently instead.
			if ( ! EMPTY_TRASH_DAYS ) {
				return new WP_Error( 'hb_ai_trash_disabled', __( 'The trash is disabled on this site, so content cannot be trashed here.', 'hb' ) );
			}

			if ( ! wp_trash_post( $post->ID ) ) {
				return new WP_Error( 'hb_ai_failed', __( 'The content could not be trashed.', 'hb' ) );
			}
			break;

		case 'draft':
		case 'pending':
		case 'private':
			if ( $post->post_status === $target ) {
				/* translators: %s: post status slug. */
				return new WP_Error( 'hb_ai_nothing_to_do', sprintf( __( 'The content is already %s.', 'hb' ), $target ) );
			}

			$changes = array(
				'ID'          => $post->ID,
				'post_status' => $target,
			);

			// Private content cannot also carry a password.
			if ( 'private' === $target ) {
				$changes['post_password'] = '';
			}

			$result = wp_update_post( $changes, true );

			if ( is_wp_error( $result ) ) {
				return $result;
			}
			break;

		default:
			return new WP_Error( 'hb_ai_invalid_status', __( 'status must be draft, pending, private, trash or restore.', 'hb' ) );
	}

	return array_merge(
		hb_ai_post_summary_with_date( $post->ID ),
		array( 'previous_status' => $previous )
	);
}

/**
 * Register the attribute and status abilities.
 *
 * @return void
 */
function hb_register_ai_attribute_abilities() {
	wp_register_ability(
		'hb/set-status',
		array(
			'label'               => __( 'Change content status', 'hb' ),
			'description'         => __( 'Unpublish content to draft or pending review, make it private, move it to the trash, or restore it from the trash (restored content comes back as a draft). Taking published content to any of these removes it from the live site immediately. To publish or schedule, use publish-content instead.', 'hb' ),
			'category'            => 'hb-content',
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'post_id' => array( 'type' => 'integer' ),
					'status'  => array(
						'type' => 'string',
						'enum' => array( 'draft', 'pending', 'private', 'trash', 'restore' ),
					),
				),
				'required'   => array( 'post_id', 'status' ),
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array_merge(
					hb_ai_post_summary_with_date_schema()['properties'],
					array( 'previous_status' => array( 'type' => 'string' ) )
				),
			),
			'execute_callback'    => 'hb_ai_set_status',
			'permission_callback' => static function ( array $input ) {
				$post_id = isset( $input['post_id'] ) ? absint( $input['post_id'] ) : 0;
				$post    = get_post( $post_id );
				$status  = isset( $input['status'] ) ? (string) $input['status'] : '';

				if ( null === $post || ! current_user_can( 'edit_post', $post_id ) ) {
					return false;
				}

				if ( in_array( $status, array( 'trash', 'restore' ), true ) ) {
					return current_user_can( 'delete_post', $post_id );
				}

				return 'private' !== $status || hb_ai_can_publish( $post->post_type );
			},
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

	wp_register_ability(
		'hb/list-authors',
		array(
			'label'               => __( 'List authors', 'hb' ),
			'description'         => __( 'List the users who can be set as the author of a post type, for the author setting of update-content.', 'hb' ),
			'category'            => 'hb-content',
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'post_type' => array(
						'type'    => 'string',
						'enum'    => hb_ai_allowed_post_types(),
						'default' => 'post',
					),
					'search'    => array(
						'type'        => 'string',
						'description' => __( 'Optional search on name or login.', 'hb' ),
					),
				),
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'items' => array(
						'type'  => 'array',
						'items' => array(
							'type'       => 'object',
							'properties' => array(
								'id'    => array( 'type' => 'integer' ),
								'name'  => array( 'type' => 'string' ),
								'login' => array( 'type' => 'string' ),
							),
						),
					),
				),
			),
			'execute_callback'    => 'hb_ai_list_authors',
			'permission_callback' => static function ( array $input ) {
				$type = get_post_type_object( isset( $input['post_type'] ) ? (string) $input['post_type'] : 'post' );

				return null !== $type && current_user_can( $type->cap->edit_others_posts );
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
}
add_action( 'wp_abilities_api_init', 'hb_register_ai_attribute_abilities' );
