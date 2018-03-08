<?php

namespace EA\Syncable;

use EA;
use EA\CanonicalLookup;

/**
 * Class Post
 * @package EA\Syncable
 */
class Post extends Base {

	/**
	 * @public string $name name of the syncable
	 */
	static $name = 'post';

	/**
	 * @public array $sync_hooks The actions/filters uses to trigger syncing of a syncable object
	 */
	public static $sync_hooks = array( 'edit_post', 'save_post', 'publish_post', 'trashed_post', 'untrashed_post' );

	/**
	 * @public array $delete_hooks The actions/filters used to trigger deleting of a synced object
	 */
	public static $delete_hooks = array( 'delete_post' );

	/**
	 * @public array actions which have been queued by updating an object which is marked as syncable
	 */
	static $queued_actions = [];

	/**
	 * Callback for when a syncable object is created or updated
	 *
	 * @param $post_id
	 * @param array $args
	 * @return void
	 */
	public static function insert_callback( $post_id, $args = array() ) {

		$post = get_post( $post_id );

		if ( ! $post || ! static::get_syncable_sites( $post_id ) || 'attachment' === $post->post_type ) {
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

		if ( ! $post || 'attachment' === $post->post_type ) {
			return;
		}

		static::delete_callback_base( $post_id );
	}

	/**
	 * Get syncable class instance from object
	 *
	 * @param $post
	 * @return static
	 * @throws \Exception
	 */
	public static function from_object( $post ) {

		if ( ! isset( $post->ID ) ) {
			throw new \Exception( 'Invalid post object supplied to ' . __NAMESPACE__ . '\Post syncable' );
		}

		return new static( $post, $post->ID );
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

		$syncable = static::from_object( get_post( $id ) );

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

		foreach ( $args['by_site_ids'] as $site_id => $post_id ) {

			if ( ! get_blog_details( $site_id ) ) {
				continue;
			}

			// @codingStandardsIgnoreStart (fine for VIP Go)
			switch_to_blog( $site_id );
			// @codingStandardsIgnoreEnd

			if ( static::destination_is_detached( $post_id ) ) {
				restore_current_blog();
				continue;
			}

			wp_delete_post( $post_id, true );

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

		update_post_meta( $object_id, $meta_key, $meta_value );
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

		add_post_meta( $object_id, $meta_key, $meta_value, $unique );
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

		return get_post_meta( $object_id, $meta_key, $single );
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

		delete_post_meta( $object_id, $meta_key, $meta_value );
	}

	/**
	 * Get permalink for a post from site.
	 *
	 * @param $object_id
	 * @return false|string
	 */
	public static function get_link( $object_id ) {
		return get_permalink( $object_id );
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

		// Try to find a match via looking up of the canonical ID set on the post being synced
		if ( ! $exists_mapped && ! empty( $meta['ea-syncable-import-src-id-canonical'][0] ) ) {
			$exists_mapped = CanonicalLookup\Post\lookup_for_canonical_id( $meta['ea-syncable-import-src-id-canonical'][0], $meta['ea-syncable-import-src-site-canonical'][0] );
		} 
		
		if ( ! $exists_mapped ) {
			$exists_mapped = CanonicalLookup\Post\lookup_for_canonical_id( $this->get_source_object_id(), $sync_object['source_site'] );
		}

		if ( ! $exists_mapped || ! get_post( $exists_mapped ) ) {
			$exists_mapped = false;
		}

		return apply_filters( sprintf( 'ea-syncable-%s-filter-object-exists', static::$name ), (int) $exists_mapped, $sync_object, $this );
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
			$insert->ID = $exists_mapped;
			$post_id    = wp_update_post( $insert );

		} else {
			unset( $insert->ID );
			$post_id = wp_insert_post( $insert );
		}

		return $post_id;
	}

	/**
	 * Get all objects which the current object being synced depends on (attachments, terms etc)
	 *
	 * @param $sync_object
	 * @return mixed|void
	 */
	public function get_dependencies( $sync_object ) {

		$do_parent = apply_filters(
			sprintf( 'ea-syncable-%s-auto-map-parent-dependency', static::$name ),
			'attachment' === (string) $sync_object['data']['object']->post_type ? false : true,
			$sync_object
		);

		$dependencies = [];

		if ( $sync_object['data']['object']->post_parent && $do_parent ) {
			$dependencies[] = get_post( $sync_object['data']['object']->post_parent );
		}

		//Include terms in auto mapping
		$do_terms = apply_filters(
			sprintf( 'ea-syncable-%s-auto-map-term-dependency', static::$name ),
			true,
			$sync_object
		);

		if ( $do_terms && ! empty( $sync_object['data']['terms'] ) ) {
			$dependencies = array_merge( $dependencies, $sync_object['data']['terms'] );
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

		$do_parent = apply_filters(
			sprintf( 'ea-syncable-%s-auto-map-parent-dependency', static::$name ),
			'attachment' === (string) $sync_object['data']['object']->post_type ? false : true,
			$sync_object
		);

		$parent = $sync_object['data']['object']->post_parent;

		// Object has a parent, check if parent has been inserted via dependencies and remap if possible
		if ( $parent && $do_parent ) {
			foreach ( $synced_deps as $dep_object ) {

				if ( 'post' === (string) $dep_object::$name && (int) $dep_object->object->ID === (int) $parent ) {
					$sync_object['data']['object']->post_parent = $dep_object->destination_id;
					$modified = true;
				}
			}
		}

		$do_terms = apply_filters(
			sprintf( 'ea-syncable-%s-auto-map-term-dependency', static::$name ),
			true,
			$sync_object
		);

		// Map terms
		if ( $do_terms ) {

			$by_tax = [];

			foreach ( $synced_deps as $syncable ) {

				// Make sure it's a term and that the term is assigned to the post (term deps may not be assigned)
				if ( 'term' !== $syncable::$name || ! in_array( $syncable->get_source_object_id(), wp_list_pluck( $sync_object['data']['terms'], 'term_id' ) ) ) {
					continue;
				}

				$by_tax[ $syncable->object->taxonomy ][] = $syncable->destination_id;
			}

			$sync_object['data']['mapped_terms'] = $by_tax;
		}

		// Could not remap parent, ensure parent is wiped
		if ( empty( $modified ) ) {
			$sync_object['data']['object']->post_parent = 0;
		}

		return apply_filters( sprintf( 'ea-syncable-%s-filter-map-dependencies', static::$name ), $sync_object, $synced_deps, $this );
	}

	/**
	 * Insert general object associations after object has been inserted on target site
	 *
	 * @param $site_id
	 * @param $object_id
	 * @param $sync_object
	 */
	public function insert_associations( $site_id, $object_id, $sync_object ) {

		parent::insert_associations( $site_id, $object_id, $sync_object );

		$do_terms = apply_filters(
			sprintf( 'ea-syncable-%s-auto-map-term-dependency', static::$name ),
			true,
			$sync_object
		);

		if ( ! $do_terms || empty( $sync_object['data']['mapped_terms'] ) ) {
			return;
		}

		//todo:: loop all tax and flush terms entirely? if no terms in tax, then this will not clear them
		foreach ( $sync_object['data']['mapped_terms'] as $tax => $terms ) {
			wp_set_object_terms( $object_id, $terms, $tax );
		}
	}

	/**
	 * Insert meta into destination DB
	 *
	 * @param $site_id
	 * @param $object_id
	 * @param $sync_object
	 */
	public function insert_meta( $site_id, $object_id, $sync_object ) {

		if ( isset( $sync_object['data']['meta']['_edit_lock'] ) ) {
			unset( $sync_object['data']['meta']['_edit_lock'] );
		}

		parent::insert_meta( $site_id, $object_id, $sync_object );
	}


	/**
	 * Get syncable object data to be passed through to destination site
	 *
	 * @return mixed|void
	 */
	public function get_data() {

		$taxonomies = get_taxonomies();
		$all_terms  = [];

		foreach ( $taxonomies as $tax ) {
			$terms = get_the_terms( $this->object->ID, $tax );

			if ( ! empty( $terms ) && ! is_wp_error( $terms ) ) {
				$all_terms = array_merge( $terms, $all_terms );
			}
		}

		return apply_filters( sprintf( 'ea-syncable-%s-filter-data', static::$name ),
			[
				'object' => apply_filters( sprintf( 'ea-syncable-%s-filter-object', static::$name ), clone( $this->object ) ),
				'meta'   => apply_filters( sprintf( 'ea-syncable-%s-filter-meta', static::$name ), $this->meta ),
				'terms'  => apply_filters( sprintf( 'ea-syncable-%s-filter-terms', static::$name ), $all_terms ),
				'url'    => apply_filters( sprintf( 'ea-syncable-%s-filter-canonical-url', static::$name ), static::get_link( $this->object->ID ) ),
			]
		);
	}

	/**
	 * Get the source object ID
	 *
	 * @return int
	 */
	public function get_source_object_id() {
		return (int) $this->object->ID;
	}
}
