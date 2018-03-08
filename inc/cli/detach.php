<?php

namespace EA\CLI;

use EA;
use WP_CLI;

/**
 *
 * EA Sync CLI command
 *
 * Adds methods for detaching posts from their source on a destination site
 *
 */
class Detach extends \WP_CLI_Command {

	/**
	 * Sync objects returned by WP_Query
	 *
	 * ## OPTIONS
	 *
	 * [--sites]
	 * : Comma separated list of site IDs to run the query against and do detachments, leave empty for current site only
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
	 * 	[--post__in]
	 * : WP_Query post in argument
	 *
	 * [--paged]
	 * : WP_Query paged argument
	 *
	 * ## EXAMPLES
	 *   wp ea-detach post-by-query --post_type='service' --posts_per_page=-1
	 *
	 * @alias post-by-query
	 */
	public function post_by_query( $args, $assoc_args ) {

		$sites = isset( $assoc_args['sites'] ) ? explode( ',', str_replace( ' ', '', $assoc_args['sites'] ) ) : [];

		$sites = array_filter( $sites, function( $site_id ) {
			return is_numeric( $site_id );
		} );

		if ( ! $sites ) {
			$sites = [ get_current_blog_id() ];
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

		if ( isset( $assoc_args['post__in'] ) ) {
			$query_args['post__in'] = explode( ',', str_replace( ' ', '', $query_args['post__in'] ) );
		}

		$query_args['post_type']   = explode( ',', str_replace( ' ', '', $query_args['post_type'] ) );
		$query_args['post_status'] = explode( ',', str_replace( ' ', '', $query_args['post_status'] ) );

		foreach ( $sites as $site ) {

			switch_to_blog( $site );

			$posts = get_posts( $query_args );

			foreach ( $posts as $post ) {

				$syncable = EA\Master::get_syncable( $post );

				if ( ! EA\is_object_synced( $post->ID, 'post' ) ) {
					WP_CLI::line( sprintf( '%s (%d) on site %d is not a synced object', $post->post_type, $post->ID, $site ) );
				}

				if ( ! get_blog_details( [ 'blog_id' => $site ] ) ) {
					WP_CLI::warning( sprintf( 'Blog %s does not exist', $site ) );
					continue;
				}

				$syncable->destination_set_is_detached( $post->ID, true );

				WP_CLI::line( sprintf( 'Detached %s (%d) on site %d', $post->post_type, $post->ID, $site ) );
			}

			restore_current_blog();
		}
	}
}