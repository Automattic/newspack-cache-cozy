<?php
/**
 * Newspack Nodes uninstall cleanup.
 *
 * Runs ONLY on plugin delete (WordPress defines WP_UNINSTALL_PLUGIN), never on
 * deactivate. Removes every `newspack_cache_cozy_` option this plugin created.
 *
 * @package Newspack_Cache_Cozy
 */

if ( ! \defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

require __DIR__ . '/includes/uninstall-cleanup.php';

\Newspack_Cache_Cozy\uninstall_cleanup( 'newspack_cache_cozy_' );
