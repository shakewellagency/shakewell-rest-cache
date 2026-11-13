# Shakewell REST Cache

> **Made by [Shakewell](https://shakewell.agency).** Free and open source, built and maintained by the Shakewell team.

Free, GPL-2.0-or-later WordPress plugin for headless and API-heavy sites. It keeps the finished JSON of the REST routes you choose (menus, listings, anything that is the same for every visitor) in the persistent object cache, and optionally the finished JSON of front-end pages, then retires every stored answer when an editor changes content. Setup, every setting and the filters are in `readme.txt`.

## What it does

- **Config, not code.** Which routes are cached, which query parameters count, and which post types, options and prefixes retire the cache are settings: `SHAKEWELL_REST_CACHE_<NAME>` constants or environment variables, and filters. No route is built in.
- **Anonymous GET only.** Signed-in visitors, requests with an Authorization header, login or password cookies, and any non-GET request are never served from the cache and never stored.
- **Does nothing without a persistent object cache** (Redis, Memcached or similar). It registers no hooks in that case.
- **Correct invalidation.** Publishing, editing, unpublishing or deleting content, term, menu, attachment, meta and listed option changes by an editor move a generation on at shutdown, after the whole save, so a half-saved post is never stored. Anonymous requests, scheduled jobs and drafts do not retire it. Every key carries the generation and the site's date.
- **A generic preview bypass.** Name a request header, cookie prefix or query argument and any request carrying it skips the cache completely, so a draft preview never shows a published copy.
- **Optional early answers.** With `SHAKEWELL_REST_CACHE_EARLY` on, a stored answer is sent as the plugin loads, before the theme and most plugins run.
- **Honest headers.** A stored answer carries `X-Shakewell-Rest-Cache: HIT` or `MISS` (the header name is a setting). Bodies are the route's own data.

## Quick start

In `wp-config.php` or the environment:

```php
define( 'SHAKEWELL_REST_CACHE_ROUTES', array( '/shop/v1/menu', '/shop/v1/(books|authors)' ) ); // regular expressions, matched against the whole route
define( 'SHAKEWELL_REST_CACHE_ROUTE_PARAMS', array( '/shop/v1/books' => array( 'page' ) ) ); // query parameters a route accepts; any other bypasses the cache
```

Without a persistent object cache nothing happens. Flush after a release with `wp shakewell-rest-cache flush`.

## Work on it

```sh
composer install
npm ci
npm run env:start      # WordPress at http://localhost:8932
npm test               # PHPUnit in the test container
composer lint
npm run plugin-check
```

The tests use WordPress's in-memory object cache and mark it as persistent, so no Redis is needed.

## Release

1. Bump the version in the plugin header, `readme.txt` (`Stable tag`) and the `VERSION` constant, and add a changelog line.
2. Tag `vX.Y.Z` and push. The release workflow checks the three versions match and attaches `shakewell-rest-cache.zip` to a GitHub release.

## Install with Composer

Use the release zip as a Composer `package` repository entry pinned to a version, with its SHA-1 as `shasum`, rather than a `vcs` repository: a source install puts tests and repository files under the web root.

## Made by Shakewell

This plugin is one of a family of free WordPress plugins from [Shakewell](https://shakewell.agency). Each one does one job, keeps its dependencies small and is released under GPL-2.0-or-later.

Use it, fork it and send fixes. To have it set up on your site, or to have a plugin built, [talk to Shakewell](https://shakewell.agency).

More from Shakewell: [Shakewell plugins on GitHub](https://github.com/orgs/shakewellagency/repositories?q=shakewell-).
