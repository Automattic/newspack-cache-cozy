<?php
/**
 * Cache Cozy Tick Node
 *
 * Queues a `cache_cozy` JobWorker job every INTERVAL_SECONDS from inside a
 * long-lived worker, replacing the unreliable wp-cron trigger (which competes
 * with the reconcile pass and every other minute-cron for a slot). The tick
 * hitchhikes `_router`'s ~5s TIMER heartbeat — the worker's own drain loop, not
 * wp-cron — and debounces to the interval, so cadence is immune to cron
 * contention.
 *
 * Why enqueue rather than fire the loopback here: the warm render blocks for
 * seconds; the JobWorker isolates it (its own request_id, GC cycle, cache-flush
 * cadence, stale-timeout headroom) and keeps this worker's drain loop moving.
 * The job handler (handle_job) reuses the drop-in's single-flight loopback.
 *
 * Add to a topology with a single line — the timer self-starts in arguments():
 *   make_node Cache_Cozy_Tick cache-cozy:tick
 *
 * @package Newspack_Cache_Cozy
 */

namespace Newspack_Cache_Cozy;

use Newspack_Nodes\Core;
use Newspack_Nodes\Message;
use Newspack_Nodes\Schema_Reflection;
use Newspack_Nodes\Timer_Node;

if ( ! \defined( 'ABSPATH' ) ) {
	exit;
}

class Cache_Cozy_Tick_Node extends Timer_Node {
	/** Positional args parsed via node_schema(). */
	use Schema_Reflection;

	/** DEFAULT tick cadence + the static handler's stale-drop fallback (when a job carries no `interval`). */
	public const INTERVAL_SECONDS = 60;

	/** Job handler name — shared by the enqueue (fire) and the registration so they can't drift. */
	public const JOB_HANDLER = 'cache_cozy';

	/** Static guard so init() is idempotent across the worker-runtime bootstrap. */
	private static bool $registered = false;

	/** Comma-joined cold-group override for this tick (empty = the drop-in's default cold_groups()). */
	protected string $cold_groups = '';

	/** Per-instance warm-enqueue cadence in seconds (positional arg overrides the default). */
	protected int $interval_seconds = self::INTERVAL_SECONDS;

	/** Unix timestamp of the last enqueue (0 = never). */
	protected int $last_enqueue = 0;

	/** Path warmed by the loopback (default homepage). Threaded to the job + loopback URL. */
	protected string $path = '/';

	/**
	 * Positional args via Schema_Reflection: `<interval_seconds> <path> <cold_groups>`.
	 * Empty string keeps every default; set_timer() (re)starts the _router heartbeat
	 * hitchhike — the debounce is the real cadence gate, so the ~5s poll is plenty.
	 *
	 * @param list<string>|null $args Positional argument tokens (null = getter).
	 */
	public function arguments( ?array $args = null ): array {
		if ( null === $args ) {
			return $this->arguments;
		}
		$this->arguments = $args;
		if ( [] !== $args ) {
			$this->parse_schema_args( $args );
		}
		$this->set_timer();
		return $this->arguments;
	}

	/**
	 * fire (Timer_Node override): enqueue a `cache_cozy` job once per interval.
	 * Cheap and non-blocking — the blocking warm render happens later in the
	 * JobWorker via handle_job(). Debounced so the ~5s Router heartbeat only
	 * enqueues every INTERVAL_SECONDS.
	 */
	public function fire(): void {
		$now = \time();
		if ( $now - $this->last_enqueue < $this->interval_seconds ) {
			return;
		}
		$this->last_enqueue = $now;

		$message = Message::new_message();
		$message[ Message::TYPE  ] = Message::TM_STRUCT;
		$message[ Message::FROM  ] = $this->name;
		$message[ Message::TO    ] = $this->target;
		$message[ Message::VALUE ] = [
			'k'          => 'job',
			'handler'    => self::JOB_HANDLER,
			// The warmed path names the request context before_job opens.
			'id'         => $this->path,
			'parameters' => [
				'queued_at'   => $now,
				'interval'    => $this->interval_seconds,
				'path'        => $this->path,
				'cold_groups' => $this->cold_groups,
			],
		];
		parent::fill( $message );
	}

