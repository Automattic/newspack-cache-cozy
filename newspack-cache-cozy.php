<?php
/**
 * Plugin Name: Newspack Cache Cozy
 * Description: Refresh-ahead cache warmer for newspack-nodes — keeps the homepage's caches hot out-of-band so no visitor pays the cold render.
 * Version: 0.3.1
 * Author: Automattic
 * License: GPL-2.0-or-later
 * Text Domain: newspack-cache-cozy
 * Requires Plugins: newspack-nodes
 * Requires PHP: 8.2
 *
 * @package Newspack_Cache_Cozy
 */

\defined( 'ABSPATH' ) || exit;

if ( ! \defined( 'NEWSPACK_CACHE_COZY_VERSION' ) ) {
	\define( 'NEWSPACK_CACHE_COZY_VERSION', '0.3.1' );
}
if ( ! \defined( 'NEWSPACK_CACHE_COZY_FILE' ) ) {
	\define( 'NEWSPACK_CACHE_COZY_FILE', __FILE__ );
}
if ( ! \defined( 'NEWSPACK_CACHE_COZY_DIR' ) ) {
	\define( 'NEWSPACK_CACHE_COZY_DIR', \plugin_dir_path( __FILE__ ) );
}

// Composer classmap autoload; release ships map, dev clone: composer install.
require_once NEWSPACK_CACHE_COZY_DIR . 'vendor/autoload.php';

/**
 * The runtime-dependent wiring. Cache_Cozy_Tick_Node extends the substrate's
 * Timer_Node, so the substrate must be loaded before this runs — hence the
 * deferred plugins_loaded callback below. (Tests bypass this — they require the
 * substrate explicitly in tests/bootstrap.php.)
 */
$newspack_cache_cozy_load = static function (): void {
	// Register namespace so make_node Cache_Cozy_Tick resolves the tick node.
	\Newspack_Nodes\Command_Interpreter_Node::register_namespace( 'Newspack_Cache_Cozy\\' );
	// Register the cache_cozy job handler on the Job_Worker filter.
	\Newspack_Cache_Cozy\Cache_Cozy_Tick_Node::init();
};

// Defer wiring to plugins_loaded pri 11 (substrate sorts after us).
\add_action(
	'plugins_loaded',
	static function () use ( $newspack_cache_cozy_load ): void {
		if ( ! \class_exists( '\Newspack_Nodes\Timer_Node' ) ) {
			return;
		}
		// Substrate handshake: dormant when too old (no notice API pre-0.54).
		if ( ! \method_exists( '\\Newspack_Nodes\\Bootstrap', 'version_at_least' )
			|| ! \Newspack_Nodes\Bootstrap::version_at_least( '0.54.0', 'Newspack Cache Cozy' ) ) {
			return;
		}
		$newspack_cache_cozy_load();
	},
	11
);
