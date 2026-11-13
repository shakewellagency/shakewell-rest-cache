<?php
/**
 * Keys, and reading and storing answers.
 *
 * @package Shakewell\RestCache
 */

namespace Shakewell\RestCache;

defined( 'ABSPATH' ) || exit;

/**
 * Every key carries a generation and the site's date. An editor's change moves the generation on, so
 * every earlier answer is simply never asked for again and ages out of the object cache.
 */
final class Cache {

	const GROUP = 'shakewell_rest_cache';

	/**
	 * Query parameters that never change an answer, so they are dropped from the key.
	 * Anything else a page or route does not list makes the request bypass the cache.
	 */
	const TRACKING = array( 'utm_[a-z_]+', 'gclid', 'gbraid', 'wbraid', 'fbclid', 'msclkid', 'dclid', '_ga', '_gl', '_kx', 'mc_cid', 'mc_eid', 'gad_source', 'gad_campaignid', 'srsltid', 'rwg_token' );

	/** A page or route parameter is keyed only when its value is a plain slug or number. */
	const PARAM_VALUE = '/^[\w,-]{0,64}$/';

	/**
	 * Cache key of the request being buffered for storage, or null.
	 *
	 * @var string|null
	 */
	private static ?string $page_key = null;

	/**
	 * Cache keys of the REST requests in flight, computed before they were dispatched.
	 *
	 * @var \WeakMap|null
	 */
	private static ?\WeakMap $rest_keys = null;

	/**
	 * Output buffer level opened for it.
	 *
	 * @var int
	 */
	private static int $buffer_level = 0;

	/**
	 * A $_SERVER value as a string, or ''. Never run through sanitize_text_field(), which strips percent-encoded octets from a URL.
	 *
	 * @param string $name     Key.
	 * @param string $fallback Returned when the key is missing or empty.
	 */
	public static function server( string $name, string $fallback = '' ): string {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- read-only comparison or key lookup; the value is never output or stored.
		$value = isset( $_SERVER[ $name ] ) && is_string( $_SERVER[ $name ] ) ? wp_unslash( $_SERVER[ $name ] ) : '';
		return '' === $value ? $fallback : $value;
	}

	/**
	 * Whether a persistent object cache is present. Without one the plugin does nothing.
	 */
	public static function enabled(): bool {
		return function_exists( 'wp_using_ext_object_cache' ) && wp_using_ext_object_cache();
	}

	/* ------------------------------------------------------------------ keys */

	/**
	 * A cache key.
	 *
	 * @param string      $kind       "page" or "rest".
	 * @param string      $identity   What the answer depends on.
	 * @param string|null $generation Defaults to the current one.
	 */
	public static function key( string $kind, string $identity, ?string $generation = null ): string {
		return $kind . ':' . md5( ( $generation ?? State::generation() ) . '|' . wp_date( 'Y-m-d' ) . '|' . $identity );
	}

	/**
	 * The cache key for a REST route and its query parameters.
	 *
	 * @param string              $route  Route such as /books/v1/list.
	 * @param array<string,mixed> $params     Query parameters.
	 * @param string|null         $generation Defaults to the current one.
	 */
	public static function rest_key( string $route, array $params, ?string $generation = null ): string {
		ksort( $params );
		return self::key( 'rest', $route . '?' . http_build_query( $params ), $generation );
	}

	/**
	 * Whether a route is one the site chose to cache.
	 *
	 * @param string $route REST route.
	 */
	public static function is_cached_route( string $route ): bool {
		$pattern = Config::pattern( Config::list( 'routes' ), true );
		return null !== $pattern && 1 === preg_match( $pattern, $route );
	}

