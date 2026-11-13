<?php
/**
 * Stale while rebuilding: one request rebuilds, the others are served the previous answer, within bounds,
 * and never for content that became non-public.
 *
 * @package Shakewell\RestCache
 */

namespace Shakewell\RestCache\Tests;

use Shakewell\RestCache\Cache;
use Shakewell\RestCache\State;
use WP_REST_Request;

final class StaleTest extends CacheTestCase {

	/**
	 * Runs only the pre-dispatch half of a request: what it returns, with the rebuild lock it may have won.
	 *
	 * @param string $route Route.
	 */
	private function begin( string $route = '/shop/v1/list' ): array {
		State::forget_claims(); // Each call stands for a request of its own.
		$request = new WP_REST_Request( 'GET', $route );
		$result  = apply_filters( 'rest_pre_dispatch', null, rest_get_server(), $request );
		return array( $request, $result );
	}

	/**
	 * Finishes a request that began with begin().
	 *
	 * @param WP_REST_Request $request Request.
	 */
	private function finish( WP_REST_Request $request ): \WP_REST_Response {
		$server = rest_get_server();
		// The pre-dispatch half already ran in begin(), as it does once in a real request.
		remove_filter( 'rest_pre_dispatch', array( Cache::class, 'serve_rest' ), PHP_INT_MAX - 10 );
		$response = rest_ensure_response( $server->dispatch( $request ) );
		add_filter( 'rest_pre_dispatch', array( Cache::class, 'serve_rest' ), PHP_INT_MAX - 10, 3 );
		return apply_filters( 'rest_post_dispatch', $response, $server, $request );
	}

	/**
	 * Stores an answer, then has an editor change an option.
	 */
	private function stored_then_changed(): void {
		$this->visitor();
		$this->sign_in();
		update_option( 'blogname', 'Changed' );
		$this->shutdown();
		wp_set_current_user( 0 );
	}

	public function test_the_previous_answer_is_served_while_one_request_rebuilds(): void {
		$this->stored_then_changed();
		[ $rebuilder, $result ] = $this->begin();
		$this->assertNull( $result, 'the first request rebuilds' );

		$during = $this->request( '/shop/v1/list' );
		$this->assertSame( 'STALE', $this->state( $during ) );
		$this->assertSame( 1, $during->get_data()['n'], 'the old answer' );
		$this->assertSame( 1, $this->calls, 'PHP was not run again' );

		$done = $this->finish( $rebuilder );
		$this->assertSame( 'MISS', $this->state( $done ) );
		$after = $this->request( '/shop/v1/list' );
		$this->assertSame( 'HIT', $this->state( $after ) );
		$this->assertSame( 2, $after->get_data()['n'], 'the rebuilt answer' );
	}

	public function test_a_burst_has_one_rebuilder_and_the_rest_are_stale(): void {
		$this->stored_then_changed();
		$rebuilders = 0;
		$stale      = 0;
		for ( $i = 0; $i < 8; $i++ ) {
			[ , $result ] = $this->begin();
			if ( null === $result ) {
				++$rebuilders;
			} elseif ( 'STALE' === $this->state( $result ) ) {
				++$stale;
			}
		}
		$this->assertSame( 1, $rebuilders );
		$this->assertSame( 7, $stale );
	}

	public function test_a_lost_rebuild_does_not_block_the_key_for_longer_than_the_lock(): void {
		$this->stored_then_changed();
		$this->begin();
		$key = Cache::rest_key( '/shop/v1/list', array() );
		$this->assertNotFalse( wp_cache_get( 'rebuild:' . $key, Cache::GROUP ) );
		wp_cache_delete( 'rebuild:' . $key, Cache::GROUP ); // The lock's time to live ran out.
		[ , $result ] = $this->begin();
		$this->assertNull( $result, 'the next request takes over the rebuild' );
	}

	public function test_nothing_is_stale_past_the_maximum_age(): void {
		add_filter( 'shakewell_rest_cache_max_stale', static fn() => 60 );
		$this->stored_then_changed();
		wp_cache_set( 'since', (string) ( time() - 120 ), Cache::GROUP );
		[ , $first ]  = $this->begin();
		[ , $second ] = $this->begin();
		$this->assertNull( $first );
		$this->assertNull( $second, 'no stale copy and no lock: both build' );
		$this->assertNull( State::stale_generation() );
	}

	public function test_a_zero_maximum_age_turns_stale_serving_off(): void {
		add_filter( 'shakewell_rest_cache_max_stale', '__return_zero' );
		$this->stored_then_changed();
		$this->assertNull( State::stable() );
		[ , $result ] = $this->begin();
		$this->assertNull( $result );
	}

	public function test_the_window_is_not_extended_by_later_changes(): void {
		add_filter( 'shakewell_rest_cache_max_stale', static fn() => 60 );
		$this->stored_then_changed();
		$stable = State::stable();
		wp_cache_set( 'since', (string) ( time() - 30 ), Cache::GROUP );
		$this->sign_in();
		update_option( 'blogname', 'Again' );
		$this->shutdown();
		$this->assertSame( $stable, State::stable(), 'a second change keeps the first stable copy' );
		wp_cache_set( 'since', (string) ( time() - 61 ), Cache::GROUP );
		update_option( 'blogname', 'Third' );
		$this->shutdown();
		$this->assertNotSame( $stable, State::stable(), 'an expired window starts again from the current copy' );
	}

