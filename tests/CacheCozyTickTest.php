<?php
/**
 * Tests for Cache_Cozy_Tick_Node — the Timer subclass that queues a
 * `cache_cozy` JobWorker job every 60s (replacing the flaky wp-cron trigger),
 * plus its job handler which drops requests older than the interval.
 *
 * @package Newspack_Cache_Cozy
 */

namespace Newspack_Cache_Cozy\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use Newspack_Cache_Cozy\Cache_Cozy;
use Newspack_Cache_Cozy\Cache_Cozy_Tick_Node;
use Newspack_Nodes\Tests\TestCase;
use Newspack_Nodes\Core;
use Newspack_Nodes\Event_Framework;
use Newspack_Nodes\Router_Node;
use Newspack_Nodes\Tests\Capture_Sink_Node;

#[CoversClass( Cache_Cozy_Tick_Node::class )]
class CacheCozyTickTest extends TestCase {

	private array $orig_server;

	protected function setUp(): void {
		parent::setUp();
		$this->orig_server               = $_SERVER;
		$GLOBALS['_wp_options']          = [];
		$GLOBALS['_wp_actions']          = [];
		$GLOBALS['_wp_test_remote_gets'] = [];
		$GLOBALS['_wp_transients']       = [];
		$GLOBALS['_wp_test_home_url']    = 'https://www.bendsource.com';

		$this->set_registered( false );
		Event_Framework::reset();
	}

	protected function tearDown(): void {
		$this->set_registered( false );
		Event_Framework::reset();
		$_SERVER                   = $this->orig_server;
		$GLOBALS['_wp_transients'] = [];
		parent::tearDown();
	}

	/**
	 * @param class-string $class_name Class that owns the reflected property.
	 */
	private function reflection_property( string $class_name, string $property ): \ReflectionProperty {
		return new \ReflectionProperty( $class_name, $property );
	}

	/** Reset the private static init() idempotency guard so each test starts clean. */
	private function set_registered( bool $value ): void {
		$ref = $this->reflection_property( Cache_Cozy_Tick_Node::class, 'registered' );
		$ref->setValue( null, $value );
	}

	private function last_enqueue( Cache_Cozy_Tick_Node $node ): int {
		$ref = $this->reflection_property( Cache_Cozy_Tick_Node::class, 'last_enqueue' );
		return (int) $ref->getValue( $node );
	}

	private function set_last_enqueue( Cache_Cozy_Tick_Node $node, int $value ): void {
		$ref = $this->reflection_property( Cache_Cozy_Tick_Node::class, 'last_enqueue' );
		$ref->setValue( $node, $value );
	}

	private function interval_seconds( Cache_Cozy_Tick_Node $node ): int {
		$ref = $this->reflection_property( Cache_Cozy_Tick_Node::class, 'interval_seconds' );
		return (int) $ref->getValue( $node );
	}

	private function set_interval_seconds( Cache_Cozy_Tick_Node $node, int $value ): void {
		$ref = $this->reflection_property( Cache_Cozy_Tick_Node::class, 'interval_seconds' );
		$ref->setValue( $node, $value );
	}

	private function path( Cache_Cozy_Tick_Node $node ): string {
		$ref = $this->reflection_property( Cache_Cozy_Tick_Node::class, 'path' );
		return (string) $ref->getValue( $node );
	}

	private function cold_groups( Cache_Cozy_Tick_Node $node ): string {
		$ref = $this->reflection_property( Cache_Cozy_Tick_Node::class, 'cold_groups' );
		return (string) $ref->getValue( $node );
	}

	// ── Constant + handler registration ─────────────────────────────────────

	public function test_interval_is_sixty_seconds(): void {
		$this->assertSame( 60, Cache_Cozy_Tick_Node::INTERVAL_SECONDS );
	}

	public function test_register_handler_registers_the_cache_cozy_job(): void {
		$handlers = Cache_Cozy_Tick_Node::register_handler( [] );

		$this->assertArrayHasKey( Cache_Cozy_Tick_Node::JOB_HANDLER, $handlers );
		$this->assertIsCallable( $handlers[ Cache_Cozy_Tick_Node::JOB_HANDLER ] );
	}

	public function test_register_handler_preserves_existing_handlers(): void {
		$existing = [ 'remote_manager' => 'some_callable' ];
		$handlers = Cache_Cozy_Tick_Node::register_handler( $existing );

		$this->assertSame( 'some_callable', $handlers['remote_manager'] );
		$this->assertArrayHasKey( Cache_Cozy_Tick_Node::JOB_HANDLER, $handlers );
	}

