<?php

namespace EA;

/**
 * Class Master
 * @package EA
 */
class Master {

	/**
	 * @var bool $is_initialised Is the master class already initialised?
	 */
	public $is_initialised;

	/**
	 * Plugin definitions.
	 *
	 * @var
	 */
	public $definitions;

	/**
	 * Source site ref.
	 *
	 * Stores a ref to the active site when the singleton was initialised.
	 *
	 * @var
	 */
	public $source_site;

	/**
	 * Master constructor.
	 */
	protected function __construct() {

		$this->source_site = get_current_blog_id();
	}

	/**
	 * Get the singleton class instance
	 *
	 * @return static
	 */
	public static function get_instance() {

		static $instance;

		if ( empty( $instance ) ) {
			$instance = new static();
		}

		return $instance;
	}

	/**
	 * Get a syncable class instance from a provided object
	 *
	 * @param $object
	 * @return Syncable\Base|bool
	 */
	public static function get_syncable( $object ) {

		$syncable = false;

		$classes = static::get_syncable_classes();

		try {

			if ( isset( $object->term_id ) ) {
				$syncable = call_user_func( array( $classes['term'], 'from_object' ), $object );
			}

			if ( ! isset( $object->comment_ID ) && isset( $object->post_type ) && 'attachment' !== $object->post_type ) {
				$syncable = call_user_func( array( $classes['post'], 'from_object' ), $object );
			}

			if ( ! isset( $object->comment_ID ) && isset( $object->post_type ) && 'attachment' === $object->post_type ) {
				$syncable = call_user_func( array( $classes['attachment'], 'from_object' ), $object );
			}

			if ( isset( $object->comment_ID ) ) {
				$syncable = call_user_func( array( $classes['comment'], 'from_object' ), $object );
			}

		} catch ( \Exception $e ) {
			$syncable = false;
		}

		return apply_filters( 'extendable-aggregator-get-syncable-from-object', $syncable, $object );
	}

	/**
	 * Get all syncable classes
	 *
	 * @return mixed|void
	 */
	public static function get_syncable_classes() {

		return apply_filters( 'extendable-aggregator-syncable-classes', [
			'post'       => __NAMESPACE__ . '\\Syncable\\Post',
			'attachment' => __NAMESPACE__ . '\\Syncable\\Attachment',
			'term'       => __NAMESPACE__ . '\\Syncable\\Term',
			'comment'    => __NAMESPACE__ . '\\Syncable\\Comment',
		] );
	}

	/**
	 * Initialise the master singleton
	 */
	public function init( $plugin_definitions ) {

		if ( $this->is_initialised ) {
			return;
		}

		$this->definitions = $plugin_definitions;

		do_action( 'pre-init-extendable-aggregator', $this );
		$this->add_hooks();
		// Register cron on wp_loaded
		add_action( 'wp_loaded', array( $this, 'register_cron' ) );

		$this->is_initialised = true;
	}

	/**
	 * Add a cron schedule for syncing jobs
	 *
	 * @param $schedules
	 * @return mixed
	 */
	public function add_cron_schedule( $schedules ) {

		$schedules['5-mins'] = array(
			'interval'       => 60 * 5,
			'display'        => __( 'Once every 5 minutes', 'extendable-aggregator' ),
		);

		return $schedules;
	}

	/**
	 * Register the cron job for syncing
	 */
	public function register_cron() {
		if ( ! wp_next_scheduled( 'extendable-aggregator-sync-cron' ) ) {

			/**
			 * Allow a project to change the interval for syncing content across sites.
			 *
			 * @param string $interval Name of the registered cron schedule to use for syncing.
			 */
			$interval = apply_filters( 'extendable-aggregator-sync-cron-interval', '5-mins' );
			wp_schedule_event( time(), $interval, 'extendable-aggregator-sync-cron' );
		}
	}

	/**
	 * Add hooks for the master class handling
	 */
	protected function add_hooks() {

		add_filter( 'cron_schedules', [ $this, 'add_cron_schedule' ] );

		foreach ( $this->get_syncable_classes() as $class ) {

			$this->set_syncable_hooks( $class );
		}

		// Run Admin hooks
		Admin\Admin::get_instance()->hooks();

		$this->is_initialised = true;
	}

	/**
	 * Set syncable hooks on syncable classes
	 *
	 * @param $class
	 */
	protected function set_syncable_hooks( $class ) {

		//Callbacks to tag an object for syncing
		foreach ( $class::$sync_hooks as $hook ) {

			add_action( $hook, [ $class, 'insert_callback' ], 10, 5 );
		}

		foreach ( $class::$delete_hooks as $hook ) {

			add_action( $hook, [ $class, 'delete_callback' ], 10, 5 );
		}

		// Callback to run all pending syncs
		add_action( 'extendable-aggregator-sync-cron', [ $class, 'execute_queued_actions' ] );

		// Callback to save syncing actions as current thread exits
		add_action( 'shutdown',  [ $class, 'save_actions' ] );
	}
}
