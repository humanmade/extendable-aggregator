<?php

namespace EA\Syncable;

use EA;
use EA\CanonicalLookup;

/**
 * Class Term
 * @package EA\Syncable
 */
class Term extends Base {

	/**
	 * @public string $name name of the syncable
	 */
	static $name = 'term';

	/**
	 * @public array $sync_hooks The actions/filters uses to trigger syncing of a syncable object
	 */
	public static $sync_hooks = [ 'create_term', 'edit_term' ];

	/**
	 * @public array $delete_hooks The actions/filters used to trigger deleting of a synced object
	 */
	public static $delete_hooks = [ 'pre_delete_term' ];

	/**
	 * @public array actions which have been queued by updating an object which is marked as syncable
	 */
	static $queued_actions = [];

	/**
	 * Callback for when a syncable object is created or updated
	 *
	 * @param $term_id
	 * @param $tt_id
	 * @param $taxonomy
	 * @return void
	 */
	public static function insert_callback( $term_id, $tt_id, $taxonomy = null ) {

		$term = get_term( $term_id, $taxonomy );

		if ( ! $term || is_wp_error( $term ) || ! static::get_syncable_sites( $term_id ) ) {
			return;
		}

		static::add_action( 'sync', $term_id, [ 'term_id' => $term_id, 'taxonomy' => $taxonomy ] );
	}

	/**
	 * Queue deletion callback of an object, this happens when an object is deleted
	 *
	 * @param $term_id
	 * @param $tt_id
	 * @param $taxonomy
	 * @return void
	 */
	public static function delete_callback( $term_id, $tt_id, $taxonomy = null ) {

		$term = get_term( $term_id, $taxonomy );

		if ( ! $term || is_wp_error( $term ) || ! static::get_syncable_sites( $term_id ) ) {
			return;
		}

		static::delete_callback_base( $term_id );
	}

