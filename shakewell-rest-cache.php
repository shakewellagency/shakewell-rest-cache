<?php
/**
 * Plugin Name:       Shakewell REST Cache
 * Plugin URI:        https://github.com/shakewellagency/shakewell-rest-cache
 * Description:       Keeps finished REST JSON responses in the persistent object cache and retires them when content changes. Off without an object cache.
 * Version:           0.1.0
 * Requires at least: 6.5
 * Requires PHP:      8.1
 * Author:            Shakewell
 * Author URI:        https://shakewell.agency
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       shakewell-rest-cache
 *
 * @package Shakewell\RestCache
 */

namespace Shakewell\RestCache;

defined( 'ABSPATH' ) || exit;

const VERSION = '0.1.0';
const FILE    = __FILE__;

require_once __DIR__ . '/src/Config.php';
require_once __DIR__ . '/src/Cache.php';
require_once __DIR__ . '/src/Invalidation.php';
require_once __DIR__ . '/src/Cli.php';
require_once __DIR__ . '/src/Plugin.php';

Plugin::boot();
