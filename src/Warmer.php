<?php
/**
 * Requests every configured path that has no stored answer, so visitors meet warm pages.
 *
 * @package Shakewell\RestCache
 */

namespace Shakewell\RestCache;

defined( 'ABSPATH' ) || exit;

/**
 * Runs from WP-CLI (`wp shakewell-rest-cache warm`), from a system timer calling it, or, when
 * `warm_after_change` is on, from a wp-cron event scheduled after a change.
 *
 * Its protections: one warmer at a time across the fleet (a lock in the object cache); one blocking request at
 * a time (a parallel warmer spun a CPU core and left none for visitors); a time budget; its own requests build
 * fresh and are told apart from visitors by a per-run token over loopback; it stops when a change retires the
 * generation it is building; and a path that answered but was not stored is not retried until the next
 * generation or hour.
 */
final class Warmer {

	/** Cron hook for the warm that follows a change. */
	const HOOK = 'shakewell_rest_cache_warm';

	/**
	 * Every path to keep warm, in order: the configured paths (put the shared endpoints first), then the
	 * home page and the published posts of `warm_post_types` when pages are cached.
	 *
	 * @return string[] Paths with any query string, relative to the site host.
	 */
	public static function paths(): array {
		$paths = Config::list( 'warm_paths' );
		if ( Config::get( 'pages', false ) ) {
			$paths[] = '/';
			$home    = wp_parse_url( home_url( '/' ) );
			foreach ( Config::list( 'warm_post_types' ) as $type ) {
				if ( ! post_type_exists( $type ) ) {
					continue;
				}
				$ids = get_posts(
					array(
						'post_type'      => $type,
						'post_status'    => 'publish',
						'posts_per_page' => -1,
						'fields'         => 'ids',
						'orderby'        => array(
							'menu_order' => 'ASC',
							'ID'         => 'ASC',
						),
						'no_found_rows'  => true,
					)
				);
				foreach ( $ids as $id ) {
					$parts = wp_parse_url( (string) get_permalink( $id ) );
					if ( empty( $parts['path'] ) || ( $parts['host'] ?? '' ) !== ( $home['host'] ?? '' ) ) {
						continue;
					}
					$paths[] = $parts['path'] . ( empty( $parts['query'] ) ? '' : '?' . $parts['query'] );
				}
			}
		}
		$filtered = apply_filters( 'shakewell_rest_cache_warm_list', $paths );
		return array_values( array_unique( array_filter( is_array( $filtered ) ? array_map( 'strval', $filtered ) : array(), 'strlen' ) ) );
	}

