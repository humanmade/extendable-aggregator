<?php

namespace EA\Syncable;

use EA;

/**
 * Class Base
 * @package EA\Syncable
 */
abstract class Base implements Base_Interface {

	/**
	 * @public string $name name of the syncable
	 */
	public static $name;

	/**
	 * @public array actions which have been queued by updating an object which is marked as syncable
	 */
	public static $queued_actions = [];

	/**
	 * @public array $sync_hooks The actions/filters used to trigger syncing of a syncable object
	 */
	public static $sync_hooks = [];

	/**
	 * @public array $delete_hooks The actions/filters used to trigger deleting of a synced object
	 */
	public static $delete_hooks = [];

	/**
	 * @public stdClass $object object used to instantiate class (post, term etc)
	 */
	public $object;

	/**
	 * @public array $meta all object meta
	 */
	public $meta;

	/**
	 * @public int $src_site the site currently active on class instantiation
	 */
	public $src_site;

	/**
	 * @public int $destination_id the ID of the object as stored on the destination site, set when sync_to is
	 * successfully called
	 */
	public $destination_id;

	/**
	 * Insert the syncable object into destination database
	 *
	 * Will be switched to destination site when this method is called
	 *
	 * @param $site_id
	 * @param $sync_object
	 * @return mixed
	 */
	abstract public function insert_object( $site_id, $sync_object );

	/**
	 * Get all objects which the current object being synced depends on (attachments, terms etc)
	 *
	 * @param $sync_object
	 * @return array
	 */
	abstract public function get_dependencies( $sync_object );

	/**
	 * Map object dependencies
	 *
	 * Called after the dependencies have been inserted into the destination database
	 *
	 * @param $sync_object
	 * @param $synced_deps
	 * @return mixed
	 */
	abstract public function map_dependencies( $sync_object, $synced_deps );

	/**
	 * Get the source object ID
	 *
	 * @return int
	 */
	abstract public function get_source_object_id();

	/**
	 * Class constructor
	 *
	 * @param $object
	 * @param $object_id
	 */
	public function __construct( $object, $object_id ) {

		$this->object   = $object;
		$this->meta     = $this->get_all_meta( $object_id );
		$this->src_site = get_current_blog_id();
	}

	/**
	 * Execute all currently queued syncing actions
	 */
	public static function execute_queued_actions() {

		if ( ! static::acquire_lock( 'execute-queued-syncs' ) ) {
			return;
		}

		$actions = static::get_saved_actions();
		static::clear_saved_actions();

		if ( ! $actions ) {

			static::clear_lock( 'execute-queued-syncs' );
			return;
		}

		$count = 0;

		foreach ( $actions as $id => $actions_set ) {

			// Every 2nd iteration including start
			// Increased frequency for CG due to large dependency trees
			if ( 0 === $count++ % 2 ) {
				static::clear_local_cache();
			}

			foreach ( $actions_set as $action => $args ) {

				if ( 'delete' === $action ) {
					static::delete_from_queue( $id, $action, $args );
				} else if ( 'delete_synced' === $action ) {
					static::delete_synced_from_queue( $id, $action, $args );
				} else {
					static::insert_from_queue( $id, $action, $args );
				}
			}
		}

		static::clear_lock( 'execute-queued-syncs' );
	}

	/**
	 * Callback for when a synced object is deleted on the destination site
	 *
	 * Default handling is to set the parent object on the parent site to 'detached'
	 *
	 * @param $id
	 * @param $action
	 * @param $args
	 */
	public static function delete_synced_from_queue( $id, $action, $args ) {

		if ( ! $id || empty( $args['site_id'] ) || empty( $args['source_id'] ) ) {
			return;
		}

		if ( ! get_blog_details( (int) $args['site_id'] ) ) {
			return;
		}

		$destination_site_id = get_current_blog_id();

		// @codingStandardsIgnoreStart (fine for VIP Go)
		switch_to_blog( $args['site_id'] );
		// @codingStandardsIgnoreEnd

		static::source_set_is_detached( $args['source_id'], $destination_site_id, true );
		restore_current_blog();
	}

