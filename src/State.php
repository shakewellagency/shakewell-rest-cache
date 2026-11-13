<?php
/**
 * The control values behind the cache: the generation, the stale window, rebuild locks and the warm token.
 *
 * @package Shakewell\RestCache
 */

namespace Shakewell\RestCache;

defined( 'ABSPATH' ) || exit;

/**
 * Every stored answer is keyed by a generation, and a change moves the generation on. The generation the
 * change replaced is kept as the "stable" one for a bounded time, so a request that finds no answer for the
 * new generation can be given the previous answer while one request rebuilds it. Anything that made content
 * non-public retires everything with no stable generation at all.
 */
final class State {

	/** Control values that are also kept in an option, so an object cache that evicts one does not lose it. */
	const DURABLE = array(
		'generation' => 'shakewell_rest_cache_generation',
		'stable'     => 'shakewell_rest_cache_stable',
		'since'      => 'shakewell_rest_cache_stale_since',
	);

	/** Stored for a control value that is absent, so reading it never falls through to the database. */
	const NONE = '0';

	/**
	 * Keys this request already holds a rebuild lock for, so a second check in the same request does not lose its own lock.
	 *
	 * @var array<string,bool>
	 */
	private static array $claimed = array();

	/**
	 * The current generation, created on first use.
	 */
	public static function generation(): string {
		$generation = self::read( 'generation' );
		if ( null === $generation ) {
			$generation = wp_generate_uuid4();
			if ( wp_cache_add( 'generation', $generation, Cache::GROUP ) ) {
				update_option( self::DURABLE['generation'], $generation, false );
				// Record "none" now, so a miss never has to ask the database whether there is a stale copy.
				self::write( 'stable', null );
				self::write( 'since', null );
			} else {
				$generation = (string) wp_cache_get( 'generation', Cache::GROUP, true );
			}
		}
		return $generation;
	}

	/**
	 * The last generation known to be complete, or null.
	 */
	public static function stable(): ?string {
		return self::read( 'stable' );
	}

	/**
	 * Seconds a replaced generation may still be served.
	 */
	public static function max_stale(): int {
		$default = (int) Config::get( 'ttl', HOUR_IN_SECONDS ) + (int) Config::get( 'ttl_jitter', 15 * MINUTE_IN_SECONDS );
		return max( 0, (int) Config::get( 'max_stale', $default ) );
	}

	/**
	 * Starts a new generation after a change.
	 *
	 * A soft change keeps the replaced generation servable as stale, except for the pages the change touched
	 * ($paths), which are always built fresh. A hard change (content that became non-public, was deleted, moved
	 * or renamed, and a flush) leaves nothing to serve stale. A window opens at the first soft change and is not
	 * extended by later ones, so no answer is served more than max_stale seconds after the first change it missed.
	 *
	 * @param string[] $paths Page paths the change touched.
	 * @param bool     $hard  True to serve nothing stale.
	 */
	public static function bump( array $paths = array(), bool $hard = false ): void {
		$old = self::generation();
		$new = wp_generate_uuid4();
		if ( $hard || 0 === self::max_stale() ) {
			self::write( 'stable', null );
			self::write( 'since', null );
		} else {
			$since = (int) self::read( 'since' );
			$open  = null !== self::stable() && $since > 0 && time() - $since <= self::max_stale();
			if ( ! $open ) {
				self::write( 'stable', $old );
				self::write( 'since', (string) time() );
			}
			$fresh = array_values( array_unique( array_merge( $open ? (array) self::fresh( $old ) : array(), $paths ) ) );
			// Before the generation moves, so a request that sees the new one also sees this list. Always
			// written, even empty, and kept past the window: a missing list means "unknown" and nothing is
			// served stale for a page.
			wp_cache_set( 'fresh:' . $new, $fresh, Cache::GROUP, self::max_stale() + HOUR_IN_SECONDS );
		}
		self::write( 'generation', $new );
	}

	/**
	 * Called once every answer of $generation is built: it becomes the one stale copies come from, and the
	 * window closes.
	 *
	 * @param string $generation The generation that was warmed.
	 */
	public static function mark_stable( string $generation ): void {
		self::write( 'stable', $generation );
		self::write( 'since', null );
		wp_cache_delete( 'fresh:' . $generation, Cache::GROUP );
	}

