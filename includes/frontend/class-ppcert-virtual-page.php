<?php
/**
 * Virtual page guard
 *
 * The suite renders several public pages without a real post: the
 * certificate view page (/certificate/{credential}/) and School's
 * issuer and program pages inject a stub WP_Post with ID 0 into the
 * main query. WordPress and themes then treat that stub like any page,
 * and two things go wrong (production report, 2026-09-25):
 *
 * - Comments: a theme or page builder that calls comments_template()
 *   without checking comments_open() queries comments for post 0, and
 *   WP_Comment_Query treats an empty post_id as "no post filter", so
 *   the page lists the site's most recent comments under a "Responses"
 *   heading. Elementor's Post Comments widget and Hello Elementor do
 *   exactly this.
 * - The admin bar's "Edit Page" points at post 0, which lands on the
 *   Posts list.
 *
 * guard() pins the request down: comments and pings closed for post 0,
 * a zero comment count, an empty comments template, no edit link, and
 * no admin-bar Edit node. Every filter checks the post id so real posts
 * rendered on the same page (widgets, secondary loops) are untouched.
 *
 * @package PressPrimer_Certificate
 * @subpackage Frontend
 * @since 2.0.1
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Virtual page guard class
 *
 * @since 2.0.1
 */
class PressPrimer_Certificate_Virtual_Page {

	/**
	 * Whether the guard is active for this request.
	 *
	 * @since 2.0.1
	 * @var bool
	 */
	private static $active = false;

	/**
	 * Activate the guard for the current request
	 *
	 * Called by every virtual page injector once its stub post is in
	 * the main query. Idempotent.
	 *
	 * @since 2.0.1
	 *
	 * @return void
	 */
	public static function guard() {
		if ( self::$active ) {
			return;
		}

		self::$active = true;

		add_filter( 'comments_open', [ __CLASS__, 'closed_for_stub' ], 99, 2 );
		add_filter( 'pings_open', [ __CLASS__, 'closed_for_stub' ], 99, 2 );
		add_filter( 'get_comments_number', [ __CLASS__, 'zero_for_stub' ], 99, 2 );
		add_filter( 'comments_template', [ __CLASS__, 'empty_comments_template' ], 99 );
		add_filter( 'get_edit_post_link', [ __CLASS__, 'no_edit_link_for_stub' ], 99, 2 );
		add_action( 'admin_bar_menu', [ __CLASS__, 'remove_edit_node' ], 99 );
	}

	/**
	 * Whether the guard is active
	 *
	 * @since 2.0.1
	 *
	 * @return bool
	 */
	public static function is_active() {
		return self::$active;
	}

	/**
	 * Reset (tests)
	 *
	 * @since 2.0.1
	 *
	 * @return void
	 */
	public static function reset() {
		self::$active = false;
	}

	/**
	 * Comments and pings are closed on the stub post
	 *
	 * @since 2.0.1
	 *
	 * @param bool $open    Whether open.
	 * @param int  $post_id Post id the check resolved to.
	 * @return bool
	 */
	public static function closed_for_stub( $open, $post_id ) {
		return 0 === (int) $post_id ? false : $open;
	}

	/**
	 * The stub post has no comments
	 *
	 * @since 2.0.1
	 *
	 * @param int $count   Comment count.
	 * @param int $post_id Post id.
	 * @return int
	 */
	public static function zero_for_stub( $count, $post_id ) {
		return 0 === (int) $post_id ? 0 : $count;
	}

	/**
	 * The stub post's comments template includes nothing
	 *
	 * Themes and builders that call comments_template() without checking
	 * comments_open() (Elementor's Post Comments widget) would otherwise
	 * list the site's comments: WP_Comment_Query treats post_id 0 as no
	 * filter at all.
	 *
	 * @since 2.0.1
	 *
	 * @param string $template Theme comments template path.
	 * @return string
	 */
	public static function empty_comments_template( $template ) {
		$post = isset( $GLOBALS['post'] ) ? $GLOBALS['post'] : null;

		if ( $post && is_object( $post ) && empty( $post->ID ) ) {
			return PPCERT_PLUGIN_DIR . 'templates/virtual-page-comments.php';
		}

		return $template;
	}

	/**
	 * No edit link for the stub post (edit_post_link() in themes and the
	 * admin bar's Edit node both read this)
	 *
	 * @since 2.0.1
	 *
	 * @param string $link    Edit link.
	 * @param int    $post_id Post id.
	 * @return string
	 */
	public static function no_edit_link_for_stub( $link, $post_id ) {
		return 0 === (int) $post_id ? '' : $link;
	}

	/**
	 * Drop the admin bar's Edit node on a virtual page
	 *
	 * Core adds the node before this priority when get_edit_post_link()
	 * returns a link; the filter above already empties it, so this is
	 * the belt to that brace.
	 *
	 * @since 2.0.1
	 *
	 * @param object $wp_admin_bar Admin bar.
	 * @return void
	 */
	public static function remove_edit_node( $wp_admin_bar ) {
		if ( ! is_object( $wp_admin_bar ) || ! method_exists( $wp_admin_bar, 'remove_node' ) ) {
			return;
		}

		if ( 0 === (int) get_queried_object_id() ) {
			$wp_admin_bar->remove_node( 'edit' );
		}
	}
}
