<?php
/**
 * Moves the generation on when content an editor changes could alter a stored answer.
 *
 * @package Shakewell\RestCache
 */

namespace Shakewell\RestCache;

defined( 'ABSPATH' ) || exit;

/**
 * A change only marks the request dirty. The generation moves at shutdown, after the whole save: a post
 * is written first and its fields afterwards, and moving it at the first hook would let another request
 * rebuild and store half-saved content under the new generation.
 */
final class Invalidation {

	/** Options whose change shows on a page, besides those starting with a configured prefix. */
	const OPTIONS = array( 'blogname', 'blogdescription', 'page_on_front', 'show_on_front', 'permalink_structure', 'timezone_string', 'sidebars_widgets' );

	/**
	 * Why this request will retire the cache: reason => details, for the log.
	 *
	 * @var array<string,string[]>
	 */
	private static array $reasons = array();

	/**
	 * Registers the content-change hooks.
	 */
	public static function register(): void {
		add_action( 'transition_post_status', array( self::class, 'on_post_transition' ), 10, 3 );
		add_action( 'before_delete_post', array( self::class, 'on_post_delete' ), 10, 2 );
		// Not edit_attachment: an upload changes nothing a visitor sees until a page uses it, and saving
		// that page retires the cache anyway.
		foreach ( array( 'created_term', 'edited_term', 'delete_term', 'wp_update_nav_menu', 'wp_delete_nav_menu' ) as $hook ) {
			add_action( $hook, array( self::class, 'on_editor_change' ) );
		}
		add_action( 'delete_attachment', array( self::class, 'on_attachment_delete' ) );
		add_action( 'acf/save_post', array( self::class, 'on_acf_save' ) );
		foreach ( array( 'added_post_meta', 'updated_post_meta', 'deleted_post_meta' ) as $hook ) {
			add_action( $hook, array( self::class, 'on_meta_change' ), 10, 3 );
		}
		add_action( 'updated_option', array( self::class, 'on_option_change' ) );
		add_action( 'added_option', array( self::class, 'on_option_change' ) );
	}

	/**
	 * Retires the cache when this request finishes.
	 *
	 * @param string $reason Why, for the log.
	 * @param string $detail Detail, for the log.
	 */
	public static function mark_dirty( string $reason, string $detail = '' ): void {
		if ( ! self::$reasons ) {
			add_action( 'shutdown', array( self::class, 'apply' ), PHP_INT_MAX );
		}
		self::$reasons[ $reason ][] = $detail;
	}

	/**
	 * Moves the generation on and logs the cause. Runs at shutdown.
	 */
	public static function apply(): void {
		$reasons       = self::$reasons;
		self::$reasons = array();
		if ( ! $reasons ) {
			return;
		}
		Cache::bump();
		do_action( 'shakewell_rest_cache_invalidated', $reasons );
		if ( Config::get( 'log', false ) ) {
			self::log( $reasons );
		}
	}

	/**
	 * One line per request that retires the cache, so a site-wide rebuild can be traced to its cause.
	 * Details are grouped under their reason and capped; the query string is dropped, as it can carry nonces.
	 *
	 * @param array<string,string[]> $reasons Reason => details.
	 */
	private static function log( array $reasons ): void {
		$parts = array();
		foreach ( $reasons as $reason => $details ) {
			$details = array_values( array_unique( array_filter( $details, 'strlen' ) ) );
			$shown   = implode( ', ', array_slice( $details, 0, 3 ) ) . ( count( $details ) > 3 ? ', ...' : '' );
			$parts[] = $details ? sprintf( '%s (%d: %s)', $reason, count( $details ), $shown ) : $reason;
		}
		$uri = explode( '?', Cache::server( 'REQUEST_URI' ), 2 )[0];
		error_log( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			sprintf(
				'shakewell-rest-cache: bump by user %d via %s %s: %s',
				get_current_user_id(),
				Cache::server( 'REQUEST_METHOD', 'cli' ),
				'' !== $uri ? $uri : 'wp-cli',
				implode( '; ', array_slice( $parts, 0, 5 ) ) . ( count( $parts ) > 5 ? sprintf( '; +%d more', count( $parts ) - 5 ) : '' )
			)
		);
	}

	/**
	 * The post types whose published content can appear in a stored answer.
	 *
	 * @return string[]
	 */
	private static function types(): array {
		return Config::list( 'post_types', array_values( get_post_types( array( 'public' => true ) ) ) );
	}

	/**
	 * Published content of a type visitors see: only its changes can alter a stored answer.
	 *
	 * @param mixed $post Post.
	 */
	private static function is_public_content( $post ): bool {
		return $post instanceof \WP_Post && 'publish' === $post->post_status && in_array( $post->post_type, self::types(), true );
	}

