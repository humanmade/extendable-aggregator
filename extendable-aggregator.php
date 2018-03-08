<?php
/*
Plugin name: Extendable Aggregator
Description: Aggregates articles across multisite install
Author: Human Made Limited
Version: 1.0.0
*/

namespace EA;

require_once __DIR__ . '/inc/class-master.php';
require_once __DIR__ . '/inc/syncable/class-base-interface.php';
require_once __DIR__ . '/inc/syncable/class-base.php';
require_once __DIR__ . '/inc/syncable/class-post.php';
require_once __DIR__ . '/inc/syncable/class-attachment.php';
require_once __DIR__ . '/inc/syncable/class-term.php';
require_once __DIR__ . '/inc/syncable/class-comment.php';

// Admin/UI functionality.
require_once __DIR__ . '/inc/admin/backbone-templates.php';
require_once __DIR__ . '/inc/admin/class-admin.php';
require_once __DIR__ . '/inc/admin/class-base.php';
require_once __DIR__ . '/inc/admin/class-post.php';
require_once __DIR__ . '/inc/admin/class-term.php';
require_once __DIR__ . '/inc/admin/class-attachment.php';

// General/Utilities
require_once __DIR__ . '/inc/utilities.php';

// Canonical lookup utilities
require_once __DIR__ . '/inc/canonical-lookups/post.php';
require_once __DIR__ . '/inc/canonical-lookups/term.php';
require_once __DIR__ . '/inc/canonical-lookups/comment.php';

if ( defined( 'WP_CLI' ) ) {
	require_once __DIR__ . '/inc/cli/sync.php';
	require_once __DIR__ . '/inc/cli/detach.php';
	require_once __DIR__ . '/inc/cli/resync.php';

	\WP_CLI::add_command( 'ea-sync', __NAMESPACE__ . '\\CLI\\Sync' );
	\WP_CLI::add_command( 'ea-detach', __NAMESPACE__ . '\\CLI\\Detach' );
	\WP_CLI::add_command( 'ea-resync', __NAMESPACE__ . '\\CLI\\Resync' );
}

function init() {

	$plugin_definitions = [
		'basename'   => plugin_basename( __FILE__ ),
		'directory'  => plugin_dir_path( __FILE__ ),
		'file'       => __FILE__,
		'slug'       => 'extendable-aggregator',
		'url'        => plugin_dir_url( __FILE__ ),
		'assets_url' => plugin_dir_url( __FILE__ ) . '/assets',
		'version'    => '1.0.1',
	];

	Master::get_instance()->init( $plugin_definitions );
}

add_action( 'plugins_loaded', __NAMESPACE__ . '\\init' );
