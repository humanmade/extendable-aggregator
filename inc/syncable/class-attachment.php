<?php

namespace EA\Syncable;

use EA;

/**
 * Class Post
 * @package EA\Syncable
 */
class Attachment extends Post {

	/**
	 * @public string $name name of the syncable
	 */
	static $name = 'attachment';

	/**
	 * @public array actions which have been queued by updating an object which is marked as syncable
	 */
	static $queued_actions = [];

	/**
	 * @public array $sync_hooks The actions/filters uses to trigger syncing of a syncable object
	 */
	public static $sync_hooks = array( 'edit_attachment', 'add_attachment' );

	/**
	 * @public array $delete_hooks The actions/filters used to trigger deleting of a synced object
	 */
	public static $delete_hooks = array( 'delete_attachment' );

	/**
	 * Callback for when a syncable object is created or updated
	 *
	 * @param $post_id
	 * @param array $args
	 * @return void
	 */
	public static function insert_callback( $post_id, $args = array() ) {

		$post = get_post( $post_id );

		if ( ! $post || ! static::get_syncable_sites( $post_id ) || 'attachment' !== $post->post_type ) {
			return;
		}

		static::add_action( 'sync', $post_id );
	}

	/**
	 * Queue deletion callback of an object, this happens when an object is deleted
	 *
	 * @param $post_id
	 * @param array $args
	 * @return void
	 */
	public static function delete_callback( $post_id, $args = array() ) {

		$post = get_post( $post_id );

		if ( ! $post || 'attachment' !== $post->post_type ) {
			return;
		}

		static::delete_callback_base( $post_id );
	}

	/**
	 * Insert the base syncable object
	 *
	 * @param $site_id
	 * @param $sync_object
	 * @return int|\WP_Error
	 */
	public function insert_object( $site_id, $sync_object ) {

		$exists_mapped = $this->object_exists( $site_id, $sync_object );

		$insert = clone( $sync_object['data']['object'] );

		//todo:: handle dynamically? can insert with exact name if not in use, computationally heavy though
		unset( $insert->post_name );

		if ( $exists_mapped ) {

			$current_post = get_post( $exists_mapped );

			// If parent association has been set elsewhere and we don't have a parent to set via the insert object,
			// do not override current parent
			// For attachments, post parent generally wants to be set by association callback on the main post, not during
			// initial image insertion where the parent post won't exist yet
			if ( ! empty( $current_post->post_parent ) && ! $insert->post_id ) {
				unset( $insert->post_parent );
			}

			$insert->ID = $exists_mapped;
			$post_id    = wp_update_post( $insert );

		} else {
			unset( $insert->ID );

			$post_id    = $this->insert_from_url( $sync_object['data']['url'], $insert );
		}

		return $post_id;
	}

	/**
	 * Insert meta into destination DB
	 *
	 * @param $site_id
	 * @param $object_id
	 * @param $sync_object
	 */
	public function insert_meta( $site_id, $object_id, $sync_object ) {

		if ( isset( $sync_object['data']['meta']['_wp_attached_file'] ) ) {
			unset( $sync_object['data']['meta']['_wp_attached_file'] );
		}

		if ( isset( $sync_object['data']['meta']['_wp_attachment_metadata'] ) ) {
			unset( $sync_object['data']['meta']['_wp_attachment_metadata'] );
		}

		parent::insert_meta( $site_id, $object_id, $sync_object );
	}

	/**
	 * Get the syncable object data required for running a sync
	 *
	 * @return mixed|void
	 */
	public function get_data() {
		return apply_filters( sprintf( 'ea-syncable-%s-filter-data', static::$name ),
			[
				'object' => apply_filters( sprintf( 'ea-syncable-%s-filter-object', static::$name ), clone( $this->object ) ),
				'meta'   => apply_filters( sprintf( 'ea-syncable-%s-filter-meta', static::$name ), $this->meta ),
				'url'    => apply_filters( sprintf( 'ea-syncable-%s-filter-url', static::$name ), wp_get_attachment_url( $this->object->ID ) ),
			]
		);
	}

	/**
	 * Insert an attachment using provided url
	 *
	 * @param $url
	 * @param $post_data
	 * @return array|int|object
	 */
	function insert_from_url( $url, $post_data ) {

		$this->require_dependencies();

		$file_array = $this->prepare_file( $url );

		if ( is_wp_error( $file_array ) ) {
			return $file_array;
		}

		// do the validation and storage stuff
		$post_id = media_handle_sideload( $file_array, 0, '', (array) $post_data );

		$this->cleanup_file( $file_array );

		return $post_id;
	}

