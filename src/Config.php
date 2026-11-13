<?php
/**
 * Settings: constants first, then environment variables, then a filter.
 *
 * @package Shakewell\RestCache
 */

namespace Shakewell\RestCache;

defined( 'ABSPATH' ) || exit;

/**
 * Every setting is a constant or environment variable named SHAKEWELL_REST_CACHE_<NAME> (upper case),
 * and the result passes through the filter shakewell_rest_cache_<name> (lower case).
 * A constant wins over an environment variable. Lists and maps may be PHP arrays in a constant, or in an
 * environment variable a JSON value or a comma-separated list (lists only; use JSON when a value holds a comma).
 */
final class Config {

	/**
	 * Reads one setting.
	 *
	 * @param string $name     Lower-case setting name, for example "ttl".
	 * @param mixed  $fallback Default. Its type (string, int, bool, array) decides how an environment value is read.
	 * @return mixed
	 */
	public static function get( string $name, $fallback = '' ) {
		$const = 'SHAKEWELL_REST_CACHE_' . strtoupper( $name );
		$value = null;
		if ( defined( $const ) ) {
			$value = constant( $const );
		} else {
			$env = getenv( $const );
			if ( is_string( $env ) && '' !== trim( $env ) ) {
				$value = trim( $env );
			}
		}
		if ( null === $value ) {
			$value = $fallback;
		} elseif ( is_array( $fallback ) ) {
			if ( is_string( $value ) ) {
				$decoded = json_decode( $value, true );
				$value   = is_array( $decoded ) ? $decoded : array_values( array_filter( array_map( 'trim', explode( ',', $value ) ), 'strlen' ) );
			} elseif ( ! is_array( $value ) ) {
				$value = $fallback;
			}
		} elseif ( is_bool( $fallback ) ) {
			$value = filter_var( $value, FILTER_VALIDATE_BOOLEAN );
		} elseif ( is_int( $fallback ) ) {
			$value = (int) $value;
		} else {
			$value = is_scalar( $value ) ? trim( (string) $value ) : $fallback;
		}
		return apply_filters( 'shakewell_rest_cache_' . $name, $value );
	}

	/**
	 * A list setting as an array of non-empty strings.
	 *
	 * @param string $name     Setting name.
	 * @param array  $fallback Default list.
	 * @return string[]
	 */
	public static function list( string $name, array $fallback = array() ): array {
		$value = self::get( $name, $fallback );
		return is_array( $value ) ? array_values( array_filter( array_map( 'strval', array_filter( $value, 'is_scalar' ) ), 'strlen' ) ) : array();
	}

	/**
	 * A list of regular expression fragments as one pattern, or null for an empty list or an invalid fragment.
	 *
	 * @param string[] $fragments Patterns without delimiters.
	 * @param bool     $whole     True to match the whole subject, false to match its start.
	 * @param string   $flags     Pattern flags.
	 */
	public static function pattern( array $fragments, bool $whole, string $flags = '' ): ?string {
		if ( ! $fragments ) {
			return null;
		}
		$pattern = '~^(?:' . implode( '|', str_replace( '~', '\~', $fragments ) ) . ')' . ( $whole ? '$' : '' ) . '~' . $flags;
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- an invalid pattern must disable matching, not warn on every request.
		return false === @preg_match( $pattern, '' ) ? null : $pattern;
	}
}
