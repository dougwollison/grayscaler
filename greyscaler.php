<?php
/*
Plugin Name: Grayscaler
Plugin URI: https://github.com/dougwollison/unpublish
Description: Adds additional image processing to WordPress, creating grayscale versions of images during upload.
Version: 1.0.0
Author: Doug Wollison
Author URI: http://dougw.me
Tags: grayscale
License: GPL2
Text Domain: grayscaler
Domain Path: /languages
*/

/**
 * Filter the generated metadata for the attachment.
 *
 * Creates a grayscale version of each image size found and stores it.
 *
 * @param array $metadata The attachment metadata to filter.
 *
 * @return array The filtered metadata.
 */
function grayscaler_attachment_metadata( $metadata ) {
	$files = array();

	if ( ! $metadata || ! isset( $metadata['file'] ) ) {
		return $metadata;
	}

	$uploads = wp_upload_dir();

	// Abort on non PNG/JPEG images
	$ext = strtolower( pathinfo( $metadata['file'], PATHINFO_EXTENSION ) );
	if ( $ext !== 'png' && $ext !== 'jpg' && $ext !== 'jpeg' ) {
		return $metadata;
	}

	// Build the list of sizes to process
	$files['full'] = basename( $metadata['file'] );
	foreach ( $metadata['sizes'] as $size => $data ) {
		$files[ $size ] = basename( $data['file'] );
	}

	// Store grayscaled versions here
	$metadata['grayscaled'] = array();

	foreach ( $files as $size => $file ) {
		$file_path = str_replace( basename( $metadata['file'] ), $file, $metadata['file'] );
		$file_path = path_join( $uploads['basedir'], $file_path );

		// Skip if file is missing
		if ( ! file_exists( $file_path ) ) {
			continue;
		}

		// Get the size and type
		$specs = getimagesize( $file_path );

		// Skip if too big
		if ( $specs[0] * $specs[1] > 2000 * 2000 ) {
			continue;
		}

		// Load an filter the image to grayscale
		$image = imagecreatefromstring( file_get_contents( $file_path ) );
		imagefilter( $image, IMG_FILTER_GRAYSCALE );

		// Create the new filename
		$ext = pathinfo( $file, PATHINFO_EXTENSION );
		$name = wp_basename( $file, ".$ext" );
		$grayscale_file = str_replace( basename( $metadata['file'] ), "{$name}-grayscale.{$ext}", $metadata['file'] );
		$grayscale_file = path_join( $uploads['basedir'], $grayscale_file );

		// Add the entry for it
		$metadata['grayscaled'][ $size ] = array(
			'file' => basename( $grayscale_file ),
			'width' => $specs[0],
			'height' => $specs[1],
		);

		switch ( $specs[2] ) {
			case IMAGETYPE_PNG:
				imagepng( $image, $grayscale_file );
				break;
			case IMAGETYPE_JPG:
				imagejpeg( $image, $grayscale_file );
				break;
		}
	}

	return $metadata;
}
add_filter( 'wp_generate_attachment_metadata', 'grayscaler_attachment_metadata' );

/**
 * Delete the grayscale versions of an uploaded image.
 *
 * @param int $attachment_id The ID of the attachment being deleted.
 */
function grayscaler_delete_attachment( $attachment_id ) {
	$metadata = wp_get_attachment_metadata( $attachment_id );

	if ( isset( $metadata['grayscaled'] ) ) {
		$uploads = wp_upload_dir();

		// Loop through all grayscale versions and delete
		foreach ( $metadata['grayscaled'] as $image ) {
			$grayscale_file = str_replace( basename( $metadata['file'] ), $image['file'], $metadata['file'] );
			$grayscale_file = path_join( $uploads['basedir'], $grayscale_file );
			if ( file_exists( $grayscale_file ) ) {
				unlink( $grayscale_file );
			}
		}
	}
}
add_filter( 'delete_attachment', 'grayscaler_delete_attachment' );

/**
 * Filter the downsized image data, replacing with grayscale version if found.
 *
 * @param array $downsize The image src array of the downsized image.
 * @param int   $id       The ID of the attachment being downsized.
 * @param mixed $size     The requested size for downsizing.
 *
 * @return array The filtered image data.
 */
function grayscaler_image_downsize( $downsize, $id, $size ) {
	if ( is_string( $size ) && ( $size == 'grayscale' || strpos( $size, 'grayscale:' ) === 0 ) ) {
		// Get the real size
		$size = $size == 'grayscale' ? 'full' : str_replace( 'grayscale:', '', $size );

		// Get the url, basename, and metadata
		$url = wp_get_attachment_url( $id );
		$basename = wp_basename( $url );
		$metadata = wp_get_attachment_metadata( $id );

		// Fallback to regular version if no grayscale versions exist
		if ( ! isset( $metadata['grayscaled'] ) ) {
			return image_downsize( $id, $size );
		}

		// Default to full if no grayscale version of specified size exists
		if ( ! isset( $metadata['grayscaled'][ $size ] ) ) {
			$size = 'full';
		}

		// Build the full URL to the image
		$img = $metadata['grayscaled'][ $size ];
		$url = str_replace( $basename, $img['file'], $url );

		// Send back the specs to use for this grayscaled version
		return array( $url, $img['width'], $img['height'], $size != 'full' );
	}

	return $downsize;
}
add_filter( 'image_downsize', 'grayscaler_image_downsize', 10, 3 );

/**
 * Filter the JSON response of the attachment data, inserting the grayscaled sizes.
 *
 * @param array   $response   The response data.
 * @param WP_Post $attachment The attachment object.
 * @param array   $metadata   The metadata of the attachment.
 */
function grayscaler_add_grayscale_data_for_js( $response, $attachment, $metadata ) {
	if ( isset( $metadata['grayscaled'] ) ) {
		$response['grayscaled'] = array();
		foreach ( $response['sizes'] as $size => $data ) {
			$sized = apply_filters( 'image_downsize', false, $attachment->ID, $size );
			if ( $sized ) {
				$sized = array(
					'url' => $sized[0],
					'width' => $sized[1],
					'height' => $sized[2],
					'orientation' => $sized[2] > $sized[1] ? 'portrait' : 'landscape',
				);
			} else {
				$sized = $data;
			}

			$response['grayscaled'][ $size ] = $sized;
		}
	}

	return $response;
}
add_filter( 'wp_prepare_attachment_for_js', 'grayscaler_add_grayscale_data_for_js', 10, 3 );