	/**
	 * Warms what is cold.
	 *
	 * Options: dry_run (bool, list what would be requested and change nothing), max_seconds (float, stop
	 * starting requests after this long), pause_ms (int, wait between requests).
	 *
	 * @param array<string,mixed> $options Options.
	 * @param callable|null       $fetch   fn( string $url, array $headers ): bool, true when the site answered. Only tests pass it.
	 * @return array{todo:string[],warmed:int,skipped:int,failed:int,complete:bool,locked:bool,seconds:float,slowest:array<string,int>}
	 */
	public static function run( array $options = array(), ?callable $fetch = null ): array {
		$result = array(
			'todo'     => array(),
			'warmed'   => 0,
			'skipped'  => 0,
			'failed'   => 0,
			'complete' => false,
			'locked'   => false,
			'seconds'  => 0.0,
			'slowest'  => array(),
		);
		if ( ! Cache::enabled() ) {
			return $result;
		}
		$started    = microtime( true );
		$generation = State::generation();
		$mark       = static fn( string $path ): string => 'uncacheable:' . md5( $generation . '|' . $path );
		foreach ( self::paths() as $path ) {
			if ( Cache::is_warm( $path ) || wp_cache_get( $mark( $path ), Cache::GROUP ) ) {
				++$result['skipped'];
			} else {
				$result['todo'][] = $path;
			}
		}
		if ( ! empty( $options['dry_run'] ) ) {
			$result['complete'] = true;
			return $result;
		}
		if ( ! $result['todo'] ) {
			$result['complete'] = true;
			// An empty warm list proves nothing is built, so only a list that is fully warm closes the window.
			if ( $result['skipped'] > 0 ) {
				self::close( $generation );
			}
			return $result;
		}

		// One warmer across the fleet: every instance may run the timer.
		$owner = gethostname() . '-' . getmypid();
		if ( ! wp_cache_add( 'warm-lock', $owner, Cache::GROUP, 10 * MINUTE_IN_SECONDS ) ) {
			$result['locked'] = true;
			return $result;
		}

		$max      = (float) ( $options['max_seconds'] ?? Config::get( 'warm_max_seconds', 270 ) );
		$pause    = max( 0, (int) ( $options['pause_ms'] ?? Config::get( 'warm_pause_ms', 0 ) ) );
		$fetch  ??= array( self::class, 'fetch' );
		$headers  = self::headers( State::warm_token() );
		$base     = rtrim( (string) Config::get( 'warm_base', 'http://127.0.0.1' ), '/' );
		$complete = true;
		try {
			foreach ( $result['todo'] as $i => $path ) {
				if ( microtime( true ) - $started > $max ) {
					$complete = false;
					break;
				}
				if ( $i > 0 && $pause ) {
					usleep( $pause * 1000 );
				}
				$t        = microtime( true );
				$answered = (bool) $fetch( $base . $path, $headers );
				$ms       = (int) round( ( microtime( true ) - $t ) * 1000 );

				// A change or a release retired the generation mid-run: what was built belongs to the old
				// one, and judging it against the new one would mislabel every page as uncacheable.
				if ( State::generation() !== $generation ) {
					$complete = false;
					break;
				}
				if ( ! $answered ) {
					++$result['failed'];
				} elseif ( ! Cache::is_warm( $path ) ) {
					wp_cache_set( $mark( $path ), 1, Cache::GROUP, HOUR_IN_SECONDS );
					++$result['failed'];
				} else {
					++$result['warmed'];
					$result['slowest'][ $path ] = $ms;
				}
			}
			if ( $complete ) {
				self::close( $generation );
			}
		} finally {
			if ( wp_cache_get( 'warm-lock', Cache::GROUP ) === $owner ) {
				wp_cache_delete( 'warm-lock', Cache::GROUP );
			}
		}
		$result['complete'] = $complete;
		$result['seconds']  = microtime( true ) - $started;
		arsort( $result['slowest'] );
		return $result;
	}

	/**
	 * Every path of $generation is built: it becomes the one stale copies come from.
	 *
	 * @param string $generation Generation the run started on.
	 */
	private static function close( string $generation ): void {
		if ( State::generation() === $generation ) {
			State::mark_stable( $generation );
		}
	}

	/**
	 * Headers for a warm request: the site host, the warm token, and the configured extras.
	 *
	 * @param string $token Token for this run.
	 * @return array<string,string>
	 */
	private static function headers( string $token ): array {
		$home    = wp_parse_url( home_url( '/' ) );
		$headers = array(
			'Host'                        => (string) ( $home['host'] ?? '' ),
			'User-Agent'                  => 'shakewell-rest-cache-warmer',
			'X-Shakewell-Rest-Cache-Warm' => $token,
		);
		if ( 'https' === ( $home['scheme'] ?? '' ) ) {
			$headers['X-Forwarded-Proto'] = 'https';
		}
		$extra = Config::get( 'warm_headers', array() );
		return array_merge( is_array( $extra ) ? array_map( 'strval', array_filter( $extra, 'is_scalar' ) ) : array(), $headers );
	}

	/**
	 * Requests one URL and waits for the answer, straight to the web server on this instance.
	 *
	 * @param string               $url     URL.
	 * @param array<string,string> $headers Headers.
	 */
	public static function fetch( string $url, array $headers ): bool {
		return ! is_wp_error(
			wp_remote_get(
				$url,
				array(
					'headers'     => $headers,
					'timeout'     => 30,
					'redirection' => 0,
				)
			)
		);
	}

	/**
	 * Schedules one warm shortly after a change, when `warm_after_change` is on and none is queued.
	 */
	public static function schedule(): void {
		if ( Config::get( 'warm_after_change', false ) && ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_single_event( time() + max( 1, (int) Config::get( 'warm_delay', 30 ) ), self::HOOK );
		}
	}

	/**
	 * The cron event: warms, and queues another run when the time budget or a new change cut this one short.
	 */
	public static function cron(): void {
		$result = self::run();
		if ( ! $result['complete'] && ! $result['locked'] ) {
			self::schedule();
		}
	}
}