	/**
	 * Whether a request for a cached route carries only parameters that route takes, with plain values
	 * (letters, digits, underscore, comma, hyphen, up to 64 characters). Without this, /route?x=<random>
	 * would store a new entry per request and fill the shared cache.
	 *
	 * @param string              $route  REST route.
	 * @param array<string,mixed> $params Query parameters.
	 */
	public static function route_params_allowed( string $route, array $params ): bool {
		$map     = Config::get( 'route_params', array() );
		$allowed = isset( $map[ $route ] ) && is_array( $map[ $route ] ) ? $map[ $route ] : array();
		foreach ( $params as $name => $value ) {
			if ( ! in_array( (string) $name, $allowed, true ) || ! is_scalar( $value ) || ! preg_match( self::PARAM_VALUE, (string) $value ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * The cache key for a front-end page request, or null when it must not be cached.
	 *
	 * @param string $request_uri Path with any query string.
	 */
	public static function page_key( string $request_uri ): ?string {
		$identity = self::page_identity( $request_uri );
		return null === $identity ? null : self::key( 'page', $identity );
	}

	/**
	 * A page request reduced to what changes its answer, or null when it must not be cached.
	 *
	 * @param string $request_uri Path with any query string.
	 */
	private static function page_identity( string $request_uri ): ?string {
		$parts = wp_parse_url( $request_uri );
		if ( ! is_array( $parts ) || empty( $parts['path'] ) ) {
			return null;
		}
		$skip = Config::pattern( array_map( static fn( $p ) => '/' . ltrim( $p, '/' ), Config::list( 'skip_paths', array( 'wp[/-]' ) ) ), false );
		if ( null !== $skip && preg_match( $skip, $parts['path'] ) ) {
			return null;
		}

		$params = array();
		if ( ! empty( $parts['query'] ) ) {
			$ignored = Config::pattern( Config::list( 'ignored_params', self::TRACKING ), true, 'i' );
			$allowed = Config::list( 'page_params', array( 'page', 'paged' ) );
			parse_str( $parts['query'], $query );
			foreach ( $query as $name => $value ) {
				if ( null !== $ignored && preg_match( $ignored, (string) $name ) ) {
					continue;
				}
				if ( ! in_array( (string) $name, $allowed, true ) || ! is_scalar( $value ) || ! preg_match( self::PARAM_VALUE, (string) $value ) ) {
					return null;
				}
				$params[ $name ] = (string) $value;
			}
			ksort( $params );
		}

		// /books and /books/ answer the same, so they share one entry.
		$path = $parts['path'];
		if ( '/' !== $path && false === strpos( basename( $path ), '.' ) ) {
			$path = rtrim( $path, '/' ) . '/';
		}
		return $path . '?' . http_build_query( $params );
	}

	/* ---------------------------------------------------------------- bypass */

	/**
	 * Whether this request must never touch the cache: a signed-in or password-protected visitor, a
	 * configured preview header, cookie or query argument (pages only), or the filter says so.
	 *
	 * @param bool $page True for a front-end page request, false for REST.
	 */
	public static function bypassed( bool $page ): bool {
		// The header reaches PHP under different names on CGI and FastCGI; core reads all three for application passwords.
		$bypass = ! empty( $_SERVER['HTTP_AUTHORIZATION'] ) || ! empty( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ) || ! empty( $_SERVER['PHP_AUTH_USER'] );
		foreach ( Config::list( 'bypass_headers' ) as $header ) {
			$bypass = $bypass || ! empty( $_SERVER[ 'HTTP_' . strtoupper( str_replace( '-', '_', $header ) ) ] );
		}
		$prefixes = array_merge( array( 'wordpress_logged_in_', 'wp-postpass_' ), Config::list( 'bypass_cookies' ) );
		foreach ( array_keys( $_COOKIE ) as $name ) {
			foreach ( $prefixes as $prefix ) {
				$bypass = $bypass || 0 === strpos( (string) $name, $prefix );
			}
		}
		if ( $page ) {
			foreach ( Config::list( 'bypass_args', array( 'preview', 'p', 'page_id', 's' ) ) as $arg ) {
				$bypass = $bypass || isset( $_GET[ $arg ] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only tests that the argument is present.
			}
		}
		return (bool) apply_filters( 'shakewell_rest_cache_bypass', $bypass, $page );
	}

	/* --------------------------------------------------------------- serving */

	/**
	 * Answers a hit while the plugin loads, before the theme and most plugins have run. Off unless the
	 * "early" setting is on. Only keys a full request stored exist, so this repeats an answer WordPress
	 * already gave for the same URL; anything uncertain falls through to the normal path.
	 */
	public static function serve_early(): void {
		$method = self::server( 'REQUEST_METHOD' );
		if (
			! Config::get( 'early', false ) ||
			( 'GET' !== $method && 'HEAD' !== $method ) ||
			'/index.php' !== substr( self::server( 'SCRIPT_NAME' ), -10 ) ||
			is_admin() || wp_doing_ajax() || wp_doing_cron() || ( defined( 'WP_CLI' ) && WP_CLI ) ||
			self::bypassed( false )
		) {
			return;
		}

		$uri  = self::server( 'REQUEST_URI' );
		$path = (string) wp_parse_url( $uri, PHP_URL_PATH );
		// The REST base path, so a subdirectory install or a changed rest_url_prefix still matches. The prefix
		// filter must be hooked before this plugin loads. A ?rest_route= request takes the normal path.
		$base = self::rest_base();
		if ( 0 === strpos( $path, $base ) ) {
			// A cross-origin request needs the CORS headers the REST server adds as it serves.
			if ( ! empty( $_SERVER['HTTP_ORIGIN'] ) ) {
				return;
			}
			$hit = self::lookup_rest( rtrim( substr( $path, strlen( $base ) - 1 ), '/' ), $_GET ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if ( null !== $hit ) {
				self::send_hit( (string) wp_json_encode( $hit['data'] ), 'application/json; charset=UTF-8', $method, $hit['state'], $hit['headers'] );
			}
			return;
		}

		if ( ! self::bypassed( true ) ) {
			$page = self::lookup_page( $uri );
			if ( null !== $page && null !== $page['body'] ) {
				self::send_hit( $page['body'], 'application/json', $method, $page['state'] );
			}
		}
	}

	/**
	 * The path REST routes are served under, with a trailing slash: /wp-json/ unless the install or prefix differs.
	 */
	public static function rest_base(): string {
		return rtrim( (string) wp_parse_url( home_url( '/' . rest_get_url_prefix() ), PHP_URL_PATH ), '/' ) . '/';
	}

	/**
	 * Whether a path the warmer requests already has a stored answer. A path that can never be cached counts
	 * as warm only for a page; a route that is not stored is retried until the warmer marks it uncacheable.
	 *
	 * @param string $path Path with any query string.
	 */
	public static function is_warm( string $path ): bool {
		$base = self::rest_base();
		$bare = (string) wp_parse_url( $path, PHP_URL_PATH );
		if ( 0 === strpos( $bare, $base ) ) {
			parse_str( (string) wp_parse_url( $path, PHP_URL_QUERY ), $params );
			return is_array( wp_cache_get( self::rest_key( rtrim( substr( $bare, strlen( $base ) - 1 ), '/' ), $params ), self::GROUP ) );
		}
		$page = self::lookup_page( $path );
		return null === $page || is_array( wp_cache_get( $page['key'], self::GROUP ) );
	}

	/**
	 * A stored REST answer, or null when this request must build it. A stale answer is returned only to
	 * requests that did not win the right to rebuild it.
	 *
	 * @param string              $route  REST route.
	 * @param array<string,mixed> $params Query parameters.
	 * @return array{data:mixed,headers:array<string,string>,state:string}|null
	 */
	public static function lookup_rest( string $route, array $params ): ?array {
		if ( ! self::is_cached_route( $route ) || ! self::route_params_allowed( $route, $params ) ) {
			return null;
		}
		$key = self::rest_key( $route, $params );
		$hit = self::read_rest( $key, 'HIT' );
		if ( null !== $hit ) {
			return $hit;
		}
		$stable = State::stale_generation();
		$stale  = null === $stable ? null : self::read_rest( self::rest_key( $route, $params, $stable ), 'STALE' );
		return null !== $stale && ! State::claim_rebuild( $key ) ? $stale : null;
	}

	/**
	 * A stored REST entry in the shape lookup_rest() returns, or null.
	 *
	 * @param string $key   Cache key.
	 * @param string $state HIT or STALE.
	 * @return array{data:mixed,headers:array<string,string>,state:string}|null
	 */
	private static function read_rest( string $key, string $state ): ?array {
		$cached = wp_cache_get( $key, self::GROUP );
		if ( ! is_array( $cached ) || ! array_key_exists( 'data', $cached ) ) {
			return null;
		}
		return array(
			'data'    => $cached['data'],
			'headers' => isset( $cached['headers'] ) && is_array( $cached['headers'] ) ? $cached['headers'] : array(),
			'state'   => $state,
		);
	}

	/**
	 * A page request's cache key and any stored body, or null when the page must not be cached. The body is
	 * null on a miss; a stale body is returned only to requests that did not win the right to rebuild it.
	 *
	 * @param string $request_uri Path with any query string.
	 * @return array{key:string,body:?string,state:string}|null
	 */
	public static function lookup_page( string $request_uri ): ?array {
		$identity = Config::get( 'pages', false ) ? self::page_identity( $request_uri ) : null;
		if ( null === $identity ) {
			return null;
		}
		$key  = self::key( 'page', $identity );
		$body = self::read_page( $key );
		if ( null !== $body ) {
			return array(
				'key'   => $key,
				'body'  => $body,
				'state' => 'HIT',
			);
		}
		$stable = State::stale_generation();
		// A page the change touched is built fresh, never served stale.
		if ( null !== $stable && ! in_array( (string) strtok( $identity, '?' ), State::fresh( State::generation() ), true ) ) {
			$body = self::read_page( self::key( 'page', $identity, $stable ) );
			if ( null !== $body && ! State::claim_rebuild( $key ) ) {
				return array(
					'key'   => $key,
					'body'  => $body,
					'state' => 'STALE',
				);
			}
		}
		return array(
			'key'   => $key,
			'body'  => null,
			'state' => 'MISS',
		);
	}

	/**
	 * A stored page body, inflated, or null.
	 *
	 * @param string $key Cache key.
	 */
	private static function read_page( string $key ): ?string {
		$stored = wp_cache_get( $key, self::GROUP );
		if ( ! is_array( $stored ) || ! isset( $stored['z'] ) ) {
			return null;
		}
		$body = gzinflate( (string) $stored['z'] );
		return is_string( $body ) && '' !== $body ? $body : null;
	}

	/**
	 * Sends a stored body and ends the request.
	 *
	 * @param string $body         Body.
	 * @param string $content_type Content-Type.
	 * @param string $method       GET or HEAD.
	 * @param string $state        HIT or STALE.
	 * @param array  $headers      Headers of the original response, such as pagination totals.
	 */
	private static function send_hit( string $body, string $content_type, string $method, string $state = 'HIT', array $headers = array() ): void {
		status_header( 200 );
		if ( ! headers_sent() ) {
			foreach ( $headers as $name => $value ) {
				header( $name . ': ' . $value );
			}
			header( 'Content-Type: ' . $content_type );
			header( Config::get( 'header', 'X-Shakewell-Rest-Cache' ) . ': ' . $state );
		}
		// An early hit ends before wp_headers, so a site that adds headers there adds them here.
		do_action( 'shakewell_rest_cache_hit_headers' );
		if ( 'HEAD' !== $method ) {
			echo $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- a stored JSON body, sent as it was.
		}
		exit;
	}

	/**
	 * Front-end pages: answer from the cache, or buffer the response to store it at shutdown.
	 */
	public static function serve_page(): void {
		$method = self::server( 'REQUEST_METHOD', 'GET' );
		if (
			( 'GET' !== $method && 'HEAD' !== $method ) ||
			! Config::get( 'pages', false ) ||
			is_user_logged_in() || self::bypassed( true ) ||
			is_preview() || is_search() || is_feed() || is_robots() || is_trackback() || is_embed()
		) {
			return;
		}
		$page = self::lookup_page( self::server( 'REQUEST_URI' ) );
		if ( null === $page ) {
			return;
		}
		if ( null !== $page['body'] ) {
			self::send_hit( $page['body'], 'application/json', $method, $page['state'] );
		}
		// The key from before the build started, so a change that lands meanwhile cannot be stored under.
		$key = $page['key'];
		if ( 'GET' !== $method ) {
			return;
		}
		if ( ! headers_sent() ) {
			header( Config::get( 'header', 'X-Shakewell-Rest-Cache' ) . ': MISS' );
		}
		self::$page_key = $key;
		ob_start();
		self::$buffer_level = ob_get_level();
		// Before wp_ob_end_flush_all (1) empties the buffers.
		add_action( 'shutdown', array( self::class, 'store_page' ), 0 );
	}

	/**
	 * Stores the buffered page when it is a 200 JSON answer that sets no cookie and the site accepts.
	 *
	 * @param string[]|null $headers Response headers, as headers_list() returns them. Only tests pass them.
	 * @param int|null      $status  Response status. Only tests pass it.
	 */
	public static function store_page( ?array $headers = null, ?int $status = null ): void {
		$key            = self::$page_key;
		self::$page_key = null;
		if ( null === $key || 200 !== ( $status ?? http_response_code() ) ) {
			return;
		}
		// Buffers opened after ours still hold part of the page. Flush them down into ours, running their
		// handlers as wp_ob_end_flush_all() would, so what is stored is what the visitor receives.
		while ( ob_get_level() > self::$buffer_level ) {
			if ( ! @ob_end_flush() ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a handler that refuses means we cannot know what the visitor gets.
				return;
			}
		}
		if ( ob_get_level() !== self::$buffer_level ) {
			return;
		}

		$json = false;
		foreach ( $headers ?? headers_list() as $header ) {
			if ( 0 === stripos( $header, 'set-cookie:' ) ) {
				return;
			}
			$json = $json || ( 0 === stripos( $header, 'content-type:' ) && false !== stripos( $header, 'application/json' ) );
		}
		$body    = (string) ob_get_contents();
		$decoded = $json ? json_decode( $body, true ) : null;
		if ( ! is_array( $decoded ) || ! apply_filters( 'shakewell_rest_cache_page_cacheable', true, $decoded, $body ) ) {
			return;
		}
		// Compressed: payloads are large and share a small node with the object cache.
		wp_cache_set( $key, array( 'z' => gzdeflate( $body, 6 ) ), self::GROUP, self::ttl( 'page', $body ) );
		State::release_rebuild( $key );
	}

	/**
	 * Seconds to keep an answer: the configured TTL plus jitter, so entries do not all expire together.
	 *
	 * @param string $kind    "page" or "rest".
	 * @param mixed  $payload The body (page) or data (REST), for a filter that wants a shorter life.
	 */
	public static function ttl( string $kind, $payload ): int {
		$ttl = (int) Config::get( 'ttl', HOUR_IN_SECONDS ) + wp_rand( 0, max( 0, (int) Config::get( 'ttl_jitter', 15 * MINUTE_IN_SECONDS ) ) );
		return max( 1, (int) apply_filters( 'shakewell_rest_cache_entry_ttl', $ttl, $kind, $payload ) );
	}

	/**
	 * REST: answers a cached route from the cache.
	 *
	 * @param mixed            $result  Pre-dispatch result.
	 * @param \WP_REST_Server  $server  Server.
	 * @param \WP_REST_Request $request Request.
	 * @return mixed
	 */
	public static function serve_rest( $result, $server, $request ) {
		if ( null !== $result || ! $request instanceof \WP_REST_Request || 'GET' !== $request->get_method() || is_user_logged_in() || self::bypassed( false ) ) {
			return $result;
		}
		// On a miss, keep the key now. It must not be computed again when the answer is stored: a request that
		// began before an editor's save and ends after the generation moved would store its stale answer under the new one.
		self::$rest_keys           ??= new \WeakMap();
		self::$rest_keys[ $request ] = self::rest_key( $request->get_route(), $request->get_query_params() );
		$hit                         = self::lookup_rest( $request->get_route(), $request->get_query_params() );
		if ( null === $hit ) {
			return $result;
		}
		// store_rest() skips any response carrying this header, so a hit is never stored again.
		$response = new \WP_REST_Response( $hit['data'], 200 );
		$response->set_headers( $hit['headers'] );
		$response->header( Config::get( 'header', 'X-Shakewell-Rest-Cache' ), $hit['state'] );
		return $response;
	}

	/**
	 * REST: stores a 200 answer of a cached route.
	 *
	 * @param mixed            $response Response.
	 * @param \WP_REST_Server  $server   Server.
	 * @param \WP_REST_Request $request  Request.
	 * @return mixed
	 */
	public static function store_rest( $response, $server, $request ) {
		$header = Config::get( 'header', 'X-Shakewell-Rest-Cache' );
		$key    = $request instanceof \WP_REST_Request ? ( self::$rest_keys[ $request ] ?? null ) : null;
		if (
			null === $key ||
			! $response instanceof \WP_REST_Response ||
			'GET' !== $request->get_method() ||
			200 !== $response->get_status() ||
			isset( $response->get_headers()[ $header ] ) ||
			! self::is_cached_route( $request->get_route() ) ||
			! self::route_params_allowed( $request->get_route(), $request->get_query_params() ) ||
			is_user_logged_in() || self::bypassed( false )
		) {
			return $response;
		}
		// Stored as the JSON-ready array so nothing live (a WP_Post, a closure) is serialised. Embedded
		// objects are included when the request asked for them, as WP_REST_Server::serve_request() does.
		$embed   = $request->has_param( '_embed' ) ? rest_parse_embed_param( $request->get_param( '_embed' ) ) : false;
		$data    = $server->response_to_data( $response, $embed );
		$headers = array_diff_key( $response->get_headers(), array( $header => 1 ) );
		wp_cache_set(
			$key,
			array(
				'data'    => $data,
				'headers' => $headers,
			),
			self::GROUP,
			self::ttl( 'rest', $data )
		);
		State::release_rebuild( $key );
		$response->header( $header, 'MISS' );
		return $response;
	}
}