	// ── Self-start on name() (so the topology is a single make_node line) ────

	public function test_name_self_starts_the_router_timer(): void {
		$router = new Router_Node();
		$router->name( '_router' );

		$node = new Cache_Cozy_Tick_Node();
		$node->name( 'cache-cozy:tick' );
		$node->arguments( [] );

		$ref  = $this->reflection_property( \Newspack_Nodes\Node::class, 'registrations' );
		$regs = $ref->getValue( $router );
		$this->assertArrayHasKey( 'TIMER', $regs );
		$this->assertArrayHasKey( 'cache-cozy:tick', $regs['TIMER'] );
	}

	public function test_name_is_a_no_op_when_router_missing(): void {
		// Core::reset() in parent::setUp() guarantees no _router. Self-start must
		// degrade gracefully (no throw) — periodic tick disabled, not an error.
		$this->assertNull( Core::node( '_router' ) );

		$node = new Cache_Cozy_Tick_Node();
		$node->name( 'cache-cozy:tick' );

		$this->assertSame( 0, $this->last_enqueue( $node ) );
	}

	// ── fire(): enqueue + debounce ───────────────────────────────────────────

	public function test_fire_enqueues_then_debounces(): void {
		$node = new Cache_Cozy_Tick_Node();
		$sink = new Capture_Sink_Node();
		$node->name( 'cache-cozy:tick' );
		$node->sink( $sink );
		$node->fire();
		$after_first = $this->last_enqueue( $node );
		$this->assertGreaterThan( 0, $after_first, 'first fire must enqueue (last_enqueue advances)' );
		$this->assertCount( 1, $sink->captured );

		// Second tick within the interval must early-return — no re-enqueue.
		$node->fire();
		$this->assertSame( $after_first, $this->last_enqueue( $node ) );
		$this->assertCount( 1, $sink->captured );
	}

	// ── handle_job(): stale-drop + warm ──────────────────────────────────────

	public function test_handle_job_warms_when_fresh(): void {
		Cache_Cozy_Tick_Node::handle_job( [ 'queued_at' => \time(), 'interval' => 60, 'path' => '/', 'cold_groups' => '' ] );

		$this->assertCount( 1, $GLOBALS['_wp_test_remote_gets'], 'a fresh job must fire the warm loopback' );
		$this->assertStringContainsString( 'cache_cozy_warm=', $GLOBALS['_wp_test_remote_gets'][0]['url'] );
	}

	public function test_handle_job_drops_incomplete_envelope(): void {
		// The sole producer (fire()) always emits the full {queued_at, interval,
		// path, cold_groups} shape. A job missing any field is corrupt (or an old
		// pre-migration replay) and is dropped, not warmed with guessed defaults.
		Cache_Cozy_Tick_Node::handle_job( [ 'queued_at' => \time() ] );

		$this->assertCount( 0, $GLOBALS['_wp_test_remote_gets'], 'an incomplete envelope must be dropped, not warmed' );
	}

	public function test_handle_job_drops_stale_request(): void {
		// A job that sat in the queue >= one full interval: skip it — the next
		// tick's job will warm. Must NOT fire the loopback.
		Cache_Cozy_Tick_Node::handle_job( [ 'queued_at' => \time() - Cache_Cozy_Tick_Node::INTERVAL_SECONDS, 'interval' => Cache_Cozy_Tick_Node::INTERVAL_SECONDS, 'path' => '/', 'cold_groups' => '' ] );

		$this->assertCount( 0, $GLOBALS['_wp_test_remote_gets'], 'stale job must be dropped, not warmed' );
	}

	public function test_handle_job_no_ops_when_dropin_absent(): void {
		// Guard belt-and-suspenders: handle_job references the drop-in's
		// run_tick; if the class isn't loaded it must not fatal. The drop-in IS
		// loaded in this suite, so assert the class exists (documents the dependency).
		$this->assertTrue( \class_exists( '\\Newspack_Cache_Cozy\\Cache_Cozy' ) );
	}

	// ── init(): handler-filter registration + idempotency guard ──────────────

	public function test_init_registers_the_job_handlers_filter(): void {
		Cache_Cozy_Tick_Node::init();

		$callbacks = $GLOBALS['_wp_actions']['newspack_nodes/job_handlers'] ?? [];
		$this->assertContains(
			[ Cache_Cozy_Tick_Node::class, 'register_handler' ],
			$callbacks,
			'init() must register register_handler on the job_handlers filter'
		);
	}

