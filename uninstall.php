<?php
/**
 * Removes everything the plugin stored when it is deleted from the Plugins screen.
 *
 * @package Shakewell\RestCache
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'shakewell_rest_cache_generation' );
delete_option( 'shakewell_rest_cache_stable' );
delete_option( 'shakewell_rest_cache_stale_since' );
wp_clear_scheduled_hook( 'shakewell_rest_cache_warm' );
