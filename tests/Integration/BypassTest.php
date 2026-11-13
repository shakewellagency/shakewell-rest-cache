<?php
/**
 * Requests that must never touch the cache.
 *
 * @package Shakewell\RestCache
 */

namespace Shakewell\RestCache\Tests;

final class BypassTest extends CacheTestCase {

	public function test_a_configured_header_skips_serving_and_storing(): void {
		add_filter( 'shakewell_rest_cache_bypass_headers', static fn() => array( 'X-Preview' ) );
		$this->request( '/shop/v1/list' );
		$this->assertSame( 'HIT', $this->state( $this->request( '/shop/v1/list' ) ) );

		$_SERVER['HTTP_X_PREVIEW'] = '1';
		$this->assertNull( $this->state( $this->request( '/shop/v1/list' ) ), 'a stored answer is not served' );
		$this->request( '/shop/v1/item' );
		unset( $_SERVER['HTTP_X_PREVIEW'] );
		$this->assertSame( 'MISS', $this->state( $this->request( '/shop/v1/item' ) ), 'nothing was stored' );
	}

	public function test_the_header_is_ignored_when_not_configured(): void {
		$this->request( '/shop/v1/list' );
		$_SERVER['HTTP_X_PREVIEW'] = '1';
		$this->assertSame( 'HIT', $this->state( $this->request( '/shop/v1/list' ) ) );
	}

	public function test_a_login_cookie_skips_the_cache(): void {
		$this->request( '/shop/v1/list' );
		$_COOKIE['wordpress_logged_in_x'] = 'v';
		$this->assertNull( $this->state( $this->request( '/shop/v1/list' ) ) );
	}

	public function test_a_configured_cookie_prefix_skips_the_cache(): void {
		add_filter( 'shakewell_rest_cache_bypass_cookies', static fn() => array( 'example_session_' ) );
		$this->request( '/shop/v1/list' );
		$_COOKIE['example_session_1'] = 'v';
		$this->assertNull( $this->state( $this->request( '/shop/v1/list' ) ) );
		unset( $_COOKIE['example_session_1'] );
	}

	public function test_the_bypass_filter_has_the_last_word(): void {
		$this->request( '/shop/v1/list' );
		add_filter( 'shakewell_rest_cache_bypass', '__return_true' );
		$this->assertNull( $this->state( $this->request( '/shop/v1/list' ) ) );
	}

	public function test_an_authorization_header_skips_the_cache(): void {
		$this->request( '/shop/v1/list' );
		$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer x';
		$this->assertNull( $this->state( $this->request( '/shop/v1/list' ) ) );
		unset( $_SERVER['HTTP_AUTHORIZATION'] );
	}
}
