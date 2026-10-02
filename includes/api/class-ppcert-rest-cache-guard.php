<?php
/**
 * REST cache guard
 *
 * Every PressPrimer Certificate REST response is live data: the free
 * /ppcert/v1 routes and the addons' /ppcert-educator/v1,
 * /ppcert-school/v1, and /ppcert-enterprise/v1 routes. The designer's
 * template load carries the timestamp the save conflict check compares,
 * Bulk Award progress and the License tab must show current state, and
 * a verification lookup must reflect a revocation immediately.
 *
 * WordPress already sends no-store headers to logged-in users, but
 * LiteSpeed Cache's REST API caching (with logged-in caching on)
 * overrides them and serves a per-user copy. A 2.0.1 customer site on
 * LiteSpeed showed the result: after any save, reopening the designer
 * loaded the older cached template, so the next save reported "Changed
 * elsewhere", and Save anyway would have rolled the newer version back.
 *
 * The guard tells LiteSpeed not to cache these responses in two ways:
 * the LiteSpeed Cache plugin's documented control action (when that
 * plugin runs the cache), and the X-LiteSpeed-Cache-Control response
 * header, which LiteSpeed Web Server honors over Cache-Control (for
 * server-level cache rules without the plugin). Routes that stream
 * their own output and exit inside the callback, such as Enterprise's
 * versioned widget.js, never reach the dispatch filter and keep their
 * own long-cache headers.
 *
 * @package PressPrimer_Certificate
 * @subpackage API
 * @since 2.0.2
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * REST cache guard class
 *
 * @since 2.0.2
 */
class PressPrimer_Certificate_REST_Cache_Guard {

	/**
	 * Routes the guard covers: the free namespace and every ppcert-*
	 * addon namespace (the ppcert prefix is reserved for this suite).
	 *
	 * @since 2.0.2
	 * @var string
	 */
	const ROUTE_PATTERN = '#^/ppcert(?:-[a-z0-9]+)?/#';

	/**
	 * The reason LiteSpeed Cache records for the no-cache decision.
	 *
	 * @since 2.0.2
	 * @var string
	 */
	const NOCACHE_REASON = 'PressPrimer Certificate REST response';

	/**
	 * Register the dispatch filter
	 *
	 * @since 2.0.2
	 */
	public static function init() {
		add_filter( 'rest_post_dispatch', [ __CLASS__, 'mark_uncacheable' ], 10, 3 );
	}

	/**
	 * Whether a REST route belongs to this plugin suite
	 *
	 * @since 2.0.2
	 *
	 * @param string $route Route, e.g. /ppcert/v1/templates/6.
	 * @return bool
	 */
	public static function is_suite_route( $route ) {
		return 1 === preg_match( self::ROUTE_PATTERN, (string) $route );
	}

	/**
	 * Mark a suite response as not cacheable by LiteSpeed
	 *
	 * Runs for every method and status, error responses included: a
	 * cached 401 or 404 is as wrong as a cached 200.
	 *
	 * @since 2.0.2
	 *
	 * @param WP_HTTP_Response $response The response about to be served.
	 * @param WP_REST_Server   $server   The REST server (unused).
	 * @param WP_REST_Request  $request  The request.
	 * @return WP_HTTP_Response The same response.
	 */
	public static function mark_uncacheable( $response, $server, $request ) {
		unset( $server );

		if ( ! $request instanceof WP_REST_Request || ! self::is_suite_route( $request->get_route() ) ) {
			return $response;
		}

		if ( $response instanceof WP_REST_Response ) {
			$response->header( 'X-LiteSpeed-Cache-Control', 'no-cache' );
		}

		// LiteSpeed Cache's own control API; a no-op when that plugin is
		// not active.
		do_action( 'litespeed_control_set_nocache', self::NOCACHE_REASON ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- LiteSpeed Cache's documented control action, not a hook this plugin defines.

		return $response;
	}
}
