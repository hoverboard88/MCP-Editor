<?php
/**
 * Media Abilities
 *
 * Image upload and featured image abilities for MCP clients. Kept apart from
 * the content abilities because they need the upload_files capability and
 * touch attachments, which the content abilities deliberately never reach.
 *
 * Uploads are images only. Everything else WordPress accepts — PDFs, archives,
 * audio, video and SVG in particular — stays a human upload.
 *
 * @package Hoverboard
 */

/**
 * Image types an MCP client may upload.
 *
 * Keyed by the MIME type detected from the file's contents, never from the
 * name or a client claim, mapped to the extension the stored file is given.
 * SVG is absent on purpose: it is markup, not pixels, and can carry script.
 *
 * @return array Map of MIME type to file extension.
 */
function hb_ai_image_mimes() {
	/**
	 * Filters the image types an MCP client may upload.
	 *
	 * @param array $mimes Map of MIME type to file extension.
	 */
	return (array) apply_filters(
		'hb_ai_image_mimes',
		array(
			'image/jpeg' => 'jpg',
			'image/png'  => 'png',
			'image/gif'  => 'gif',
			'image/webp' => 'webp',
			'image/avif' => 'avif',
		)
	);
}

/**
 * Load the admin includes media_handle_sideload() depends on.
 *
 * Abilities run over REST, where wp-admin is not loaded.
 *
 * @return void
 */
function hb_ai_load_media_includes() {
	require_once ABSPATH . 'wp-admin/includes/file.php';
	require_once ABSPATH . 'wp-admin/includes/media.php';
	require_once ABSPATH . 'wp-admin/includes/image.php';
}

/**
 * Fetch a remote image into a temporary file.
 *
 * download_url() goes through wp_safe_remote_get(), which refuses local and
 * private addresses, so a client cannot use this to probe the server's own
 * network.
 *
 * @param string $url Remote image URL.
 * @return string|WP_Error Path to the temporary file, or an error.
 */
function hb_ai_fetch_remote_image( $url ) {
	$scheme = wp_parse_url( $url, PHP_URL_SCHEME );

	if ( ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
		return new WP_Error( 'hb_ai_invalid_url', __( 'The image URL must be http or https.', 'hb' ) );
	}

	return download_url( $url, 30 );
}

/**
 * Write base64-encoded image data into a temporary file.
 *
 * Accepts a bare base64 string or a data: URI.
 *
 * @param string $data     Base64 image data.
 * @param string $filename Name used for the temporary file.
 * @return string|WP_Error Path to the temporary file, or an error.
 */
