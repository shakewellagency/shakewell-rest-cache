<?php
/**
 * The warmer: what it requests, its protections, and the scheduled warm after a change.
 *
 * @package Shakewell\RestCache
 */

namespace Shakewell\RestCache\Tests;

use Shakewell\RestCache\Cache;
use Shakewell\RestCache\State;
use Shakewell\RestCache\Warmer;

final class WarmTest extends CacheTestCase {

	/**
	 * Fetches the fake site made.
	 *
	 * @var string[]
	 */
	private array $fetched = array();

	/**
	 * Headers of the last fake fetch.
	 *
	 * @var array<string,string>
	 */
	private array $headers = array();

	public function set_up(): void {
		parent::set_up();
		add_filter( 'shakewell_rest_cache_warm_paths', static fn() => array( '/wp-json/shop/v1/list', '/wp-json/shop/v1/item', '/wp-json/shop/v1/private' ) );
	}

	/**
	 * A stand-in for the site answering a loopback request: the REST request runs and stores as a visitor.
	 *
	 * @param string               $url     URL.
	 * @param array<string,string> $headers Headers.
	 */
	public function fetch( string $url, array $headers ): bool {
		$this->fetched[] = $url;
		$this->headers   = $headers;
		$this->request( substr( (string) wp_parse_url( $url, PHP_URL_PATH ), strlen( '/wp-json' ) ) );
		return true;
	}

	/**
	 * Runs the warmer against the stand-in.
	 *
	 * @param array<string,mixed> $options Options.
	 */
	private function warm( array $options = array() ): array {
		$this->fetched = array();
		return Warmer::run( $options, array( $this, 'fetch' ) );
	}

	public function test_a_dry_run_lists_cold_paths_and_changes_nothing(): void {
		$result = $this->warm( array( 'dry_run' => true ) );
		$this->assertSame( array( '/wp-json/shop/v1/list', '/wp-json/shop/v1/item', '/wp-json/shop/v1/private' ), $result['todo'] );
		$this->assertSame( array(), $this->fetched );
		$this->assertSame( 0, $this->calls );
		$this->assertFalse( wp_cache_get( 'warm-lock', Cache::GROUP ) );
	}

	public function test_a_real_run_warms_cold_paths_and_a_second_run_does_nothing(): void {
		$first = $this->warm();
		$this->assertSame( 2, $first['warmed'] );
		$this->assertSame( 1, $first['failed'], 'the route that is never stored is reported, not retried forever' );
		$this->assertTrue( $first['complete'] );
		$this->assertCount( 3, $this->fetched );
		$this->assertSame( 'http://127.0.0.1/wp-json/shop/v1/list', $this->fetched[0] );

		$second = $this->warm();
		$this->assertSame( array(), $this->fetched, 'idempotent' );
		$this->assertSame( 3, $second['skipped'] );
		$this->assertSame( 'HIT', $this->state( $this->request( '/shop/v1/list' ) ) );
	}

	public function test_the_warm_request_carries_the_token_the_site_will_accept(): void {
		$this->warm();
		$this->assertSame( 'shakewell-rest-cache-warmer', $this->headers['User-Agent'] );
		$_SERVER['REMOTE_ADDR']                      = '127.0.0.1';
		$_SERVER['HTTP_X_SHAKEWELL_REST_CACHE_WARM'] = $this->headers['X-Shakewell-Rest-Cache-Warm'];
		$this->assertTrue( State::is_warm_request() );
	}

	public function test_extra_headers_are_configurable(): void {
		add_filter( 'shakewell_rest_cache_warm_headers', static fn() => array( 'X-Proxied-Host' => 'front.example' ) );
		$this->warm();
		$this->assertSame( 'front.example', $this->headers['X-Proxied-Host'] );
	}

	public function test_only_one_warmer_runs_at_a_time(): void {
		wp_cache_set( 'warm-lock', 'another-instance-1', Cache::GROUP );
		$result = $this->warm();
		$this->assertTrue( $result['locked'] );
		$this->assertSame( array(), $this->fetched );
		$this->assertSame( 'another-instance-1', wp_cache_get( 'warm-lock', Cache::GROUP ), 'the other warmer keeps its lock' );
	}

