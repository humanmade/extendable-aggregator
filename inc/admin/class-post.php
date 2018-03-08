<?php
/**
 * Post-object admin and UI elements.
 *
 * @package WordPress
 * @subpackage Extendable Aggregator
 */

namespace EA\Admin;
use EA\Syncable, EA;

class Post extends Base {
	// Future @todo::Bulk aggregate items using bulk items dropdown

	/**
	 * Type of object admin UI relates to in WP language.
	 *
	 * string
	 */
	protected $type = 'post';

	/**
	 * Runs all hooks related to object type.
	 */
	public function hooks() {
		add_action( 'init', [ $this, 'cpt_specific_hooks' ], 10 );
		add_action( 'post_row_actions', [ $this, 'row_action_links' ], 10, 2 );
		add_action( 'page_row_actions', [ $this, 'row_action_links' ], 10, 2 );
		add_filter( 'post_class', [ $this, 'filter_classes' ], 10, 3 );
		add_action( 'admin_bar_menu', [ $this, 'edit_admin_bar' ], 85, 1 );
		add_filter( 'display_post_states', [ $this, 'aggregated_post_state' ], 10, 2 );
		add_action( 'restrict_manage_posts', [ $this, 'synced_filter_markup' ], 10, 1 );
		add_filter( 'pre_get_posts', [ $this, 'synced_filter_query' ], 10, 1 );

		// AJAX
		add_action( 'wp_ajax_ea_post_publish', [ $this, 'ajax_publish' ] );

		// Default AJAX hooks.
		parent::hooks();
	}

	/**
	 * Apply hooks on all eligible post types.
	 */
	public function cpt_specific_hooks() {

		/**
		 * Choose whether or not a post type is syncable.
		 *
		 * @param array $post_types Eligible post types. Defaults to post and attachment.
		 */
		$eligible_post_types = apply_filters( 'extendable-aggregator-syncable-post-types', [ 'post' ] );

		foreach ( $eligible_post_types as $cpt ) {
			// Add metabox to cpt.
			add_action( 'add_meta_boxes_' . $cpt, [ $this, 'register_meta_box' ] );
			// Save metabox data.
			add_action( 'save_post_' . $cpt, [ $this, 'save_syncable_meta' ], 10, 2 );
		}
	}

	/**
	 * Modify post action links in the post listing table.
	 *
	 * @param array    $actions Existing actions.
	 * @param \WP_Post $post Post Object.
	 * @return array Modified post actions
	 */
	public function row_action_links( array $actions, \WP_Post $post ) {

		// Only run on eligible post types.
		if ( ! $this->is_post_type_syncable( $post->post_type ) ) {
			return $actions;
		}

		$is_scheduled_post = ( mysql2date( 'U', $post->post_date ) > mysql2date( 'U', current_time( 'mysql' ) ) );

		if ( $is_scheduled_post ) {
			$text = __( 'Enable Publishing', 'extendable-aggregator' );
		} else {
			$text = __( 'Publish', 'extendable-aggregator' );
		}

		// Add a publish link for draft sync posts.
		if ( $this->can_user_aggregate() && $this->is_synced( $post->ID ) ) {

			/**
			 * Choose whether or not to display a publish action for the synced post
			 *
			 * @param string $can_publish Whether or not to show the publish UI
			 * @param \WP_Post $post The WP_Post object which the filter is being applied for
			 */
			$can_publish = apply_filters( 'extendable-aggregator-filter-post-can-override-publish',
				'draft' === get_post_status( $post->ID ) || 'pending' === get_post_status( $post->ID ),
				$post
			);

			if ( $can_publish ) {
				$actions['publish'] = sprintf( '<a class="ea-action" data-object-type="%1$s" data-object-id="%2$s" data-ajax-action="%3$s" href="%4$s">%5$s</a>',
					esc_attr( $this->type ),
					esc_attr( $post->ID ),
					'publish',
					'javascript:void(0);',
					esc_html( $text )
				);
			}
		}

		return $this->action_links( $actions, $post->ID );
	}

	/**
	 * Remove edit link from the WP admin bar on singular pages.
	 *
	 * @param WP_Admin_Bar $wp_admin_bar
	 */
	public function edit_admin_bar( $wp_admin_bar ) {
		if ( ! is_admin() && is_singular( 'post' ) && $this->is_synced( get_queried_object_id() ) ) {
			$wp_admin_bar->remove_node( 'edit' );
		}
	}

	/**
	 * Add class to post listing that post is aggregated or detached.
	 *
	 * @param array  $classes Classes on the post.
	 * @param string $class Extra classes being pushed through this post. String because WordPress.
	 * @param int    $post_id Post ID
	 * @return array (Potentially) modified classes
	 */
	public function filter_classes( $classes, $class, $post_id ) {
		if ( is_admin() && $this->is_synced( $post_id ) ) {
			$classes[] = 'synced-object';
		} else if ( is_admin() && $this->is_destination_detached( $post_id ) ) {
			$classes[] = 'detached-object';
		}

		if ( is_admin() && $this->is_synced( $post_id ) && $this->get_alternative_sources( $post_id ) ) {
			$classes[] = 'synced-object-alt-source';
		}

		return $classes;
	}