function hb_ai_write_base64_image( $data, $filename ) {
	if ( preg_match( '/^data:[^;,]*;base64,/i', $data, $matches ) ) {
		$data = substr( $data, strlen( $matches[0] ) );
	}

	$data = preg_replace( '/\s+/', '', $data );

	// Check the size before decoding, so an oversized payload is refused
	// without allocating it twice.
	if ( (int) floor( strlen( $data ) * 3 / 4 ) > wp_max_upload_size() ) {
		return new WP_Error( 'hb_ai_too_large', __( 'The image is larger than this site allows.', 'hb' ) );
	}

	$bytes = base64_decode( $data, true );

	if ( false === $bytes || '' === $bytes ) {
		return new WP_Error( 'hb_ai_invalid_data', __( 'The image data is not valid base64.', 'hb' ) );
	}

	$tmp = wp_tempnam( $filename );

	if ( ! $tmp || false === file_put_contents( $tmp, $bytes ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		return new WP_Error( 'hb_ai_write_failed', __( 'The image could not be written to a temporary file.', 'hb' ) );
	}

	return $tmp;
}

/**
 * Build the stored filename for an uploaded image.
 *
 * The extension always comes from the detected type, so an image fetched from
 * an extensionless URL, or named with the wrong one, is still stored correctly.
 *
 * @param string $name Requested or derived filename.
 * @param string $ext  Extension for the detected image type.
 * @return string Sanitized filename.
 */
function hb_ai_image_filename( $name, $ext ) {
	$base = sanitize_file_name( pathinfo( (string) $name, PATHINFO_FILENAME ) );

	if ( '' === $base ) {
		$base = 'image';
	}

	return $base . '.' . $ext;
}

/**
 * Validate an attachment ID as an image in the media library.
 *
 * @param int $attachment_id Attachment ID.
 * @return WP_Post|WP_Error The attachment, or an error.
 */
function hb_ai_check_image( $attachment_id ) {
	$attachment = get_post( $attachment_id );

	if ( null === $attachment || 'attachment' !== $attachment->post_type ) {
		return new WP_Error( 'hb_ai_not_found', __( 'No media item with that ID.', 'hb' ) );
	}

	if ( ! wp_attachment_is_image( $attachment ) ) {
		return new WP_Error( 'hb_ai_not_image', __( 'That media item is not an image.', 'hb' ) );
	}

	return $attachment;
}

/**
 * Summarize an image attachment.
 *
 * @param int $attachment_id Attachment ID.
 * @return array|null Summary, or null when there is no image.
 */
function hb_ai_image_summary( $attachment_id ) {
	$attachment_id = (int) $attachment_id;

	if ( ! $attachment_id || ! wp_attachment_is_image( $attachment_id ) ) {
		return null;
	}

	$meta = wp_get_attachment_metadata( $attachment_id );

	return array(
		'attachment_id' => $attachment_id,
		'url'           => (string) wp_get_attachment_url( $attachment_id ),
		'alt'           => (string) get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ),
		'mime_type'     => (string) get_post_mime_type( $attachment_id ),
		'width'         => isset( $meta['width'] ) ? (int) $meta['width'] : 0,
		'height'        => isset( $meta['height'] ) ? (int) $meta['height'] : 0,
	);
}

/**
 * Schema describing an image summary.
 *
 * @return array JSON Schema definition.
 */
function hb_ai_image_summary_schema() {
	return array(
		'type'       => array( 'object', 'null' ),
		'properties' => array(
			'attachment_id' => array(
				'type'        => 'integer',
				'description' => __( 'Media library ID of the image.', 'hb' ),
			),
			'url'           => array(
				'type'        => 'string',
				'description' => __( 'Public URL of the full-size image.', 'hb' ),
			),
			'alt'           => array( 'type' => 'string' ),
			'mime_type'     => array( 'type' => 'string' ),
			'width'         => array( 'type' => 'integer' ),
			'height'        => array( 'type' => 'integer' ),
		),
	);
}

/**
 * Check that a post can take a featured image.
 *
 * @param int $post_id Post ID.
 * @return WP_Post|WP_Error The post, or an error.
 */
function hb_ai_check_featured_target( $post_id ) {
	$post = hb_ai_check_field_target( $post_id );

	if ( is_wp_error( $post ) ) {
		return $post;
	}

	if ( ! post_type_supports( $post->post_type, 'thumbnail' ) ) {
		return new WP_Error(
			'hb_ai_no_thumbnail_support',
			sprintf(
				/* translators: %s: post type slug. */
				__( 'The post type "%s" does not support featured images.', 'hb' ),
				$post->post_type
			)
		);
	}

	return $post;
}

/**
 * Upload an image to the media library.
 *
 * The image comes either from a URL, which the server fetches, or from base64
 * data sent by the client. Its type is detected from the bytes and checked
 * against hb_ai_image_mimes() before anything reaches the media library.
 *
 * When post_id is given the image is attached to that post, and set_featured
 * additionally makes it the post's featured image.
 *
 * @param array $input Ability input.
 * @return array|WP_Error Summary of the uploaded image, or an error.
 */
