<?php
/**
 * General utility functions for easy access to key functionality outside of Syncable classes.
 *
 * @package WordPress
 * @subpackage Extendable Aggregator
 */
namespace EA;

/**
 * Print out a canonical meta tag referencing the original post URL.
 *
 * @param string Existing canonical URL
 * @param WP_Post $post WP_Post object
 */
function post_canonical_tag( $canonical_url, $post ) {

	/**
	 * Decide whether or not to print a canonical meta tag on post single.
	 *
	 * Gives you the object ID to decide on a more granular level.
	 *
	 * Defaults to true
	 *
	 * @param bool $do_canonical,
	 * @param int $object_id
	 */
	$do_canonical = apply_filters( 'extendable-aggregator-post-canonical-tag', true, get_queried_object_id() );

	/**
	 * Documented in Admin\Post.
	 */
	$eligible_post_types = apply_filters( 'extendable-aggregator-syncable-post-types', [ 'post', 'attachment' ] );

	// If we don't want canonical tags or are not on the correct singular page, bounce.
	if ( ! $do_canonical || ! is_singular( $eligible_post_types ) ) {
		return $canonical_url;
	}

	$url = get_source_url( $post->ID, 'post' );

	if ( is_object_synced( $post->ID, 'post' ) && $url && ! is_wp_error( $url ) ) {
		return $url;
	}

	// Default return the default URL.
	return $canonical_url;

}
add_filter( 'get_canonical_url', __NAMESPACE__ . '\\post_canonical_tag', 15, 2 );

/**
 * Get source URL of a post or term object.
 *
 * Designed to be run on destination site.
 *
 * @param int    $object_id Post or Term ID.
 * @param string $type Object type [ 'post', 'term' ]
 * @return string Permalink for post or term
 */
function get_source_url( $object_id, $type ) {
	// Get syncable class used to fetch information.
	$class = get_syncable_class( $type );

	if ( empty( $class ) ) {
		return '';
	}

	$permalink = $class::get_meta( $object_id, 'ea-syncable-import-src-canonical-url' );

	// Post is not synced, let's not try to find a URL.
	if ( ! $permalink ) {
		return '';
	}

	return $permalink;
}

/**
 * Wrapper for checking if an object is currently synced or not.
 *
 * @param $object_id
 * @param $object_type
 * @return bool|null Returns true if synced, false if detached, and null if not synced at all
 */
function is_object_synced( $object_id, $object_type ) {
	// Get syncable class used to fetch information.
	$class = get_syncable_class( $object_type );

	if ( empty( $class ) ) {
		return false;
	}

	return (
		$class::get_meta( $object_id, 'ea-syncable-import-src-site' )
		&& ! $class::destination_is_detached( $object_id )
		&& 'create' !== $class::get_meta( $object_id, 'ea-syncable-import-method' )
	);
}

/**
 * Wrapper for checking if an object is syncable.
 *
 * @param $object_id
 * @param $destination_site_id
 * @param $object_type
 * @return bool
 */
function is_object_syncable( $object_id, $destination_site_id, $object_type ) {
	// Get syncable class used to fetch information.
	$class = get_syncable_class( $object_type );

	if ( empty( $class ) ) {
		return false;
	}

	return ( $class::get_is_syncable( $destination_site_id, $object_id ) && ! $class::source_is_detached( $object_id, $destination_site_id ) );
}

/**
 * Wrapper for getting all sites for which an object can be synced to.
 *
 * @param $object_id
 * @param $object_type
 * @return array
 */
function get_object_syncable_sites( $object_id, $object_type ) {
	// Get syncable class used to fetch information.
	$class = get_syncable_class( $object_type );

	if ( empty( $class ) ) {
		return [];
	}

	return (array) $class::get_syncable_sites( $object_id );
}

/**
 * Checks whether or not a destination object is detached from source.
 *
 * Intended to be run from destination site.
 *
 * @param int $object_id Post or term ID
 * @param string $object_type post, term, or attachment
 * @return bool True for detached object, false for never-detached
 */