	/**
	 * Register the `cache_cozy` job handler on the standard JobWorker filter
	 * (plugin load). Named init() not register() — Node::register() is the
	 * instance-level event-subscription API and can't be overridden static.
	 */
	public static function init(): void {
		if ( self::$registered ) {
			return;
		}
		self::$registered = true;
		if ( \function_exists( 'add_filter' ) ) {
			\add_filter( 'newspack_nodes/job_handlers', [ self::class, 'register_handler' ] );
		}
	}

	/**
	 * Add the `cache_cozy` handler to the JobWorker's local-handler map.
	 *
	 * @param mixed $handlers Existing handlers (filter boundary — coerced to array).
	 * @return array<string,mixed>
	 */
	public static function register_handler( $handlers ): array {
		// Filter-boundary value; handler maps are string-keyed by design.
		/** @var array<string,mixed> $handlers */
		$handlers = Core::arr( $handlers );
		$handlers[ self::JOB_HANDLER ] = [ self::class, 'handle_job' ];
		return $handlers;
	}

	/**
	 * JobWorker handler: fire the single-flight warm loopback, unless the job has
	 * been queued for >= one full interval (a newer tick is already coming, so
	 * skip the stale one). There is no uniform stale-drop in JobWorker — each
	 * handler enforces its own age (cf. RemoteManager::STALE_THRESHOLD = 600s).
	 *
	 * @param string              $id         The warmed path; names this job's request context.
	 * @param array<string,mixed> $parameters Job parameters (`queued_at`).
	 */
	public static function handle_job( string $id, array $parameters ): void {
		if ( ! isset( $parameters['queued_at'], $parameters['interval'], $parameters['path'], $parameters['cold_groups'] ) ) {
			// Producer always emits the full shape; a missing field is corrupt.
			Core::print_less_often( 'CacheCozyTick: dropping malformed warm job (missing required fields)' );
			return;
		}
		$queued_at = Core::num_int( $parameters['queued_at'], 0 );
		$interval  = Core::num_int( $parameters['interval'], self::INTERVAL_SECONDS );
		if ( ( \time() - $queued_at ) >= $interval ) {
			Core::print_less_often( 'CacheCozyTick: dropping stale warm job (age >= ' . $interval . 's)' );
			return;
		}
		if ( ! \class_exists( '\\Newspack_Cache_Cozy\\Cache_Cozy' ) ) {
			Core::print_less_often( 'CacheCozyTick: drop-in not installed; cannot warm' );
			return;
		}
		\Newspack_Cache_Cozy\Cache_Cozy::run_tick( Core::str( $parameters['path'], '/' ), Core::str( $parameters['cold_groups'] ) );
	}

	public static function node_schema(): array {
		return [
			'category'     => 'Control',
			'description'  => 'Queues a cache_cozy JobWorker job (default every 60s); self-starts in arguments(). Positional args: <interval_seconds> <path> <cold_groups>.',
			'arguments'    => [
				[ 'name' => 'interval_seconds', 'type' => 'int',    'default' => self::INTERVAL_SECONDS, 'description' => 'How often to enqueue a cache-warm job, in seconds (default 60).' ],
				[ 'name' => 'path',             'type' => 'string', 'default' => '/', 'description' => 'URL path to keep warm (default / — the homepage).' ],
				[ 'name' => 'cold_groups',      'type' => 'string', 'default' => '', 'description' => 'Comma-separated cache groups to warm; empty uses the drop-in default cold_groups().' ],
			],
			'commands'     => [],
			'accepts_fill' => false,
		];
	}
}