	/**
	 * Common functionality to be used on all objects for handling deletion callbacks
	 *
	 * @param $object_id
	 * @param array $args
	 */
	public static function delete_callback_base( $object_id, $args = array() ) {

		// Is a source post, detach synced posts
		if ( static::get_syncable_sites( $object_id ) ) {

			$args = [ 'by_site_ids' => [] ];

			foreach ( static::get_syncable_sites( $object_id ) as $site_id ) {
				$args['by_site_ids'][ $site_id ] = static::get_meta( $object_id, sprintf( 'ea-syncable-%s-synced-to-%s', static::$name, $site_id ) );
			}

			static::add_action( 'delete', $object_id );
		}

		$site_id   = static::get_meta( $object_id, 'ea-syncable-import-src-site' );
		$source_id = static::get_meta( $object_id, 'ea-syncable-import-src-id' );

		// Is a synced post, detach parent post
		if ( $site_id && $source_id ) {
			static::add_action( 'delete_synced', $object_id, [ 'site_id' => $site_id, 'source_id' => $source_id ] );
		}
	}

	/**
	 * Queue an action for execution on php shutdown - e.g. index post item after 'save_post' hook has been fired
	 *
	 * @param $action
	 * @param $identifier
	 * @param array $args
	 */
	public static function add_action( $action, $identifier, $args = array() ) {

		if ( apply_filters( 'ea-syncable-suspend-sync-actions', false ) ) {
			return;
		}
		
		// Later fix to handle cases where objects can be syndicated from an attached syndicated object
		// This should avoid issues associated with switch_to_blog
		if ( EA\Master::get_instance()->source_site !== get_current_blog_id() ) {
			$add_to_external_queue = [ (string) $identifier => [ $action => $args ] ];
			static::save_actions( $add_to_external_queue );
			return;
		}

		//keep actions in order of when they were last set
		if ( isset( static::$queued_actions[ $identifier ][ $action ] ) ) {
			unset( static::$queued_actions[ $identifier ][ $action ] );
		}

		static::$queued_actions[ (string) $identifier ][ $action ] = $args;
	}

	/**
	 * Get all sync actions queued by the current thread
	 *
	 * @return array
	 */
	public static function get_actions() {

		return static::$queued_actions;
	}

	/**
	 * Get all actions that have been queued
	 *
	 * @param null|array $actions Actions to save - defaults to actions collected via save/delete hooks
	 */
	public static function save_actions( $actions = null ) {

		//no actions to save
		if ( ! static::$queued_actions && ! $actions ) {
			return;
		}

		if ( ! $actions ) {
			$actions = static::$queued_actions;
		}

		if ( ! static::acquire_lock( 'save_actions' ) ) {
			return;
		}

		$saved  = static::get_saved_actions();
		$all    = array_replace_recursive( $saved, $actions );

		$limit = apply_filters( 'extendable-aggregator-queued-actions-limit', 10000 );
		do_action( 'extendable-aggregator-queued-actions-over-limit-check', count( $all ), $limit, static::$name );
		if ( count( $all ) > $limit ) {
			$all = array_slice( $all, -$limit, $limit, true );
		}

		static::clear_saved_actions();
		add_option( 'extendable-aggregator-queued-actions-' . static::$name, $all, '', 'no' );

		static::clear_lock( 'save_actions' );
	}

	/**
	 * Get the array of global syncing actions which should be performed
	 *
	 * @return array
	 */
	public static function get_saved_actions() {

		return get_option( 'extendable-aggregator-queued-actions-' . static::$name, array() );
	}

	/**
	 * Clear the saved syncing actions
	 */
	public static function clear_saved_actions() {

		delete_option( 'extendable-aggregator-queued-actions-' . static::$name );
	}

