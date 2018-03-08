<?php

namespace EA\Syncable;

use EA;
use EA\CanonicalLookup;

/**
 * Class Comment
 * @package EA\Syncable
 */
class Comment extends Base {

	/**
	 * @public string $name name of the syncable
	 */
	static $name = 'comment';

	/**
	 * @public array $sync_hooks The actions/filters uses to trigger syncing of a syncable object
	 */
	public static $sync_hooks = array( 'edit_comment', 'wp_insert_comment', 'wp_set_comment_status' );

	/**
	 * @public array $delete_hooks The actions/filters used to trigger deleting of a synced object
	 */
	public static $delete_hooks = array( 'delete_comment' );

	/**
	 * @public array actions which have been queued by updating an object which is marked as syncable
	 */
	static $queued_actions = [];

	/**
	 * Callback for when a syncable object is created or updated
	 *
	 * @param $comment_id
	 * @param array $args
	 * @return void
	 */
	public static function insert_callback( $comment_id, $args = array() ) {

		$comment = get_comment( $comment_id );

		if ( ! $comment || ! static::get_syncable_sites( $comment_id ) ) {
			return;
		}

		static::add_action( 'sync', $comment_id );
	}

	/**
	 * Queue deletion callback of an object, this happens when an object is deleted
	 *
	 * @param $comment_id
	 * @param array $args
	 * @return void
	 */
	public static function delete_callback( $comment_id, $args = array() ) {

		$comment = get_comment( $comment_id );

		if ( ! $comment ) {
			return;
		}

		static::delete_callback_base( $comment_id );
	}

	/**
	 * Get syncable class instance from object
	 *
	 * @param $comment
	 * @return static
	 * @throws \Exception
	 */
	public static function from_object( $comment ) {

		if ( ! isset( $comment->comment_ID ) ) {
			throw new \Exception( 'Invalid comment object supplied to ' . __NAMESPACE__ . '\Comment syncable' );
		}

		return new static( $comment, $comment->comment_ID );
	}

	/**
	 * Insert callback triggered by update/insert of an object on the source site
	 *
	 * @param $id
	 * @param $action
	 * @param $args
	 * @throws \Exception
	 */
	public static function insert_from_queue( $id, $action, $args ) {

		$syncable = static::from_object( get_comment( $id ) );

		if ( ! $syncable ) {
			return;
		}

		foreach ( static::get_syncable_sites( $id ) as $site_id => $bool ) {

			// Site does not exist
			if ( ! get_blog_details( $site_id ) ) {
				continue;
			}

			if ( $bool ) {
				$syncable->sync_to( $site_id, $action );
			}
		}
	}

	/**
	 * Cron deletion callback triggered by deletion of an object on the source site
	 *
	 * @param $id
	 * @param $action
	 * @param $args
	 */
	public static function delete_from_queue( $id, $action, $args ) {

		if ( empty( $args['by_site_ids'] ) ) {
			return;
		}

		foreach ( $args['by_site_ids'] as $site_id => $comment_id ) {

			if ( ! get_blog_details( $site_id ) ) {
				continue;
			}

			// @codingStandardsIgnoreStart (fine for VIP Go)
			switch_to_blog( $site_id );
			// @codingStandardsIgnoreEnd

			if ( static::destination_is_detached( $comment_id ) ) {
				restore_current_blog();
				continue;
			}

			wp_delete_comment( $comment_id, true );

			restore_current_blog();
		}
	}

	/**
	 * Set a meta entry for provided object ID
	 *
	 * @param $object_id
	 * @param $meta_key
	 * @param $meta_value
	 * @return void
	 */
	public static function set_meta( $object_id, $meta_key, $meta_value ) {

		update_comment_meta( $object_id, $meta_key, $meta_value );
	}

	/**
	 * Add a meta entry for provided object ID
	 *
	 * @param $object_id
	 * @param $meta_key
	 * @param $meta_value
	 * @param $unique
	 * @return void
	 */
	public static function add_meta( $object_id, $meta_key, $meta_value, $unique = false ) {

		add_comment_meta( $object_id, $meta_key, $meta_value, $unique );
	}

	/**
	 *  Get a meta entry for provided object ID
	 *
	 * @param $object_id
	 * @param $meta_key
	 * @param $single
	 * @return mixed
	 */
	public static function get_meta( $object_id, $meta_key, $single = true ) {

		return get_comment_meta( $object_id, $meta_key, $single );
	}

	/**
	 * Add a meta entry for provided object ID
	 *
	 * @param $object_id
	 * @param $meta_key
	 * @param $meta_value
	 * @return void
	 */
	public static function delete_meta( $object_id, $meta_key, $meta_value = '' ) {

		delete_comment_meta( $object_id, $meta_key, $meta_value );
	}

	/**
	 * Get permalink for a comment from site.
	 *
	 * @param $object_id
	 * @return false|string
	 */
	public static function get_link( $object_id ) {
		return get_comment_link( $object_id );
	}

