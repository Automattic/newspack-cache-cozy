<?php
/**
 * Tests for Newspack_Cache_Cozy\Cache_Cozy (the standalone drop-in).
 *
 * The refresh-ahead warmer: keeps the homepage's caches hot out-of-band so no
 * visitor pays the cold render. Covers the host gate, cold groups, secret,
 * the drop-in-load cold-cache install, single-flight, and stats exclusion.
 *
 * @package Newspack_Cache_Cozy
 */

namespace Newspack_Cache_Cozy\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use Newspack_Cache_Cozy\Cache_Cozy;
use Newspack_Cache_Cozy\Cold_Read_Object_Cache;
use Newspack_Nodes\Tests\TestCase;

#[CoversClass( Cache_Cozy::class )]
/**
 * Nothing here is a slow unit: 86 tests in 0.27s, 0.59s under coverage. What once
 * exceeded the one-second limit was the FIRST test paying the process's warm-up while the
 * pre-push gate ran a coverage pass beside it, and one test forks
 * (`RunInSeparateProcess`), which re-bootstraps the substrate. `failOnRisky` makes such an
 * abort a failed run, so keep the warm-up off the first test rather than buying time.
 */
class CacheCozyTest extends TestCase {

	private mixed $saved_object_cache = null;

	protected function setUp(): void {
		parent::setUp();
		$this->saved_object_cache = $GLOBALS['wp_object_cache'] ?? null;
		$GLOBALS['_wp_test_remote_gets'] = [];
		$GLOBALS['_wp_transients']       = [];
		$_GET = [];
		unset(
			$GLOBALS['_wp_options']['newspack_cache_cozy_secret'],
			$GLOBALS['_wp_test_home_url'],
			$_SERVER['NEWSPACK_NODES_WORKER_TYPE']
		);
	}

	protected function tearDown(): void {
		// Restore every global these tests touch so nothing bleeds into later
		// suites (e.g. $_SERVER worker-type leaking into RequestBuilderTest).
		unset(
			$GLOBALS['_wp_actions']['password_protected_is_active'],
			$GLOBALS['_wp_actions']['determine_current_user'],
			$_SERVER['NEWSPACK_NODES_WORKER_TYPE']
		);
		$GLOBALS['wp_object_cache'] = $this->saved_object_cache;
		$GLOBALS['_wp_transients']  = [];
		$_GET                       = [];
		parent::tearDown();
	}

	/**
	 * Read a private/protected property off any object via reflection.
	 *
	 * @param object $obj  The object to read from.
	 * @param string $prop The property name.
	 */
	private function read_protected( object $obj, string $prop ): mixed {
		$ref = new \ReflectionProperty( $obj, $prop );
		return $ref->getValue( $obj );
	}

	/** Minimal array-backed WP_Object_Cache double (group-namespaced get/set). */
	private function fake_object_cache(): object {
		return new class() {
			public array $store = [];
			public function get( $key, $group = '', $force = false, &$found = null ) {
				$found = isset( $this->store[ $group ][ $key ] );
				return $found ? $this->store[ $group ][ $key ] : false;
			}
			public function set( $key, $data, $group = '', $expire = 0 ) {
				$this->store[ $group ][ $key ] = $data;
				return true;
			}
		};
	}

	// ── register() — drop-in bootstrap ──────────────────────────────────────

	public function test_register_hooks_the_cron_handler(): void {
		// The event is scheduled manually (`wp cron event schedule`); register()
		// must hook run_tick so the scheduled/`wp cron event run` tick is runnable.
		$saved                  = $GLOBALS['_wp_actions'] ?? [];
		$GLOBALS['_wp_actions'] = [];
		try {
			Cache_Cozy::register();
			$this->assertNotEmpty( $GLOBALS['_wp_actions'][ Cache_Cozy::CRON_HOOK ] ?? [] );
		} finally {
			$GLOBALS['_wp_actions'] = $saved;
		}
	}