	/**
	 * Acquire a save lock to update the global actions queue with those set in the current thread
	 *
	 * @return bool
	 */
	public static function acquire_lock( $action ) {

		$attempts = 0;

		//Wait until other threads have finished saving their queued items (failsafe)
		while ( ! wp_cache_add( 'extendable-aggregator-acquire-lock-' . static::$name . '_' . $action, '1', '', 60 ) && $attempts < 10 ) {
			$attempts++;
			time_nanosleep( 0, 500000000 );
		}

		return $attempts < 10 ? true : false;
	}

	/**
	 * Clear the save lock after global actions have been updated
	 */
	public static function clear_lock( $action ) {

		wp_cache_delete( 'extendable-aggregator-acquire-lock-' . static::$name . '_' . $action );
	}

	/**
	 * Get sites which we should sync to for the provided object ID
	 *
	 * @param $object_id
	 * @return array|mixed
	 */
	public static function get_syncable_sites( $object_id ) {

		$syncables = static::get_meta( $object_id, sprintf( 'ea-syncable-%s-syncable-sites', static::$name ), true );
		$syncables = is_array( $syncables ) ? $syncables : [];

		return $syncables;
	}

	/**
	 * Check if the supplied object ID is syncable to the supplied site ID
	 *
	 * @param $site_id
	 * @param $object_id
	 * @return bool
	 */
	public static function get_is_syncable( $site_id, $object_id ) {

		$syncables = static::get_syncable_sites( $object_id );

		return ! empty( $syncables[ $site_id ] );
	}

	/**
	 * Set whether or not the supplied object is syncable for the supplied site
	 *
	 * @param $site_id
	 * @param $object_id
	 * @param $is_syncable
	 */
	public static function set_is_syncable( $site_id, $object_id, $is_syncable ) {

		$syncables = static::get_syncable_sites( $object_id );

		$syncables[ $site_id ] = $is_syncable;

		static::set_meta( $object_id, sprintf( 'ea-syncable-%s-syncable-sites', static::$name ), $syncables );
	}

	/**
	 * Check whether or not the supplied object is detached from parent
	 *
	 * Expects current site to be destination site
	 *
	 * @param $object_id
	 * @return mixed
	 */
	public static function destination_is_detached( $object_id ) {

		return static::get_meta( $object_id, sprintf( 'ea-syncable-%s-is-detached', static::$name ) );
	}

	/**
	 * Check whether or not the supplied object is detached from parent on the current site
	 *
	 * Expects current site to be source site
	 *
	 * @param $object_id
	 * @param $destination_site_id
	 * @return mixed
	 */
	public static function source_is_detached( $object_id, $destination_site_id ) {

		return static::get_meta( $object_id, sprintf( 'ea-syncable-%s-is-detached-%s', static::$name, $destination_site_id ) );
	}

	/**
	 * Set whether or not the supplied object is detached from parent on the current site
	 *
	 * Expects current site to be destination site
	 *
	 * @param $object_id
	 * @param $bool
	 * @return bool
	 */
	public static function destination_set_is_detached( $object_id, $bool ) {

		static::set_meta( $object_id, sprintf( 'ea-syncable-%s-is-detached', static::$name ), $bool );

		$src_site = static::get_meta( $object_id, 'ea-syncable-import-src-site' );
		$src_id   = static::get_meta( $object_id, 'ea-syncable-import-src-id' );
		$site_id  = get_current_blog_id();

		if ( ! $src_site || ! get_blog_details( $src_site ) || ! $src_id ) {
			return false;
		}

		// @codingStandardsIgnoreStart (fine for VIP Go)
		switch_to_blog( $src_site );
		// @codingStandardsIgnoreEnd

		static::set_meta( $src_id, sprintf( 'ea-syncable-%s-is-detached-%s', static::$name, $site_id ), $bool );
		restore_current_blog();

		// Notify all alternative sources that the object is detached
		$alternatives   = static::get_meta( $object_id, 'ea-syncable-import-src-alternative-sites', true );
		$alternatives   = is_array( $alternatives ) ? $alternatives : [];

		foreach( $alternatives as $alternative ) {

			if ( empty( $alternative['site'] ) || empty( $alternative['object'] ) ) {
				continue;
			}

			// @codingStandardsIgnoreStart (fine for VIP Go)
			switch_to_blog( $alternative['site']  );
			// @codingStandardsIgnoreEnd

			static::set_meta( $alternative['object'], sprintf( 'ea-syncable-%s-is-detached-%s', static::$name, $site_id ), $bool );

			restore_current_blog();
		}

		return true;
	}

