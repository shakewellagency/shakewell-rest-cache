<?php
/**
 * REST answers: what is stored, what is served, and what is never touched.
 *
 * @package Shakewell\RestCache
 */

namespace Shakewell\RestCache\Tests;

use Shakewell\RestCache\Cache;
use WP_REST_Request;

final class RestTest extends CacheTestCase {

	public function test_second_request_is_served_from_the_cache(): void {
		$first  = $this->request( '/shop/v1/list' );
		$second = $this->request( '/shop/v1/list' );
		$this->assertSame( 'MISS', $this->state( $first ) );
		$this->assertSame( 'HIT', $this->state( $second ) );
		$this->assertSame( 1, $this->calls );
		$this->assertSame( $first->get_data(), $second->get_data() );
		$this->assertSame( 200, $second->get_status() );
	}

	public function test_a_hit_keeps_the_headers_of_the_original_response(): void {
		$this->request( '/shop/v1/list' );
		$hit = $this->request( '/shop/v1/list' );
		$this->assertSame( 'HIT', $this->state( $hit ) );
		$this->assertSame( '5', $hit->get_headers()['X-Total'] );
	}

	public function test_an_answer_that_finishes_after_a_change_is_not_stored_under_the_new_generation(): void {
		$server  = rest_get_server();
		$request = new WP_REST_Request( 'GET', '/shop/v1/list' );
		apply_filters( 'rest_pre_dispatch', null, $server, $request );
		$response = $server->dispatch( $request );
		Cache::bump(); // An editor's save finished while this request was running.
		apply_filters( 'rest_post_dispatch', $response, $server, $request );
		$this->assertSame( 'MISS', $this->state( $this->request( '/shop/v1/list' ) ), 'the stale answer was not stored' );
		$this->assertSame( 'HIT', $this->state( $this->request( '/shop/v1/list' ) ) );
	}

	public function test_a_value_that_is_not_a_plain_slug_bypasses_the_cache(): void {
		$this->request( '/shop/v1/list', array( 'page' => 'a b' ) );
		$this->assertNull( $this->state( $this->request( '/shop/v1/list', array( 'page' => 'a b' ) ) ) );
		$this->request( '/shop/v1/list', array( 'page' => str_repeat( '1', 65 ) ) );
		$this->assertNull( $this->state( $this->request( '/shop/v1/list', array( 'page' => str_repeat( '1', 65 ) ) ) ) );
		$this->assertSame( 4, $this->calls );
	}

	public function test_a_route_matches_whole_and_unlisted_routes_are_untouched(): void {
		$this->request( '/shop/v1/private' );
		$second = $this->request( '/shop/v1/private' );
		$this->assertNull( $this->state( $second ) );
		$this->assertSame( 2, $this->calls );
	}

	public function test_listed_params_are_part_of_the_key(): void {
		$this->request( '/shop/v1/list', array( 'page' => '1' ) );
		$this->request( '/shop/v1/list', array( 'page' => '2' ) );
		$again = $this->request( '/shop/v1/list', array( 'page' => '2' ) );
		$this->assertSame( 'HIT', $this->state( $again ) );
		$this->assertSame( '2', $again->get_data()['page'] );
		$this->assertSame( 2, $this->calls );
	}

	public function test_an_unlisted_param_bypasses_the_cache(): void {
		$this->request( '/shop/v1/list', array( 'x' => 'random1' ) );
		$second = $this->request( '/shop/v1/list', array( 'x' => 'random1' ) );
		$this->assertNull( $this->state( $second ) );
		$this->assertSame( 2, $this->calls );
	}

	public function test_a_signed_in_user_is_neither_served_nor_stored(): void {
		$this->sign_in();
		$this->request( '/shop/v1/list' );
		$this->request( '/shop/v1/list' );
		$this->assertSame( 2, $this->calls );
		wp_set_current_user( 0 );
		$this->assertSame( 'MISS', $this->state( $this->request( '/shop/v1/list' ) ), 'nothing was stored for the user' );
	}

	public function test_a_signed_in_user_is_not_served_what_a_visitor_stored(): void {
		$this->request( '/shop/v1/list' );
		$this->sign_in();
		$response = $this->request( '/shop/v1/list' );
		$this->assertNull( $this->state( $response ) );
		$this->assertSame( 2, $this->calls );
	}

	public function test_only_get_is_cached(): void {
		$this->request( '/shop/v1/list', array(), 'POST' );
		$get = $this->request( '/shop/v1/list' );
		$this->assertSame( 'MISS', $this->state( $get ), 'a POST stored nothing' );
		$post = $this->request( '/shop/v1/list', array(), 'POST' );
		$this->assertNull( $this->state( $post ), 'a POST is never served from the cache' );
		$this->assertSame( 3, $this->calls );
	}

	public function test_an_error_is_not_stored(): void {
		$response = $this->request( '/shop/v1/missing' );
		$this->assertSame( 404, $response->get_status() );
		$this->assertNull( $this->state( $response ) );
	}

	public function test_without_an_object_cache_nothing_is_hooked(): void {
		$this->tear_down_hooks();
		wp_using_ext_object_cache( false );
		\Shakewell\RestCache\Plugin::boot();
		$this->assertFalse( has_filter( 'rest_pre_dispatch', array( Cache::class, 'serve_rest' ) ) );
		$this->assertFalse( has_filter( 'rest_post_dispatch', array( Cache::class, 'store_rest' ) ) );
		$this->assertFalse( has_action( 'updated_option', array( \Shakewell\RestCache\Invalidation::class, 'on_option_change' ) ) );
		$this->request( '/shop/v1/list' );
		$this->request( '/shop/v1/list' );
		$this->assertSame( 2, $this->calls );
	}

	public function test_the_header_name_is_configurable(): void {
		add_filter( 'shakewell_rest_cache_header', static fn() => 'X-Example-Cache' );
		$first = $this->request( '/shop/v1/list' );
		$hit   = $this->request( '/shop/v1/list' );
		$this->assertSame( 'MISS', $first->get_headers()['X-Example-Cache'] );
		$this->assertSame( 'HIT', $hit->get_headers()['X-Example-Cache'] );
		$this->assertNull( $this->state( $hit ) );
	}

	public function test_ttl_is_configurable_and_filterable(): void {
		add_filter( 'shakewell_rest_cache_ttl', static fn() => 100 );
		add_filter( 'shakewell_rest_cache_ttl_jitter', static fn() => 0 );
		$this->assertSame( 100, Cache::ttl( 'rest', array() ) );
		add_filter(
			'shakewell_rest_cache_entry_ttl',
			static fn( $ttl, $kind, $payload ) => 'rest' === $kind && isset( $payload['short'] ) ? 7 : $ttl,
			10,
			3
		);
		$this->assertSame( 7, Cache::ttl( 'rest', array( 'short' => 1 ) ) );
		$this->assertSame( 100, Cache::ttl( 'rest', array() ) );
		$this->assertSame( 100, Cache::ttl( 'page', '' ) );
	}

	public function test_an_invalid_route_pattern_disables_matching_instead_of_failing(): void {
		add_filter( 'shakewell_rest_cache_routes', static fn() => array( '/shop/(' ), 20 );
		$this->request( '/shop/v1/list' );
		$this->assertNull( $this->state( $this->request( '/shop/v1/list' ) ) );
	}

	/**
	 * Drops the hooks set_up registered, to test boot() from nothing.
	 */
	private function tear_down_hooks(): void {
		remove_all_filters( 'rest_pre_dispatch' );
		remove_all_filters( 'rest_post_dispatch' );
		remove_all_actions( 'updated_option' );
	}
}