function hb_ai_upload_image( array $input ) {
	$url  = isset( $input['url'] ) ? trim( (string) $input['url'] ) : '';
	$data = isset( $input['data'] ) ? (string) $input['data'] : '';

	if ( ( '' === $url ) === ( '' === $data ) ) {
		return new WP_Error( 'hb_ai_invalid_source', __( 'Supply exactly one of url or data.', 'hb' ) );
	}

	$post_id      = isset( $input['post_id'] ) ? absint( $input['post_id'] ) : 0;
	$set_featured = ! empty( $input['set_featured'] );
	$post         = null;

	if ( $set_featured && ! $post_id ) {
		return new WP_Error( 'hb_ai_missing_post', __( 'set_featured requires a post_id.', 'hb' ) );
	}

	// Validate the target before fetching anything, so a bad post ID does not
	// leave an orphaned upload behind.
	if ( $post_id ) {
		$post = $set_featured ? hb_ai_check_featured_target( $post_id ) : hb_ai_check_field_target( $post_id );

		if ( is_wp_error( $post ) ) {
			return $post;
		}
	}

	hb_ai_load_media_includes();

	$name = isset( $input['filename'] ) ? (string) $input['filename'] : '';

	if ( '' === $name && '' !== $url ) {
		$name = wp_basename( (string) wp_parse_url( $url, PHP_URL_PATH ) );
	}

	$tmp = '' !== $url ? hb_ai_fetch_remote_image( $url ) : hb_ai_write_base64_image( $data, $name ? $name : 'image' );

	if ( is_wp_error( $tmp ) ) {
		return $tmp;
	}

	if ( filesize( $tmp ) > wp_max_upload_size() ) {
		wp_delete_file( $tmp );

		return new WP_Error( 'hb_ai_too_large', __( 'The image is larger than this site allows.', 'hb' ) );
	}

	$mime  = wp_get_image_mime( $tmp );
	$mimes = hb_ai_image_mimes();

	if ( ! $mime || ! isset( $mimes[ $mime ] ) ) {
		wp_delete_file( $tmp );

		return new WP_Error(
			'hb_ai_invalid_image',
			sprintf(
				/* translators: %s: comma-separated list of file extensions. */
				__( 'The file is not a supported image. Allowed: %s', 'hb' ),
				implode( ', ', array_values( $mimes ) )
			)
		);
	}

	$post_data = array();

	if ( isset( $input['title'] ) && '' !== $input['title'] ) {
		$post_data['post_title'] = sanitize_text_field( (string) $input['title'] );
	}

	if ( isset( $input['caption'] ) ) {
		$post_data['post_excerpt'] = wp_kses_post( (string) $input['caption'] );
	}

	$attachment_id = media_handle_sideload(
		array(
			'name'     => hb_ai_image_filename( $name, $mimes[ $mime ] ),
			'tmp_name' => $tmp,
		),
		$post_id,
		null,
		$post_data
	);

	if ( is_wp_error( $attachment_id ) ) {
		wp_delete_file( $tmp );

		return $attachment_id;
	}

	if ( isset( $input['alt'] ) ) {
		update_post_meta( $attachment_id, '_wp_attachment_image_alt', sanitize_text_field( (string) $input['alt'] ) );
	}

	$featured_on = null;

	if ( $set_featured ) {
		set_post_thumbnail( $post->ID, $attachment_id );
		$featured_on = (int) $post->ID;
	}

	return array_merge(
		hb_ai_image_summary( $attachment_id ),
		array(
			'attached_to' => $post_id ? $post_id : null,
			'featured_on' => $featured_on,
			'edit_link'   => get_edit_post_link( $attachment_id, 'raw' ),
		)
	);
}

/**
 * Set or remove the featured image of a post.
 *
 * The featured image is post meta that WordPress does not revision, so the
 * previous image is returned; that response is the only record of what was
 * replaced.
 *
 * @param array $input Ability input.
 * @return array|WP_Error Previous and current featured image, or an error.
 */