	// ── Self-owned cron recurrence (so scheduling never depends on another plugin) ──

	public function test_register_cron_schedule_adds_a_minute_interval(): void {
		$schedules = Cache_Cozy::register_cron_schedule( [] );

		$this->assertArrayHasKey( Cache_Cozy::CRON_SCHEDULE, $schedules );
		$this->assertSame( 60, $schedules[ Cache_Cozy::CRON_SCHEDULE ]['interval'] );
	}

	public function test_register_cron_schedule_preserves_existing_schedules(): void {
		$existing  = [ 'hourly' => [ 'interval' => 3600, 'display' => 'Once Hourly' ] ];
		$schedules = Cache_Cozy::register_cron_schedule( $existing );

		$this->assertSame( $existing['hourly'], $schedules['hourly'] );
		$this->assertArrayHasKey( Cache_Cozy::CRON_SCHEDULE, $schedules );
	}

	public function test_register_wires_the_cron_schedule_filter(): void {
		// register() must add the cron_schedules filter so the recurrence is
		// available to `wp cron event schedule` without newspack-nodes loaded.
		$saved                  = $GLOBALS['_wp_actions'] ?? [];
		$GLOBALS['_wp_actions'] = [];
		try {
			Cache_Cozy::register();
			$schedules = apply_filters( 'cron_schedules', [] );
			$this->assertArrayHasKey( Cache_Cozy::CRON_SCHEDULE, $schedules );
		} finally {
			$GLOBALS['_wp_actions'] = $saved;
		}
	}

	// ── Cold-group allowlist ────────────────────────────────────────────────

