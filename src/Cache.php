<?php
/**
 * Keys, the generation that invalidates them, and reading and storing answers.
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

	const GROUP   = 'shakewell_rest_cache';
	const DURABLE = 'shakewell_rest_cache_generation';

	/**
	 * Query parameters that never change an answer, so they are dropped from the key.
	 * Anything else a page or route does not list makes the request bypass the cache.
	 */
	const TRACKING = array( 'utm_[a-z_]+', 'gclid', 'gbraid', 'wbraid', 'fbclid', 'msclkid', 'dclid', '_ga', '_gl', '_kx', 'mc_cid', 'mc_eid', 'gad_source', 'gad_campaignid', 'srsltid', 'rwg_token' );

	/** A page parameter is keyed only when its value is a plain slug or number. */
	const PARAM_VALUE = '/^[\w,-]{0,64}$/';

	/**
	 * Cache key of the request being buffered for storage, or null.
	 *
	 * @var string|null
	 */
	private static ?string $page_key = null;

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

	/* ------------------------------------------------------------ generation */

	/**
	 * The current generation, created on first use.
	 */
	public static function generation(): string {
		$generation = self::read_generation();
		if ( null === $generation ) {
			$generation = wp_generate_uuid4();
			if ( wp_cache_add( 'generation', $generation, self::GROUP ) ) {
				update_option( self::DURABLE, $generation, false );
			} else {
				$generation = (string) wp_cache_get( 'generation', self::GROUP, true );
			}
		}
		return $generation;
	}

	/**
	 * Starts a new generation: every stored answer stops being served.
	 */
	public static function bump(): void {
		$generation = wp_generate_uuid4();
		wp_cache_set( 'generation', $generation, self::GROUP );
		update_option( self::DURABLE, $generation, false );
	}

	/**
	 * The generation from the object cache, forced past the in-process copy so a long-running worker
	 * sees an editor's change. Kept in an option too: an object cache that evicts it would otherwise
	 * drop every stored answer at once.
	 */
	private static function read_generation(): ?string {
		$value = wp_cache_get( 'generation', self::GROUP, true );
		if ( is_string( $value ) && '' !== $value ) {
			return $value;
		}
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- must bypass the options cache, which is the cache being rebuilt.
		$durable = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::DURABLE ) );
		if ( ! is_string( $durable ) || '' === $durable ) {
			return null;
		}
		wp_cache_add( 'generation', $durable, self::GROUP );
		return $durable;
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
		return $kind . ':' . md5( ( $generation ?? self::generation() ) . '|' . wp_date( 'Y-m-d' ) . '|' . $identity );
	}

	/**
	 * The cache key for a REST route and its query parameters.
	 *
	 * @param string              $route  Route such as /books/v1/list.
	 * @param array<string,mixed> $params Query parameters.
	 */
	public static function rest_key( string $route, array $params ): string {
		ksort( $params );
		return self::key( 'rest', $route . '?' . http_build_query( $params ) );
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
	 * Whether a request for a cached route carries only parameters that route takes. Without this,
	 * /route?x=<random> would store a new entry per request and fill the shared cache.
	 *
	 * @param string              $route  REST route.
	 * @param array<string,mixed> $params Query parameters.
	 */
	public static function route_params_allowed( string $route, array $params ): bool {
		$map     = Config::get( 'route_params', array() );
		$allowed = isset( $map[ $route ] ) && is_array( $map[ $route ] ) ? $map[ $route ] : array();
		foreach ( $params as $name => $value ) {
			if ( ! in_array( (string) $name, $allowed, true ) || ! is_scalar( $value ) ) {
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
		$bypass = ! empty( $_SERVER['HTTP_AUTHORIZATION'] );
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
		if ( 0 === strpos( $path, '/wp-json/' ) ) {
			// A cross-origin request needs the CORS headers the REST server adds as it serves.
			if ( ! empty( $_SERVER['HTTP_ORIGIN'] ) ) {
				return;
			}
			$hit = self::lookup_rest( rtrim( substr( $path, strlen( '/wp-json' ) ), '/' ), $_GET ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if ( null !== $hit ) {
				self::send_hit( (string) wp_json_encode( $hit ), 'application/json; charset=UTF-8', $method );
			}
			return;
		}

		if ( ! self::bypassed( true ) ) {
			$body = self::lookup_page( $uri );
			if ( null !== $body ) {
				self::send_hit( $body, 'application/json', $method );
			}
		}
	}

	/**
	 * A stored REST answer, or null.
	 *
	 * @param string              $route  REST route.
	 * @param array<string,mixed> $params Query parameters.
	 * @return mixed
	 */
	public static function lookup_rest( string $route, array $params ) {
		if ( ! self::is_cached_route( $route ) || ! self::route_params_allowed( $route, $params ) ) {
			return null;
		}
		$cached = wp_cache_get( self::rest_key( $route, $params ), self::GROUP );
		return is_array( $cached ) && array_key_exists( 'data', $cached ) ? $cached['data'] : null;
	}

	/**
	 * A stored page body, inflated, or null.
	 *
	 * @param string $request_uri Path with any query string.
	 */
	public static function lookup_page( string $request_uri ): ?string {
		if ( ! Config::get( 'pages', false ) ) {
			return null;
		}
		$key    = self::page_key( $request_uri );
		$stored = null === $key ? null : wp_cache_get( $key, self::GROUP );
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
	 */
	private static function send_hit( string $body, string $content_type, string $method ): void {
		status_header( 200 );
		if ( ! headers_sent() ) {
			header( 'Content-Type: ' . $content_type );
			header( Config::get( 'header', 'X-Shakewell-Rest-Cache' ) . ': HIT' );
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
		$uri  = self::server( 'REQUEST_URI' );
		$body = self::lookup_page( $uri );
		if ( null !== $body ) {
			self::send_hit( $body, 'application/json', $method );
		}
		$key = self::page_key( $uri );
		if ( null === $key || 'GET' !== $method ) {
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
		$data = self::lookup_rest( $request->get_route(), $request->get_query_params() );
		if ( null === $data ) {
			return $result;
		}
		// store_rest() skips any response carrying this header, so a hit is never stored again.
		$response = new \WP_REST_Response( $data, 200 );
		$response->header( Config::get( 'header', 'X-Shakewell-Rest-Cache' ), 'HIT' );
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
		if (
			! $response instanceof \WP_REST_Response ||
			! $request instanceof \WP_REST_Request ||
			'GET' !== $request->get_method() ||
			200 !== $response->get_status() ||
			isset( $response->get_headers()[ $header ] ) ||
			! self::is_cached_route( $request->get_route() ) ||
			! self::route_params_allowed( $request->get_route(), $request->get_query_params() ) ||
			is_user_logged_in() || self::bypassed( false )
		) {
			return $response;
		}
		// Stored as the JSON-ready array so nothing live (a WP_Post, a closure) is serialised.
		$data = $server->response_to_data( $response, false );
		wp_cache_set( self::rest_key( $request->get_route(), $request->get_query_params() ), array( 'data' => $data ), self::GROUP, self::ttl( 'rest', $data ) );
		$response->header( $header, 'MISS' );
		return $response;
	}
}