	/**
	 * Add a post state to synced posts identifying where they're synced from.
	 *
	 * @param array   $post_states Existing post states on post
	 * @param WP_Post $post Post object
	 * @return array (Potentially) modified post states
	 */
	public function aggregated_post_state( $post_states, $post = null ) {
		// Fetch current post if none is properly passed in.
		if ( is_null( $post ) ) {
			$post = get_post();
		}

		if ( $this->is_synced( $post->ID ) ) {
			// Get blog information from source site.
			$synced_blog_id = get_post_meta( $post->ID, 'ea-syncable-import-src-site', true );
			$blog_info      = get_blog_details( $synced_blog_id );

			if ( ! empty( $blog_info ) ) {
				$post_states[] = esc_html( sprintf( __( 'Synced from %s', 'extendable-aggregator' ), $blog_info->blogname ) );
			}
		}

		return $post_states;
	}

	/**
	 * Add meta box for editors to choose which sites a post should be synced to.
	 *
	 * @param WP_Post $post Post object
	 */
	public function register_meta_box( $post ) {

		// We don't want this metabox displaying on synced posts.
		if ( $this->is_synced( $post->ID ) || ! $this->can_user_aggregate() || empty( $this->get_blogs() ) ) {
			return;
		}

		$labels = $this->get_labels( $post->ID );
		$singular = ! empty( $labels->singular_name ) ? $labels->singular_name : 'Post';

		add_meta_box(
			'consumer_sites',
			sprintf( __( '%s Syndication', 'extendable-aggregator' ), $singular ),
			[ $this, 'meta_box' ],
			$post->post_type,
			'side',
			'high'
		);
	}

	/**
	 * Meta box HTML callback.
	 *
	 * Add the markup for the sync-to metabox on posts.
	 *
	 * @param WP_Post $post Post object.
	 */
	public function meta_box( $post ) {
		$labels   = $this->get_labels( $post->ID );
		$singular = ! empty( $labels->singular_name ) ? $labels->singular_name : 'post';

		?>
		<div class='inside'>
			<h4>
				<?php echo esc_html( sprintf( __( 'Sync this %s to:', 'extendable-aggregator' ), strtolower( $singular ) ) ); ?>

				<?php if ( count( $this->get_blogs() ) > 1 ) : ?>

					<span class="syndicate-toggle">
						<a href="#" class="select-all"><?php esc_html_e( 'Select all', 'extendable-aggregator' ); ?></a>
						<a href="#" class="deselect-all" style="display: none;"><?php esc_html_e( 'Deselect all', 'extendable-aggregator' ); ?></a>
					</span>

				<?php endif; ?>

			</h4>

			<p>
				<?php $this->meta_fields_markup( $post->ID ); ?>
			</p>

		</div>

		<?php
	}

	/**
	 * Build markup for a post-listing filter in the posts admin area.
	 *
	 * We want to enable editors and authors to be able to find particular posts quicker and easier.
	 * This dropdown provides a UI to allow them to filter by source site, or show all/no synced posts.
	 *
	 * @param string $post_type Current Post Type for edit listing.
	 */
	public function synced_filter_markup( $post_type ) {
		if ( ! $this->is_post_type_syncable( $post_type ) ) {
			return;
		}

		$blogs = $this->get_blogs( 'sources' );
		$cpt   = get_post_type_object( $post_type );

		if ( empty( $blogs ) ) {
			return;
		}

		$selected = ( isset( $_GET['synced_origin'] ) && ! empty( $_GET['synced_origin'] ) ) ? $_GET['synced_origin'] : false;

		if ( false !== $selected ) {
			if ( is_numeric( $selected ) ) {
				$selected = $this->sanitize_object_id( $selected );
			} else {
				$selected = sanitize_text_field( $selected );
			}
		}

		?>
		<select name="synced_origin" id="synced_origin" class="postform">
			<option value="" <?php selected( true, empty( $selected ) ); ?>><?php echo esc_html( sprintf( __( 'All %s', 'extendable-aggregator' ), $cpt->labels->name ) ); ?></option>
			<?php foreach ( $blogs as $id => $name ) : ?>
				<option value="<?php echo esc_attr( $id ); ?>" <?php selected( $id, $selected ); ?>>
					<?php echo esc_html( sprintf( __( 'Synced from %s', 'extendable-aggregator' ), $name ) ); ?>
				</option>
			<?php endforeach; ?>
			<option value="any" <?php selected( 'any', $selected ); ?>><?php echo esc_html_e( 'Synced from all sources', 'extendable-aggregator' ); ?></option>
			<option value="original" <?php selected( 'original', $selected ); ?>><?php echo esc_html( sprintf( __( 'Original %s Only', 'extendable-aggregator' ), $cpt->labels->name ) ); ?></option>
		</select>
		<?php
	}