	/**
	 * The generation a stale answer may come from, or null when none may be served.
	 */
	public static function stale_generation(): ?string {
		if ( self::is_warm_request() ) {
			return null;
		}
		$stable = self::stable();
		if ( null === $stable || self::generation() === $stable ) {
			return null;
		}
		$since = (int) self::read( 'since' );
		return $since > 0 && time() - $since <= self::max_stale() ? $stable : null;
	}

	/**
	 * Page paths of $generation that must be built fresh rather than served stale.
	 *
	 * @param string $generation Generation.
	 * @return string[]|null The paths, or null when the list is missing (evicted or never written).
	 */
	public static function fresh( string $generation ): ?array {
		$fresh = wp_cache_get( 'fresh:' . $generation, Cache::GROUP, true );
		return is_array( $fresh ) ? $fresh : null;
	}

	/**
	 * Takes the right to rebuild $key. One request gets it; the others are served the stale answer. The lock
	 * expires on its own, so a rebuild that fails does not block the key for long.
	 *
	 * @param string $key Cache key being rebuilt.
	 */
	public static function claim_rebuild( string $key ): bool {
		if ( isset( self::$claimed[ $key ] ) ) {
			return true;
		}
		$ttl = max( 1, (int) Config::get( 'rebuild_lock', 30 ) );
		if ( wp_cache_add( 'rebuild:' . $key, 1, Cache::GROUP, $ttl ) ) {
			if ( ! self::$claimed ) {
				// A build that is never stored (an error, a cookie, a refusal, a change during the build) must not keep the lock.
				add_action( 'shutdown', array( self::class, 'release_claims' ), PHP_INT_MAX );
			}
			self::$claimed[ $key ] = true;
			return true;
		}
		return false;
	}

	/**
	 * Forgets which locks this request holds. Only tests call it, to stand for the start of another request.
	 */
	public static function forget_claims(): void {
		self::$claimed = array();
	}

	/**
	 * Gives back every lock this request still holds. Runs at shutdown.
	 */
	public static function release_claims(): void {
		foreach ( array_keys( self::$claimed ) as $key ) {
			self::release_rebuild( (string) $key );
		}
	}

	/**
	 * Gives the rebuild lock back once the answer is stored.
	 *
	 * @param string $key Cache key.
	 */
	public static function release_rebuild( string $key ): void {
		unset( self::$claimed[ $key ] );
		wp_cache_delete( 'rebuild:' . $key, Cache::GROUP );
	}

	/**
	 * A secret the warmer puts in the object cache for the length of a run, and sends in a header.
	 */
	public static function warm_token(): string {
		$token = wp_generate_password( 32, false );
		wp_cache_set( 'warm-token', $token, Cache::GROUP, 15 * MINUTE_IN_SECONDS );
		return $token;
	}

	/**
	 * Whether this request is the warmer's own: loopback, carrying the current token. It must build fresh
	 * rather than be answered with a stale copy.
	 */
	public static function is_warm_request(): bool {
		$sent = Cache::server( 'HTTP_X_SHAKEWELL_REST_CACHE_WARM' );
		if ( '' === $sent || ! in_array( Cache::server( 'REMOTE_ADDR' ), array( '127.0.0.1', '::1' ), true ) ) {
			return false;
		}
		$token = wp_cache_get( 'warm-token', Cache::GROUP, true );
		return is_string( $token ) && '' !== $token && hash_equals( $token, $sent );
	}

	/**
	 * A control value, from the object cache forced past the in-process copy (a long-running worker must see an
	 * editor's change), or else from its option.
	 *
	 * @param string $name generation, stable or since.
	 */
	private static function read( string $name ): ?string {
		$value = wp_cache_get( $name, Cache::GROUP, true );
		if ( is_string( $value ) && '' !== $value ) {
			return self::NONE === $value ? null : $value;
		}
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- must bypass the options cache, which is the cache being rebuilt.
		$durable = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::DURABLE[ $name ] ) );
		if ( ! is_string( $durable ) || '' === $durable ) {
			return null;
		}
		wp_cache_add( $name, $durable, Cache::GROUP );
		return self::NONE === $durable ? null : $durable;
	}

	/**
	 * Writes a control value to the object cache and its option, or removes both.
	 *
	 * @param string      $name  generation, stable or since.
	 * @param string|null $value Value, or null for none.
	 */
	private static function write( string $name, ?string $value ): void {
		$value ??= self::NONE;
		wp_cache_set( $name, $value, Cache::GROUP );
		update_option( self::DURABLE[ $name ], $value, false );
	}
}
