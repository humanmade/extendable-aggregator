<?php

namespace EA\CLI;

use EA;
use WP_CLI;

/**
 *
 * EA Resync CLI command
 *
 * Adds methods for resyncing posts from one site to another via CLI script
 *
 */
class Resync extends \WP_CLI_Command {

	/**
	 * Resync objects returned by WP_Query
	 *
	 * Adds in meta filter to only return results that have already been tagged for syndication on at least 1 site
	 *
	 * ## OPTIONS
	 *
	 * [--method]
	 * : The syndication method, sync|create, defaults to sync
	 *
	 * [--post_status]
	 * : WP_Query post status argument
	 *
	 * [--post_type=<post_type>]
	 * : WP_Query post type argument
	 *
	 * [--posts_per_page]
	 * : WP_Query posts per page argument
	 *
	 * [--paged]
	 * : WP_Query paged argument
	 *
	 * [--post__in]
	 * : WP_Query post__in argument
	 *
	 * [--force_sync]
	 * : Whether or not to force syncing on detached objects
	 *
	 * [--sites]
	 * : Optional list of site IDs to do the resyc for, will default to all sites marked as syncable on the object
	 *
	 * ## EXAMPLES
	 *   wp ea-resync post-by-query --post_type='resource' --posts_per_page=-1
	 *
	 * @subcommand post-by-query
	 */
	public function post_by_query( $args, $assoc_args ) {

		$method = isset( $assoc_args['method'] ) ? $assoc_args['method'] : 'sync';

		if ( empty( $assoc_args['post_type'] ) ) {
			WP_CLI::error( 'Please define the --post_type query arg.' );
		}

		$query_args = wp_parse_args( $assoc_args, [
			'post_type'      => 'post',
			'posts_per_page' => 100,
			'paged'          => 0,
			'post_status'    => 'publish'
		] );

		$query_args['meta_query'] = [
			[
				'key'     => 'ea-syncable-post-syncable-sites',
				'compare' => 'EXISTS',
			]
		];

		if ( empty( $assoc_args['force_sync'] ) || 'false' === $assoc_args['force_sync'] ) {
			$assoc_args['force_sync'] = false;
		}

		if ( isset( $assoc_args['post__in'] ) ) {
			$query_args['post__in'] = explode( ',', str_replace( ' ', '', $query_args['post__in'] ) );
		}

		$query_args['post_type']   = explode( ',', str_replace( ' ', '', $query_args['post_type'] ) );
		$query_args['post_status'] = explode( ',', str_replace( ' ', '', $query_args['post_status'] ) );

		$posts = get_posts( $query_args );

		WP_CLI::line( sprintf( 'Processing %d posts', count( $posts ) ) );

		foreach ( $posts as $key => $post ) {

			if ( 0 === ( $key % 20 ) ) {
				$this->stop_the_insanity();
			}

			$syncable = EA\Master::get_syncable( $post );
			$sites    = array_keys( array_filter( $syncable->get_syncable_sites( $post->ID ) ) );

			if ( ! empty( $assoc_args['sites'] ) ) {
				$sites = explode( ',', str_replace( ' ', '', $assoc_args['sites'] ) );
			}

			foreach ( $sites as $site ) {

				if ( ! $syncable->get_is_syncable( $site, $post->ID ) ) {
					WP_CLI::warning( sprintf( '%s %d is not configured to be syncable for site %d', $post->post_type, $post->ID, $site ) );
					continue;
				}

				if ( ! get_blog_details( [ 'blog_id' => $site ] ) ) {
					WP_CLI::warning( sprintf( 'Blog %s does not exist', $site ) );
					continue;
				}

				if ( $syncable->source_is_detached( $post->ID, $site ) && ! $assoc_args['force_sync'] ) {
					WP_CLI::warning( sprintf( 'Syncable %d has been detached on the destination site %d', $post->ID, $site ) );
					continue;
				}

				$id = $syncable->sync_to( $site, $method );

				WP_CLI::line( sprintf( 'Synced %s (%d) to site %d, Destination id: %d' , $post->post_type, $post->ID, $site, $id ) );
			}
		}
	}

	/**
	 * Clear all of the caches for memory management
	 */
	protected function stop_the_insanity() {
		global $wpdb, $wp_object_cache;

		$wpdb->queries = array(); // or define( 'WP_IMPORTING', true );

		if ( !is_object( $wp_object_cache ) )
			return;

		$wp_object_cache->group_ops = array();
		$wp_object_cache->memcache_debug = array();
		$wp_object_cache->cache = array();

		if ( is_callable( $wp_object_cache, '__remoteset' ) )
			$wp_object_cache->__remoteset(); // important
	}
}