	public function test_init_is_idempotent(): void {
		// First call registers; the static guard must make the second call a
		// no-op so the worker-runtime bootstrap can call init() repeatedly.
		Cache_Cozy_Tick_Node::init();
		Cache_Cozy_Tick_Node::init();

		$callbacks = $GLOBALS['_wp_actions']['newspack_nodes/job_handlers'] ?? [];
		$matches   = \array_filter(
			$callbacks,
			static fn ( $cb ) => $cb === [ Cache_Cozy_Tick_Node::class, 'register_handler' ]
		);
		$this->assertCount( 1, $matches, 'init() must register exactly once across repeated calls' );
	}

	// ── name() / arguments() getter passthroughs ─────────────────────────────

	public function test_name_getter_returns_the_set_name(): void {
		$node = new Cache_Cozy_Tick_Node();
		$node->name( 'cache-cozy:tick' );

		// No-arg call hits the getter branch (func_num_args() === 0 → parent::name()).
		$this->assertSame( 'cache-cozy:tick', $node->name() );
	}

	public function test_arguments_getter_returns_the_stored_value(): void {
		// A numeric arg now does a _router hitchhike (was an Event_Framework timer).
		$router = new Router_Node();
		$router->name( '_router' );

		$node = new Cache_Cozy_Tick_Node();
		$node->name( 'cache-cozy:tick' );
		$node->arguments( [ '30' ] );

		// No-arg call hits the getter branch (null === $args → return $this->arguments).
		$this->assertSame( [ '30' ], $node->arguments() );
	}

	// ── arguments(): numeric arg sets the warm cadence, keeps router hitchhike ─

	public function test_arguments_numeric_sets_interval_and_keeps_router_hitchhike(): void {
		// A numeric arg means "warm-enqueue interval in seconds" (what node_schema
		// advertises). It must (a) set the per-instance interval and (b) stay on
		// the efficient _router TIMER hitchhike — NOT arm a busy Event_Framework
		// timer. The ~5s router poll is plenty of granularity; the debounce is the
		// real cadence gate.
		$router = new Router_Node();
		$router->name( '_router' );

		$node = new Cache_Cozy_Tick_Node();
		$node->name( 'cache-cozy:tick' );

		$node->arguments( [ '30' ] );

		$this->assertSame( 30, $this->interval_seconds( $node ), 'numeric arg sets the per-instance interval' );

		$ref  = $this->reflection_property( \Newspack_Nodes\Node::class, 'registrations' );
		$regs = $ref->getValue( $router );
		$this->assertArrayHasKey( 'TIMER', $regs );
		$this->assertArrayHasKey( 'cache-cozy:tick', $regs['TIMER'] );

		$ef     = $this->reflection_property( Event_Framework::class, 'timers' );
		$timers = $ef->getValue( Event_Framework::instance() );
		$this->assertArrayNotHasKey(
			\spl_object_id( $node ),
			$timers,
			'numeric arg must NOT arm an event-framework timer slot'
		);
	}

	// ── fire(): per-instance interval governs the debounce ───────────────────

	public function test_fire_honors_per_instance_interval(): void {
		$router = new Router_Node();
		$router->name( '_router' );

		$node = new Cache_Cozy_Tick_Node();
		$sink = new Capture_Sink_Node();
		$node->name( 'cache-cozy:tick' );
		$node->arguments( [ '5' ] );
		$node->sink( $sink );

		// Last enqueue 6s ago: with a 5s interval, the next fire MUST re-enqueue.
		$this->set_last_enqueue( $node, \time() - 6 );
		$node->fire();

		$this->assertGreaterThan(
			\time() - 6,
			$this->last_enqueue( $node ),
			'a 5s-interval node must re-enqueue when the last enqueue is 6s old'
		);
		$this->assertCount( 1, $sink->captured );
	}

	public function test_fire_default_interval_debounces_under_sixty_seconds(): void {
		// Control: a default-60 node with the SAME 6s-old last_enqueue must NOT
		// re-enqueue (6 < 60), proving the cadence is driven by the interval.
		$node = new Cache_Cozy_Tick_Node();
		$sink = new Capture_Sink_Node();
		$node->name( 'cache-cozy:tick' );
		$node->sink( $sink );

		$prior = \time() - 6;
		$this->set_last_enqueue( $node, $prior );
		$node->fire();

		$this->assertSame( $prior, $this->last_enqueue( $node ), 'default-60 node must not re-enqueue at 6s' );
		$this->assertCount( 0, $sink->captured );
	}