	/**
	 * Get syncable class instance from object
	 *
	 * @param $term
	 * @return static
	 * @throws \Exception
	 */
	public static function from_object( $term ) {

		if ( ! isset( $term->term_id ) ) {
			throw new \Exception( 'Invalid term object supplied to ' . __NAMESPACE__ . '\Term syncable' );
		}

		return new static( $term, $term->term_id );
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

		$syncable = static::from_object( get_term( $id, $args['taxonomy'] ) );

		if ( ! $syncable instanceof Term ) {
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

		foreach ( $args['by_site_ids'] as $site_id => $post_id ) {

			if ( ! get_blog_details( $site_id ) ) {
				continue;
			}

			// @codingStandardsIgnoreStart (fine for VIP Go)
			switch_to_blog( $site_id );
			// @codingStandardsIgnoreEnd

			static::destination_set_is_detached( $post_id, true );
			restore_current_blog();
		}
	}

	/**
	 *  Set a meta entry for provided object ID
	 *
	 * @param $object_id
	 * @param $meta_key
	 * @param $meta_value
	 * @return void
	 */
	public static function set_meta( $object_id, $meta_key, $meta_value ) {

		update_term_meta( $object_id, $meta_key, $meta_value );
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

		add_term_meta( $object_id, $meta_key, $meta_value, $unique );
	}

	/**
	 * Get a meta entry for provided object ID
	 *
	 * @param $object_id
	 * @param $meta_key
	 * @param $single
	 * @return mixed
	 */
	public static function get_meta( $object_id, $meta_key, $single = true ) {

		return get_term_meta( $object_id, $meta_key, $single );
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

		delete_term_meta( $object_id, $meta_key, $meta_value );
	}

	/**
	 * Get permalink for a term from site.
	 *
	 * @param $object_id
	 * @return false|string
	 */
	public static function get_link( $object_id ) {

		$link = '';

		if ( function_exists( 'wpcom_vip_get_term_link' ) ) {
			$term = get_term( $object_id, 'nav_menu' );

			if ( $term && ! is_wp_error( $term ) ) {
				$link = wpcom_vip_get_term_link( $term, $term->taxonomy );
			}

		} else {
			$link = get_term_link( $object_id );
		}

		if ( empty( $term ) || is_wp_error( $term ) ) {
			$link = '';
		}

		return $link;
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

		// Try to find a match via looking up of the canonical ID set on the term being synced
		if ( ! $exists_mapped && ! empty( $meta['ea-syncable-import-src-id-canonical'] ) ) {
			$exists_mapped = CanonicalLookup\Term\lookup_for_canonical_id( $meta['ea-syncable-import-src-id-canonical'][0], $meta['ea-syncable-import-src-site-canonical'][0] );
		}

		if ( ! $exists_mapped ) {
			$exists_mapped = CanonicalLookup\Term\lookup_for_canonical_id( $this->get_source_object_id(), $sync_object['source_site'] );
		}

		// Found an existing synced term
		if ( $exists_mapped ) {
			$id   = $exists_mapped;
			$term = get_term( $id, $sync_object['data']['object']->taxonomy );

			// double check term still exists, might have been deleted
			if ( $id && ! is_wp_error( $term ) && $term ) {
				return apply_filters( sprintf( 'ea-syncable-%s-filter-object-exists', static::$name ), (int) $id, $sync_object );
			}
		}

		// Cant find a synced one, try to find a matching term which already exists
		$terms = get_terms(
			[
				'hide_empty' => false,
				'taxonomy'   => $sync_object['data']['object']->taxonomy,
				'name'       => $sync_object['data']['object']->name,
			]
		);

		foreach ( $terms as $term ) {

			// No hierarchy, don't need to drill through.
			if ( ! $term->parent && ! $sync_object['data']['hierarchy'] ) {
				$exists = (int) $term->term_id;
				break;
			}

			// Check that the hierarchy of the existing term matches
			foreach ( $sync_object['data']['hierarchy'] as $parent ) {

				$parent_local = get_term( $term->parent, $term->taxonomy );

				if ( (string) $parent_local->name === (string) $parent->name ) {
					$exists = (int) $term->term_id;
					break 2;
				}
			}
		}

		$exists = ! empty( $exists ) ? $exists : false;

		return apply_filters( sprintf( 'ea-syncable-%s-filter-object-exists', static::$name ), (int) $exists, $sync_object );
	}

	/**
	 * Insert the base syncable object
	 *
	 * @param $site_id
	 * @param $sync_object
	 * @return array|bool|\WP_Error
	 */
	public function insert_object( $site_id, $sync_object ) {

		$obj    = $sync_object['data']['object'];
		$exists = $this->object_exists( $site_id, $sync_object );

		// todo:: term insertion/update
		if ( ! empty( $exists ) ) {
			$id  = $exists;

			wp_update_term( $id, $obj->taxonomy,
				[
					'description' => $obj->description,
					'parent'      => $obj->parent,
					'name'        => $obj->name,
				]
			);
			//already mapped up
		} else {

			$id = wp_insert_term( $obj->name, $obj->taxonomy, [
				'description' => $obj->description,
				'parent'      => $obj->parent,
			] );

			// Check for and handle WP_Error if exists.
			if ( is_wp_error( $id ) ) {

				// If we're also passed a 'term_exists' key from wp_error, we can use that to update the existing object.
				if (
					isset( $id['error_data']['term_exists'] )
					&& 'sync' === self::get_meta( $id['error_data']['term_exists'], 'ea-syncable-import-method' )
				) {
					$id = absint( $id['error_data']['term_exists'] );

					wp_update_term( $id, $obj->taxonomy,
						[
							'description' => $obj->description,
							'parent'      => $obj->parent,
							'name'        => $obj->name,
						]
					);
				} else {
					// Else, return the wp_error as sync_to looks for this.
					return $id;
				}
			}

			if ( is_array( $id ) && ! empty( $id['term_id'] ) ) {
				$id = $id['term_id'];
			}
		}

		return $id;
	}

	/**
	 * Get all objects which the current object being synced depends on (attachments, terms etc)
	 *
	 * @param $sync_object
	 * @return mixed|void
	 */
	public function get_dependencies( $sync_object ) {

		$do_parent    = apply_filters( sprintf( 'ea-syncable-%s-auto-map-parent-dependency', static::$name ), true, $sync_object );
		$dependencies = [];

		if ( $sync_object['data']['object']->parent && $do_parent ) {
			$dependencies[] = get_term( $sync_object['data']['object']->parent, $sync_object['data']['object']->taxonomy );
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

		$do_parent = apply_filters( sprintf( 'ea-syncable-%s-auto-map-parent-dependency', static::$name ), true, $sync_object );
		$parent    = $sync_object['data']['object']->parent;

		// Object has a parent, check if parent has been inserted via dependencies and remap if possible
		if ( $parent && $do_parent ) {
			foreach ( $synced_deps as $dep_object ) {

				if ( 'term' === (string) $dep_object::$name && (int) $dep_object->object->term_id === (int) $parent ) {
					$sync_object['data']['object']->parent = $dep_object->destination_id;
					$modified = true;
				}
			}
		}

		// Could not remap parent, ensure parent is wiped
		if ( empty( $modified ) ) {
			$sync_object['data']['object']->parent = 0;
		}

		return apply_filters( sprintf( 'ea-syncable-%s-filter-map-dependencies', static::$name ), $sync_object, $synced_deps, $this );
	}

	/**
	 * Get syncable object data to be passed through to destination site
	 *
	 * @return mixed|void
	 */
	public function get_data() {

		$hierarchy  = [];
		$obj        = $this->object;
		$has_parent = ( $obj->parent );

		// Get a full term hierarchy
		while ( $has_parent ) {
			$obj         = get_term( $obj->parent, $obj->taxonomy );
			$has_parent  = ( $obj && ! empty( $obj->parent ) );
			$hierarchy[] = $obj;
		}

		return apply_filters( sprintf( 'ea-syncable-%s-filter-data', static::$name ),
			[
				'object'    => apply_filters( sprintf( 'ea-syncable-%s-filter-object', static::$name ), clone( $this->object ) ),
				'meta'      => apply_filters( sprintf( 'ea-syncable-%s-filter-meta', static::$name ), $this->meta ),
				'hierarchy' => apply_filters( sprintf( 'ea-syncable-%s-filter-hierarchy', static::$name ), $hierarchy ),
				'url'       => apply_filters( sprintf( 'ea-syncable-%s-filter-canonical-url', static::$name ), static::get_link( $this->get_source_object_id() ) ),

			]
		);
	}

	/**
	 * Get the source object ID
	 *
	 * @return int
	 */
	public function get_source_object_id() {
		return (int) $this->object->term_id;
	}
}