	/**
	 * Set whether or not the supplied object is detached from child on the current site
	 *
	 * Expects current site to be source site
	 *
	 * @param int $object_id
	 * @param bool $bool True for detaching, false for re-attaching
	 * @param int $site_id
	 */
	public static function source_set_is_detached( $object_id, $site_id, $bool ) {

		static::set_meta( $object_id, sprintf( 'ea-syncable-%s-is-detached-%s', static::$name, $site_id ), $bool );

		$dest_id = get_post_meta( sprintf( 'ea-syncable-%s-synced-to-%s', static::$name, $site_id ) );

		if ( ! get_bloginfo( $site_id ) ) {
			return;
		}

		// @codingStandardsIgnoreStart (fine for VIP Go)
		switch_to_blog( $site_id );
		// @codingStandardsIgnoreEnd

		static::set_meta( $dest_id, sprintf( 'ea-syncable-%s-is-detached', static::$name ), $bool );

		restore_current_blog();
	}

	/**
	 * Set whether or not the supplied object is detached from child on all sites
	 *
	 * Expects current site to be source site
	 *
	 * @param int $object_id
	 * @param bool $bool  True for detaching, false for re-attaching
	 */
	public static function source_set_all_is_detached( $object_id, $bool ) {

		foreach ( static::get_syncable_sites( $object_id ) as $site_id ) {

			static::source_set_is_detached( $object_id, $site_id, $bool );

		}
	}

	/**
	 * Clear local caches to free up memory
	 *
	 * Similar to WPCOM_VIP_CLI_Command::stop_the_insanity minus clearing $wp_object_cache->stats which
	 * can cause issues with some object cache implementations
	 */
	public static function clear_local_cache() {

		global $wpdb, $wp_object_cache;

		$wpdb->queries = [];

		if ( ! is_object( $wp_object_cache ) ) {
			return;
		}

		$wp_object_cache->group_ops      = [];
		$wp_object_cache->memcache_debug = [];
		$wp_object_cache->cache          = [];

		if ( is_callable( [ $wp_object_cache, '__remoteset' ] ) ) {
			$wp_object_cache->__remoteset(); // important
		}
	}
	
