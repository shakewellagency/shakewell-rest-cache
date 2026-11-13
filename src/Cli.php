<?php
/**
 * WP-CLI commands.
 *
 * @package Shakewell\RestCache
 */

namespace Shakewell\RestCache;

defined( 'ABSPATH' ) || exit;

/**
 * Inspect and clear the REST cache.
 */
final class Cli {

	/**
	 * Retires every stored answer. Run it on release, since new code can change an answer's shape.
	 *
	 * @when after_wp_load
	 */
	public function flush(): void {
		if ( ! Cache::enabled() ) {
			\WP_CLI::log( 'No persistent object cache on this environment; nothing to flush.' );
			return;
		}
		Cache::bump();
		\WP_CLI::success( 'REST cache retired (generation ' . Cache::generation() . ').' );
	}

	/**
	 * Shows whether the cache is active and its current generation.
	 *
	 * @when after_wp_load
	 */
	public function status(): void {
		if ( ! Cache::enabled() ) {
			\WP_CLI::log( 'No persistent object cache on this environment; the REST cache is off.' );
			return;
		}
		\WP_CLI::log( 'generation ' . Cache::generation() );
	}
}
