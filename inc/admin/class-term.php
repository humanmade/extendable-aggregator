<?php
/**
 * Term-object admin and UI elements.
 *
 * @package WordPress
 * @subpackage Extendable Aggregator
 */

namespace EA\Admin;

use \EA\Syncable;

class Term extends Base {

	/**
	 * Type of object admin UI relates to in WP language.
	 *
	 * string
	 */
	protected $type = 'term';

	/**
	 * Runs all hooks related to object type.
	 */
	public function hooks() {
		add_action( 'init', [ $this, 'term_specific_hooks' ], 10 );
		add_filter( 'editable_slug', [ $this, 'aggregated_state' ], 10, 2 );

		// Default AJAX hooks.
		parent::hooks();
	}

	/**
	 * Apply term row links to all eligible taxonomies.
	 */
	public function term_specific_hooks() {

		/**
		 * Choose whether or not a taxonomy is syncable.
		 *
		 * @param array $taxonomies Eligible taxonomies. Defaults to category and tags.
		 */
		$eligible_taxonomies = apply_filters( 'extendable-aggregator-syncable-taxonomies', [ 'category', 'post_tag' ] );

		foreach ( $eligible_taxonomies as $tax ) {
			// Term row links.
			add_action( $tax . '_row_actions', [ $this, 'row_action_links' ], 10, 2 );
			// Metabox output.
			add_action( $tax . '_edit_form_fields', [ $this, 'metabox_output' ] );
			// Save term meta
			add_action( 'edit_' . $tax,  [ $this, 'save_syncable_meta' ] );
		}
	}

	/**
	 * Modify term action links in the term listing table.
	 *
	 * @param array    $actions Existing actions.
	 * @param \WP_Post $post Post Object.
	 * @return array Modified post actions
	 */
	public function row_action_links( array $actions, \WP_Term $term ) {

		$links = $this->action_links( $actions, $term->term_id );

		if ( isset( $links['detach'] ) ) {
			$links['detach'] = str_replace(
				'data-object-type',
				sprintf( 'data-object-taxonomy="%s" data-object-type', esc_attr( $term->taxonomy ) ),
				$links['detach']
			);
		}

		if ( isset( $links['change_source'] ) ) {
			$links['change_source'] = str_replace(
				'data-object-type',
				sprintf( 'data-object-taxonomy="%s" data-object-type', esc_attr( $term->taxonomy ) ),
				$links['change_source']
			);
		}

		return $links;
	}

	/**
	 * Custom markup for sites metabox on term edit page.
	 */
	public function metabox_output() {
		$term_id = ( isset( $_GET['tag_ID'] ) ) ? $this->sanitize_object_id( $_GET['tag_ID'] ) : false;

		// We don't want this metabox displaying on synced terms.
		if ( ! $term_id || $this->is_synced( $term_id ) || ! $this->can_user_aggregate() || empty( $this->get_blogs() ) ) {
			return;
		}

		?>
		<tr class="form-field term-meta-text-wrap">
			<th scope="row"><label for="term-meta-text"><?php esc_html_e( 'Syndicate this term to:', 'extendable-aggregator' ); ?></label></th>
			<td>
				<?php if ( count( $this->get_blogs() ) > 1 ) : ?>

					<span class="syndicate-toggle">
						<a href="#" class="select-all"><?php esc_html_e( 'Select all', 'extendable-aggregator' ); ?></a>
						<a href="#" class="deselect-all" style="display: none;"><?php esc_html_e( 'Deselect all', 'extendable-aggregator' ); ?></a>
					</span>

				<?php endif; ?>

				<?php $this->meta_fields_markup( $term_id ); ?>
			</td>
		</tr>
		<?php
	}

	/**
	 * Add textual indicator that term has been synced from another site.
	 *
	 * @param string $slug Slug for post/term.
	 * @param mixed  $term Hopefully a WP_Term object, could be other things.
	 * @return $slug (Potentially) modified slug
	 */
	public function aggregated_state( $slug, $term ) {

		// Because WP is WP, this can be used for posts and for terms no info or differently formed info can come in $term.
		// $head->desk->repeat();
		// We only want the terms.
		if ( empty( $term ) || ! $term instanceof \WP_Term ) {
			return $slug;
		}

		$screen = get_current_screen();

		if ( is_admin() && 'edit-tags' === $screen->base && $this->is_synced( $term->term_id ) ) {
			$synced_blog_id = get_term_meta( $term->term_id, 'ea-syncable-import-src-site', true );
			$blog_info      = get_blog_details( $synced_blog_id );

			$slug .= '<strong>' . esc_html( sprintf( __( ' - Synced from %s', 'extendable-aggregator' ), $blog_info->blogname ) ) . '</strong>';
		}
		
		if ( $this->get_alternative_sources( $term->term_id ) ) {
			$slug .= '<strong>' . esc_html(  __( ' - Alternative sources available', 'extendable-aggregator' ) ) . '</strong>';
		}

		return $slug;
	}

	/**
	 * Check that a term exists.
	 *
	 * @access protected
	 *
	 * @param int $post_id Term ID.
	 * @return bool Validity of post.
	 */
	protected function object_exists( $term_id ) {
		$term_id = $this->sanitize_object_id( $term_id );

		if ( ! $term_id ) {
			return false;
		}

		$term = get_term( $term_id );
		return ( ! is_wp_error( $term ) && ! is_null( $term ) );
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

		$alternatives = get_term_meta( $object_id, 'ea-syncable-import-src-alternative-sites', true );

		if ( ! $alternatives || ! is_array( $alternatives ) ) {
			return [];
		}

		return $alternatives;
	}

	/**
	 * Get an instance of a term by ID.
	 *
	 * @access protected
	 *
	 * @param $term_id
	 * @return array|null|\WP_Term|WP_Error
	 */
	protected function get_object( $term_id ) {
		return get_term( $term_id );
	}

	/**
	 * Get the labels for a given term ID.
	 *
	 * @access protected
	 *
	 * @param int $object_id Term ID.
	 * @return object Labels
	 */
	protected function get_labels( $object_id ) {
		$term = get_term( $object_id );
		$tax  = get_taxonomy( $term->taxonomy );
		return get_taxonomy_labels( $tax );
	}
}
