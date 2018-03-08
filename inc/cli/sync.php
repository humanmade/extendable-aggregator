<?php

namespace EA\CLI;

use EA;
use WP_CLI;

/**
 *
 * EA Sync CLI command
 *
 * Adds methods for syncing posts from one site to another via CLI script
 * 
 */
class Sync extends \WP_CLI_Command {

	/**
	 * Sync objects returned by WP_Query
	 *
	 * ## OPTIONS
	 * <sites>
	 * : Comma separated list of site IDs to sync to
	 *
	 * [--method]
	 * : The syndication method, sync|create, defaults to sync
	 *
	 * [--post_status]
	 * : WP_Query post status argument
	 *
	 * [--post_type]
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
	 *
	 * ## EXAMPLES
	 *   wp ea-sync post-by-query 2,3,4 --post_type='service' --posts_per_page=-1
	 *
	 * @alias post-by-query
	 */
	public function post_by_query( $args, $assoc_args ) {

		$start = time();

		$sites = $args[0] ? explode( ',', $args[0] ) : [];

		$sites = array_filter( $sites, 'is_numeric' );

		$method = isset( $assoc_args['method'] ) ? $assoc_args['method'] : 'sync';

		if ( ! $sites ) {
			WP_CLI::error( 'Invalid site ID param' );
		}

		if ( empty( $assoc_args['post_type'] ) ) {
			WP_CLI::error( 'Please define the --post_type query arg.' );
		}

		$query_args = wp_parse_args( $assoc_args, [
			'post_type'      => 'post',
			'posts_per_page' => 100,
			'paged'          => 0,
			'post_status'    => 'publish'
		] );

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

		$this->start_bulk_operation();

		foreach ( $posts as $key => $post ) {

			if ( 0 === ( $key % 20 ) ) {
				$this->stop_the_insanity();
			}

			$syncable = EA\Master::get_syncable( $post );

			foreach ( $sites as $site ) {

				if ( ! get_blog_details( [ 'blog_id' => $site ] ) ) {
					WP_CLI::warning( sprintf( 'Blog %s does not exist', $site ) );
					continue;
				}

				if ( $syncable->source_is_detached( $post->ID, $site ) && ! $assoc_args['force_sync'] ) {
					WP_CLI::warning( sprintf( 'Syncable %d has been detached on the destination site %d', $post->ID, $site ) );
					continue;
				}

				$id = $syncable->sync_to( $site, $method );

				if ( 'sync' === $method ) {
					$syncable->set_is_syncable( $site, $post->ID, true );
				}

				WP_CLI::line( sprintf( 'Synced %s (%d) to site %d, Destination id: %d' , $post->post_type, $post->ID, $site, $id ) );
			}
		}

		$this->end_bulk_operation();

		WP_CLI::line( 'Time taken: ' . ( time() - $start ) );
	}

	/**
	 * Sync objects returned by a term query
	 *
	 * ## OPTIONS
	 * <sites>
	 * : Comma separated list of site IDs to sync to
	 *
	 * [--method]
	 * : The syndication method, sync|create, defaults to sync
	 *
	 * [--taxonomy]
	 * : Term query taxonomy arg
	 *
	 * [--include]
	 * : Term query include arg
	 *
	 * [--offset]
	 * : Term query offset arg
	 *
	 * [--number]
	 * : Term query number arg
	 *
	 * [--name]
	 * : Term query name arg
	 *
	 * [--slug]
	 * Term query slug arg
	 *
	 * [--hide_empty]
	 * Whether or not to include empty terms
	 *
	 * [--force_sync]
	 * : Whether or not to force syncing on detached objects
	 *
	 * ## EXAMPLES
	 *   wp ea-sync term-by-query 2,3,4 --taxonomy='service'
	 *
	 * @alias term-by-query
	 */
	public function term_by_query( $args, $assoc_args ) {

		$sites = $args[0] ? explode( ',',  $args[0] ) : [];

		$sites = array_filter( $sites, function( $site_id ) {
			return is_numeric( $site_id );
		} );

		$method = isset( $assoc_args['method'] ) ? $assoc_args['method'] : 'sync';

		if ( ! $sites ) {
			WP_CLI::error( 'Invalid site ID param' );
		}

		if ( empty( $assoc_args['taxonomy'] ) ) {
			WP_CLI::error( 'Please define the taxonomy query arg.' );
		}

		$query_args = wp_parse_args( $assoc_args, [
			'taxonomy'       => 'post_tag',
			'offset'         => 0,
			'number'         => 100,
			'hide_empty'     => false,
		] );

		if ( empty( $assoc_args['force_sync'] ) || 'false' === $assoc_args['force_sync'] ) {
			$assoc_args['force_sync'] = false;
		}

		if ( isset( $assoc_args['include'] ) ) {
			$query_args['include'] = explode( ',', str_replace( ' ', '', $query_args['include'] ) );
		}

		$query_args['taxonomy']  = explode( ',', str_replace( ' ', '', $query_args['taxonomy'] ) );

		if ( isset( $query_args['name'] ) ) {
			$query_args['name']      = explode( ',', str_replace( ' ', '', $query_args['name'] ) );
		}

		if ( isset( $query_args['slug'] ) ) {
			$query_args['slug'] = explode( ',', str_replace( ' ', '', $query_args['slug'] ) );
		}

		$terms = get_terms( $query_args );

		WP_CLI::line( sprintf( 'Processing %d terms', count( $terms ) ) );

		foreach ( $terms as $key => $term ) {

			if ( 0 === ( $key % 20 ) ) {
				$this->stop_the_insanity();
			}

			$syncable = EA\Master::get_syncable( $term );

			foreach ( $sites as $site ) {

				if ( ! get_blog_details( [ 'blog_id' => $site ] ) ) {
					WP_CLI::warning( sprintf( 'Blog %s does not exist' ) );
					continue;
				}

				if ( $syncable->source_is_detached( $term->term_id, $site ) && ! $assoc_args['force_sync'] ) {
					WP_CLI::warning( sprintf( 'Syncable %d has been detached on the destination site %d', $term->term_id, $site ) );
					continue;
				}

				$id = $syncable->sync_to( $site, $method );

				if ( 'sync' === $method ) {
					$syncable->set_is_syncable( $site, $term->term_id, true );
				}

				WP_CLI::line( sprintf( 'Synced %s (%d) to site %d, Destination id: %d', $term->taxonomy, $term->term_id, $site, $id ) );
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

	/**
	 * Disable term counting so that terms are not all recounted after every term operation
	 */
	protected function start_bulk_operation() {

		// Disable term count updates for speed
		wp_defer_term_counting( true );
	}

	/**
	 * Re-enable Term counting and trigger a term counting operation to update all term counts
	 */
	protected function end_bulk_operation() {
		
		// This will also trigger a term count.
		wp_defer_term_counting( false );
	}
}