<?php
/**
 * Plugin bootstrap.
 *
 * @package Shakewell\RestCache
 */

namespace Shakewell\RestCache;

defined( 'ABSPATH' ) || exit;

/**
 * Wires the plugin into WordPress.
 */
final class Plugin {

	/**
	 * Registers hooks. Does nothing without a persistent object cache. Called as the plugin loads, so
	 * that a stored answer can be sent before the theme and other plugins run (the "early" setting).
	 */
	public static function boot(): void {
		// Registered even without an object cache, so the commands can say why they do nothing.
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'shakewell-rest-cache', Cli::class );
		}
		if ( ! Cache::enabled() ) {
			return;
		}
		Invalidation::register();
		Cache::serve_early();
		// Before the canonical redirect (10): a URL is only stored after it produced an answer, so a URL that redirects is never served from here.
		add_action( 'template_redirect', array( Cache::class, 'serve_page' ), -1000 );
		// After ACF's own rest_pre_dispatch hooks, which can replace the value with null.
		add_filter( 'rest_pre_dispatch', array( Cache::class, 'serve_rest' ), PHP_INT_MAX - 10, 3 );
		add_filter( 'rest_post_dispatch', array( Cache::class, 'store_rest' ), 10, 3 );
		add_action( Warmer::HOOK, array( Warmer::class, 'cron' ) );
	}
}