	public function test_the_lock_is_given_back(): void {
		$this->warm();
		$this->assertFalse( wp_cache_get( 'warm-lock', Cache::GROUP ) );
	}

	public function test_the_time_budget_stops_the_run_and_the_next_run_continues(): void {
		$slow = function ( string $url, array $headers ): bool {
			usleep( 20000 );
			return $this->fetch( $url, $headers );
		};
		$result = Warmer::run( array( 'max_seconds' => 0.01 ), $slow );
		$this->assertFalse( $result['complete'] );
		$this->assertLessThan( 3, $result['warmed'] + $result['failed'] );
		$this->assertNotSame( State::generation(), State::stable() );

		$this->fetched = array();
		$rest          = $this->warm();
		$this->assertTrue( $rest['complete'] );
		$this->assertLessThan( 3, count( $this->fetched ), 'only what was still cold' );
	}

	public function test_a_change_during_the_run_stops_it_without_marking_the_generation_stable(): void {
		$meddling = function ( string $url, array $headers ): bool {
			$this->fetch( $url, $headers );
			State::bump( array(), true );
			return true;
		};
		$result = Warmer::run( array(), $meddling );
		$this->assertFalse( $result['complete'] );
		$this->assertCount( 1, $this->fetched );
		$this->assertNull( State::stable() );
	}

	public function test_a_finished_run_makes_the_generation_the_stable_one(): void {
		$this->sign_in();
		update_option( 'blogname', 'x' );
		$this->shutdown();
		wp_set_current_user( 0 );
		$this->assertNotNull( State::stale_generation() );
		$this->warm();
		$this->assertSame( State::generation(), State::stable() );
		$this->assertNull( State::stale_generation(), 'the window is closed' );
	}

	public function test_an_empty_warm_list_does_not_close_the_stale_window(): void {
		remove_all_filters( 'shakewell_rest_cache_warm_paths' );
		$this->sign_in();
		update_option( 'blogname', 'x' );
		$this->shutdown();
		wp_set_current_user( 0 );
		$stable = State::stable();
		$this->warm();
		$this->assertSame( $stable, State::stable() );
	}

	public function test_pages_and_posts_of_the_configured_types_are_listed(): void {
		remove_all_filters( 'shakewell_rest_cache_warm_paths' );
		$this->set_permalink_structure( '/%postname%/' );
		add_filter( 'shakewell_rest_cache_pages', '__return_true' );
		add_filter( 'shakewell_rest_cache_warm_post_types', static fn() => array( 'page', 'post' ) );
		self::factory()->post->create( array( 'post_status' => 'publish', 'post_name' => 'listed' ) );
		self::factory()->post->create( array( 'post_status' => 'draft', 'post_name' => 'hidden' ) );
		$paths = Warmer::paths();
		$this->assertSame( '/', $paths[0] );
		$this->assertContains( '/listed/', $paths );
		$this->assertNotContains( '/hidden/', $paths );
		add_filter( 'shakewell_rest_cache_warm_list', static fn( $p ) => array_reverse( $p ) );
		$reversed = Warmer::paths();
		$this->assertSame( '/', end( $reversed ) );
	}

	public function test_a_change_schedules_a_warm_only_when_turned_on(): void {
		$this->sign_in();
		update_option( 'blogname', 'x' );
		$this->shutdown();
		$this->assertFalse( wp_next_scheduled( Warmer::HOOK ), 'off by default' );

		add_filter( 'shakewell_rest_cache_warm_after_change', '__return_true' );
		update_option( 'blogname', 'y' );
		$this->shutdown();
		$this->assertNotFalse( wp_next_scheduled( Warmer::HOOK ) );
		update_option( 'blogname', 'z' );
		$this->shutdown();
		$this->assertCount( 1, array_filter( _get_cron_array() ?: array(), static fn( $hooks ) => isset( $hooks[ Warmer::HOOK ] ) ), 'one queued warm, not one per change' );
	}

	public function test_the_cron_hook_is_registered(): void {
		$this->assertNotFalse( has_action( Warmer::HOOK, array( Warmer::class, 'cron' ) ) );
	}
}
