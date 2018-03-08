<?php
/**
 * Attachment-object admin and UI elements.
 *
 * @package WordPress
 * @subpackage Extendable Aggregator
 */

namespace EA\Admin;

// @todo:: Add metabox for syncing to sites for attachments

class Attachment extends Base {

	/**
	 * Type of object admin UI relates to in WP language.
	 *
	 * string
	 */
	protected $type = 'attachment';

	/**
	 * Runs all hooks related to object type.
	 */
	public function hooks() {
		add_action( 'media_row_actions', [ $this, 'row_action_links' ], 10, 2 );
		add_filter( 'wp_prepare_attachment_for_js', [ $this, 'modify_attachment_js_data' ], 25, 2 );
		add_filter( 'attachment_fields_to_edit', [ $this, 'attachment_edit_fields' ], 999, 2 );
		add_filter( 'attachment_fields_to_save', [ $this, 'save_attachment_meta' ], 25, 2 );
		add_action( 'print_media_templates', [ $this, 'modified_backbone_templates' ], 10 );

		// Default AJAX hooks.
		parent::hooks();
	}

	/**
	 * Modify post action links in the post listing table.
	 *
	 * @param array    $actions Existing actions.
	 * @param \WP_Post $post Post Object.
	 * @return array Modified post actions
	 */
	public function row_action_links( array $actions, \WP_Post $post ) {

		return $this->action_links( $actions, $post->ID );
	}

	/**
	 * Add our metadata on being synced for the WP Media library JS.
	 *
	 * @param array   $response   Array of prepared attachment data.
	 * @param WP_Post $attachment Attachment ID or object.
	 * @param array   $meta       Array of attachment meta data.
	 * @return array (Potentially) modified attachment data
	 */
	public function modify_attachment_js_data( $response, $attachment ) {

		// If the attachment is synced, add some data to the return.
		if ( $this->is_synced( $attachment->ID ) ) {
			// Send a flag about the attachment being synced over.
			$bloginfo = get_blog_details( $this->get_synced_meta( $attachment->ID ) );
			$response['ea_synced'] = $bloginfo->blogname;

			// Wipe the edit nonces and link to force all core fields into readonly mode.
			$response['editLink']  = false;
			$response['nonces']['update'] = false;
			$response['nonces']['edit'] = false;
		}

		return $response;
	}

	/**
	 * On synced posts, block all custom edit fields from populating.
	 *
	 * @param array $form_fields An array of attachment form fields.
	 * @param WP_Post $post The WP_Post attachment object.
	 * @return array Empty array on synced object, or full edit fields array.
	 */
	public function attachment_edit_fields( $form_fields, $post ) {
		if ( $this->is_synced( $post->ID ) ) {
			return [];
		} elseif ( ! $this->is_destination_detached( $post->ID ) ) {

			ob_start();
			$this->meta_fields_markup( $post->ID );
			$html = ob_get_clean();

			$form_fields[] = [
				'label' => __( 'Syndicate this media to:', 'extendable-aggregator' ),
				'input' => 'html',
				'html'  => $html,
			];
		}

		return $form_fields;
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
			// Is object currently and actively syncing to destination.
			$is_syncing = ( $this->is_syncable( $object_id, $blog_id ) && ! $this->is_source_detached( $object_id, $blog_id ) );
			// Is site currently syncing, or has been detached.
			$disabled   = ( $this->is_syncable( $object_id, $blog_id ) || $this->is_source_detached( $object_id, $blog_id ) ) ? 'disabled' : '';
			?>
			<input type="checkbox" name="attachments[<?php echo esc_attr( $object_id ); ?>][consumer_sites]" value="<?php echo esc_attr( $blog_id ); ?>" id="consumer-sites-<?php echo esc_attr( $blog_id ); ?>" <?php echo esc_attr( $disabled ); ?> <?php checked( true, $is_syncing ); ?>>
			<label for="consumer-sites-<?php echo esc_attr( $blog_id ); ?>">
				<?php echo esc_html( $blog_name ); ?>
				<?php if ( $this->is_source_detached( $object_id, $blog_id ) ) : ?>
					<span class='ea-alert'><span><?php esc_html_e( 'This attachment has been detached and must be re-attached from the destination site.', 'extendable-aggregator' ); ?></span></span>
				<?php endif; ?>
			</label><br>
		<?php endforeach;
	}

	/**
	 * Saved attachment metadata.
	 *
	 * Not using the base method because attachments save using different variables and methods.
	 *
	 * @param array $post $_POST values.
	 * @param array $attachment Custom values saved in form.
	 */
	public function save_attachment_meta( $post, $attachment ) {
		// Check that the user is logged in and has proper permissions to sync/detach.
		if ( ! $this->can_user_aggregate() || ! isset( $attachment['consumer_sites'] ) ) {
			return $post;
		}

		// Save site values.
		$this->save_consumer_site_values( $post['ID'], (array) $attachment['consumer_sites'] );

		return $post;
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
	 * Wrapper for printing modified backbone templates.
	 *
	 * @see EA\modified_backbone_templates()
	 */
	public function modified_backbone_templates() {
		\EA\modified_backbone_templates();
	}

		/**
	 * Check that an attachment exists.
	 *
	 * @access protected
	 *
	 * @param int $post_id Post ID.
	 * @return bool Validity of post.
	 */
	protected function object_exists( $post_id ) {
		$post = get_post( $this->sanitize_object_id( $post_id ) );
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
	 * @return null|\WP_Post
	 */
	protected function get_object( $post_id ) {
		return get_post( $post_id );
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