	/**
	 * Upload attachment file
	 *
	 * @param $url
	 * @return array
	 */
	protected function prepare_file( $url ) {

		$file_array = array();

		try {
			$file_array['tmp_name'] = $this->download_url( $url );
		} catch ( \Exception $e ) {
			$file_array['tmp_name'] = new \WP_Error( 500, $e->getMessage() );
		}

		// If error storing temporarily, unlink
		if ( is_wp_error( $file_array['tmp_name'] ) ) {
			// @codingStandardsIgnoreStart
			@unlink( $file_array['tmp_name'] );
			// @codingStandardsIgnoreEnd
			return $file_array['tmp_name'];
		}

		// Set variables for storage
		// Fix file filename for query strings
		preg_match( apply_filters( 'ea-upload-attachment-filter-extensions', '/[^\?\/\=]+\.(jpg|jpe|jpeg|gif|png|ico|pdf|csv|txt)/i' ), $url, $matches );

		if ( empty( $matches ) ) {
			$parts = explode( '/', $url );
			$file_array['name'] = end( $parts ) . '.png';
		} else {
			$file_array['name'] = $matches[0];
		}

		$file_array['name'] = sanitize_file_name( $file_array['name'] );

		return $file_array;
	}

	/**
	 * Require media upload dependencies
	 */
	protected function require_dependencies() {

		require_once( ABSPATH . '/wp-admin/includes/file.php' );
		require_once( ABSPATH . '/wp-admin/includes/media.php' );
		require_once( ABSPATH . '/wp-admin/includes/image.php' );
	}

	/**
	 * Download a remote attachment file
	 *
	 * @param $url
	 * @param int $timeout
	 * @return mixed
	 */
	protected function download_url( $url, $timeout = 15 ) {

		//WARNING: The file is not automatically deleted, The script must unlink() the file.
		if ( ! $url ) {
			return new \WP_Error( 'http_no_url', __( 'Invalid URL Provided.' ) );
		}

		/*
		 * Override default functionality from wp download_url function
		 */
		$parts = explode( '/', $url );

		// Set variables for storage
		// Fix file filename for query strings
		$found_extension = strpos( $url, '/' ) !== false && strpos( end( $parts ), '.' ) !== false;

		// wp_tempnam expects an extension to replace but in some cases download urls won't include an extension - it doesn't matter what extension we use
		if ( ! $found_extension ) {
			$tmpfname = wp_tempnam( $url . '.png' );
		} else {
			$tmpfname = wp_tempnam( $url );
		}
		/*
		 * End override default
		 */

		if ( ! $tmpfname ) {
			return new \WP_Error( 'http_no_file', __( 'Could not create Temporary file.' ) );
		}

		$response = wp_safe_remote_get( $url, array( 'timeout' => $timeout, 'stream' => true, 'filename' => $tmpfname ) );

		if ( is_wp_error( $response ) ) {
			// @codingStandardsIgnoreStart
			unlink( $tmpfname );
			// @codingStandardsIgnoreEnd
			return $response;
		}

		if ( 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			// @codingStandardsIgnoreStart
			unlink( $tmpfname );
			// @codingStandardsIgnoreEnd
			return new \WP_Error( 'http_404', trim( wp_remote_retrieve_response_message( $response ) ) );
		}

		$content_md5 = wp_remote_retrieve_header( $response, 'content-md5' );
		if ( $content_md5 ) {
			$md5_check = verify_file_md5( $tmpfname, $content_md5 );
			if ( is_wp_error( $md5_check ) ) {
				// @codingStandardsIgnoreStart
				unlink( $tmpfname );
				// @codingStandardsIgnoreEnd
				return $md5_check;
			}
		}

		return $tmpfname;
	}

	/**
	 * Remove temporary file
	 *
	 * @param $file_array
	 */
	protected function cleanup_file( $file_array ) {
		// @codingStandardsIgnoreStart
		@unlink( $file_array['tmp_name'] );
		// @codingStandardsIgnoreEnd
	}

	/**
	 * Get all meta for the source object
	 *
	 * @param $object_id
	 * @return array
	 */
	public function get_all_meta( $object_id ) {

		$meta = get_metadata( 'post', $object_id );

		foreach ( $meta as $key => $values ) {

			foreach ( $values as $index => $value ) {
				$meta[ $key ][ $index ] = maybe_unserialize( $value );
			}
		}

		return $meta;
	}
}