	/**
	 * Check if the object exists on destination site
	 *
	 * @param $site_id
	 * @param $sync_object
	 * @return int
	 */
	public function object_exists( $site_id, $sync_object ) {

		$exists_mapped = false;

		$meta     = $sync_object['data']['meta'];
		$src_site = ! empty( $meta['ea-syncable-import-src-site'][0] ) ? $meta['ea-syncable-import-src-site'][0] : false;

		if ( ! empty( $meta[ sprintf( 'ea-syncable-%s-synced-to-%s', static::$name, $site_id ) ] ) ) {
			$exists_mapped = (int) $meta[ sprintf( 'ea-syncable-%s-synced-to-%s', static::$name, $site_id ) ][0];
			// Sometimes we will be attempting to create a dependency which was actually originally synced from the destination site, ensure mapping is maintained
		} elseif ( $src_site && (int) $src_site === (int) $site_id ) {
			$exists_mapped = ! empty( $meta['ea-syncable-import-src-id'][0] ) ? $meta['ea-syncable-import-src-id'][0] : false;
			// Later addition, sometimes we need track a post across multiple sites, adding a source ID per site allows this
		} elseif ( ! empty( $meta[ sprintf( 'ea-syncable-import-src-id-%d', get_current_blog_id() ) ][0] ) ) {
			$exists_mapped = $meta[ sprintf( 'ea-syncable-import-src-id-%d', get_current_blog_id() ) ][0];
		}

		// Try to find a match via looking up of the canonical ID set on the comment being synced
		if ( ! $exists_mapped && ! empty( $meta['ea-syncable-import-src-id-canonical'] ) ) {
			$exists_mapped = CanonicalLookup\Comment\lookup_for_canonical_id( $meta['ea-syncable-import-src-id-canonical'][0], $meta['ea-syncable-import-src-site-canonical'][0] );
		}

		if ( ! $exists_mapped ) {
			$exists_mapped = CanonicalLookup\Comment\lookup_for_canonical_id( $this->get_source_object_id(), $sync_object['source_site'] );
		}

		if ( ! $exists_mapped || ! get_comment( $exists_mapped ) ) {
			$exists_mapped = false;
		}

		return apply_filters( sprintf( 'ea-syncable-%s-filter-object-exists', static::$name ), (int) $exists_mapped, $sync_object );
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

		if ( $exists_mapped ) {
			$insert->comment_ID = $exists_mapped;
			$comment_id = wp_update_comment( (array) $insert );
		} else {
			unset( $insert->comment_ID );
			$insert = wp_insert_comment( (array) $insert );
		}

		return $insert;
	}

	/**
	 * Get all objects which the current object being synced depends on (attachments, terms, comments etc)
	 *
	 * @param $sync_object
	 * @return mixed|void
	 */
	public function get_dependencies( $sync_object ) {

		$do_post = apply_filters( sprintf( 'ea-syncable-%s-auto-map-post-dependency', static::$name ), true, $sync_object );

		$dependencies = [];

		if ( $sync_object['data']['object']->comment_post_ID && $do_post ) {

			$dependencies[] = get_post( $sync_object['data']['object']->comment_post_ID );
		}

		return apply_filters( sprintf( 'ea-syncable-%s-filter-dependencies', static::$name ), $dependencies, $sync_object, $this );
	}

	/**
	 * Map object dependencies
	 *
	 * Called after the dependencies have been inserted into the destination database
	 *
	 * @param $sync_object
	 * @param $synced_deps
	 * @return mixed|void
	 */
	public function map_dependencies( $sync_object, $synced_deps ) {

		/* @var Base[] $synced_deps */

		$do_post = apply_filters( sprintf( 'ea-syncable-%s-auto-map-post-dependency', static::$name ), true, $sync_object );

		$parent = $sync_object['data']['object']->comment_post_ID;

		// Object has a parent, check if parent has been inserted via dependencies and remap if possible
		if ( $parent && $do_post ) {
			foreach ( $synced_deps as $dep_object ) {

				if ( 'post' === (string) $dep_object::$name && (int) $dep_object->object->ID === (int) $parent ) {
					$sync_object['data']['object']->comment_post_ID = $dep_object->destination_id;
					$modified = true;
				}
			}
		}

		// Could not remap parent, ensure parent is wiped
		if ( empty( $modified ) ) {
			$sync_object['data']['object']->comment_post_ID = 0;
		}

		return apply_filters( sprintf( 'ea-syncable-%s-filter-map-dependencies', static::$name ), $sync_object, $synced_deps, $this );
	}

	/**
	 * Get syncable object data to be passed through to destination site
	 *
	 * @return mixed|void
	 */
	public function get_data() {

		return apply_filters( sprintf( 'ea-syncable-%s-filter-data', static::$name ),
			[
				'object' => apply_filters( sprintf( 'ea-syncable-%s-filter-object', static::$name ), clone( $this->object ) ),
				'meta'   => apply_filters( sprintf( 'ea-syncable-%s-filter-meta', static::$name ), $this->meta ),
				'url'    => apply_filters( sprintf( 'ea-syncable-%s-filter-canonical-url', static::$name ), static::get_link( $this->object->comment_ID ) ),
			]
		);
	}

	/**
	 * Get the source object ID
	 *
	 * @return int
	 */
	public function get_source_object_id() {
		return (int) $this->object->comment_ID;
	}
}
