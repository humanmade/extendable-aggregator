<?php
/**
 * Base abstract class for admin/UI elements.
 *
 * @package WordPress
 * @subpackage Extendable Aggregator
 */

namespace EA\Admin;

use EA\Master;

abstract class Base {

	/**
	 * Type of object admin UI relates to in WP language.
	 *
	 * [post, term, attachment]
	 *
	 * string
	 */
	protected $type;

	/**
	 * Syncable class name for type.
	 *
	 * string
	 */
	protected $syncable;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->syncable = $this->get_syncable();
	}

	/**
	 * Get a syncable class name for use in static checks.
	 *
	 * @return string Class name
	 */
	public function get_syncable() {
		$classes = Master::get_syncable_classes();
		$class   = $classes[ $this->type ];
		return $class;
	}

	/**
	 * Get the singleton class instance
	 *
	 * @return static Base
	 */
	public static function get_instance() {

		static $instance;

		if ( empty( $instance ) ) {
			$instance = new static();
		}

		return $instance;
	}

	/**
	 * Runs all hooks related to object type.
	 */
	public function hooks() {
		add_action( 'admin_notices', [ $this, 'synced_object_message' ] );
		add_filter( 'admin_body_class', [ $this, 'admin_body_class' ], 15, 1 );
		add_action( "wp_ajax_ea_{$this->type}_detach", [ $this, 'ajax_detach' ] );
		add_action( "wp_ajax_ea_{$this->type}_reattach", [ $this, 'ajax_reattach' ] );
		add_action( "wp_ajax_ea_{$this->type}_change_source", [ $this, 'ajax_change_source' ] );
		add_action( "wp_ajax_ea_{$this->type}_sync_new", [ $this, 'ajax_sync_new' ] );
	}

	/**
	 * Checks whether or not an object is synced and attached.
	 *
	 * This covers the base synced meta, an object being detached, and whether or not the object
	 * was only created or synced.
	 *
	 * @access protected
	 *
	 * @param int $object_id Post or term ID
	 * @return bool True for synced object, false for not-synced
	 */
	public function is_synced( $object_id ) {
		return (
			(bool) $this->get_synced_meta( $object_id )
			&& ! $this->is_destination_detached( $object_id )
			&& 'create' !== $this->get_import_method( $object_id )
		);
	}

	/**
	 * Checks whether or not an object is synced and detached.
	 *
	 * This covers the base synced meta, an object being attached, and whether or not the object
	 * was only created or synced.
	 *
	 * @access protected
	 *
	 * @param int $object_id Post or term ID
	 * @return bool True for synced object, false for not-synced
	 */
	public function is_synced_detached( $object_id ) {
		return (
			(bool) $this->get_synced_meta( $object_id )
			&&  $this->is_destination_detached( $object_id )
			&& 'create' !== $this->get_import_method( $object_id )
		);
	}

	/**
	 * Get the import method of an object.
	 *
	 * @param int $object_id Post or term ID
	 * @return string 'create' or 'sync'
	 */
	protected function get_import_method( $object_id ) {
		$syncable = $this->syncable;
		return $syncable::get_meta( $object_id, 'ea-syncable-import-method' );
	}

	/**
	 * Checks whether or not an object is actively syncable to a destination.
	 *
	 * Intended to be run from source site.
	 *
	 * @access protected
	 *
	 * @param int $object_id           Post or term ID.
	 * @param int $destination_site_id Destination site ID to check.
	 * @return bool True for syncing, false for not-syncing
	 */
	public function is_syncable( $object_id, $destination_site_id ) {
		$syncable = $this->syncable;
		return $syncable::get_is_syncable( $destination_site_id, $object_id );
	}

	/**
	 * Checks whether or not a destination object is detached from source.
	 *
	 * Intended to be run from destination site.
	 *
	 * @access protected
	 *
	 * @param int $object_id Post or term ID
	 * @return bool True for detached object, false for never-detached
	 */
	public function is_destination_detached( $object_id ) {
		$syncable = $this->syncable;
		return $syncable::destination_is_detached( $object_id );
	}

	/**
	 * Checks whether or not a source object is detached from destination.
	 *
	 * @access protected
	 *
	 * @param int $object_id           Post or term ID.
	 * @param int $destination_site_id Destination site ID to check.
	 * @return bool True for detached object, false for never-detached
	 */
	public function is_source_detached( $object_id, $destination_site_id ) {
		$syncable = $this->syncable;
		return $syncable::source_is_detached( $object_id, $destination_site_id );
	}

	/**
	 * Get metadata indicating whether or not the post is synced and where it's synced to.
	 *
	 * @access protected
	 *
	 * @param int $object_id Post or term ID.
	 * @return int|bool Source site ID or false for none-found
	 */
	protected function get_synced_meta( $object_id ) {
		$syncable = $this->syncable;
		return $syncable::get_meta( $object_id, 'ea-syncable-import-src-site' );
	}

	/**
	 * Get metadata pointing to which sites the object is syncable to.
	 *
	 * @access protected
	 *
	 * @param int $object_id Post or term ID.
	 * @return array Sites object is syncable to.
	 */
	public function get_syncing_meta( $object_id ) {
		$syncable = $this->syncable;
		return $syncable::get_meta( $object_id, sprintf( 'ea-syncable-%s-syncable-sites', $this->type ) );
	}

	/**
	 * Check nonce and permissions on every AJAX request.
	 *
	 * @access protected
	 */
	protected function check_ajax_request() {
		check_ajax_referer( 'extendable-aggregator-ajax', 'nonce' );

		if ( ! $this->can_user_aggregate() ) {
			wp_send_json_error( __( 'User does not have correct permissions.', 'extendable-aggregator' ) );
		}
	}

	/**
	 * Detach handler for AJAX.
	 */
	public function ajax_detach() {
		// Check nonce and permissions first.
		$this->check_ajax_request();

		// Fetch, clean, and verify the object ID.
		$object_id = $this->ajax_object_exists();

		$syncable = $this->syncable;
		$syncable::destination_set_is_detached( $object_id, true );

		wp_send_json_success( sprintf( __( '%s %d detached.', 'extendable-aggregator' ), $this->type, $object_id ) );
	}

	/**
	 * Reattach handler for AJAX.
	 */
	public function ajax_reattach() {
		// Check nonce and permissions first.
		$this->check_ajax_request();

		// Fetch, clean, and verify the object ID.
		$object_id = $this->ajax_object_exists();

		$syncable = $this->syncable;
		$syncable::destination_set_is_detached( $object_id, false );

		$src_site = $syncable::get_meta( $object_id, 'ea-syncable-import-src-site' );
		$src_id   = $syncable::get_meta( $object_id, 'ea-syncable-import-src-id' );
		$cur_site = get_current_blog_id();

		// @codingStandardsIgnoreStart (fine for VIP Go)
		switch_to_blog( $src_site );
		// @codingStandardsIgnoreEnd

		// Instantly sync the object back up.
		$sync = \EA\Master::get_instance()->get_syncable( $this->get_object( $src_id ) );
		$sync->sync_to( $cur_site );

		restore_current_blog();

		wp_send_json_success( sprintf( __( '%s %d reattached.', 'extendable-aggregator' ), $this->type, $object_id ) );
	}


	/**
	 * Reattach handler for AJAX.
	 */
	public function ajax_change_source() {

		// Check nonce and permissions first.
		$this->check_ajax_request();

		// Fetch, clean, and verify the object ID.
		$object_id       = $this->ajax_object_exists();
		$new_source_site = absint( $_POST['site_id'] );

		$syncable = Master::get_syncable( $this->get_object( $object_id ) );

		$syncable->switch_source( $new_source_site );

		wp_send_json_success( sprintf( __( '%s %d attached to new source site.', 'extendable-aggregator' ), $this->type, $object_id ) );
	}

	/**
	 * Sync an object via AJAX request.
	 */
	public function ajax_sync_new() {
		// Check nonce and permissions first.
		$this->check_ajax_request();

		// Fetch, clean, and verify the post ID.
		$object_id = $this->ajax_object_exists();

		$site_id = $this->sanitize_object_id( $_POST['site_id'] );

		if ( false === $site_id ) {
			wp_send_json_error( __( 'Site ID is not valid', 'extendable-agregator' ) );
		}

		// Setup the new sync work.
		$sync = \EA\Master::get_instance()->get_syncable( $this->get_object( $object_id ) );
		$sync->sync_to( $site_id );
		$sync::set_is_syncable( $site_id, $object_id, true );

		wp_send_json_success( sprintf(
			__( 'Sync of %s %d to site %d started', 'extendable-aggregator' ),
			$this->type,
			$object_id ,
			$site_id
		) );
	}

	/**
	 * Check that if an object exists and return an error/exit if it doesn't.
	 *
	 * This function only runs after nonce has been evaluated in an AJAX action.
	 *
	 * @access protected
	 *
	 * @return int Object ID
	 */
	protected function ajax_object_exists() {
		// Parse object ID and check that object exists.
		$object_id  = $this->sanitize_object_id( $_POST['object_id'] );

		// Object does not exist, send error and exit;
		if ( false === $object_id || ! $this->object_exists( $object_id ) ) {
			wp_send_json_error(
				sprintf(
					__( '%s %d does not exist', 'extendable-aggregator' ),
					$this->type,
					$object_id
				)
			);
		}

		return $object_id;
	}

	/**
	 * Output the markup for meta fields for syncing an object to another site.
	 *
	 * @access protected
	 *
	 * @param $object_id
	 */
	protected function meta_fields_markup( $object_id ) {
		$blogs = $this->get_blogs();

		// Found no blogs, nothing to print so let's bounce.
		if ( empty( $blogs ) ) {
			return;
		}

		// Add a nonce field for checking when we input.
		wp_nonce_field( basename( __FILE__ ), 'synced-to-sites-nonce' );

		foreach ( $blogs as $blog_id => $blog_name ) :
			/**
			 * Customise the display name for blogs in the admin meta box.
			 *
			 * @param string $blog_name The blog display name.
			 * @param int    $blog_id   The blog ID.
			 */
			$blog_name = apply_filters( 'extendable-aggregator-blog-name', $blog_name, $blog_id );
			// Is object currently and actively syncing to destination.
			$is_syncing = ( $this->is_syncable( $object_id, $blog_id ) && ! $this->is_source_detached( $object_id, $blog_id ) );
			// Is site currently syncing, or has been detached.
			$disabled   = ( $this->is_syncable( $object_id, $blog_id ) || $this->is_source_detached( $object_id, $blog_id ) ) ? 'disabled' : '';
			?>
			<input type="checkbox" class="consumer-sites-item" name="consumer_sites[]" value="<?php echo esc_attr( $blog_id ); ?>" id="consumer-sites-<?php echo esc_attr( $blog_id ); ?>" <?php echo esc_attr( $disabled ); ?> <?php checked( true, $is_syncing ); ?>>
			<label for="consumer-sites-<?php echo esc_attr( $blog_id ); ?>">
				<?php echo esc_html( $blog_name ); ?>
				<?php if ( $this->is_source_detached( $object_id, $blog_id ) ) : ?>
					<span class='ea-alert'><span><?php esc_html_e( 'This post has been detached and must be re-attached from the destination site.', 'extendable-aggregator' ); ?></span></span>
				<?php endif; ?>
				<?php do_action( 'extendable-aggregator-sync-site-links', $this->type, $blog_id, $object_id, $this ); ?>
			</label><br>
		<?php endforeach;
	}

	/**
	 * Upon object updating, either add sync job for object or remove job.
	 *
	 * @param int $object_id
	 * @return bool false if failure.
	 */
	public function save_syncable_meta( $object_id ) {
		$saved = false;

		// Check that the user is logged in and has proper permissions to sync/detach.
		if ( ! $this->can_user_aggregate() ) {
			return false;
		}

		// Save site values.
		if ( isset( $_POST['consumer_sites'] ) ) {
			/**
			 * $_POST value is parsed and cleaned in save_consumer_site_values().
			 * Also, nonce is validated in the verify_consumer_site_values() method triggered in save_consumer_site_values()
			 *
			 * No data can be saved without the nonce and data being validated.
			 */
			$saved = $this->save_consumer_site_values( $object_id, $_POST['consumer_sites'] );
		}

		/**
		 * Hook for saving other various meta objects.
		 *
		 * @param int  $object_id Post or term ID.
		 * @param bool $saved Whether or not the save was successful.
		 */
		do_action( 'extendable-aggregator-save-object-meta', $object_id, $saved );

		return $saved;
	}

	/**
	 * Saves the values submitted through a post, term, or attachment save.
	 *
	 * Objects are only detachable or re-attachable from the destination side, so the only scenario
	 * in which this method does anything is if we are adding a destination site to sync to.
	 *
	 * @param int $object_id Object ID to save against.
	 * @param array $consumer_sites Sites to sync against.
	 */
	protected function save_consumer_site_values( $object_id, $consumer_sites ) {
		$blogs = $this->get_blogs();

		// Evaluate and validate nonce and check for empty array.
		if ( ! $this->verify_consumer_site_values( $consumer_sites ) ) {
			return false;
		}

		// Validate and cast IDs as integers.
		$consumer_sites = array_map( [ $this, 'sanitize_object_id' ], $consumer_sites );

		foreach ( $blogs as $blog_id => $blog_name ) {
			// Check if we already have a relationship setup for this site.
			$relationship = ( $this->is_syncable( $object_id, $blog_id ) );
			$sync = \EA\Master::get_instance()->get_syncable( $this->get_object( $object_id ) );

			// If setting and relationship already match, or has already been detached then do nothing.
			if ( in_array( $blog_id, $consumer_sites ) === $relationship || $this->is_source_detached( $object_id, $blog_id ) ) {
				continue;

			// If we've checked this site, but don't have a relationship - set the post to syncable.
			} elseif ( in_array( $blog_id, $consumer_sites ) && ! $relationship ) {
				// Setup sync job for object.
				$sync::set_is_syncable( $blog_id, $object_id, true );
			}
		}

		return true;
	}

	/**
	 * Verify our consumer site values and metadata.
	 *
	 * @param array $consumer_sites Data being submitted
	 * @return bool
	 */
	protected function verify_consumer_site_values( $consumer_sites ) {
		// Verify that our request is coming from the appropriate place.
		if ( ! isset( $_POST['synced-to-sites-nonce'] ) || ! wp_verify_nonce( wp_unslash( $_POST['synced-to-sites-nonce'] ), basename( __FILE__ ) ) ) {
			return false;
		}

		// Check that we actually have the value coming through.
		if ( empty( $consumer_sites ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Simple, small method for validating and sanitizing a post/term/site ID.
	 *
	 * @param int $object_id
	 * @return bool|int
	 */
	function sanitize_object_id( $object_id ) {
		$object_id = wp_unslash( $object_id );

		if ( ! is_numeric( $object_id ) || $object_id != floor( $object_id ) || ! $object_id ) {
			return false;
		}

		return (int) $object_id;
	}

	/**
	 * Check whether an object exists.
	 *
	 * @access protected
	 *
	 * @param int $object_id ID of object.
	 */
	abstract protected function object_exists( $object_id );

	/**
	 * Get an instance of a object by ID.
	 *
	 * @access protected
	 *
	 * @param int $object_id ID of object.
	 */
	abstract protected function get_object( $object_id );

	/**
	 * Check meta to see if the synced object has an alternative source site which also wants to sync
	 *
	 * @access protected
	 *
	 * @param int $object_id ID of object.
	 */
	abstract protected function get_alternative_sources( $object_id );

	/**
	 * Check that a user has permissions to sync and detach.
	 *
	 * Checks whether a user is logged in and has the minimum capability to sync and detach.
	 *
	 * @access protected
	 * @return bool
	 */
	public function can_user_aggregate() {
		/**
		 * Minimum capability that a user must have to detach or sync an object.
		 *
		 * Defaults to 'edit_others_posts' to give editors and up access to
		 * the capability.
		 *
		 * @param string Capability.
		 */
		$permission_level = apply_filters( 'extendable-aggregator-sync-permission-level', 'edit_others_posts' );

		return current_user_can( $permission_level );
	}

	/**
	 * Get a list of all other sites available on the network.
	 *
	 * Fetches blog ID and name for all blogs on the network and skips the current blog.
	 *
	 * @param string $filter 'sources' or 'destinations'.
	 * @return array Array of syncable sites.
	 */
	public function get_blogs( $filter = 'destinations' ) {
		return \EA\get_syncable_sites( $filter );
	}

	/**
	 * Setup basic links for post or term listing row.
	 *
	 * Links that will be removed if a post is synced:
	 *   Edit
	 *   Quick Edit
	 *
	 * Links that will be added:
	 *   Detach    (if synced)
	 *   Publish   (if synced and draft)
	 *   Sync to X (if not synced)
	 *
	 * @access protected
	 *
	 * @param array $action    Default Actions on the item.
	 * @param int   $object_id Post or term ID.
	 * @return array $action links
	 */
	protected function action_links( $actions, $object_id ) {

		/**
		 * Object is synced, remove the unnecessary action links and add an aggregated one.
		 */
		if ( $this->is_synced( $object_id ) ) {
			// Remove edit and inline-edit links as user should not be able to edit the object.
			unset( $actions['edit'] );
			unset( $actions['inline hide-if-no-js'] );

			$bloginfo = get_blog_details( $this->get_synced_meta( $object_id ) );

			if ( $this->can_user_detach( $object_id ) ) {
				$actions['detach'] = sprintf( '<a class="ea-action" data-object-type="%1$s" data-object-id="%2$s" data-ajax-action="%3$s" href="%4$s">%5$s</a>',
					esc_attr( $this->type ),
					esc_attr( $object_id ),
					'detach',
					'javascript:void(0);',
					esc_html( sprintf( __( 'Detach from %s', 'extendable-aggregator' ), $bloginfo->blogname ) )
				);
			}

			if ( \EA\get_source_url( $object_id, $this->type ) ) {
				$actions['original'] = sprintf(
					'<a href="%s">%s</a>.',
					esc_url( \EA\get_source_url( $object_id, $this->type ) ),
					esc_html__( 'View the original', 'extendable-aggregator' )
				);
			}

			if ( $this->can_user_aggregate() && $this->get_alternative_sources( $object_id ) ) {

				$sources = $this->get_alternative_sources( $object_id );

				foreach( $sources as $source ) {

					$blog_details = get_blog_details( [
						'blog_id' => $source['site']
					] );

					if ( ! $blog_details ) {
						continue;
					}

					$actions['change_source'] = sprintf( '<a class="ea-action" data-object-type="%1$s" data-object-id="%2$s" data-ajax-action="%3$s" href="%4$s" data-site-id="%5$s" >%6$s</a>',
						esc_attr( $this->type ),
						esc_attr( $object_id ),
						'change_source',
						'javascript:void(0);',
						esc_attr( $source['site'] ),
						// Translators: %1$s is replaced with a site name, e.g 'Global'.
						esc_html( sprintf( __( 'Attach to %1$s', 'extendable-aggregator' ), $blog_details->blogname ) )
					);
				}
			}

		/**
		 * Object is not a synced child object - add aggregation links
		 */
		} else {

			$sites = $this->get_blogs();

			if ( ! $this->can_user_aggregate() ) {
				return $actions;
			}

			// If destination object has been detached, give option to re-attach
			if ( $this->is_destination_detached( $object_id ) ) {

				$bloginfo = get_blog_details( $this->get_synced_meta( $object_id ) );

				$actions['reattach'] = sprintf( '<a class="ea-action" data-object-type="%1$s" data-object-id="%2$s" data-ajax-action="%3$s" href="%4$s">%5$s</a>',
					esc_attr( $this->type ),
					esc_attr( $object_id ),
					'reattach',
					'javascript:void(0);',
					esc_html( sprintf( __( 'Re-attach to %s', 'extendable-aggregator' ), $bloginfo->blogname ) )
				);

				if ( \EA\get_source_url( $object_id, $this->type ) ) {
					$actions['original'] = sprintf(
						'<a href="%s">%s</a>.',
						esc_url( \EA\get_source_url( $object_id, $this->type ) ),
						esc_html__( 'View the original', 'extendable-aggregator' )
					);
				}
			}

			/**
			 * Choose whether or not to show the aggregation sync action links on the listing table.
			 *
			 * @param bool   $show_links Whether or not to show aggregation action links. Default true.
			 * @param int    $object_id  Post or term ID.
			 * @param string $type       The object type. Usually 'post' or 'term'.
			 */
			$show_sync_actions = apply_filters( 'extendable-aggregator-show-sync-action-links', true, $object_id, $this->type );

			if ( $show_sync_actions && ! empty( $sites ) ) {

				foreach ( $sites as $blog_id => $blog_name ) {
					// If we've already synced to this site or have detached then we don't want the link.
					if ( $this->is_syncable( $object_id, $blog_id ) || $this->is_source_detached( $object_id, $blog_id ) || $this->is_destination_detached( $object_id ) ) {
						continue;
					}

					$actions[ 'aggregate_' . $blog_id ] = sprintf( '<a class="ea-action" data-object-type="%1$s" data-object-id="%2$s" data-site-id="%3$s" data-ajax-action="%4$s" href="%5$s">%6$s</a>',
						esc_attr( $this->type ),
						esc_attr( $object_id ),
						esc_attr( $blog_id ),
						'sync_new',
						'javascript:void(0);',
						esc_html( sprintf( __( 'Sync to %s', 'extendable-aggregator' ),  $blog_name ) )
					);
				}
			}

		}

		return $actions;
	}

	/**
	 * Add a class to the body of post/term edit screens to allow editing functionality to be blocked/fuzzied.
	 *
	 * @param string $classes String of existing classes on the admin body
	 * @return string Modified classes
	 */
	public function admin_body_class( $classes ) {
		$object_id = $this->get_edit_screen_object_id( get_current_screen() );

		if ( $object_id && $this->is_synced( $object_id ) ) {
			$classes .= ' synced-object';
		}

		return $classes;
	}

	/**
	 * Add alert with notice and info about detaching.
	 *
	 * Since we've turned off all editing on synced terms, we need a message indicating what's happened on the object.
	 * This alert box will inform a user that the object is detached and that they can detach it (if they can).
	 *
	 * This will be very rarely be seen as we've turned off most edit links.
	 */
	public function synced_object_message() {
		$object_id = $this->get_edit_screen_object_id( get_current_screen() );

		// Check the object ID.
		if ( ! $object_id || ( ! $this->is_synced( $object_id ) && ! $this->is_synced_detached( $object_id ) ) ) {
			return false;
		}

		$this->admin_notice( $object_id );
	}

	/**
	 * Get and sanitize an object ID using the available screen and GET variables.
	 *
	 * @param \WP_Screen $screen Screen object
	 * @return bool|int False for no valid ID, integer if valid
	 */
	protected function get_edit_screen_object_id( $screen ) {
		$object_id = false;

		if ( isset( $_GET['post'] ) && 'post' === $screen->base ) {
			if (
				( 'attachment' === get_post_type( absint( $_GET['post'] ) ) && 'attachment' === $this->type )
				|| ( 'post' === $this->type && $this->is_post_type_syncable( get_post_type( $_GET['post'] ) ) )
			) {
				$object_id = $this->sanitize_object_id( $_GET['post'] );
			}
		} elseif ( isset( $_GET['tag_ID'] ) && 'term' === $screen->base && 'term' === $this->type ) {
			$object_id = $this->sanitize_object_id( $_GET['tag_ID'] );
		}

		return $object_id;
	}

	/**
	 * Prints an admin notice that an object has is synced and cannot be edited.
	 *
	 * @access protected
	 *
	 * @param $object_id
	 */
	protected function admin_notice( $object_id ) {

		$labels    = $this->get_labels( $object_id );
		$view_link = '';

		if ( \EA\get_source_url( $object_id, $this->type ) ) {
			$view_link = sprintf(
				'<a href="%s">%s</a>.',
				esc_url( \EA\get_source_url( $object_id, $this->type ) ),
				esc_html__( 'View the original', 'extendable-aggregator' )
			);
		}

		if ( $this->can_user_detach( $object_id ) && $this->is_synced( $object_id ) ) {
			$link = sprintf( '<a class="ea-action" data-object-type="%1$s" data-object-id="%2$s" data-ajax-action="%3$s" href="%4$s">%5$s</a>',
				esc_attr( $this->type ),
				absint( $object_id ),
				'detach',
				'javascript:void(0);',
				/* Translator: Link within edit-post synced message */
				esc_html__( 'detach', 'extendable-aggregator' )
			);

			$message = sprintf(
				__( 'This %s is synced with from another site and cannot be edited. To edit this post, please %s it. %s', 'extendable-aggregator' ),
				strtolower( $labels->singular_name ),
				$link,
				$view_link
			);
		} else if ( $this->is_synced_detached( $object_id ) ) {

			$message = sprintf(
				__( 'This %s is synced with from another site but has been detached from the original. %s', 'extendable-aggregator' ),
				strtolower( $labels->singular_name ),
				$view_link
			);

		} else {
			$message = sprintf(
				__( 'This %s is synced from another site and cannot be edited. %s', 'extendable-aggregator' ),
				strtolower( $labels->singular_name ),
				( ! empty( $view_link ) ) ? $view_link : ''
			);
		}

		/**
		 * Filter the sync locked message for the object edit screen
		 *
		 * @param string $message      The unfiltered message
		 * @param string $object_type  The type of object (post|attachment|term)
		 * @param int $object_id       The ID of the current object
		 * @param \stdClass $labels    The labels object of the current objecct
		 * @param bool $can_detach     Whether or not the object can be detached
		 * @return string              The filtered message
		 */
		$message = apply_filters( 'extendable-aggregator-sync-locked-message', $message, $this->type, $object_id, $labels, $this->can_user_detach( $object_id ), $view_link );

		printf(
			'<div class="notice notice-error"><p>%s</p></div>',
			wp_kses(
				$message,
				[
					'a' => [
						'href'             => [],
						'class'            => [],
						'data-object-type' => [],
						'data-object-id'   => [],
						'data-ajax-action' => [],
					],
				],
				'javascript'
			)
		);
	}

	/**
	 * For a given synced object ID, check if the current user can detach the synced object from the source
	 *
	 * @param int $object_id The supplied object ID
	 * @return bool Whether or not the user can detach the object defined by $object_id
	 */
	protected function can_user_detach( $object_id ) {

		$can_detach = $this->can_user_aggregate();

		/**
		 * Filter whether or not the given synced object is detachable from the source
		 *
		 * @param bool $can_detach Can the object be detached
		 * @param bool $object_id  The object ID
		 */
		return (bool) apply_filters( 'extendable-aggregator-can-user-detach', $can_detach, $this->type, $object_id );
	}
}