	/**
	 * Sync instantiated object onto a specified destination site
	 *
	 * @param $site_id
	 * @param string $method
	 * @return mixed
	 */
	public function sync_to( $site_id, $method = 'sync', $recursion = 0 ) {

		if ( ! get_blog_details( $site_id ) ) {
			return false;
		}

		$this->destination_id = false;

		$sync_object = $this->get_sync_data( $site_id, $method );

		// Incase method has been filtered
		$method = $sync_object['method'];

		if ( $this->source_is_detached( $this->get_source_object_id(), $site_id ) ) {
			$cur_id = (int) static::get_meta( $this->get_source_object_id(), sprintf( 'ea-syncable-%s-synced-to-%s', static::$name, $site_id ) );
			$this->destination_id = $cur_id;
			return $cur_id;
		}

		$sync_object = $this->do_dependencies( $sync_object, $recursion );

		// @codingStandardsIgnoreStart (fine for VIP Go)
		switch_to_blog( $site_id );
		// @codingStandardsIgnoreEnd

		$cur_id = $this->object_exists( $site_id, $sync_object );

		$src_canonical   = ! empty( $sync_object['data']['meta']['ea-syncable-import-src-site-canonical'][0] ) ? $sync_object['data']['meta']['ea-syncable-import-src-site-canonical'][0] : 0;
		$src_current     = $cur_id ? $this->get_meta( $cur_id, 'ea-syncable-import-src-site' ) : 0;

		$skip_create     = ( ( 'create' === $method && $cur_id ) );
		$skip_detached   = $this->destination_is_detached( $cur_id );
		$skip_canonical  = (int) $src_canonical === (int) get_current_blog_id();
		$skip_alt_source = $src_current && (int) $src_current !== (int) $this->src_site;

		// If a different site from the current source is trying to sync an object, store some information on the destination
		// This allows us to switch the destination object to use the alternative source
		if ( $skip_alt_source && $method = 'sync' ) {
			$alternatives   = $this->get_meta( $cur_id, 'ea-syncable-import-src-alternative-sites', true );
			$alternatives   = is_array( $alternatives ) ? $alternatives : [];
			$alternatives[] = [ 'site' => $this->src_site, 'object' => $this->get_source_object_id(), 'time' => time() ];

			$clean_alternatives = [];

			// Remove duplicates
			foreach ( $alternatives as $alternative ) {
				if ( ! in_array( $alternative['site'], wp_list_pluck( $clean_alternatives, 'site' ), true ) ) {
					$clean_alternatives[] = $alternative;
				}
			}

			$this->set_meta( $cur_id, 'ea-syncable-import-src-alternative-sites', $alternatives );
		}

		// If we are just making sure the object is created, don't trigger sync
		// If the object is detached, don't trigger a sync
		// If the object is originally from the site being synced to, don't trigger a sync
		// If the object's source site differs from current source site and the current site is not the canonical source, don't trigger sync
		if ( $skip_create || $skip_detached || $skip_canonical || $skip_alt_source ) {
			restore_current_blog();
			$this->destination_id = $cur_id;
			return $cur_id;
		}

		$sync_object = apply_filters( sprintf( 'ea-syncable-%s-filter-pre-insert', static::$name ), $sync_object, $this );
		$object_id   = $this->insert_object( $site_id, $sync_object );

		if ( ! $object_id || is_wp_error( $object_id ) ) {
			restore_current_blog();
			return false;
		}

		$this->destination_id = $object_id;

		$this->insert_meta( $site_id, $object_id, $sync_object );

		$this->insert_associations( $site_id, $object_id, $sync_object );

		restore_current_blog();

		$this->update_source( $site_id, $object_id, $sync_object );

		return $object_id;
	}

	/**
	 * Get the sync data object for the current object being synced
	 *
	 * @param $site_id
	 * @param $method
	 * @return mixed|void
	 */
	public function get_sync_data( $site_id, $method ) {

		$sync_object = apply_filters( sprintf( 'ea-syncable-%s-filter-pre-process', static::$name ),
			[
				'site'        => $site_id,
				'method'      => $method,
				'data'        => $this->get_data(),
				'source_site' => get_current_blog_id(),
			]
		);

		return $sync_object;
	}

	/**
	 * Modify the provided sync object to include mapped dependency changes
	 *
	 * @param $sync_object
	 * @param $recursion
	 * @return mixed
	 */
	public function do_dependencies( $sync_object, $recursion = 0 ) {

		$recursion++;

		/**
		 * Don't go more than 3 levels deep when mapping dependencies
		 *
		 * This acts as a failsafe for infinite recursion where dependencies can link back to each other
		 * Also adds a hard limit on how much of a site can be copied over due to complex relationships
		 */
		$do_dependencies = $recursion <= 3 ? true : false;
		$do_dependencies = apply_filters( sprintf( 'ea-syncable-%s-filter-do-dependencies', static::$name ), $do_dependencies, $recursion, $sync_object, $this );
		
		if ( ! $do_dependencies ) {
			$sync_object['dependencies'] = [];
			return $sync_object;
		}

		$dependencies = $this->get_dependencies( $sync_object );
		$sync_dependencies = [];

		foreach ( $dependencies as $dep ) {
			$syncable = EA\Master::get_syncable( $dep );

			if ( ! $syncable ) {
				continue;
			}

			$id = $syncable->sync_to( $sync_object['site'], 'create', $recursion );

			if ( ! is_wp_error( $id ) && $id ) {
				$sync_dependencies[] = $syncable;
			}
		}

		$sync_object['dependencies'] = $sync_dependencies;

		return $this->map_dependencies( $sync_object, $sync_dependencies );
	}

