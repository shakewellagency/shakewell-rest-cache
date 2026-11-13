<?php
/**
 * Shared set-up: the in-memory object cache stands in for a persistent one, plus a counted REST route.
 *
 * @package Shakewell\RestCache
 */

namespace Shakewell\RestCache\Tests;

use Shakewell\RestCache\Cache;
use Shakewell\RestCache\Invalidation;
use Shakewell\RestCache\Plugin;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * Base class. Tests act through real WordPress functions and the real REST server.
 */
abstract class CacheTestCase extends WP_UnitTestCase {

	/** Times the counted route's callback ran. */
	protected int $calls = 0;

	/** Settings the tests set through their filters. */
	private const SETTINGS = array(
		'routes',
		'route_params',
		'pages',
		'page_params',
		'skip_paths',
		'ignored_params',
		'bypass_headers',
		'bypass_cookies',
		'bypass_args',
		'post_types',
		'options',
		'option_prefixes',
		'ttl',
		'ttl_jitter',
		'header',
		'early',
		'log',
		'bypass',
		'entry_ttl',
		'page_cacheable',
		'invalidated',
	);

	public function set_up(): void {
		parent::set_up();
		wp_using_ext_object_cache( true );
		add_filter( 'shakewell_rest_cache_routes', static fn() => array( '/shop/v1/list', '/shop/v1/(item|tile)' ) );
		add_filter( 'shakewell_rest_cache_route_params', static fn() => array( '/shop/v1/list' => array( 'page' ) ) );
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
		Plugin::boot();

		global $wp_rest_server;
		$wp_rest_server = new \WP_REST_Server();
		do_action( 'rest_api_init', $wp_rest_server );
		$this->calls = 0;
	}

	public function tear_down(): void {
		global $wp_rest_server;
		$wp_rest_server = null;
		foreach ( self::SETTINGS as $name ) {
			remove_all_filters( 'shakewell_rest_cache_' . $name );
		}
		unset( $_SERVER['HTTP_X_PREVIEW'], $_COOKIE['wordpress_logged_in_x'], $_GET['preview'], $_GET['page'], $_SERVER['REQUEST_URI'] );
		wp_using_ext_object_cache( false );
		parent::tear_down();
	}

	/**
	 * Registers the counted routes.
	 */
	public function register_routes(): void {
		$callback = function ( WP_REST_Request $request ) {
			++$this->calls;
			$response = new \WP_REST_Response(
				array(
					'n'    => $this->calls,
					'page' => $request->get_param( 'page' ),
				)
			);
			$response->header( 'X-Total', '5' );
			return $response;
		};
		foreach ( array( '/shop/v1/list', '/shop/v1/item', '/shop/v1/private' ) as $route ) {
			register_rest_route(
				'shop/v1',
				substr( $route, strlen( '/shop/v1' ) ),
				array(
					array(
						'methods'             => 'GET',
						'callback'            => $callback,
						'permission_callback' => '__return_true',
					),
					array(
						'methods'             => 'POST',
						'callback'            => $callback,
						'permission_callback' => '__return_true',
					),
				)
			);
		}
	}

	/**
	 * Requests a route and returns the response.
	 *
	 * @param string              $route  Route.
	 * @param array<string,mixed> $params Query parameters.
	 * @param string              $method HTTP method.
	 */
	protected function request( string $route, array $params = array(), string $method = 'GET' ): \WP_REST_Response {
		$request = new WP_REST_Request( $method, $route );
		$request->set_query_params( $params );
		// rest_do_request() skips rest_post_dispatch, which only WP_REST_Server::serve_request() applies, so do what it does.
		$server   = rest_get_server();
		$response = $server->dispatch( $request );
		return apply_filters( 'rest_post_dispatch', rest_ensure_response( $response ), $server, $request );
	}

	/**
	 * Requests the counted list route as an anonymous visitor, whoever is signed in.
	 */
	protected function visitor(): \WP_REST_Response {
		$user = get_current_user_id();
		wp_set_current_user( 0 );
		$response = $this->request( '/shop/v1/list' );
		wp_set_current_user( $user );
		return $response;
	}

	/**
	 * The cache header of a response, or null.
	 *
	 * @param \WP_REST_Response $response Response.
	 */
	protected function state( \WP_REST_Response $response ): ?string {
		return $response->get_headers()['X-Shakewell-Rest-Cache'] ?? null;
	}

	/**
	 * Runs what shutdown would, so the generation moves.
	 */
	protected function shutdown(): void {
		Invalidation::apply();
	}

	/**
	 * The current generation.
	 */
	protected function generation(): string {
		return Cache::generation();
	}

	/**
	 * Signs in an administrator.
	 */
	protected function sign_in(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}
}
