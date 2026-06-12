<?php
/**
 * Plugin Name: Newspack Cache Cozy
 * Description: Refresh-ahead cache warmer for newspack-nodes — keeps the homepage's caches hot out-of-band so no visitor pays the cold render.
 * Version: 0.2.0
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
	\define( 'NEWSPACK_CACHE_COZY_VERSION', '0.2.0' );
}
if ( ! \defined( 'NEWSPACK_CACHE_COZY_FILE' ) ) {
	\define( 'NEWSPACK_CACHE_COZY_FILE', __FILE__ );
}
if ( ! \defined( 'NEWSPACK_CACHE_COZY_DIR' ) ) {
	\define( 'NEWSPACK_CACHE_COZY_DIR', \plugin_dir_path( __FILE__ ) );
}

// Composer classmap autoloader. Class files in includes/ load on first
// reference; the production map ships in the release zip (build-release.sh runs
// composer install --no-dev). A dev clone needs `composer install`.
require_once NEWSPACK_CACHE_COZY_DIR . 'vendor/autoload.php';

/**
 * The runtime-dependent wiring. Cache_Cozy_Tick_Node extends the substrate's
 * Timer_Node, so the substrate must be loaded before this runs — hence the
 * deferred plugins_loaded callback below. (Tests bypass this — they require the
 * substrate explicitly in tests/bootstrap.php.)
 */
$newspack_cache_cozy_load = static function (): void {
	// Resolve `make_node Cache_Cozy_Tick` to this plugin's Cache_Cozy_Tick_Node.
	\Newspack_Nodes\Command_Interpreter_Node::register_namespace( 'Newspack_Cache_Cozy\\' );
	// Register the `cache_cozy` job handler on the substrate's Job_Worker filter.
	\Newspack_Cache_Cozy\Cache_Cozy_Tick_Node::init();
};

// WordPress loads plugins alphabetically; `newspack-cache-cozy` sorts before
// `newspack-nodes`, so the substrate isn't loaded at this file's load time.
// Defer the wiring to plugins_loaded priority 11 — both plugins are loaded by
// then. `Requires Plugins: newspack-nodes` keeps the substrate active (WP 6.5+);
// the class_exists check is the graceful no-op if it somehow isn't.
\add_action(
	'plugins_loaded',
	static function () use ( $newspack_cache_cozy_load ): void {
		if ( \class_exists( '\Newspack_Nodes\Timer_Node' ) ) {
			$newspack_cache_cozy_load();
		}
	},
	11
);