	/**
	 * Insert meta into destination DB
	 *
	 * @param $site_id
	 * @param $object_id
	 * @param $sync_object
	 */
	public function insert_meta( $site_id, $object_id, $sync_object ) {

		$ignore_meta = [
			sprintf( 'ea-syncable-%s-import-ref-', static::$name ),
			sprintf( 'ea-syncable-%s-synced-to-', static::$name ),
			sprintf( 'ea-syncable-%s-syncable-sites', static::$name ),
			sprintf( 'ea-syncable-%s-is-detached', static::$name ),
			'ea-syncable-post-is-detached',
			'ea-syncable-import-last-synced',
		];

		$ignore_meta = apply_filters( sprintf( 'ea-syncable-%s-filter-ignored-meta', static::$name ), $ignore_meta );

		foreach ( $sync_object['data']['meta'] as $meta_key => $meta_value ) {

			$skip = array_filter( $ignore_meta, function( $ignore ) use ( $meta_key ) {
				return ( strpos( $meta_key, $ignore ) !== false );
			} );

			if ( $skip ) {
				continue;
			}

			// todo:: support deletion of meta?
			if ( count( $meta_value ) === 1 && count( static::get_meta( $object_id, $meta_key, false ) ) === 1 ) {
				static::set_meta( $object_id, $meta_key, $meta_value[0] );
			} else {

				static::delete_meta( $object_id, $meta_key );

				foreach ( $meta_value as $meta_value_single ) {
					static::add_meta( $object_id, $meta_key, $meta_value_single );
				}
			}
		}

		$method_saved = static::get_meta( $object_id, 'ea-syncable-import-method' );

		// Store method used to sync on destination object
		// We need to know if this post is being continually synced or just created
		// Object may be synced with 'create' call by dependency mapping so don't override if method is already set to 'sync'
		if ( ! $method_saved || 'sync' !== $method_saved ) {
			static::set_meta( $object_id, 'ea-syncable-import-method', $sync_object['method'] );
		}

		// We need to store the original source data independently to allow us to check and ensure we don't circle around with sync callbacks
		$source_site_saved = static::get_meta( $object_id, 'ea-syncable-import-src-site-canonical' );

		if ( ! $source_site_saved ) {
			static::set_meta( $object_id, 'ea-syncable-import-src-site-canonical', $this->src_site );
		}

		$source_id_saved = static::get_meta( $object_id, 'ea-syncable-import-src-id-canonical' );

		if ( ! $source_id_saved ) {
			static::set_meta( $object_id, 'ea-syncable-import-src-id-canonical', $this->get_source_object_id() );
		}

		$canonical_url_saved = static::get_meta( $object_id, 'ea-syncable-import-src-canonical-url' );

		if ( ! $canonical_url_saved ) {
			static::set_meta( $object_id, 'ea-syncable-import-src-canonical-url', $sync_object['data']['url'] );
		}

		static::set_meta( $object_id, 'ea-syncable-import-src-site', $this->src_site );
		static::set_meta( $object_id, 'ea-syncable-import-last-synced', time() );
		static::set_meta( $object_id, 'ea-syncable-import-src-id', $this->get_source_object_id() );

		static::set_meta( $object_id, sprintf( 'ea-syncable-import-src-id-%d', $this->src_site ), $this->get_source_object_id() );

		do_action( sprintf( 'ea-syncable-%s-action-insert-meta', static::$name ), $object_id, $sync_object, $this );
	}

	/**
	 * Insert object associations into destination DB
	 *
	 * @param $site_id
	 * @param $object_id
	 * @param $sync_object
	 */
	public function insert_associations( $site_id, $object_id, $sync_object ) {
		do_action( sprintf( 'ea-syncable-%s-action-insert-associations', static::$name ), $object_id, $sync_object );
	}

