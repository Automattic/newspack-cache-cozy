<?php
/**
 * Cache Cozy Tick Node
 *
 * Queues a `cache_cozy` JobWorker job every INTERVAL_SECONDS from inside a
 * long-lived worker, replacing the unreliable wp-cron trigger (which competes
 * with the supervisor and every other minute-cron for a slot). The tick
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
	// Positional `<interval_seconds> <path> <cold_groups>` parsing via node_schema().
	use Schema_Reflection;

	/** DEFAULT tick cadence + the static handler's stale-drop fallback (when a job carries no `interval`). */
	public const INTERVAL_SECONDS = 60;

	/** Job handler name — shared by the enqueue (fire) and the registration so they can't drift. */
	public const JOB_HANDLER = 'cache_cozy';

	/** Per-instance warm-enqueue cadence in seconds (positional arg overrides the default). */
	protected int $interval_seconds = self::INTERVAL_SECONDS;

	/** Path warmed by the loopback (default homepage). Threaded to the job + loopback URL. */
	protected string $path = '/';

	/** Comma-joined cold-group override for this tick (empty = the drop-in's default cold_groups()). */
	protected string $cold_groups = '';

	/** Unix timestamp of the last enqueue (0 = never). */
	protected int $last_enqueue = 0;

	/** Static guard so init() is idempotent across the worker-runtime bootstrap. */
	private static bool $registered = false;

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
	 * @return array<string, mixed>
	 */
	public static function register_handler( $handlers ): array {
		// Filter-boundary value; handler maps are string-keyed by design.
		/** @var array<string, mixed> $handlers */
		$handlers = \is_array( $handlers ) ? $handlers : [];
		$handlers[ self::JOB_HANDLER ] = [ self::class, 'handle_job' ];
		return $handlers;
	}

	/**
	 * Positional args via Schema_Reflection: `<interval_seconds> <path> <cold_groups>`.
	 * Empty string keeps every default; set_timer() (re)starts the _router heartbeat
	 * hitchhike — the debounce is the real cadence gate, so the ~5s poll is plenty.
	 *
	 * @param string|null $args Raw positional argument string (null = getter).
	 */
	public function arguments( ?string $args = null ): string {
		if ( null === $args ) {
			return $this->arguments;
		}
		$this->arguments = $args;
		if ( '' !== $args ) {
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
	 * JobWorker handler: fire the single-flight warm loopback, unless the job has
	 * been queued for >= one full interval (a newer tick is already coming, so
	 * skip the stale one). There is no uniform stale-drop in JobWorker — each
	 * handler enforces its own age (cf. RemoteManager::STALE_THRESHOLD = 600s).
	 *
	 * @param array<string, mixed> $parameters Job parameters (`queued_at`).
	 */
	public static function handle_job( array $parameters ): void {
		/** @var int|float|string|bool|null $raw_queued_at */
		$raw_queued_at = $parameters['queued_at'] ?? 0;
		$queued_at     = (int) $raw_queued_at;
		// Read the job's own interval; fall back to the const so old/in-flight jobs
		// that predate the threaded `interval` (or carry a malformed one) still drop correctly.
		$raw_interval = $parameters['interval'] ?? self::INTERVAL_SECONDS;
		$interval     = \is_numeric( $raw_interval ) ? (int) $raw_interval : self::INTERVAL_SECONDS;
		if ( $queued_at > 0 && ( \time() - $queued_at ) >= $interval ) {
			Core::print_less_often( 'CacheCozyTick: dropping stale warm job (age >= ' . $interval . 's)' );
			return;
		}
		if ( ! \class_exists( '\\Newspack_Cache_Cozy\\Cache_Cozy' ) ) {
			Core::print_less_often( 'CacheCozyTick: drop-in not installed; cannot warm' );
			return;
		}
		$raw_path    = $parameters['path'] ?? '/';
		$path        = \is_string( $raw_path ) && '' !== $raw_path ? $raw_path : '/';
		$raw_groups  = $parameters['cold_groups'] ?? '';
		$cold_groups = \is_string( $raw_groups ) ? $raw_groups : '';
		\Newspack_Cache_Cozy\Cache_Cozy::run_tick( $path, $cold_groups );
	}

	public static function node_schema(): array {
		return [
			'category'     => 'Control',
			'description'  => 'Queues a cache_cozy JobWorker job (default every 60s); self-starts in arguments(). Positional args: <interval_seconds> <path> <cold_groups>.',
			'arguments'    => [
				[ 'name' => 'interval_seconds', 'type' => 'int',    'default' => self::INTERVAL_SECONDS ],
				[ 'name' => 'path',             'type' => 'string', 'default' => '/' ],
				[ 'name' => 'cold_groups',      'type' => 'string', 'default' => '' ],
			],
			'commands'     => [],
			'accepts_fill' => false,
		];
	}
}
