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