	/**
	 * Update the source object on the source site
	 *
	 * Will be triggered after object has been inserted on destination site
	 *
	 * @param $site_id
	 * @param $object_id
	 * @param $sync_object
	 * @return void
	 */
	public function update_source( $site_id, $object_id, $sync_object ) {

		$id = $this->get_source_object_id();

		static::set_meta( $id, sprintf( 'ea-syncable-%s-synced-to-%s', static::$name, $site_id ), $object_id );
		static::set_meta( $id, sprintf( 'ea-syncable-%s-synced-to-time-%s', static::$name, $site_id ), time() );

		do_action( sprintf( 'ea-syncable-%s-post-sync-update-source', static::$name ), $site_id, $object_id, $sync_object );
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
				'url'    => apply_filters( sprintf( 'ea-syncable-%s-filter-canonical-url', static::$name ), static::get_link( $this->get_source_object_id() ) ),
			]
		);
	}

	/**
	 * Get all meta for the source object
	 *
	 * @param $object_id
	 * @return array
	 */
	public function get_all_meta( $object_id ) {

		$meta = get_metadata( static::$name, $object_id );

		foreach ( $meta as $key => $values ) {

			foreach ( $values as $index => $value ) {
				$meta[ $key ][ $index ] = maybe_unserialize( $value );
			}
		}

		return $meta;
	}

	/**
	 * Switches the source of a synced object on the destination site to an alternative site, T
	 * The alternative site needs to have tried to sync the same object to the destination
	 *
	 * @param int $new_source_site the new source site ID
	 * @return bool
	 */
	public function switch_source( $new_source_site ) {

		$alt_sources = $this->get_meta( $this->get_source_object_id(), 'ea-syncable-import-src-alternative-sites', true );

		if ( ! $alt_sources || ! is_array( $alt_sources ) ) {
			return false;
		}

		$current_source_site        = $this->get_meta( $this->get_source_object_id(), 'ea-syncable-import-src-site', true );
		$current_source_object      = $this->get_meta( $this->get_source_object_id(), 'ea-syncable-import-src-id', true );
		$current_source_last_synced = $this->get_meta( $this->get_source_object_id(), 'ea-syncable-import-last-synced', true );

		$found_alt_key    = false;
		$found_alt_source = false;

		foreach ( $alt_sources as $alt_source_key => $source ) {

			if ( $new_source_site === absint( $source['site'] ) ) {
				$found_alt_key = $alt_source_key;
				$found_alt_source = $source;
			}
		}

		// The desired source site hasn't supplied alt source data to use
		if ( $found_alt_key === false || ! $found_alt_source ) {
			return false;
		}

		$old_source = [
			'site'   => $current_source_site,
			'object' => $current_source_object,
			'time'   => $current_source_last_synced,
		];

		unset( $alt_sources[ $found_alt_key ] );
		$alt_sources[] = $old_source;

		$this->set_meta( $this->get_source_object_id(), 'ea-syncable-import-src-site', $found_alt_source['site'] );
		$this->set_meta( $this->get_source_object_id(), 'ea-syncable-import-src-id', $found_alt_source['object'] );
		$this->set_meta( $this->get_source_object_id(), 'ea-syncable-import-src-alternative-sites', $alt_sources );

		$current_site = get_current_blog_id();

		switch_to_blog( $found_alt_source['site'] );

		if ( 'post' === static::$name || 'attachment' === static::$name ) {

			$syncable = EA\Master::get_syncable( get_post( $found_alt_source['object'] ) );

		} else if ( 'comment' === static::$name ) {

			$syncable = EA\Master::get_syncable( get_comment( $found_alt_source['object'] ) );

		} elseif ( 'term' === static::$name ) {

			$syncable = EA\Master::get_syncable( get_term( $found_alt_source['object'] ) );
		}

		if ( ! empty( $syncable ) && $syncable instanceof Base ) {
			$syncable->sync_to( $current_site, 'sync' );
		}

		restore_current_blog();

		return true;
	}
}