function hb_ai_set_featured_image( array $input ) {
	$post_id = isset( $input['post_id'] ) ? absint( $input['post_id'] ) : 0;
	$post    = hb_ai_check_featured_target( $post_id );

	if ( is_wp_error( $post ) ) {
		return $post;
	}

	$attachment_id = isset( $input['attachment_id'] ) ? absint( $input['attachment_id'] ) : 0;
	$previous      = hb_ai_image_summary( get_post_thumbnail_id( $post->ID ) );

	if ( $attachment_id ) {
		$attachment = hb_ai_check_image( $attachment_id );

		if ( is_wp_error( $attachment ) ) {
			return $attachment;
		}

		if ( isset( $input['alt'] ) ) {
			update_post_meta( $attachment->ID, '_wp_attachment_image_alt', sanitize_text_field( (string) $input['alt'] ) );
		}

		set_post_thumbnail( $post->ID, $attachment->ID );
	} else {
		delete_post_thumbnail( $post->ID );
	}

	return array(
		'post_id'        => (int) $post->ID,
		'previous'       => $previous,
		'featured_image' => hb_ai_image_summary( get_post_thumbnail_id( $post->ID ) ),
	);
}

/**
 * List images in the media library.
 *
 * Gives a client a way to reuse an existing image, rather than uploading a
 * duplicate, by finding the attachment_id that set-featured-image needs.
 *
 * @param array $input Ability input.
 * @return array List of image summaries.
 */
function hb_ai_list_images( array $input ) {
	$search  = isset( $input['search'] ) ? trim( (string) $input['search'] ) : '';
	$post_id = isset( $input['post_id'] ) ? absint( $input['post_id'] ) : 0;
	$limit   = isset( $input['limit'] ) ? absint( $input['limit'] ) : 20;
	$limit   = max( 1, min( 50, $limit ) );

	$args = array(
		'post_type'              => 'attachment',
		'post_status'            => 'inherit',
		'post_mime_type'         => array_keys( hb_ai_image_mimes() ),
		'posts_per_page'         => $limit,
		'orderby'                => 'date',
		'order'                  => 'DESC',
		'no_found_rows'          => true,
		'update_post_term_cache' => false,
	);

	if ( '' !== $search ) {
		$args['s'] = $search;
	}

	if ( $post_id ) {
		$args['post_parent'] = $post_id;
	}

	$query = new WP_Query( $args );
	$items = array();

	foreach ( $query->posts as $attachment ) {
		$summary = hb_ai_image_summary( $attachment->ID );

		if ( null === $summary ) {
			continue;
		}

		$items[] = array_merge(
			$summary,
			array(
				'title'       => $attachment->post_title,
				'caption'     => $attachment->post_excerpt,
				'attached_to' => $attachment->post_parent ? (int) $attachment->post_parent : null,
				'date'        => $attachment->post_date,
			)
		);
	}

	return array( 'items' => $items );
}

/**
 * Register the media abilities.
 *
 * @return void
 */