	/**
	 * Asserts that $change leaves nothing to serve stale, for REST and for a stored page.
	 *
	 * @param callable $change What an editor does. Receives the post id.
	 */
	private function assertNothingStale( callable $change ): void {
		$this->set_permalink_structure( '/%postname%/' );
		add_filter( 'shakewell_rest_cache_pages', '__return_true' );
		$this->sign_in();
		$id   = self::factory()->post->create(
			array(
				'post_status' => 'publish',
				'post_name'   => 'hello',
			)
		);
		$this->shutdown();
		$path = (string) wp_parse_url( get_permalink( $id ), PHP_URL_PATH );
		wp_set_current_user( 0 );
		$_SERVER['REQUEST_URI'] = $path;
		Cache::serve_page();
		echo '{"status":200}'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		Cache::store_page( array( 'Content-Type: application/json' ), 200 );
		ob_end_clean();
		$this->visitor();
		$this->assertSame( '{"status":200}', Cache::lookup_page( $path )['body'] );
		$this->sign_in();

		$change( $id );
		$this->shutdown();
		wp_set_current_user( 0 );
		$this->assertNull( State::stale_generation(), 'no stale generation' );
		$this->assertNull( Cache::lookup_page( $path )['body'], 'the page is not served stale' );
		[ , $result ] = $this->begin();
		$this->assertNull( $result, 'the list is not served stale' );
	}

	public function test_a_page_that_became_a_draft_is_never_served_stale(): void {
		$this->assertNothingStale( fn( $id ) => wp_update_post( array( 'ID' => $id, 'post_status' => 'draft' ) ) );
	}

	public function test_a_page_that_became_private_is_never_served_stale(): void {
		$this->assertNothingStale( fn( $id ) => wp_update_post( array( 'ID' => $id, 'post_status' => 'private' ) ) );
	}

	public function test_a_trashed_or_deleted_page_is_never_served_stale(): void {
		$this->assertNothingStale( fn( $id ) => wp_trash_post( $id ) );
		$this->assertNothingStale( fn( $id ) => wp_delete_post( $id, true ) );
	}

	public function test_a_page_that_gained_a_password_is_never_served_stale(): void {
		$this->assertNothingStale( fn( $id ) => wp_update_post( array( 'ID' => $id, 'post_password' => 'secret' ) ) );
	}

	public function test_a_page_that_moved_is_never_served_stale(): void {
		$this->assertNothingStale( fn( $id ) => wp_update_post( array( 'ID' => $id, 'post_name' => 'renamed' ) ) );
	}

	public function test_a_configured_visibility_meta_key_leaves_nothing_stale(): void {
		add_filter( 'shakewell_rest_cache_hard_meta_keys', static fn() => array( 'is_private' ) );
		$this->assertNothingStale( fn( $id ) => update_post_meta( $id, 'is_private', 'true' ) );
	}

	public function test_a_deleted_attachment_is_never_served_stale(): void {
		$this->assertNothingStale( fn() => wp_delete_attachment( self::factory()->attachment->create(), true ) );
	}

	public function test_a_flush_leaves_nothing_stale(): void {
		$this->assertNothingStale( fn() => State::bump( array(), true ) );
	}

	public function test_the_page_a_change_touched_is_built_fresh_and_others_are_stale(): void {
		$this->set_permalink_structure( '/%postname%/' );
		add_filter( 'shakewell_rest_cache_pages', '__return_true' );
		$this->sign_in();
		$a = self::factory()->post->create( array( 'post_status' => 'publish', 'post_name' => 'aaa' ) );
		$b = self::factory()->post->create( array( 'post_status' => 'publish', 'post_name' => 'bbb' ) );
		$this->shutdown();
		wp_set_current_user( 0 );
		foreach ( array( $a, $b ) as $id ) {
			$_SERVER['REQUEST_URI'] = (string) wp_parse_url( get_permalink( $id ), PHP_URL_PATH );
			Cache::serve_page();
			echo '{"status":200,"id":' . (int) $id . '}'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			Cache::store_page( array( 'Content-Type: application/json' ), 200 );
			ob_end_clean();
		}
		State::mark_stable( State::generation() ); // As a finished warm does.
		$this->sign_in();
		wp_update_post( array( 'ID' => $a, 'post_title' => 'Edited' ) );
		$this->shutdown();
		wp_set_current_user( 0 );
		State::forget_claims();
		$touched = Cache::lookup_page( (string) wp_parse_url( get_permalink( $a ), PHP_URL_PATH ) );
		$path_b  = (string) wp_parse_url( get_permalink( $b ), PHP_URL_PATH );
		$this->assertNull( Cache::lookup_page( $path_b )['body'], 'the first request for the other page rebuilds it' );
		State::forget_claims();
		$other = Cache::lookup_page( $path_b );
		$this->assertNull( $touched['body'], 'the edited page is rebuilt' );
		$this->assertSame( 'STALE', $other['state'] );
		$this->assertNotNull( $other['body'] );
	}

