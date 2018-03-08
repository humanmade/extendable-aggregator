<?php
/**
 * Admin class to call all admin/UI elements.
 *
 * @package WordPress
 * @subpackage Extendable Aggregator
 */

namespace EA\Admin;

class Admin {

	/**
	 * Plugin-related definitions.
	 *
	 * array
	 */
	private $definitions;

	/**
	 * Admin constructor.
	 *
	 * Used to fetch plugin definitions for assets access.
	 */
	public function __construct() {
		$this->definitions = \EA\Master::get_instance()->definitions;
	}

	/**
	 * Get the singleton class instance
	 *
	 * @return static Admin
	 */
	public static function get_instance() {

		static $instance;

		if ( empty( $instance ) ) {
			$instance = new static();
		}

		return $instance;
	}

	/**
	 * Run hooks related to admin/UI related functionality.
	 */
	public function hooks() {
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue' ], 10 );

		// Hook in our admin modifications per syncable type.
		foreach ( $this->get_admin_classes() as $class ) {

			$class::get_instance()->hooks();
		}
	}

	/**
	 * Get all admin classes
	 *
	 * @return mixed|void
	 */
	public static function get_admin_classes() {

		return apply_filters( 'extendable-aggregator-admin-classes', [
			'post'       => __NAMESPACE__ . '\\Post',
			'attachment' => __NAMESPACE__ . '\\Attachment',
			'term'       => __NAMESPACE__ . '\\Term',
		] );
	}

	/**
	 * Enqueue required styles and scripts.
	 */
	public function enqueue() {
		wp_enqueue_style( 'extendable_aggregator_styles', $this->definitions['assets_url'] . '/aggregator.css', [], $this->definitions['version'] );
		wp_enqueue_script( 'extendable_aggregator_main', $this->definitions['assets_url'] . '/aggregator.js', [], $this->definitions['version'] );
		wp_enqueue_script( 'extendable_aggregator_media', $this->definitions['assets_url'] . '/aggregator-media.js', [ 'extendable_aggregator_main', 'media' ], $this->definitions['version'] );

		// Send in a nonce for security.
		wp_localize_script( 'extendable_aggregator_main', 'eaValues', [
			'nonce' => wp_create_nonce( 'extendable-aggregator-ajax' ),
			'page'  => esc_js( $this->get_current_page() ),
			'links' => [
				'post'     => get_admin_url() . 'post.php?post={{ID}}&action=edit',
				'term'     => get_admin_url() . 'term.php?taxonomy={{taxonomy}}&tag_ID={{ID}}',
				'viewPost' => site_url() . '?p={{ID}}',
			],
			'text' => [
				'detach'   => __( 'Detach', 'extendable-aggregator' ),
				'reattach' => __( 'Reattach', 'extendable-aggregator' ),
				'publish'  => __( 'Publish', 'extendable-aggregator' ),
				'edit'     => __( 'Edit', 'extendable-aggregator' ),
				'view'     => __( 'View', 'extendable-aggregator' ),
			],
		] );
	}

	/**
	 * Get the current page base.
	 *
	 * @return bool|string
	 */
	private function get_current_page() {
		$screen = get_current_screen();

		if ( ! is_admin() || null === $screen ) {
			return false;
		}

		return $screen->base;
	}
}