function hb_register_ai_media_abilities() {
	wp_register_ability(
		'hb/list-images',
		array(
			'label'               => __( 'List images', 'hb' ),
			'description'         => __( 'Search the media library for images, newest first. Searches title, caption and description. Use this to reuse an existing image with set-featured-image instead of uploading a duplicate.', 'hb' ),
			'category'            => 'hb-content',
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'search'  => array(
						'type'        => 'string',
						'description' => __( 'Optional keyword search.', 'hb' ),
					),
					'post_id' => array(
						'type'        => 'integer',
						'description' => __( 'Only images attached to this post. Omit for all.', 'hb' ),
					),
					'limit'   => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'maximum'     => 50,
						'default'     => 20,
						'description' => __( 'Maximum number of images to return.', 'hb' ),
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
							'properties' => array_merge(
								hb_ai_image_summary_schema()['properties'],
								array(
									'title'       => array( 'type' => 'string' ),
									'caption'     => array( 'type' => 'string' ),
									'attached_to' => array( 'type' => array( 'integer', 'null' ) ),
									'date'        => array( 'type' => 'string' ),
								)
							),
						),
					),
				),
			),
			'execute_callback'    => 'hb_ai_list_images',
			'permission_callback' => static function () {
				// The same capability the admin media library requires.
				return current_user_can( 'upload_files' );
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
		'hb/upload-image',
		array(
			'label'               => __( 'Upload image', 'hb' ),
			'description'         => __( 'Add an image to the media library, from a public URL or from base64 data. Prefer url; use data only for images that are not online. Optionally attach it to a post and make it that post\'s featured image. Returns the attachment_id for use with set-featured-image.', 'hb' ),
			'category'            => 'hb-content',
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'url'          => array(
						'type'        => 'string',
						'description' => __( 'Public http(s) URL of the image. Supply this or data, not both.', 'hb' ),
					),
					'data'         => array(
						'type'        => 'string',
						'description' => __( 'Base64-encoded image bytes, optionally as a data: URI. Supply this or url, not both.', 'hb' ),
					),
					'filename'     => array(
						'type'        => 'string',
						'description' => __( 'Filename to store the image under. The extension is corrected to match the image type.', 'hb' ),
					),
					'alt'          => array(
						'type'        => 'string',
						'description' => __( 'Alt text describing the image for screen readers. Strongly recommended.', 'hb' ),
					),
					'title'        => array(
						'type'        => 'string',
						'description' => __( 'Media library title. Defaults to the filename.', 'hb' ),
					),
					'caption'      => array(
						'type'        => 'string',
						'description' => __( 'Optional caption.', 'hb' ),
					),
					'post_id'      => array(
						'type'        => 'integer',
						'description' => __( 'Post to attach the image to. Omit to leave it unattached.', 'hb' ),
					),
					'set_featured' => array(
						'type'        => 'boolean',
						'default'     => false,
						'description' => __( 'Make the image the featured image of post_id.', 'hb' ),
					),
				),
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array_merge(
					hb_ai_image_summary_schema()['properties'],
					array(
						'attached_to' => array( 'type' => array( 'integer', 'null' ) ),
						'featured_on' => array( 'type' => array( 'integer', 'null' ) ),
						'edit_link'   => array( 'type' => 'string' ),
					)
				),
			),
			'execute_callback'    => 'hb_ai_upload_image',
			'permission_callback' => static function ( array $input ) {
				if ( ! current_user_can( 'upload_files' ) ) {
					return false;
				}

				$post_id = isset( $input['post_id'] ) ? absint( $input['post_id'] ) : 0;

				return ! $post_id || current_user_can( 'edit_post', $post_id );
			},
			'meta'                => hb_ai_ability_meta(),
		)
	);

	wp_register_ability(
		'hb/set-featured-image',
		array(
			'label'               => __( 'Set featured image', 'hb' ),
			'description'         => __( 'Set the featured image of a post to an image already in the media library, or remove it by passing attachment_id 0. On published content this changes the live site immediately; the previous image is returned since WordPress keeps no revision of it.', 'hb' ),
			'category'            => 'hb-content',
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'post_id'       => array(
						'type'        => 'integer',
						'description' => __( 'ID of the post to update.', 'hb' ),
					),
					'attachment_id' => array(
						'type'        => 'integer',
						'description' => __( 'Media library ID of the image, from upload-image. 0 removes the featured image.', 'hb' ),
					),
					'alt'           => array(
						'type'        => 'string',
						'description' => __( 'Optional replacement alt text for the image.', 'hb' ),
					),
				),
				'required'   => array( 'post_id', 'attachment_id' ),
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'post_id'        => array( 'type' => 'integer' ),
					'previous'       => hb_ai_image_summary_schema(),
					'featured_image' => hb_ai_image_summary_schema(),
				),
			),
			'execute_callback'    => 'hb_ai_set_featured_image',
			'permission_callback' => static function ( array $input ) {
				$post_id = isset( $input['post_id'] ) ? absint( $input['post_id'] ) : 0;

				if ( ! current_user_can( 'edit_post', $post_id ) ) {
					return false;
				}

				// Changing alt text edits the attachment itself.
				$attachment_id = isset( $input['attachment_id'] ) ? absint( $input['attachment_id'] ) : 0;

				return ! isset( $input['alt'] ) || ! $attachment_id || current_user_can( 'edit_post', $attachment_id );
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
}
add_action( 'wp_abilities_api_init', 'hb_register_ai_media_abilities' );
