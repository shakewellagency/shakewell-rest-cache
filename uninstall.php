<?php
/**
 * Removes everything the plugin stored when it is deleted from the Plugins screen.
 *
 * @package Shakewell\RestCache
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'shakewell_rest_cache_generation' );
