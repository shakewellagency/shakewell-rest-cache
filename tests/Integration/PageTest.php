<?php
/**
 * Front-end page answers: keys, storing and serving.
 *
 * @package Shakewell\RestCache
 */

namespace Shakewell\RestCache\Tests;

use Shakewell\RestCache\Cache;

final class PageTest extends CacheTestCase {

	private const JSON = array( 'Content-Type: application/json; charset=UTF-8' );

	public function set_up(): void {
		parent::set_up();
		add_filter( 'shakewell_rest_cache_pages', '__return_true' );
	}

	/**
	 * The stored body for a URL, or null.
	 *
	 * @param string $uri Request URI.
	 */
	private function body( string $uri ): ?string {
		$page = Cache::lookup_page( $uri );
		return $page['body'] ?? null;
	}

	/**
	 * Visits a URL through serve_page(), prints $body, and stores it as shutdown would.
	 *
	 * @param string   $uri     Request URI.
	 * @param string   $body    What the theme prints.
	 * @param string[] $headers Response headers.
	 */
	private function visit( string $uri, string $body, array $headers = self::JSON ): void {
		// Not go_to(), which empties the object cache.
		$_SERVER['REQUEST_URI'] = $uri;
		Cache::serve_page();
		echo $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		Cache::store_page( $headers, 200 );
		ob_end_clean();
	}

	public function test_a_json_page_is_stored_and_found(): void {
		$this->visit( '/books/', '{"status":200,"title":"Books"}' );
		$this->assertSame( '{"status":200,"title":"Books"}', $this->body( '/books/' ) );
		$this->assertSame( $this->body( '/books/' ), $this->body( '/books' ), 'a trailing slash does not matter' );
	}

	public function test_nothing_is_stored_unless_pages_are_enabled(): void {
		remove_all_filters( 'shakewell_rest_cache_pages' );
		$this->go_to( '/books/' );
		Cache::serve_page();
		$this->assertNull( $this->body( '/books/' ) );
	}

	public function test_html_and_non_200_and_cookie_setting_answers_are_not_stored(): void {
		$this->visit( '/html/', '{"a":1}', array( 'Content-Type: text/html' ) );
		$this->assertNull( $this->body( '/html/' ) );

		$this->visit( '/cookie/', '{"a":1}', array( 'Content-Type: application/json', 'Set-Cookie: a=b' ) );
		$this->assertNull( $this->body( '/cookie/' ) );

		$this->go_to( '/missing/' );
		Cache::serve_page();
		echo '{"a":1}'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		Cache::store_page( self::JSON, 404 );
		ob_end_clean();
		$this->assertNull( $this->body( '/missing/' ) );
	}

	public function test_the_site_can_refuse_an_answer(): void {
		add_filter(
			'shakewell_rest_cache_page_cacheable',
			static fn( $ok, $decoded ) => 200 === ( $decoded['status'] ?? null ),
			10,
			2
		);
		$this->visit( '/ok/', '{"status":200}' );
		$this->visit( '/gone/', '{"status":404}' );
		$this->assertNotNull( $this->body( '/ok/' ) );
		$this->assertNull( $this->body( '/gone/' ) );
	}

	public function test_a_body_that_is_not_json_is_not_stored(): void {
		$this->visit( '/broken/', '{"a":1} <b>notice</b>' );
		$this->assertNull( $this->body( '/broken/' ) );
	}

	public function test_tracking_params_are_dropped_and_unknown_params_bypass(): void {
		$this->assertSame( Cache::page_key( '/books/' ), Cache::page_key( '/books/?utm_source=a&gclid=b&fbclid=c' ) );
		$this->assertNull( Cache::page_key( '/books/?random=1' ) );
		$this->assertNull( Cache::page_key( '/books/?page=<script>' ), 'a value that is not a plain slug bypasses' );
		$this->assertNotNull( Cache::page_key( '/books/?page=2' ) );
		$this->assertNotSame( Cache::page_key( '/books/?page=2' ), Cache::page_key( '/books/?page=3' ) );
	}

	public function test_page_params_and_ignored_params_come_from_settings(): void {
		add_filter( 'shakewell_rest_cache_page_params', static fn() => array( 'genre' ) );
		add_filter( 'shakewell_rest_cache_ignored_params', static fn() => array( 'ref_.*' ) );
		$this->assertNotNull( Cache::page_key( '/books/?genre=fiction' ) );
		$this->assertNull( Cache::page_key( '/books/?page=2' ) );
		$this->assertSame( Cache::page_key( '/books/' ), Cache::page_key( '/books/?ref_x=1' ) );
		$this->assertNull( Cache::page_key( '/books/?utm_source=a' ), 'the default tracking list was replaced' );
	}

	public function test_core_paths_are_skipped_and_the_list_is_configurable(): void {
		$this->assertNull( Cache::page_key( '/wp-admin/x' ) );
		$this->assertNull( Cache::page_key( '/wp/y' ) );
		$this->assertNotNull( Cache::page_key( '/app/z' ) );
		add_filter( 'shakewell_rest_cache_skip_paths', static fn() => array( 'wp[/-]', 'app/' ) );
		$this->assertNull( Cache::page_key( '/app/z' ) );
	}

	public function test_a_change_retires_stored_pages(): void {
		$this->visit( '/books/', '{"status":200}' );
		$this->assertNotNull( $this->body( '/books/' ) );
		$this->sign_in();
		update_option( 'blogname', 'x' );
		$this->shutdown();
		$this->assertNull( $this->body( '/books/' ) );
	}

	public function test_a_preview_argument_or_header_is_not_buffered(): void {
		$_GET['preview'] = 'true';
		$this->go_to( '/books/?preview=true' );
		$level = ob_get_level();
		Cache::serve_page();
		$this->assertSame( $level, ob_get_level() );
		unset( $_GET['preview'] );

		add_filter( 'shakewell_rest_cache_bypass_headers', static fn() => array( 'X-Preview' ) );
		$_SERVER['HTTP_X_PREVIEW'] = '1';
		$this->go_to( '/books/' );
		Cache::serve_page();
		$this->assertSame( $level, ob_get_level() );
	}

	public function test_a_signed_in_user_is_not_buffered(): void {
		$this->sign_in();
		$this->go_to( '/books/' );
		$level = ob_get_level();
		Cache::serve_page();
		$this->assertSame( $level, ob_get_level() );
	}

	public function test_a_head_request_is_not_buffered(): void {
		$_SERVER['REQUEST_METHOD'] = 'HEAD';
		$this->go_to( '/books/' );
		$_SERVER['REQUEST_METHOD'] = 'HEAD';
		$level                     = ob_get_level();
		Cache::serve_page();
		$this->assertSame( $level, ob_get_level() );
		$_SERVER['REQUEST_METHOD'] = 'GET';
	}
}