	/**
	 * A signed-in person acting through the web, not a scheduled job or WP-CLI.
	 */
	private static function is_editor_context(): bool {
		// Options can be written while plugins load, before pluggable.php defines this.
		return function_exists( 'is_user_logged_in' ) && is_user_logged_in() && ! wp_doing_cron() && ! ( defined( 'WP_CLI' ) && WP_CLI );
	}

	/**
	 * An anonymous visitor's request. Every enquiry or upload creates a record, and none may retire the cache.
	 */
	private static function is_anonymous_web_request(): bool {
		if ( wp_doing_cron() || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			return false;
		}
		return ! function_exists( 'is_user_logged_in' ) || ! is_user_logged_in();
	}

	/**
	 * Published content appearing, changing or going away.
	 *
	 * @param string $new_status New status.
	 * @param string $old_status Old status.
	 * @param mixed  $post       Post.
	 */
	public static function on_post_transition( $new_status, $old_status, $post ): void {
		if (
			$post instanceof \WP_Post &&
			in_array( $post->post_type, self::types(), true ) &&
			( 'publish' === $new_status || 'publish' === $old_status ) &&
			! self::is_anonymous_web_request()
		) {
			self::mark_dirty( "post {$post->post_type} {$post->ID}", "$old_status>$new_status" );
		}
	}

	/**
	 * Published content being deleted.
	 *
	 * @param int   $post_id Post id.
	 * @param mixed $post    Post.
	 */
	public static function on_post_delete( $post_id, $post = null ): void {
		$post = $post instanceof \WP_Post ? $post : get_post( $post_id );
		if ( self::is_public_content( $post ) && ! self::is_anonymous_web_request() ) {
			self::mark_dirty( "delete {$post->post_type} {$post->ID}" );
		}
	}

	/**
	 * Terms and menus, only ever changed by someone signed in, or by WP-CLI.
	 *
	 * @param mixed $id Term or menu id.
	 */
	public static function on_editor_change( $id = null ): void {
		if ( ! self::is_anonymous_web_request() ) {
			self::mark_dirty( current_filter(), is_scalar( $id ) ? (string) $id : '' );
		}
	}

	/**
	 * A deleted file may be on a page, and a takedown must not wait for an entry to age out.
	 *
	 * @param mixed $post_id Attachment id.
	 */
	public static function on_attachment_delete( $post_id = null ): void {
		if ( ! self::is_anonymous_web_request() ) {
			self::mark_dirty( 'delete attachment', is_scalar( $post_id ) ? (string) $post_id : '' );
		}
	}

	/**
	 * ACF options pages and term fields save through acf/save_post with a non-numeric id.
	 *
	 * @param mixed $post_id Post id or ACF selector.
	 */
	public static function on_acf_save( $post_id ): void {
		if ( is_numeric( $post_id ) ) {
			$post = get_post( (int) $post_id );
			if ( self::is_public_content( $post ) && ! self::is_anonymous_web_request() ) {
				self::mark_dirty( "acf {$post->post_type} {$post->ID}" );
			}
			return;
		}
		self::on_editor_change( $post_id );
	}

	/**
	 * Meta written by someone who can edit the post. Scheduled jobs and WP-CLI are ignored: the time to live
	 * already bounds how stale their data can be. So are drafts, autosaves and revisions, which cannot change
	 * a stored answer, and meta a member writes on a post they cannot edit, such as a view counter.
	 *
	 * @param int    $meta_id   Meta id.
	 * @param int    $object_id Post id.
	 * @param string $meta_key  Meta key.
	 */
	public static function on_meta_change( $meta_id, $object_id, $meta_key ): void {
		$meta_key = (string) $meta_key;
		if ( 0 === strpos( $meta_key, '_edit_' ) || '_wp_old_slug' === $meta_key || ! self::is_editor_context() ) {
			return;
		}
		$post = get_post( (int) $object_id );
		if ( self::is_public_content( $post ) && current_user_can( 'edit_post', $post->ID ) ) {
			self::mark_dirty( "meta {$post->post_type} {$post->ID}", $meta_key );
		}
	}

	/**
	 * A site option an editor changed.
	 *
	 * @param mixed $option Option name.
	 */
	public static function on_option_change( $option ): void {
		$option = (string) $option;
		if ( ! self::is_editor_context() ) {
			return;
		}
		$listed = in_array( $option, Config::list( 'options', self::OPTIONS ), true );
		foreach ( Config::list( 'option_prefixes' ) as $prefix ) {
			$listed = $listed || 0 === strpos( $option, $prefix );
		}
		if ( $listed ) {
			self::mark_dirty( 'option', $option );
		}
	}
}