	// ── fire(): interval is threaded into the job parameters ─────────────────

	public function test_fire_threads_interval_into_job_parameters(): void {
		$node = new Cache_Cozy_Tick_Node();
		$node->name( 'cache-cozy:tick' );
		$this->set_interval_seconds( $node, 45 );

		$capture = new \Newspack_Nodes\Tests\Capture_Sink_Node();
		$node->sink( $capture );

		$node->fire();

		$this->assertCount( 1, $capture->captured, 'fire() must emit one job message' );
		$value = $capture->captured[0][ \Newspack_Nodes\Message::VALUE ];
		// Canonical job-entry kind field is `k` (the firehose category Job_Worker
		// dispatches on), not `type`.
		$this->assertSame( 'job', $value['k'] );
		$this->assertSame( Cache_Cozy_Tick_Node::JOB_HANDLER, $value['handler'] );
		$this->assertSame( 45, $value['parameters']['interval'], 'fire() must thread the interval into the job params' );
	}

	// ── fire(): path + cold_groups are threaded into the job parameters ──────

	public function test_fire_enqueues_path_and_cold_groups(): void {
		$router = new Router_Node();
		$router->name( '_router' );

		$node = new Cache_Cozy_Tick_Node();
		$sink = new Capture_Sink_Node();
		$node->name( 'cache-cozy:tick' );
		$node->arguments( [ '60', '/events', 'newspack_blocks' ] );
		$node->sink( $sink );

		$node->fire();

		$this->assertCount( 1, $sink->captured, 'fire() must emit one job message' );
		$value = $sink->captured[0][ \Newspack_Nodes\Message::VALUE ];
		$this->assertSame( '/events', $value['parameters']['path'], 'fire() must thread the path into the job params' );
		$this->assertSame( 'newspack_blocks', $value['parameters']['cold_groups'], 'fire() must thread cold_groups into the job params' );
	}

	// ── handle_job(): stale threshold uses the job's own interval ────────────

	public function test_handle_job_drops_stale_request_using_job_interval(): void {
		// A 30s-interval job queued 31s ago is stale and must be dropped.
		Cache_Cozy_Tick_Node::handle_job( [ 'queued_at' => \time() - 31, 'interval' => 30, 'path' => '/', 'cold_groups' => '' ] );

		$this->assertCount( 0, $GLOBALS['_wp_test_remote_gets'], 'stale-by-job-interval must be dropped' );
	}

	public function test_handle_job_forwards_path_and_cold_groups_to_warm_loopback(): void {
		Cache_Cozy_Tick_Node::handle_job(
			[
				'queued_at'   => \time(),
				'interval'    => 60,
				'path'        => '/events',
				'cold_groups' => 'newspack_blocks,transient',
			]
		);

		$this->assertCount( 1, $GLOBALS['_wp_test_remote_gets'], 'a fresh job must fire the warm loopback' );
		$url = $GLOBALS['_wp_test_remote_gets'][0]['url'];
		$this->assertStringContainsString( '/events', $url, 'handle_job must forward the path to the loopback URL' );
		$this->assertStringContainsString( 'cache_cozy_groups=', $url, 'handle_job must forward cold_groups to the loopback URL' );
	}

	// ── arguments(): schema-driven interval + path + cold_groups ─────────────

	public function test_arguments_assign_interval_path_and_cold_groups(): void {
		// arguments() ends in set_timer() (the router hitchhike), so a _router is required.
		$router = new Router_Node();
		$router->name( '_router' );

		$node = new Cache_Cozy_Tick_Node();
		$node->name( 'cache-cozy:tick' );
		$node->arguments( [ '120', '/category/news', 'newspack_blocks,transient' ] );

		$this->assertSame( 120, $this->interval_seconds( $node ) );
		$this->assertSame( '/category/news', $this->path( $node ) );
		$this->assertSame( 'newspack_blocks,transient', $this->cold_groups( $node ) );
	}

	public function test_arguments_default_path_and_groups_when_only_interval(): void {
		$router = new Router_Node();
		$router->name( '_router' );

		$node = new Cache_Cozy_Tick_Node();
		$node->name( 'cache-cozy:tick' );
		$node->arguments( [ '90' ] );

		$this->assertSame( 90, $this->interval_seconds( $node ) );
		$this->assertSame( '/', $this->path( $node ), 'path defaults to / when only interval is given' );
		$this->assertSame( '', $this->cold_groups( $node ), 'cold_groups defaults to empty when only interval is given' );
	}
}
