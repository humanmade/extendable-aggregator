<?php

namespace EA\Syncable;

use EA;

/**
 * Interface Base_Interface
 * @package EA\Syncable
 */
interface Base_Interface {

	/**
	 * Get a class instance from a syncable object
	 *
	 * @param $object
	 * @return Base
	 */
	public static function from_object( $object );

	/**
	 * Check if the object being synced already exists on the destination database
	 *
	 * @param $site_id
	 * @param $sync_object
	 * @return mixed
	 */
	public function object_exists( $site_id, $sync_object );

	/**
	 * Insert callback triggered by update/insert of an object on the source site
	 *
	 * @param $id
	 * @param $action
	 * @param $args
	 * @return void
	 */
	public static function insert_from_queue( $id, $action, $args );

	/**
	 * Delete callback triggered by deletion of an object on the source site
	 *
	 * @param $id
	 * @param $action
	 * @param $args
	 * @return void
	 */
	public static function delete_from_queue( $id, $action, $args );

	/**
	 * Set a meta entry for provided object ID
	 *
	 * @param $object_id
	 * @param $meta_key
	 * @param $meta_value
	 * @return mixed
	 */
	public static function set_meta( $object_id, $meta_key, $meta_value );

	/**
	 * add a meta entry for provided object ID
	 *
	 * @param $object_id
	 * @param $meta_key
	 * @param $meta_value
	 * @param $unique
	 * @return mixed
	 */
	public static function add_meta( $object_id, $meta_key, $meta_value, $unique = false );

	/**
	 * Delete a meta entry for provided object ID
	 *
	 * @param $object_id
	 * @param $meta_key
	 * @param $meta_value
	 * @return mixed
	 */
	public static function delete_meta( $object_id, $meta_key, $meta_value = '' );

	/**
	 * Get a meta entry for provided object ID
	 *
	 * @param $object_id
	 * @param $meta_key
	 * @param $single
	 * @return mixed
	 */
	public static function get_meta( $object_id, $meta_key, $single = true );

	/**
	 * Queue insertion callback of an object, this happens when an object is created or updated
	 *
	 * @param $arg1
	 * @param $arg2
	 * @return mixed
	 */
	public static function insert_callback( $arg1, $arg2 );

	/**
	 * Queue deletion callback of an object, this happens when an object is deleted
	 *
	 * @param $arg1
	 * @param $arg2
	 * @return mixed
	 */
	public static function delete_callback( $arg1, $arg2 );
}