	public function test_a_warm_request_is_never_answered_stale(): void {
		$this->stored_then_changed();
		$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
		$token                  = State::warm_token();
		$_SERVER['HTTP_X_SHAKEWELL_REST_CACHE_WARM'] = $token;
		$this->assertNull( State::stale_generation() );
		[ , $result ] = $this->begin();
		$this->assertNull( $result );

		$_SERVER['HTTP_X_SHAKEWELL_REST_CACHE_WARM'] = 'wrong';
		$this->assertNotNull( State::stale_generation(), 'a guessed token is just a visitor' );
		$_SERVER['HTTP_X_SHAKEWELL_REST_CACHE_WARM'] = $token;
		$_SERVER['REMOTE_ADDR']                      = '203.0.113.9';
		$this->assertNotNull( State::stale_generation(), 'and so is the right token from elsewhere' );
	}

	/**
	 * Stores a page for a post as a visitor, closes the window as a finished warm does, then has an editor change an option.
	 *
	 * @return string The page path.
	 */
	private function stored_page_then_changed(): string {
		$this->set_permalink_structure( '/%postname%/' );
		add_filter( 'shakewell_rest_cache_pages', '__return_true' );
		$id   = self::factory()->post->create(
			array(
				'post_status' => 'publish',
				'post_name'   => 'solo',
			)
		);
		$path = (string) wp_parse_url( get_permalink( $id ), PHP_URL_PATH );
		$_SERVER['REQUEST_URI'] = $path;
		Cache::serve_page();
		echo '{"status":200}'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		Cache::store_page( array( 'Content-Type: application/json' ), 200 );
		ob_end_clean();
		State::mark_stable( State::generation() );
		$this->sign_in();
		update_option( 'blogname', 'Changed' );
		$this->shutdown();
		wp_set_current_user( 0 );
		State::forget_claims();
		return $path;
	}

	public function test_a_status_check_or_dry_run_takes_no_rebuild_lock(): void {
		$path = $this->stored_page_then_changed();
		$key  = Cache::page_key( $path );
		$this->assertFalse( Cache::is_warm( $path ), 'the new generation is cold' );
		\Shakewell\RestCache\Warmer::run( array( 'dry_run' => true ) );
		$this->assertFalse( wp_cache_get( 'rebuild:' . $key, Cache::GROUP ) );
		$this->assertNull( Cache::lookup_page( $path )['body'], 'the first real visitor still rebuilds' );
	}

	public function test_a_head_request_never_takes_the_rebuild_lock(): void {
		$this->visitor();
		$path = $this->stored_page_then_changed();
		$head = Cache::lookup_page( $path, false );
		$this->assertSame( 'STALE', $head['state'] );
		$this->assertFalse( wp_cache_get( 'rebuild:' . $head['key'], Cache::GROUP ) );
		$rest = Cache::lookup_rest( '/shop/v1/list', array(), false );
		$this->assertSame( 'STALE', $rest['state'] );
		$this->assertFalse( wp_cache_get( 'rebuild:' . Cache::rest_key( '/shop/v1/list', array() ), Cache::GROUP ) );
		$this->assertNull( Cache::lookup_page( $path )['body'], 'a GET then rebuilds' );
	}

	public function test_a_build_that_is_never_stored_gives_the_lock_back_at_shutdown(): void {
		$path = $this->stored_page_then_changed();
		$page = Cache::lookup_page( $path );
		$this->assertNull( $page['body'] );
		$this->assertNotFalse( wp_cache_get( 'rebuild:' . $page['key'], Cache::GROUP ) );
		State::release_claims(); // What the shutdown hook runs, when the build ended without storing.
		$this->assertFalse( wp_cache_get( 'rebuild:' . $page['key'], Cache::GROUP ) );
		$this->assertNotFalse( has_action( 'shutdown', array( State::class, 'release_claims' ) ) );
	}

	public function test_a_missing_fresh_list_means_no_page_is_served_stale(): void {
		$path = $this->stored_page_then_changed();
		wp_cache_delete( 'fresh:' . State::generation(), Cache::GROUP );
		$this->assertNull( Cache::lookup_page( $path, false )['body'] );
		$this->assertNotNull( State::stale_generation(), 'REST can still be served stale' );
	}

	public function test_a_miss_without_a_stale_copy_does_not_query_the_database(): void {
		global $wpdb;
		State::bump( array(), true );
		$before = $wpdb->num_queries;
		State::stale_generation();
		State::stale_generation();
		$this->assertSame( $before, $wpdb->num_queries );
		$this->assertNull( State::stable() );
	}

	public function test_stale_state_survives_the_object_cache_losing_it(): void {
		$this->stored_then_changed();
		$stable = State::stable();
		wp_cache_flush();
		$this->assertSame( $stable, State::stable() );
		$this->assertNotNull( State::stale_generation() );
	}
}