function is_destination_detached( $object_id, $object_type ) {
	$class = get_syncable_class( $object_type );

	if ( empty( $class ) ) {
		return false;
	}

	return $class::destination_is_detached( $object_id );
}

/**
 * Add an object to the syncing queue.
 *
 * @param int    $object_id   Object ID.
 * @return bool false if no class found.
 */
function add_post_to_queue( $object_id ) {
	$class = get_syncable_class( 'post' );

	if ( empty( $class ) ) {
		return false;
	}

	$class::insert_callback( $object_id );
}

/**
 *  Get a syncable class reference to use with static calls on Syncable objects.
 *
 * @param string $object_type Type/name of object - post, term, attachment
 * @return string Class name
 */
function get_syncable_class( $object_type ) {
	$classes = Master::get_syncable_classes();

	if ( empty( $classes[ $object_type ] ) ) {
		return false;
	}

	return $classes[ $object_type ];
}

/**
 * External getter/check for whether a user can sync or detach posts.
 *
 * @return bool
 */
function can_user_aggregate() {
	/**
	 * Documented in Admin\Base
	 */
	$permission_level = apply_filters( 'extendable-aggregator-sync-permission-level', 'edit_others_posts' );

	return current_user_can( $permission_level );
}

/**
 * Get syncable sites
 *
 * @param string $filter
 * @return array
 */
function get_syncable_sites( $filter = 'destinations' ) {
	$blog_ids   = [];

	$args = [
		'spam'     => 0,
		'deleted'  => 0,
		'archived' => 0,
	];

	$sites = get_sites( $args );

	/**
	 * Filter out specific sites when fetching blog IDs for use with sync-tos.
	 *
	 * Default filtering out the current site ID and main (original) site ID.
	 *
	 * @param array $blocked_sites Sites to block.
	 */
	$blacklisted_destination_sites = apply_filters( 'extendable-aggregator-blocked-destination-sites', [ get_current_blog_id() ] );

	/**
	 * Filter out specific sites when fetching blog IDs for use with sync-froms.
	 *
	 * Default blank.
	 *
	 * @param array $blocked_sites Sites to block.
	 */
	$blacklisted_source_sites = apply_filters( 'extendable-aggregator-blocked-origin-sites', [ get_current_blog_id() ] );

	// Choose which filter we'd like to apply.
	$blacklisted = ( 'destinations' === $filter ) ? $blacklisted_destination_sites : $blacklisted_source_sites;

	foreach ( $sites as $site ) {
		// Skip over blacklisted sites.
		if ( in_array( $site->blog_id, $blacklisted ) ) {
			continue;
		}
		$blog_ids[ $site->blog_id ] = $site->blogname;
	}

	return apply_filters( 'extendable-aggregator-syncable-sites', $blog_ids, $filter );
}

/**
 * Get syncable post types for for the "post" syncable.
 *
 * Not applicable to attachments (handled by the attackment syncable type)
 *
 * @return array
 */
function get_syncable_post_types() {

	return apply_filters( 'extendable-aggregator-syncable-post-types', [ 'post' ] );
}

/**
 * Wrapper for checking if an object is syndicated
 *
 * @param $object_id
 * @param $object_type
 * @return bool
 */
function is_object_syndicated( $object_id, $object_type ) {
	// Get syncable class used to fetch information.
	$class = get_syncable_class( $object_type );

	if ( empty( $class ) ) {
		return false;
	}

	return $class::get_meta( $object_id, 'ea-syncable-import-src-site' );
}

/**
 * Remove sync-related meta keys from an object
 *
 * @param $object
 */
function remove_object_syndication_meta( $object ) {
	// Get syncable class used to fetch information.
	$syncable = \EA\Master::get_syncable( $object );
	$object_id = $syncable->get_source_object_id();

	$meta = $syncable->get_all_meta( $object_id );
	$sync_meta_keys = array_filter( array_keys( $meta ), function ( $meta_key ) {
		return 0 === strpos( $meta_key, 'ea-syncable' );
	} );
	foreach ( $sync_meta_keys as $meta_key ) {
		$syncable::delete_meta( $object_id, $meta_key );
	}
}