	/**
	 * Adds a query filter for handling post listing filter.
	 *
	 * @param WP_Query $query Query
	 * @return WP_Query (Potentially) modified Query
	 */
	public function synced_filter_query( $query ) {
		$screen = false;

		if ( function_exists( 'get_current_screen' ) ) {
			$screen = get_current_screen();
		}

		if ( ! $screen || 'edit' !== $screen->base || empty( $_GET['synced_origin'] ) ) {
			return $query;
		}

		$meta_query = $query->get( 'meta_query' );

		// Establish some baseline values.
		$new_meta_query = [
			'relation' => 'AND',
			[
				'key'   => 'ea-syncable-import-method',
				'value' => 'sync',
			]
		];

		// Get content synced from any site.
		if ( 'any' === $_GET['synced_origin'] ) {
			$new_meta_query[] = [
				'key'     => 'ea-syncable-import-src-site',
				'compare' => 'EXISTS',
			];

		// Only original content.
		} elseif ( 'original' === $_GET['synced_origin'] ) {
			// Override all existing filters, we only want non-synced.
			$new_meta_query = [
				[
					'key'     => 'ea-syncable-import-src-site',
					'compare' => 'NOT EXISTS',
				],
			];

		// From a specific site.
		} else {
			$new_meta_query[] = [
				'key'   => 'ea-syncable-import-src-site',
				'value' => absint( $_GET['synced_origin'] ),
			];
		}

		if ( '' !== $meta_query ) {
			$meta_query['relation'] = 'AND';
			$meta_query[] = $new_meta_query;
		} else {
			$meta_query = $new_meta_query;
		}

		$query->set( 'meta_query', $meta_query );

		return $query;
	}

	/**
	 * Publish a post via AJAX request.
	 */
	public function ajax_publish() {
		// Check nonce and permissions first.
		$this->check_ajax_request();

		// Fetch, clean, and verify the post ID.
		$post_id = $this->ajax_object_exists();
		$post = $this->get_object( $post_id );

		// If this is a scheduled post, we want the status to change to 'future'
		if ( mysql2date( 'U', $post->post_date ) > mysql2date( 'U', current_time( 'mysql' ) ) ) {
			$status = 'future';
		} else {
			$status = 'publish';
		}

		$data = [
			'ID'          => $post_id,
			'post_status' => $status,
		];

		wp_update_post( $data );

		// Send back a success signal.
		wp_send_json_success( _x( 'Post published', 'ajax success message', 'extendable-aggregator' ) );
	}

	/**
	 * Check that a post exists.
	 *
	 * @access protected
	 *
	 * @param int $post_id Post ID.
	 * @return bool Validity of post.
	 */
	protected function object_exists( $post_id ) {
		$post_id = $this->sanitize_object_id( $post_id );

		if ( ! $post_id ) {
			return false;
		}

		$post = get_post( $post_id );
		return ( null !== $post );
	}
	
	/**
	 * Check meta to see if the synced object has an alternative source site which also wants to sync
	 *
	 * @access protected
	 *
	 * @param int $object_id ID of object.
	 *
	 * @return array [][ 'site' => $site_id, 'object' => $object_id, 'time' => $unix_timestamp ]
	 */
	protected function get_alternative_sources( $object_id ) {

		$synced = get_post_meta( $object_id, 'ea-syncable-import-src-alternative-sites', true );

		if ( ! is_array( $synced ) || ! $synced ) {
			return [];
		}

		return $synced;
	}

	/**
	 * Get an instance of a post by ID.
	 *
	 * @access protected
	 *
	 * @param $post_id
	 * @return array|null|\WP_Post
	 */
	protected function get_object( $post_id ) {
		return get_post( $post_id );
	}

	/**
	 * Verify that a post type is available for syncing.
	 *
	 * @access private
	 *
	 * @param string $post_type Post Type
	 * @return bool Eligibility
	 */
	protected function is_post_type_syncable( $post_type ) {

		$post_types = $this->get_syncable_post_types();

		return in_array( $post_type, $post_types );
	}

	/**
	 * Get an array of syncable post type slugs
	 *
	 * @return array
	 */
	protected function get_syncable_post_types() {
		return EA\get_syncable_post_types();
	}

	/**
	 * Get the labels for a given post ID.
	 *
	 * @access protected
	 *
	 * @param int $object_id Post ID.
	 * @return object Labels
	 */
	protected function get_labels( $object_id ) {
		$post_type = get_post_type_object( get_post_type( $object_id ) );
		return $post_type->labels;
	}
}
