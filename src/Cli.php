<?php
/**
 * WP-CLI commands.
 *
 * @package Shakewell\RestCache
 */

namespace Shakewell\RestCache;

defined( 'ABSPATH' ) || exit;

/**
 * Inspect, clear and warm the REST cache.
 */
final class Cli {

	/**
	 * Retires every stored answer, with nothing served stale. Run it on release, since new code can change an
	 * answer's shape.
	 *
	 * @when after_wp_load
	 */
	public function flush(): void {
		if ( ! Cache::enabled() ) {
			\WP_CLI::log( 'No persistent object cache on this environment; nothing to flush.' );
			return;
		}
		State::bump( array(), true );
		\WP_CLI::success( 'REST cache retired (generation ' . State::generation() . ').' );
	}

	/**
	 * Shows whether the cache is active, its generations and how much of the warm list is warm.
	 *
	 * @when after_wp_load
	 */
	public function status(): void {
		if ( ! Cache::enabled() ) {
			\WP_CLI::log( 'No persistent object cache on this environment; the REST cache is off.' );
			return;
		}
		$paths = Warmer::paths();
		$warm  = count( array_filter( $paths, array( Cache::class, 'is_warm' ) ) );
		\WP_CLI::log( sprintf( 'generation %s: %d of %d paths warm; stable generation %s', State::generation(), $warm, count( $paths ), State::stable() ?? 'none' ) );
	}

	/**
	 * Requests every path that has no stored answer, one at a time, over the loopback.
	 *
	 * ## OPTIONS
	 *
	 * [--max-seconds=<seconds>]
	 * : Stop starting new requests after this long. Default is the warm_max_seconds setting, 270.
	 *
	 * [--pause-ms=<milliseconds>]
	 * : Wait this long between requests. Default is the warm_pause_ms setting, 0.
	 *
	 * [--dry-run]
	 * : List what would be requested and change nothing.
	 *
	 * [--quiet-if-idle]
	 * : Print nothing when every path was already warm.
	 *
	 * @when after_wp_load
	 *
	 * @param string[]             $args  Positional arguments.
	 * @param array<string,string> $assoc Options.
	 */
	public function warm( $args, $assoc ): void {
		if ( ! Cache::enabled() ) {
			\WP_CLI::log( 'No persistent object cache on this environment; nothing to warm.' );
			return;
		}
		$options = array( 'dry_run' => isset( $assoc['dry-run'] ) );
		if ( isset( $assoc['max-seconds'] ) ) {
			$options['max_seconds'] = (float) $assoc['max-seconds'];
		}
		if ( isset( $assoc['pause-ms'] ) ) {
			$options['pause_ms'] = (int) $assoc['pause-ms'];
		}
		$result = Warmer::run( $options );

		if ( $options['dry_run'] ) {
			foreach ( $result['todo'] as $path ) {
				\WP_CLI::log( $path );
			}
			\WP_CLI::log( sprintf( 'warm dry run: %d would be requested, %d already warm', count( $result['todo'] ), $result['skipped'] ) );
			return;
		}
		if ( $result['locked'] ) {
			\WP_CLI::log( 'Another instance is warming; skipping.' );
			return;
		}
		if ( 0 === $result['warmed'] && 0 === $result['failed'] && isset( $assoc['quiet-if-idle'] ) ) {
			return;
		}
		$top = array_slice( $result['slowest'], 0, 3, true );
		\WP_CLI::log(
			sprintf(
				'warm: %d warmed, %d already warm, %d not cacheable or failed, %.1fs%s%s',
				$result['warmed'],
				$result['skipped'],
				$result['failed'],
				$result['seconds'],
				$result['complete'] ? '' : '; stopped early, run again to continue',
				$top ? '; slowest ' . implode( ', ', array_map( static fn( $p, $ms ) => "$p {$ms}ms", array_keys( $top ), $top ) ) : ''
			)
		);
	}
}
