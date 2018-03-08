<?php
/**
 * Handling for high efficiency meta lookups
 *
 * Infinite object caches a wpdb query for a meta key + value pair
 *
 **/

namespace EA\CanonicalLookup\Term;

/**
 * For a given canonical ID and canonical site ID, look for the associated term on the current site
 *
 * @param $canonical_id
 * @param $canonical_site_id
 * @return bool|mixed
 */
function lookup_for_canonical_id( $canonical_id, $canonical_site_id ) {

	global $wpdb;

	$cache_group         = 'ea-canonical-id-lookup-term';
	$terms_with_canon_id = wp_cache_get( $canonical_id, $cache_group );
	$canonical_id        = absint( $canonical_id );

	if ( ! is_array( $terms_with_canon_id ) ) {
		$terms_with_canon_id = $wpdb->get_col( $wpdb->prepare( "SELECT term_id from $wpdb->termmeta WHERE meta_key = 'ea-syncable-import-src-id-canonical' AND meta_value = %s", $canonical_id ) );
		wp_cache_set( $canonical_id, $terms_with_canon_id, $cache_group );
	}

	$new_id = false;

	foreach ( $terms_with_canon_id as $term_id ) {

		if ( absint( get_term_meta( $term_id, 'ea-syncable-import-src-site-canonical', true ) ) === absint( $canonical_site_id ) ) {
			$new_id = $term_id;
		}
	}

	return $new_id;
}

/**
 * Clear our canonical term ID lookup on meta update
 *
 * @param $meta_id
 * @param $object_id
 * @param $meta_key
 * @param $meta_value
 */
function bust_canonical_term_id_lookup_on_update( $meta_id, $object_id, $meta_key, $meta_value ) {

	// Not the meta we are looking for
	if ( 'ea-syncable-import-src-id-canonical' !== $meta_key ) {
		return;
	}

	// Old and new meta values respectively
	$old_value = absint( get_term_meta( $object_id, 'ea-syncable-import-src-id-canonical', true ) );
	$new_value = absint( $meta_value );

	// No change in value, bail
	if ( $old_value === $new_value ) {
		return;
	}

	/**
	 * Delete old and new value AFTER the update has been completed.
	 * We double hook to get access to both old and new values but only clear caches after update is complete
	 * This function when called will unhook it's self to avoid additional unnecessary calls to clear cache if multiple term meta
	 * changes happen in the same thread.
	 */
	$func = function () use ( &$func, $old_value, $new_value ) {
		remove_action( 'updated_term_meta', $func );
		wp_cache_delete( absint( $old_value ), 'ea-canonical-id-lookup-term' );
		wp_cache_delete( absint( $new_value ), 'ea-canonical-id-lookup-term' );
	};

	// Hook the anonymous function into the `updated_term_meta` call so that we clear cache AFTER DB update
	add_action( 'updated_term_meta', $func );
}

add_action( 'update_term_meta', __NAMESPACE__ . '\\bust_canonical_term_id_lookup_on_update', 5, 4 );

/**
 * Clear our canonical term ID lookup on meta delete
 *
 * @param $meta_ids
 * @param $object_id
 * @param $meta_key
 * @param $_meta_value
 */
function bust_canonical_term_id_lookup_on_delete( $meta_ids, $object_id, $meta_key ) {

	if ( 'ea-syncable-import-src-id-canonical' !== $meta_key ) {
		return;
	}

	$meta_value = get_term_meta( $object_id, $meta_key, true );

	/**
	 * Delete old and new value AFTER the update has been completed.
	 */
	$func = function () use ( &$func, $meta_value ) {
		remove_action( 'updated_term_meta', $func );
		wp_cache_delete( absint( $meta_value ), 'ea-canonical-id-lookup-term' );
	};

	// Hook the anonymous function into the `updated_term_meta` call so that we clear cache AFTER DB update
	add_action( 'deleted_term_meta', $func );
}

add_action( "delete_term_meta", __NAMESPACE__ . '\\bust_canonical_term_id_lookup_on_delete', 10, 3 );

/**
 * Clear our canonical term ID lookup on meta add
 *
 * @param $object_id
 * @param $meta_key
 * @param $_meta_value
 */
function bust_canonical_term_id_lookup_on_add( $mid, $object_id, $meta_key, $meta_value ) {

	if ( 'ea-syncable-import-src-id-canonical' !== $meta_key ) {
		return;
	}

	wp_cache_delete( absint( $meta_value ), 'ea-canonical-id-lookup-term' );
}

add_action( "added_term_meta", __NAMESPACE__ . '\\bust_canonical_term_id_lookup_on_add', 10, 4 );
