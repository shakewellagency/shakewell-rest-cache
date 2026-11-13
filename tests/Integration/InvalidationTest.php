<?php
/**
 * Content changes move the generation on, so earlier answers are never served again.
 *
 * @package Shakewell\RestCache
 */

namespace Shakewell\RestCache\Tests;

final class InvalidationTest extends CacheTestCase {

	/**
	 * Asserts that $change moves the generation and that a stored answer is then rebuilt.
	 *
	 * @param callable $change What an editor does.
	 */
	private function assertRetires( callable $change ): void {
		$this->visitor();
		$before = $this->generation();
		$change();
		$this->shutdown();
		$this->assertNotSame( $before, $this->generation() );
		$this->assertSame( 'MISS', $this->state( $this->visitor() ) );
	}

	/**
	 * Asserts that $change leaves the generation alone.
	 *
	 * @param callable $change What happens.
	 */
	private function assertKeeps( callable $change ): void {
		$this->visitor();
		$before = $this->generation();
		$change();
		$this->shutdown();
		$this->assertSame( $before, $this->generation() );
		$this->assertSame( 'HIT', $this->state( $this->visitor() ) );
	}

	public function test_publishing_a_post_retires_the_cache(): void {
		$this->sign_in();
		$this->assertRetires( fn() => self::factory()->post->create( array( 'post_status' => 'publish' ) ) );
	}

	public function test_editing_a_published_post_retires_the_cache(): void {
		$this->sign_in();
		$id = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$this->shutdown();
		$this->assertRetires( fn() => wp_update_post( array( 'ID' => $id, 'post_title' => 'Changed' ) ) );
	}

	public function test_unpublishing_and_deleting_retire_the_cache(): void {
		$this->sign_in();
		$id = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$this->shutdown();
		$this->assertRetires( fn() => wp_update_post( array( 'ID' => $id, 'post_status' => 'draft' ) ) );

		$id = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$this->shutdown();
		$this->assertRetires( fn() => wp_delete_post( $id, true ) );
	}

	public function test_saving_a_draft_keeps_the_cache(): void {
		$this->sign_in();
		$this->assertKeeps( fn() => self::factory()->post->create( array( 'post_status' => 'draft' ) ) );
	}

	public function test_an_anonymous_web_request_keeps_the_cache(): void {
		$this->assertKeeps( fn() => self::factory()->post->create( array( 'post_status' => 'publish' ) ) );
	}

	public function test_a_type_outside_the_configured_list_keeps_the_cache(): void {
		$this->sign_in();
		add_filter( 'shakewell_rest_cache_post_types', static fn() => array( 'page' ) );
		$this->assertKeeps( fn() => self::factory()->post->create( array( 'post_status' => 'publish' ) ) );
		$this->assertRetires( fn() => self::factory()->post->create( array( 'post_type' => 'page', 'post_status' => 'publish' ) ) );
	}

	public function test_a_term_change_retires_the_cache(): void {
		$this->sign_in();
		$this->assertRetires( fn() => self::factory()->term->create( array( 'taxonomy' => 'category' ) ) );
	}

	public function test_a_menu_change_retires_the_cache(): void {
		$this->sign_in();
		$this->assertRetires( fn() => wp_create_nav_menu( 'Main' ) );
	}

	public function test_deleting_an_attachment_retires_the_cache(): void {
		$this->sign_in();
		$id = self::factory()->attachment->create();
		$this->shutdown();
		$this->assertRetires( fn() => wp_delete_attachment( $id, true ) );
	}

	public function test_meta_of_published_content_by_an_editor_retires_the_cache(): void {
		$this->sign_in();
		$id = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$this->shutdown();
		$this->assertRetires( fn() => update_post_meta( $id, 'colour', 'red' ) );
	}

	public function test_edit_lock_meta_and_meta_of_drafts_keep_the_cache(): void {
		$this->sign_in();
		$published = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$draft     = self::factory()->post->create( array( 'post_status' => 'draft' ) );
		$this->shutdown();
		$this->assertKeeps( fn() => update_post_meta( $published, '_edit_lock', '1:2' ) );
		$this->assertKeeps( fn() => update_post_meta( $draft, 'colour', 'red' ) );
	}

	public function test_meta_written_outside_an_editor_session_keeps_the_cache(): void {
		$id = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$this->shutdown();
		$this->assertKeeps( fn() => update_post_meta( $id, 'colour', 'red' ) );
	}

	public function test_listed_options_and_prefixes_retire_the_cache_and_others_do_not(): void {
		$this->sign_in();
		add_filter( 'shakewell_rest_cache_option_prefixes', static fn() => array( 'options_' ) );
		$this->assertRetires( fn() => update_option( 'blogname', 'A new name' ) );
		$this->assertRetires( fn() => update_option( 'options_footer', 'x' ) );
		$this->assertKeeps( fn() => update_option( 'unrelated_setting', 'x' ) );
	}

	public function test_the_option_list_is_configurable(): void {
		$this->sign_in();
		add_filter( 'shakewell_rest_cache_options', static fn() => array( 'my_option' ) );
		$this->assertRetires( fn() => update_option( 'my_option', 'x' ) );
		$this->assertKeeps( fn() => update_option( 'blogname', 'Not listed now' ) );
	}

	public function test_one_request_with_many_changes_moves_the_generation_once_and_reports_why(): void {
		$this->sign_in();
		$seen = array();
		add_action(
			'shakewell_rest_cache_invalidated',
			static function ( $reasons ) use ( &$seen ) {
				$seen[] = $reasons;
			}
		);
		$before = $this->generation();
		$id     = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		update_post_meta( $id, 'a', '1' );
		update_option( 'blogname', 'x' );
		$this->assertSame( $before, $this->generation(), 'nothing moves before shutdown' );
		$this->shutdown();
		$this->shutdown();
		$this->assertCount( 1, $seen );
		$this->assertArrayHasKey( 'option', $seen[0] );
	}

	public function test_the_generation_survives_the_object_cache_losing_it(): void {
		$generation = $this->generation();
		wp_cache_flush();
		$this->assertSame( $generation, $this->generation() );
	}

	public function test_bump_is_logged_when_enabled(): void {
		$this->sign_in();
		add_filter( 'shakewell_rest_cache_log', '__return_true' );
		$file = (string) tempnam( sys_get_temp_dir(), 'rc' );
		$old  = (string) ini_get( 'error_log' );
		ini_set( 'error_log', $file ); // phpcs:ignore WordPress.PHP.IniSet.Risky
		update_option( 'blogname', 'x' );
		$this->shutdown();
		ini_set( 'error_log', $old ); // phpcs:ignore WordPress.PHP.IniSet.Risky
		$log = (string) file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		unlink( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
		$this->assertStringContainsString( 'shakewell-rest-cache: bump', $log );
		$this->assertStringContainsString( 'option (1: blogname)', $log );
	}
}
