=== Shakewell REST Cache ===
Contributors: shakewellagency
Tags: rest api, cache, headless, object cache, performance
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Keeps the JSON of the REST routes you choose in the object cache and retires it when content changes. Does nothing without an object cache.

== Description ==

Shakewell REST Cache is for headless and API-heavy sites where the same JSON is built again and again. It stores the finished response of the REST routes you list, and optionally of front-end pages that answer with JSON, in the persistent object cache, so a repeat request is one cache read.

* Anonymous GET requests only. Signed-in visitors, requests with an Authorization header, login and password cookies and every other HTTP method are never served from the cache and never stored.
* Needs a persistent object cache (Redis, Memcached or similar). Without one the plugin registers nothing and changes nothing.
* Retires every stored answer when an editor publishes, edits, unpublishes or deletes content, or changes a term, menu, attachment, a listed option, or post meta on a post they can edit. The change takes effect at the end of the request, after the whole save.
* A stored answer keeps the response headers of the original, such as pagination totals and `Link`, and embedded objects when the request asked for `_embed` and listed it as a parameter.
* Never stores errors, answers that set a cookie, or responses to requests with parameters you did not list.
* Nothing is built in: you choose the routes, parameters, post types and options.

= Settings =

Each setting is a constant or an environment variable named `SHAKEWELL_REST_CACHE_<NAME>` in upper case, and a filter named `shakewell_rest_cache_<name>` in lower case. A constant wins over an environment variable. Lists and maps are PHP arrays in a constant, or in an environment variable a JSON value or (lists only) a comma-separated list. Use JSON when a value contains a comma, such as a regular expression with `{1,3}`.

* `routes` (list of regular expressions, default none): REST routes to cache, each matched against the whole route such as `/shop/v1/books`.
* `route_params` (map of route to list, default none): query parameters each cached route accepts. A request with any other parameter, or a value that is not a plain slug or number (letters, digits, underscore, comma, hyphen, up to 64 characters), bypasses the cache, so random parameters cannot fill it. A route that takes free text, such as a search, is therefore never cached.
* `pages` (true or false, default false): also cache front-end pages whose response is JSON.
* `page_params` (list, default `page`, `paged`): query parameters that change a page. Only plain slugs and numbers up to 64 characters are keyed; anything else bypasses.
* `ignored_params` (list of regular expressions, default common analytics parameters): dropped from a page key.
* `skip_paths` (list of regular expressions, default `wp[/-]`): page paths, after the leading slash, that are never cached.
* `bypass_headers` (list, default none): request header names. A request carrying any of them skips the cache completely, for both serving and storing. Use it for a draft preview header.
* `bypass_cookies` (list, default none): cookie name prefixes with the same effect, added to `wordpress_logged_in_` and `wp-postpass_`.
* `bypass_args` (list, default `preview`, `p`, `page_id`, `s`): page query arguments with the same effect.
* `post_types` (list, default every public post type): types whose content changes retire the cache. Add a type that is embedded in other pages without being public.
* `options` (list, default `blogname`, `blogdescription`, `page_on_front`, `show_on_front`, `permalink_structure`, `timezone_string`, `sidebars_widgets`) and `option_prefixes` (list, default none, for example `options_`): options whose change by an editor retires the cache.
* `ttl` (seconds, default 3600) and `ttl_jitter` (seconds added at random, default 900).
* `header` (default `X-Shakewell-Rest-Cache`): name of the HIT or MISS response header.
* `early` (true or false, default false): send a stored answer while the plugin loads, before the theme and most plugins run. It follows the REST base path, including a subdirectory install and a changed `rest_url_prefix` (hook that filter before this plugin loads); a `?rest_route=` request takes the normal path. Filters hooked after the plugin loads do not apply to that early check; use constants or environment variables, or hook from a must-use plugin.
* `log` (true or false, default false): one error log line for each request that retires the cache, with the reasons.

= Filters and actions =

* `shakewell_rest_cache_bypass` (bool, bool): last word on whether a request skips the cache. The second argument is true for a page and false for REST.
* `shakewell_rest_cache_page_cacheable` (bool, array decoded, string body): refuse to store a page answer.
* `shakewell_rest_cache_entry_ttl` (int, string kind, mixed payload): change the life of one entry, for example a shorter life for a page that shows the time.
* `shakewell_rest_cache_hit_headers` (action): add headers to an early answer, which ends before WordPress sends its own.
* `shakewell_rest_cache_invalidated` (action, array reasons): runs after the generation moves.

Keys also carry the site's date, so a page that says "today" is rebuilt after midnight.

= WP-CLI =

* `wp shakewell-rest-cache flush` retires every stored answer. Run it on release.
* `wp shakewell-rest-cache status` shows whether the cache is active and the current generation.

= External services =

None. The plugin talks only to your site's object cache.

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/`, or install it with Composer.
2. Activate it from the Plugins screen.
3. Set `SHAKEWELL_REST_CACHE_ROUTES` as described above. Until you do, nothing is cached.

== Frequently Asked Questions ==

= Is anything cached without a persistent object cache? =

No. The plugin registers no hooks, so nothing is stored and nothing changes.

= Can a signed-in user see a cached answer? =

No. A signed-in user, a request with an Authorization header or a login cookie, and any request that is not a GET is never served from the cache and never stored.

= Where do I report a security problem? =

Please do not open a public issue. Follow SECURITY.md in the plugin's repository.

== Changelog ==

= 0.1.0 =
* First release.