	public function test_default_cold_groups_cover_block_cache_and_transients(): void {
		$groups = Cache_Cozy::cold_groups();

		$this->assertContains( 'newspack_blocks', $groups );
		$this->assertContains( 'transient', $groups );
		$this->assertContains( 'site-transient', $groups );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_cold_groups_can_be_configured_with_wp_config_constant(): void {
		if ( ! defined( 'NEWSPACK_CACHE_COZY_COLD_GROUPS' ) ) {
			define(
				'NEWSPACK_CACHE_COZY_COLD_GROUPS',
				[ 'newspack_blocks', 'transient', 'site-transient', 'es_query_cache' ]
			);
		}

		$this->assertContains( 'es_query_cache', Cache_Cozy::cold_groups() );
	}

	// ── The drop-in ships on its own, so it carries its own version ─────────

	public function test_the_drop_in_header_matches_the_plugin_version(): void {
		// A site can hold a copy of the drop-in that came from anywhere — the
		// release attaches it as its own asset — so a header frozen at 0.1.0
		// while the plugin moved to 0.6.0 left nobody able to tell which one
		// they had. `scripts/bump-version.sh` moves it now; this catches a
		// hand-edit that goes around the script.
		$root   = \dirname( __DIR__ );
		$plugin = (string) \file_get_contents( $root . '/newspack-cache-cozy.php' );
		$dropin = (string) \file_get_contents( $root . '/mu-plugins/01-newspack-cache-cozy.php' );

		$this->assertSame( 1, \preg_match( '/^ \* Version: (.+)$/m', $plugin, $want ) );
		$this->assertSame( 1, \preg_match( '/^ \* Version: (.+)$/m', $dropin, $got ) );
		$this->assertSame( \trim( $want[1] ), \trim( $got[1] ), 'the drop-in header trails the plugin' );
	}

	// ── The drop-in warms; it touches nothing else ──────────────────────────

	public function test_register_touches_no_rest_request(): void {
		// A cache warmer has no business in the editor's REST preload; the
		// autosaves trim that once lived here is kept in dndocker's notes.
		$saved                  = $GLOBALS['_wp_actions'] ?? [];
		$GLOBALS['_wp_actions'] = [];
		try {
			Cache_Cozy::register();
			$this->assertArrayNotHasKey( 'rest_request_before_callbacks', $GLOBALS['_wp_actions'] );
			$this->assertFalse( \method_exists( Cache_Cozy::class, 'trim_autosave_fields' ) );
		} finally {
			$GLOBALS['_wp_actions'] = $saved;
		}
	}

	// ── Secret-gated warm-request detection ─────────────────────────────────

	public function test_warm_request_recognized_with_matching_secret(): void {
		$this->assertTrue(
			Cache_Cozy::is_warm_request( [ 'cache_cozy_warm' => 's3cr3t' ], 's3cr3t' )
		);
	}

	public function test_warm_request_rejected_with_wrong_secret(): void {
		$this->assertFalse(
			Cache_Cozy::is_warm_request( [ 'cache_cozy_warm' => 'nope' ], 's3cr3t' )
		);
	}

	public function test_warm_request_rejected_when_param_absent(): void {
		$this->assertFalse( Cache_Cozy::is_warm_request( [], 's3cr3t' ) );
	}

	public function test_warm_request_rejected_when_secret_is_empty(): void {
		// An unset/empty stored secret must never match an empty param.
		$this->assertFalse( Cache_Cozy::is_warm_request( [ 'cache_cozy_warm' => '' ], '' ) );
	}

	public function test_array_param_rejected_without_a_php_warning(): void {
		// ?cache_cozy_warm[]=x makes the param an array; it must be rejected
		// cleanly, not cast to string ("Array to string conversion" warning).
		$warned = false;
		set_error_handler(
			static function () use ( &$warned ): bool {
				$warned = true;
				return true;
			}
		);
		$result = Cache_Cozy::is_warm_request( [ 'cache_cozy_warm' => [ 'x' ] ], 's3cr3t' );
		restore_error_handler();

		$this->assertFalse( $result );
		$this->assertFalse( $warned, 'an array param must not trigger a PHP warning' );
	}

	// ── Decorator install (the $wp_object_cache swap) ───────────────────────

	public function test_install_cold_cache_wraps_object_cache_with_cold_reads(): void {
		$real = $this->fake_object_cache();
		$real->set( 'np_cached_block_x_0', 'stale', 'newspack_blocks' );
		$real->set( 'alloptions', [ 'a' => 1 ], 'options' );
		$GLOBALS['wp_object_cache'] = $real;

		Cache_Cozy::install_cold_cache();

		$this->assertInstanceOf( Cold_Read_Object_Cache::class, $GLOBALS['wp_object_cache'] );
		// Cold group reads miss; warm group reads still pass through.
		$this->assertFalse( $GLOBALS['wp_object_cache']->get( 'np_cached_block_x_0', 'newspack_blocks' ) );
		$this->assertSame( [ 'a' => 1 ], $GLOBALS['wp_object_cache']->get( 'alloptions', 'options' ) );
	}

	public function test_install_cold_cache_is_idempotent(): void {
		$GLOBALS['wp_object_cache'] = $this->fake_object_cache();

		Cache_Cozy::install_cold_cache();
		$first = $GLOBALS['wp_object_cache'];
		Cache_Cozy::install_cold_cache();

		$this->assertSame( $first, $GLOBALS['wp_object_cache'], 'must not double-wrap the object cache' );
	}

	public function test_install_cold_cache_honors_explicit_groups(): void {
		$GLOBALS['wp_object_cache'] = $this->fake_object_cache();

		Cache_Cozy::install_cold_cache( [ 'only_this' ] );

		$this->assertInstanceOf( Cold_Read_Object_Cache::class, $GLOBALS['wp_object_cache'] );
		$this->assertSame( [ 'only_this' ], $this->read_protected( $GLOBALS['wp_object_cache'], 'cold' ) );
	}

	public function test_install_cold_cache_falls_back_to_default_groups(): void {
		$GLOBALS['wp_object_cache'] = $this->fake_object_cache();

		Cache_Cozy::install_cold_cache();

		$this->assertSame(
			Cache_Cozy::cold_groups(),
			$this->read_protected( $GLOBALS['wp_object_cache'], 'cold' ),
			'null groups must fall back to the default cold_groups()'
		);
	}

	public function test_maybe_install_honors_secret_gated_groups_override(): void {
		$GLOBALS['_wp_test_home_url'] = 'https://www.bendsource.com';
		$GLOBALS['wp_object_cache']   = $this->fake_object_cache();
		$_GET['cache_cozy_warm']      = Cache_Cozy::secret();
		$_GET['cache_cozy_groups']    = 'only_this,and_that';

		Cache_Cozy::maybe_install_for_request();

		$this->assertInstanceOf( Cold_Read_Object_Cache::class, $GLOBALS['wp_object_cache'] );
		$this->assertSame(
			[ 'only_this', 'and_that' ],
			$this->read_protected( $GLOBALS['wp_object_cache'], 'cold' )
		);
	}

	public function test_groups_param_ignored_without_a_valid_secret(): void {
		// The groups override is post-secret only: a wrong secret installs nothing,
		// so the override can't be abused to cool arbitrary groups on a real request.
		$GLOBALS['_wp_test_home_url'] = 'https://www.bendsource.com';
		$real                         = $this->fake_object_cache();
		$GLOBALS['wp_object_cache']   = $real;
		$_GET['cache_cozy_warm']      = 'wrong-secret';
		$_GET['cache_cozy_groups']    = 'only_this';

		Cache_Cozy::maybe_install_for_request();

		$this->assertSame( $real, $GLOBALS['wp_object_cache'], 'a bad secret must not install the cold cache' );
	}

	// ── Secret + loopback URL ───────────────────────────────────────────────

	public function test_secret_generated_once_then_persisted(): void {
		$first  = Cache_Cozy::secret();
		$second = Cache_Cozy::secret();

		$this->assertNotSame( '', $first );
		$this->assertSame( $first, $second, 'secret must persist, not regenerate per call' );
		$this->assertSame( $first, $GLOBALS['_wp_options']['newspack_cache_cozy_secret'] );
	}

	public function test_secret_option_is_not_autoloaded(): void {
		Cache_Cozy::secret();
		$this->assertFalse( $GLOBALS['_wp_option_autoload']['newspack_cache_cozy_secret'] );
	}

	public function test_warm_url_targets_home_with_secret_param(): void {
		$GLOBALS['_wp_test_home_url'] = 'https://www.bendsource.com';

		$url = Cache_Cozy::warm_url();

		$this->assertStringStartsWith( 'https://www.bendsource.com/', $url );
		$this->assertStringContainsString( 'cache_cozy_warm=' . Cache_Cozy::secret(), $url );
	}

	public function test_warm_url_includes_path_and_cold_groups(): void {
		$GLOBALS['_wp_test_home_url'] = 'https://www.bendsource.com';

		$url = Cache_Cozy::warm_url( '/events', 'newspack_blocks,transient' );

		$this->assertStringContainsString( '/events', $url );
		$this->assertStringContainsString( 'cache_cozy_warm=' . Cache_Cozy::secret(), $url );
		$this->assertStringContainsString( 'cache_cozy_groups=newspack_blocks%2Ctransient', $url );
	}

	public function test_warm_url_omits_groups_param_when_empty(): void {
		$GLOBALS['_wp_test_home_url'] = 'https://www.bendsource.com';

		$this->assertStringNotContainsString( 'cache_cozy_groups', Cache_Cozy::warm_url( '/', '' ) );
	}

	// ── Cron tick (loopback) ────────────────────────────────────────────────

	public function test_run_tick_fires_the_loopback(): void {
		$GLOBALS['_wp_test_home_url'] = 'https://www.bendsource.com';

		Cache_Cozy::run_tick();

		$this->assertCount( 1, $GLOBALS['_wp_test_remote_gets'] );
		$call = $GLOBALS['_wp_test_remote_gets'][0];
		$this->assertStringContainsString( 'cache_cozy_warm=', $call['url'] );
		$this->assertTrue( $call['args']['sslverify'], 'TLS verification on by default (loopback hits a public hostname)' );
		$this->assertGreaterThanOrEqual( 10, $call['args']['timeout'] );
	}

	public function test_sslverify_can_be_disabled_via_wp_config_constant_for_self_signed_hosts(): void {
		$GLOBALS['_wp_test_home_url'] = 'https://www.bendsource.com';
		if ( ! defined( 'NEWSPACK_CACHE_COZY_SSLVERIFY' ) ) {
			define( 'NEWSPACK_CACHE_COZY_SSLVERIFY', false );
		}
		Cache_Cozy::run_tick();

		$this->assertFalse( $GLOBALS['_wp_test_remote_gets'][0]['args']['sslverify'] );
	}

	public function test_run_tick_sends_basic_auth_when_credential_configured(): void {
		// An authenticated loopback makes the edge/page cache bypass and forward
		// to PHP (otherwise the proxy serves a cached homepage and the render —
		// and the cold-cache decorator — never run). The credential is an
		// application password "user:app password" supplied out-of-band.
		$GLOBALS['_wp_test_home_url'] = 'https://www.bendsource.com';
		Cache_Cozy::store_auth( 'svc:abcd 1234 efgh ijkl' );

		Cache_Cozy::run_tick();

		$call = $GLOBALS['_wp_test_remote_gets'][0];
		$this->assertSame(
			'Basic ' . base64_encode( 'svc:abcd 1234 efgh ijkl' ),
			$call['args']['headers']['Authorization'] ?? null
		);
		unset( $GLOBALS['_wp_options']['newspack_cache_cozy_auth'] );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_run_tick_sends_a_credential_another_process_rotated(): void {
		// A worker's WordPress caches the non-autoloaded option on first read.
		require_once \dirname( __DIR__, 2 ) . '/newspack-nodes/tests/Helpers/wp-object-cache-stub.php';
		$GLOBALS['_wp_test_home_url'] = 'https://www.bendsource.com';
		Cache_Cozy::store_auth( 'svc:rotated 5150 wxyz' );
		$rotated = $GLOBALS['_wp_options']['newspack_cache_cozy_auth'];
		Cache_Cozy::store_auth( 'svc:original 7302 abcd' );
		Cache_Cozy::run_tick();

		// The operator's rotation reaches the shared cache, not this worker's copy.
		\wp_test_write_elsewhere( 'newspack_cache_cozy_auth', $rotated, false );
		$GLOBALS['_wp_cache_flushes'] = [];
		Cache_Cozy::run_tick();

		$this->assertSame(
			'Basic ' . base64_encode( 'svc:rotated 5150 wxyz' ),
			$GLOBALS['_wp_test_remote_gets'][1]['args']['headers']['Authorization'] ?? null
		);
		$this->assertSame( [ 'runtime' ], $GLOBALS['_wp_cache_flushes'], 'one flush per tick, evicting nothing shared' );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_run_tick_sends_a_credential_stored_after_the_worker_read_none(): void {
		// WordPress caches the ABSENCE of a row it read, in `notoptions`.
		require_once \dirname( __DIR__, 2 ) . '/newspack-nodes/tests/Helpers/wp-object-cache-stub.php';
		$GLOBALS['_wp_test_home_url'] = 'https://www.bendsource.com';
		Cache_Cozy::store_auth( 'svc:late 8812 qrst' );
		$sealed = $GLOBALS['_wp_options']['newspack_cache_cozy_auth'];
		\delete_option( 'newspack_cache_cozy_auth' );
		Cache_Cozy::run_tick();
		$this->assertArrayNotHasKey( 'Authorization', $GLOBALS['_wp_test_remote_gets'][0]['args']['headers'] ?? [] );

		// The operator's script stores it from another process.
		\wp_test_write_elsewhere( 'newspack_cache_cozy_auth', $sealed, false );
		Cache_Cozy::run_tick();

		$this->assertSame(
			'Basic ' . base64_encode( 'svc:late 8812 qrst' ),
			$GLOBALS['_wp_test_remote_gets'][1]['args']['headers']['Authorization'] ?? null
		);
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_run_tick_sends_a_secret_another_process_rotated(): void {
		require_once \dirname( __DIR__, 2 ) . '/newspack-nodes/tests/Helpers/wp-object-cache-stub.php';
		$GLOBALS['_wp_test_home_url'] = 'https://www.bendsource.com';
		Cache_Cozy::run_tick();
		$this->assertNotSame( '', $GLOBALS['_wp_options']['newspack_cache_cozy_secret'] );

		// Another process replaces the secret the loopback checks.
		\wp_test_write_elsewhere( 'newspack_cache_cozy_secret', 'b7c0ffee5eed4a11d00d0123456789ab', false );
		Cache_Cozy::run_tick();

		$this->assertStringContainsString( 'cache_cozy_warm=b7c0ffee5eed4a11d00d0123456789ab', $GLOBALS['_wp_test_remote_gets'][1]['url'] );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_run_tick_without_an_external_cache_flushes_only_the_options_group(): void {
		// No drop-in: the cache is this process's alone, and a full flush would
		// drop every other group.
		require_once \dirname( __DIR__, 2 ) . '/newspack-nodes/tests/Helpers/wp-object-cache-stub.php';
		$GLOBALS['_wp_using_ext_object_cache'] = false;
		$GLOBALS['_wp_test_home_url']          = 'https://www.bendsource.com';
		Cache_Cozy::store_auth( 'svc:rotated 6061 mnop' );
		$rotated = $GLOBALS['_wp_options']['newspack_cache_cozy_auth'];
		Cache_Cozy::store_auth( 'svc:original 4417 efgh' );
		Cache_Cozy::run_tick();

		\wp_test_write_elsewhere( 'newspack_cache_cozy_auth', $rotated, false );
		$GLOBALS['_wp_cache_flushes'] = [];
		Cache_Cozy::run_tick();

		$this->assertSame(
			'Basic ' . base64_encode( 'svc:rotated 6061 mnop' ),
			$GLOBALS['_wp_test_remote_gets'][1]['args']['headers']['Authorization'] ?? null
		);
		$this->assertSame( [ 'options' ], $GLOBALS['_wp_cache_flushes'] );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_warm_url_and_auth_header_read_through_the_cache(): void {
		// Both are public, so a caller outside the tick must not pay a flush.
		require_once \dirname( __DIR__, 2 ) . '/newspack-nodes/tests/Helpers/wp-object-cache-stub.php';
		$GLOBALS['_wp_test_home_url'] = 'https://www.bendsource.com';
		Cache_Cozy::store_auth( 'svc:direct 2718 ijkl' );

		Cache_Cozy::warm_url();
		$this->assertSame( 'Basic ' . base64_encode( 'svc:direct 2718 ijkl' ), Cache_Cozy::auth_header() );

		$this->assertSame( [], $GLOBALS['_wp_cache_flushes'] );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_the_warm_request_path_neither_flushes_nor_evicts_the_secret(): void {
		// Any request carrying the param reaches this; it must cost no cache churn.
		require_once \dirname( __DIR__, 2 ) . '/newspack-nodes/tests/Helpers/wp-object-cache-stub.php';
		\update_option( 'newspack_cache_cozy_secret', 'c0ld5eed7e57aaaa0000111122223333', false );
		$_GET['cache_cozy_warm'] = 'not-the-secret';

		Cache_Cozy::maybe_install_for_request();

		$this->assertSame( 'c0ld5eed7e57aaaa0000111122223333', $GLOBALS['_wp_option_cache']['newspack_cache_cozy_secret'] ?? null );
		$this->assertSame( [], $GLOBALS['_wp_cache_flushes'] );
	}

	public function test_clearing_the_credential_keeps_the_row_present_and_empty(): void {
		Cache_Cozy::store_auth( 'svc:cleared 3391 uvwx' );

		Cache_Cozy::store_auth( '   ' );

		$this->assertArrayHasKey( 'newspack_cache_cozy_auth', $GLOBALS['_wp_options'], 'an absent row can hide behind a stale notoptions' );
		$this->assertSame( '', $GLOBALS['_wp_options']['newspack_cache_cozy_auth'] );
		$this->assertFalse( $GLOBALS['_wp_option_autoload']['newspack_cache_cozy_auth'] );
		$this->assertSame( '', Cache_Cozy::auth_header() );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_a_stale_notoptions_cannot_hide_a_credential_stored_after_a_clear(): void {
		require_once \dirname( __DIR__, 2 ) . '/newspack-nodes/tests/Helpers/wp-object-cache-stub.php';
		$GLOBALS['_wp_test_home_url'] = 'https://www.bendsource.com';
		Cache_Cozy::store_auth( 'svc:restored 9924 yzab' );
		$sealed = $GLOBALS['_wp_options']['newspack_cache_cozy_auth'];
		Cache_Cozy::store_auth( '' );
		Cache_Cozy::run_tick();

		// Stored elsewhere; then this worker writes back its own `notoptions`.
		\wp_test_write_elsewhere( 'newspack_cache_cozy_auth', $sealed, false );
		\get_option( 'newspack_cache_cozy_probe_never_stored' );
		Cache_Cozy::run_tick();

		$this->assertSame(
			'Basic ' . base64_encode( 'svc:restored 9924 yzab' ),
			$GLOBALS['_wp_test_remote_gets'][1]['args']['headers']['Authorization'] ?? null
		);
	}

	public function test_store_auth_encrypts_the_credential_at_rest(): void {
		Cache_Cozy::store_auth( 'svc:hunter2 secret' );

		$stored = $GLOBALS['_wp_options']['newspack_cache_cozy_auth'];
		$this->assertStringStartsWith( '$enc$', $stored, 'credential must be encrypted at rest, not plaintext' );
		$this->assertStringNotContainsString( 'hunter2', $stored );
		unset( $GLOBALS['_wp_options']['newspack_cache_cozy_auth'] );
	}

	public function test_run_tick_omits_auth_header_when_no_credential(): void {
		$GLOBALS['_wp_test_home_url'] = 'https://www.bendsource.com';

		Cache_Cozy::run_tick();

		$this->assertArrayNotHasKey(
			'Authorization',
			$GLOBALS['_wp_test_remote_gets'][0]['args']['headers'] ?? []
		);
	}

	// ── maybe_install_for_request() — the drop-in-load cold-cache swap ──────

	public function test_warm_request_forces_anonymous_render(): void {
		// The Authorization header is only for the edge cache; in WP the warm
		// render must be logged-OUT so Newspack's block caching stays enabled (it
		// disables for logged-in editors) and populates the anonymous cache real
		// visitors read. determine_current_user is forced to 0, overriding any
		// app-password auth the loopback's header would otherwise trigger.
		$GLOBALS['wp_object_cache'] = $this->fake_object_cache();
		$_GET['cache_cozy_warm']    = Cache_Cozy::secret();

		Cache_Cozy::maybe_install_for_request();

		$this->assertSame( 0, apply_filters( 'determine_current_user', 7 ) );
	}

	public function test_normal_request_does_not_force_anonymous(): void {
		Cache_Cozy::maybe_install_for_request(); // no secret param

		$this->assertSame( 7, apply_filters( 'determine_current_user', 7 ) );
	}

	public function test_install_happens_on_warm_loopback_request(): void {
		$GLOBALS['_wp_test_home_url'] = 'https://www.bendsource.com';
		$GLOBALS['wp_object_cache']   = $this->fake_object_cache();
		$_GET['cache_cozy_warm']      = Cache_Cozy::secret();

		Cache_Cozy::maybe_install_for_request();

		$this->assertInstanceOf( Cold_Read_Object_Cache::class, $GLOBALS['wp_object_cache'] );
	}

	public function test_no_install_on_a_normal_request(): void {
		$GLOBALS['_wp_test_home_url'] = 'https://www.bendsource.com';
		$real                         = $this->fake_object_cache();
		$GLOBALS['wp_object_cache']   = $real;
		// no cache_cozy_warm param

		Cache_Cozy::maybe_install_for_request();

		$this->assertSame( $real, $GLOBALS['wp_object_cache'] );
	}

	// ── #6: warm render excluded from timing stats ──────────────────────────

	public function test_warm_request_marks_worker_type_for_stats_exclusion(): void {
		$GLOBALS['_wp_test_home_url'] = 'https://www.bendsource.com';
		$GLOBALS['wp_object_cache']   = $this->fake_object_cache();
		$_GET['cache_cozy_warm']      = Cache_Cozy::secret();

		Cache_Cozy::maybe_install_for_request();

		// LogManager reads $_SERVER['NEWSPACK_NODES_WORKER_TYPE'] → tags the
		// request worker_type → Flame_Builder drops it from timing stats.
		$this->assertSame( 'cache-cozy', $_SERVER['NEWSPACK_NODES_WORKER_TYPE'] ?? null );
	}

	public function test_normal_request_does_not_mark_worker_type(): void {
		$GLOBALS['_wp_test_home_url'] = 'https://www.bendsource.com';

		Cache_Cozy::maybe_install_for_request();

		$this->assertArrayNotHasKey( 'NEWSPACK_NODES_WORKER_TYPE', $_SERVER );
	}

	// ── warm render bypasses access gates so the loopback reaches the page ──

	public function test_warm_request_bypasses_password_protection(): void {
		$GLOBALS['wp_object_cache'] = $this->fake_object_cache();
		$_GET['cache_cozy_warm']    = Cache_Cozy::secret();

		Cache_Cozy::maybe_install_for_request();

		// The Password Protected plugin would otherwise 302 the loopback to its
		// login page; the warmer disables it for its own (secret-gated) render.
		$this->assertFalse( apply_filters( 'password_protected_is_active', true ) );
	}

	public function test_normal_request_leaves_password_protection_active(): void {
		Cache_Cozy::maybe_install_for_request(); // no secret param

		$this->assertTrue( apply_filters( 'password_protected_is_active', true ) );
	}

	// ── #7: single-flight — no overlapping warm renders ─────────────────────

	public function test_run_tick_skips_when_a_warm_render_is_already_in_flight(): void {
		$GLOBALS['_wp_test_home_url'] = 'https://www.bendsource.com';
		set_transient( 'newspack_cache_cozy_lock', 1, 60 ); // prior tick holds the lock

		Cache_Cozy::run_tick();

		$this->assertCount( 0, $GLOBALS['_wp_test_remote_gets'], 'must not fire a second loopback while one is in flight' );
	}

	public function test_run_tick_takes_and_releases_the_lock_on_a_clean_run(): void {
		$GLOBALS['_wp_test_home_url'] = 'https://www.bendsource.com';

		Cache_Cozy::run_tick();

		$this->assertCount( 1, $GLOBALS['_wp_test_remote_gets'] );
		$this->assertFalse( get_transient( 'newspack_cache_cozy_lock' ), 'lock must be released after the run' );
	}
}
