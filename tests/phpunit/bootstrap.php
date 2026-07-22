<?php
/**
 * PHPUnit Bootstrap
 *
 * Minimal bootstrap for unit tests that don't require WordPress.
 * Defines required constants, loads the plugin autoloader, and stubs the
 * WordPress functions the tested services call - mirroring the Quiz test
 * harness pattern. Stubs are behavior-faithful for the inputs used in
 * tests; anything needing real WordPress belongs in an integration suite.
 *
 * @package PressPrimer_Certificate
 * @subpackage Tests
 * @since 1.0.0
 */

// Define ABSPATH so the "prevent direct access" guards don't exit().
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', '/tmp/wordpress/' );
}

// Define the plugin path constant used by the autoloader.
define( 'PPCERT_PLUGIN_DIR', dirname( __DIR__, 2 ) . '/' );

// Load Composer autoloader (PHPUnit, etc.).
require_once PPCERT_PLUGIN_DIR . 'vendor/autoload.php';

// Register the plugin autoloader so PressPrimer_Certificate_* classes resolve.
require_once PPCERT_PLUGIN_DIR . 'includes/class-ppcert-autoloader.php';
PressPrimer_Certificate_Autoloader::register();

if ( ! function_exists( 'absint' ) ) {
	/**
	 * Stub: Convert a value to a non-negative integer.
	 *
	 * @param mixed $maybeint Data to convert.
	 * @return int
	 */
	function absint( $maybeint ) {
		return abs( (int) $maybeint );
	}
}

if ( ! function_exists( 'wp_rand' ) ) {
	/**
	 * Stub: Random integer in a range (CSPRNG, like modern WP).
	 *
	 * @param int $min Lower bound.
	 * @param int $max Upper bound.
	 * @return int
	 */
	function wp_rand( $min = 0, $max = 0 ) {
		return random_int( (int) $min, (int) $max );
	}
}

if ( ! function_exists( '__' ) ) {
	/**
	 * Stub: Return the untranslated text.
	 *
	 * @param string $text   Text to translate.
	 * @param string $domain Text domain (unused).
	 * @return string
	 */
	function __( $text, $domain = 'default' ) { // phpcs:ignore WordPress.WP.I18n
		return $text;
	}
}

// ---------------------------------------------------------------------
// Mini hook system: behavior-faithful add_filter/apply_filters and
// add_action/do_action with priorities and accepted_args. Tests call
// ppcert_tests_reset_hooks() in setUp() for isolation.
// ---------------------------------------------------------------------

$GLOBALS['ppcert_test_hooks'] = [];

/**
 * Reset all registered test hooks.
 *
 * @return void
 */
function ppcert_tests_reset_hooks() {
	$GLOBALS['ppcert_test_hooks'] = [];
}

if ( ! function_exists( 'add_filter' ) ) {
	/**
	 * Stub: Register a filter callback.
	 *
	 * @param string   $hook_name     Hook name.
	 * @param callable $callback      Callback.
	 * @param int      $priority      Priority.
	 * @param int      $accepted_args Number of accepted arguments.
	 * @return bool
	 */
	function add_filter( $hook_name, $callback, $priority = 10, $accepted_args = 1 ) {
		$GLOBALS['ppcert_test_hooks'][ $hook_name ][] = [
			'callback'      => $callback,
			'priority'      => (int) $priority,
			'accepted_args' => (int) $accepted_args,
			'order'         => count( isset( $GLOBALS['ppcert_test_hooks'][ $hook_name ] ) ? $GLOBALS['ppcert_test_hooks'][ $hook_name ] : [] ),
		];
		return true;
	}
}

if ( ! function_exists( 'add_action' ) ) {
	/**
	 * Stub: Register an action callback.
	 *
	 * @param string   $hook_name     Hook name.
	 * @param callable $callback      Callback.
	 * @param int      $priority      Priority.
	 * @param int      $accepted_args Number of accepted arguments.
	 * @return bool
	 */
	function add_action( $hook_name, $callback, $priority = 10, $accepted_args = 1 ) {
		return add_filter( $hook_name, $callback, $priority, $accepted_args );
	}
}

/**
 * Get a hook's callbacks in priority order (stable).
 *
 * @param string $hook_name Hook name.
 * @return array
 */
function ppcert_tests_hook_callbacks( $hook_name ) {
	if ( empty( $GLOBALS['ppcert_test_hooks'][ $hook_name ] ) ) {
		return [];
	}

	$callbacks = $GLOBALS['ppcert_test_hooks'][ $hook_name ];

	usort(
		$callbacks,
		static function ( $a, $b ) {
			if ( $a['priority'] === $b['priority'] ) {
				return $a['order'] <=> $b['order'];
			}
			return $a['priority'] <=> $b['priority'];
		}
	);

	return $callbacks;
}

if ( ! function_exists( 'apply_filters' ) ) {
	/**
	 * Stub: Run a value through registered filter callbacks.
	 *
	 * @param string $hook_name The filter hook.
	 * @param mixed  $value     The value to filter.
	 * @param mixed  ...$args   Additional arguments.
	 * @return mixed
	 */
	function apply_filters( $hook_name, $value, ...$args ) {
		foreach ( ppcert_tests_hook_callbacks( $hook_name ) as $entry ) {
			$all_args = array_merge( [ $value ], $args );
			$value    = call_user_func_array(
				$entry['callback'],
				array_slice( $all_args, 0, max( 1, $entry['accepted_args'] ) )
			);
		}
		return $value;
	}
}

if ( ! function_exists( 'do_action' ) ) {
	/**
	 * Stub: Run registered action callbacks.
	 *
	 * @param string $hook_name The action hook.
	 * @param mixed  ...$args   Arguments.
	 * @return void
	 */
	function do_action( $hook_name, ...$args ) {
		foreach ( ppcert_tests_hook_callbacks( $hook_name ) as $entry ) {
			call_user_func_array(
				$entry['callback'],
				array_slice( $args, 0, max( 0, $entry['accepted_args'] ) )
			);
		}
	}
}

if ( ! function_exists( 'wp_generate_uuid4' ) ) {
	/**
	 * Stub: Generate a v4 UUID.
	 *
	 * @return string
	 */
	function wp_generate_uuid4() {
		return sprintf(
			'%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
			random_int( 0, 0xffff ),
			random_int( 0, 0xffff ),
			random_int( 0, 0xffff ),
			random_int( 0, 0x0fff ) | 0x4000,
			random_int( 0, 0x3fff ) | 0x8000,
			random_int( 0, 0xffff ),
			random_int( 0, 0xffff ),
			random_int( 0, 0xffff )
		);
	}
}

if ( ! function_exists( 'current_time' ) ) {
	/**
	 * Stub: Current time; 'mysql' with $gmt returns UTC Y-m-d H:i:s.
	 *
	 * @param string $type Type ('mysql' or 'timestamp').
	 * @param bool   $gmt  Whether to use GMT.
	 * @return string|int
	 */
	function current_time( $type, $gmt = false ) {
		if ( 'timestamp' === $type ) {
			return time();
		}
		return gmdate( 'Y-m-d H:i:s' );
	}
}

if ( ! function_exists( 'wp_json_encode' ) ) {
	/**
	 * Stub: Encode a variable as JSON.
	 *
	 * @param mixed $data    Data to encode.
	 * @param int   $options JSON encode options.
	 * @param int   $depth   Maximum depth.
	 * @return string|false
	 */
	function wp_json_encode( $data, $options = 0, $depth = 512 ) {
		return json_encode( $data, $options, $depth ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
	}
}

if ( ! function_exists( 'sanitize_text_field' ) ) {
	/**
	 * Stub: Sanitize a single-line string.
	 *
	 * @param string $str String to sanitize.
	 * @return string
	 */
	function sanitize_text_field( $str ) {
		$filtered = wp_strip_all_tags( (string) $str, true );
		return trim( $filtered );
	}
}

if ( ! function_exists( 'sanitize_key' ) ) {
	/**
	 * Stub: Sanitize a key (lowercase alphanumerics, underscores, hyphens).
	 *
	 * @param string $key Key to sanitize.
	 * @return string
	 */
	function sanitize_key( $key ) {
		return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) );
	}
}

if ( ! function_exists( 'sanitize_hex_color' ) ) {
	/**
	 * Stub: Sanitize a hex color (behavior-faithful to WordPress).
	 *
	 * @param string $color Color string.
	 * @return string|null '' for empty input, the color if valid, null otherwise.
	 */
	function sanitize_hex_color( $color ) {
		if ( '' === $color ) {
			return '';
		}
		if ( preg_match( '|^#([A-Fa-f0-9]{3}){1,2}$|', $color ) ) {
			return $color;
		}
		return null;
	}
}

if ( ! function_exists( 'wp_strip_all_tags' ) ) {
	/**
	 * Stub: Strip all tags, removing script/style content entirely.
	 *
	 * @param string $text          Text to strip.
	 * @param bool   $remove_breaks Whether to collapse line breaks.
	 * @return string
	 */
	function wp_strip_all_tags( $text, $remove_breaks = false ) {
		$text = preg_replace( '@<(script|style)[^>]*?>.*?</\\1>@si', '', (string) $text );
		$text = strip_tags( $text );

		if ( $remove_breaks ) {
			$text = preg_replace( '/[\r\n\t ]+/', ' ', $text );
		}

		return trim( $text );
	}
}

if ( ! function_exists( 'sanitize_textarea_field' ) ) {
	/**
	 * Stub: Sanitize multi-line text, preserving newlines.
	 *
	 * @param string $str String to sanitize.
	 * @return string
	 */
	function sanitize_textarea_field( $str ) {
		return wp_strip_all_tags( (string) $str, false );
	}
}

if ( ! function_exists( 'wp_attachment_is_image' ) ) {
	/**
	 * Stub: Whether an attachment id is a known image.
	 *
	 * Tests register valid ids in $GLOBALS['ppcert_test_image_attachments'].
	 *
	 * @param int $post_id Attachment id.
	 * @return bool
	 */
	function wp_attachment_is_image( $post_id ) {
		$known = isset( $GLOBALS['ppcert_test_image_attachments'] ) ? $GLOBALS['ppcert_test_image_attachments'] : [];
		return in_array( (int) $post_id, $known, true );
	}
}

// In-memory $wpdb fake supporting the plugin's query shapes - lets the
// issuance pipeline and models run in CI without a database. Real-DB
// behavior is additionally verified live on the dev site per prompt.
require_once __DIR__ . '/doubles/class-ppcert-fake-wpdb.php';

if ( ! isset( $GLOBALS['wpdb'] ) ) {
	$GLOBALS['wpdb'] = new PPCert_Fake_WPDB();
}

/**
 * Replace the global fake wpdb with a fresh instance (test isolation).
 *
 * @return PPCert_Fake_WPDB The fresh instance.
 */
function ppcert_tests_reset_wpdb() {
	$GLOBALS['wpdb'] = new PPCert_Fake_WPDB();
	return $GLOBALS['wpdb'];
}

if ( ! function_exists( 'get_userdata' ) ) {
	/**
	 * Stub: Get a user object.
	 *
	 * Tests register users in $GLOBALS['ppcert_test_users'][id] as objects
	 * with display_name, first_name, last_name, user_email.
	 *
	 * @param int $user_id User id.
	 * @return object|false
	 */
	function get_userdata( $user_id ) {
		$users = isset( $GLOBALS['ppcert_test_users'] ) ? $GLOBALS['ppcert_test_users'] : [];
		return isset( $users[ (int) $user_id ] ) ? $users[ (int) $user_id ] : false;
	}
}

if ( ! function_exists( 'get_user_meta' ) ) {
	/**
	 * Stub: Get user meta ($single behavior).
	 *
	 * Tests register meta in $GLOBALS['ppcert_test_user_meta'][id][key].
	 *
	 * @param int    $user_id User id.
	 * @param string $key     Meta key.
	 * @param bool   $single  Single value.
	 * @return mixed '' when missing, like WordPress with $single = true.
	 */
	function get_user_meta( $user_id, $key = '', $single = false ) {
		$meta = isset( $GLOBALS['ppcert_test_user_meta'][ (int) $user_id ] ) ? $GLOBALS['ppcert_test_user_meta'][ (int) $user_id ] : [];
		return isset( $meta[ $key ] ) ? $meta[ $key ] : '';
	}
}

if ( ! function_exists( 'get_post_meta' ) ) {
	/**
	 * Stub: Get post meta ($single behavior).
	 *
	 * Tests register meta in $GLOBALS['ppcert_test_post_meta'][id][key].
	 *
	 * @param int    $post_id Post id.
	 * @param string $key     Meta key.
	 * @param bool   $single  Single value.
	 * @return mixed '' when missing, like WordPress with $single = true.
	 */
	function get_post_meta( $post_id, $key = '', $single = false ) {
		$meta = isset( $GLOBALS['ppcert_test_post_meta'][ (int) $post_id ] ) ? $GLOBALS['ppcert_test_post_meta'][ (int) $post_id ] : [];
		return isset( $meta[ $key ] ) ? $meta[ $key ] : '';
	}
}

if ( ! function_exists( 'get_bloginfo' ) ) {
	/**
	 * Stub: Site info.
	 *
	 * Overridable via $GLOBALS['ppcert_test_bloginfo'].
	 *
	 * @param string $show Info key.
	 * @return string
	 */
	function get_bloginfo( $show = '' ) {
		$defaults = [
			'name'        => 'Test Site',
			'description' => 'Just another test site',
			'url'         => 'https://test.example',
			'version'     => '6.4',
			'admin_email' => 'admin@test.example',
		];
		$info     = isset( $GLOBALS['ppcert_test_bloginfo'] ) ? array_merge( $defaults, $GLOBALS['ppcert_test_bloginfo'] ) : $defaults;
		return isset( $info[ $show ] ) ? $info[ $show ] : '';
	}
}

if ( ! function_exists( 'get_option' ) ) {
	/**
	 * Stub: Options with test overrides.
	 *
	 * @param string $option        Option name.
	 * @param mixed  $default_value Default.
	 * @return mixed
	 */
	function get_option( $option, $default_value = false ) {
		$defaults = [ 'date_format' => 'F j, Y' ];
		$options  = isset( $GLOBALS['ppcert_test_options'] ) ? array_merge( $defaults, $GLOBALS['ppcert_test_options'] ) : $defaults;
		return isset( $options[ $option ] ) ? $options[ $option ] : $default_value;
	}
}

if ( ! function_exists( 'get_date_from_gmt' ) ) {
	/**
	 * Stub: Convert a UTC datetime string to site time (UTC in tests).
	 *
	 * @param string $date_string UTC datetime (Y-m-d H:i:s).
	 * @param string $format      Output format.
	 * @return string
	 */
	function get_date_from_gmt( $date_string, $format = 'Y-m-d H:i:s' ) {
		$timestamp = strtotime( $date_string . ' +0000' );
		return false === $timestamp ? '' : gmdate( $format, $timestamp );
	}
}

if ( ! function_exists( 'home_url' ) ) {
	/**
	 * Stub: Site home URL.
	 *
	 * @param string $path Path to append.
	 * @return string
	 */
	function home_url( $path = '' ) {
		return 'https://test.example' . $path;
	}
}

if ( ! function_exists( 'wp_tempnam' ) ) {
	/**
	 * Stub: Create a temp file.
	 *
	 * @param string $filename Name hint.
	 * @param string $dir      Directory (unused).
	 * @return string|false
	 */
	function wp_tempnam( $filename = '', $dir = '' ) {
		return tempnam( sys_get_temp_dir(), 'ppcert' );
	}
}

if ( ! function_exists( 'wp_delete_file' ) ) {
	/**
	 * Stub: Delete a file.
	 *
	 * @param string $file File path.
	 * @return void
	 */
	function wp_delete_file( $file ) {
		if ( file_exists( $file ) ) {
			unlink( $file );
		}
	}
}

if ( ! function_exists( 'get_attached_file' ) ) {
	/**
	 * Stub: Resolve an attachment id to a local file path.
	 *
	 * Tests register files in $GLOBALS['ppcert_test_attachment_files'][id].
	 *
	 * @param int $attachment_id Attachment id.
	 * @return string|false
	 */
	function get_attached_file( $attachment_id ) {
		$files = isset( $GLOBALS['ppcert_test_attachment_files'] ) ? $GLOBALS['ppcert_test_attachment_files'] : [];
		return isset( $files[ (int) $attachment_id ] ) ? $files[ (int) $attachment_id ] : false;
	}
}

if ( ! function_exists( 'wp_check_filetype' ) ) {
	/**
	 * Stub: Filetype check by extension.
	 *
	 * @param string $filename File name.
	 * @param array  $mimes    Allowed mimes (unused).
	 * @return array [ 'ext' => string|false, 'type' => string|false ].
	 */
	function wp_check_filetype( $filename, $mimes = null ) {
		$map = [
			'jpg'  => 'image/jpeg',
			'jpeg' => 'image/jpeg',
			'png'  => 'image/png',
			'gif'  => 'image/gif',
			'pdf'  => 'application/pdf',
		];
		$ext = strtolower( (string) pathinfo( (string) $filename, PATHINFO_EXTENSION ) );

		if ( isset( $map[ $ext ] ) ) {
			return [
				'ext'  => $ext,
				'type' => $map[ $ext ],
			];
		}

		return [
			'ext'  => false,
			'type' => false,
		];
	}
}

if ( ! function_exists( 'wp_unslash' ) ) {
	/**
	 * Stub: Remove slashes added by WordPress.
	 *
	 * @param string|array $value Value to unslash.
	 * @return string|array
	 */
	function wp_unslash( $value ) {
		return is_array( $value ) ? array_map( 'wp_unslash', $value ) : stripslashes( (string) $value );
	}
}

if ( ! function_exists( 'wp_salt' ) ) {
	/**
	 * Stub: Site salt.
	 *
	 * @param string $scheme Salt scheme (unused).
	 * @return string
	 */
	function wp_salt( $scheme = 'auth' ) {
		return 'ppcert-test-salt';
	}
}

if ( ! function_exists( 'wp_hash' ) ) {
	/**
	 * Stub: Salted hash.
	 *
	 * @param string $data   Data to hash.
	 * @param string $scheme Salt scheme (unused).
	 * @return string
	 */
	function wp_hash( $data, $scheme = 'auth' ) {
		return md5( wp_salt( $scheme ) . $data );
	}
}

if ( ! function_exists( 'get_current_user_id' ) ) {
	/**
	 * Stub: Current user id ($GLOBALS['ppcert_test_current_user']).
	 *
	 * @return int
	 */
	function get_current_user_id() {
		return isset( $GLOBALS['ppcert_test_current_user'] ) ? (int) $GLOBALS['ppcert_test_current_user'] : 0;
	}
}

if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
	define( 'HOUR_IN_SECONDS', 3600 );
}

if ( ! function_exists( 'trailingslashit' ) ) {
	/**
	 * Stub: Append a trailing slash.
	 *
	 * @param string $value Path or URL.
	 * @return string
	 */
	function trailingslashit( $value ) {
		return rtrim( (string) $value, '/\\' ) . '/';
	}
}

if ( ! function_exists( 'wp_upload_dir' ) ) {
	/**
	 * Stub: Uploads directory under the system temp dir.
	 *
	 * @return array
	 */
	function wp_upload_dir() {
		$base = sys_get_temp_dir() . '/ppcert-test-uploads';

		if ( ! is_dir( $base ) ) {
			mkdir( $base, 0777, true );
		}

		return [
			'basedir' => $base,
			'baseurl' => 'http://example.test/wp-content/uploads',
			'error'   => false,
		];
	}
}

if ( ! function_exists( 'wp_mkdir_p' ) ) {
	/**
	 * Stub: Recursive mkdir.
	 *
	 * @param string $dir Directory path.
	 * @return bool
	 */
	function wp_mkdir_p( $dir ) {
		return is_dir( $dir ) || mkdir( $dir, 0777, true );
	}
}

if ( ! function_exists( 'wp_generate_password' ) ) {
	/**
	 * Stub: Random alphanumeric string.
	 *
	 * @param int  $length        Length.
	 * @param bool $special_chars Unused.
	 * @param bool $extra_special Unused.
	 * @return string
	 */
	function wp_generate_password( $length = 12, $special_chars = true, $extra_special = false ) {
		$alphabet = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
		$out      = '';

		for ( $i = 0; $i < $length; $i++ ) {
			$out .= $alphabet[ random_int( 0, strlen( $alphabet ) - 1 ) ];
		}

		return $out;
	}
}

if ( ! function_exists( 'mysql2date' ) ) {
	/**
	 * Stub: Format a LOCAL-time MySQL datetime (core semantics).
	 *
	 * Only non-ppcert values may pass through this (ppcert tables store
	 * UTC and are formatted with get_date_from_gmt).
	 *
	 * @param string $format Date format.
	 * @param string $date   MySQL datetime string.
	 * @return string
	 */
	function mysql2date( $format, $date ) {
		$timestamp = strtotime( (string) $date );

		return false === $timestamp ? '' : gmdate( $format, $timestamp );
	}
}

if ( ! function_exists( 'current_user_can' ) ) {
	/**
	 * Stub: Capability check.
	 *
	 * $GLOBALS['ppcert_test_user_caps']: true grants everything
	 * (default), false denies everything, an array grants only the
	 * listed capabilities.
	 *
	 * @param string $capability Capability name.
	 * @return bool
	 */
	function current_user_can( $capability ) {
		$granted = isset( $GLOBALS['ppcert_test_user_caps'] ) ? $GLOBALS['ppcert_test_user_caps'] : true;

		if ( is_array( $granted ) ) {
			return in_array( $capability, $granted, true );
		}

		return (bool) $granted;
	}
}

if ( ! function_exists( 'get_post' ) ) {
	/**
	 * Stub: Post lookup ($GLOBALS['ppcert_test_posts'][id] = object).
	 *
	 * @param int $post_id Post id.
	 * @return object|null
	 */
	function get_post( $post_id ) {
		$posts = isset( $GLOBALS['ppcert_test_posts'] ) ? $GLOBALS['ppcert_test_posts'] : [];

		return isset( $posts[ (int) $post_id ] ) ? $posts[ (int) $post_id ] : null;
	}
}

/**
 * Reset the in-memory transient store.
 *
 * @return void
 */
function ppcert_tests_reset_transients() {
	$GLOBALS['ppcert_test_transients'] = [];
}

if ( ! function_exists( 'get_transient' ) ) {
	/**
	 * Stub: Transient read with expiry.
	 *
	 * @param string $key Transient key.
	 * @return mixed False when missing/expired.
	 */
	function get_transient( $key ) {
		$store = isset( $GLOBALS['ppcert_test_transients'] ) ? $GLOBALS['ppcert_test_transients'] : [];

		if ( ! isset( $store[ $key ] ) ) {
			return false;
		}

		if ( $store[ $key ]['expires'] > 0 && $store[ $key ]['expires'] <= time() ) {
			unset( $GLOBALS['ppcert_test_transients'][ $key ] );
			return false;
		}

		return $store[ $key ]['value'];
	}
}

if ( ! function_exists( 'set_transient' ) ) {
	/**
	 * Stub: Transient write.
	 *
	 * @param string $key        Transient key.
	 * @param mixed  $value      Value.
	 * @param int    $expiration Seconds until expiry (0 = never).
	 * @return bool
	 */
	function set_transient( $key, $value, $expiration = 0 ) {
		$GLOBALS['ppcert_test_transients'][ $key ] = [
			'value'   => $value,
			'expires' => $expiration > 0 ? time() + $expiration : 0,
		];
		return true;
	}
}

if ( ! function_exists( '__return_true' ) ) {
	/**
	 * Stub: Return true.
	 *
	 * @return bool
	 */
	function __return_true() { // phpcs:ignore PHPCompatibility.FunctionNameRestrictions.ReservedFunctionNames.FunctionDoubleUnderscore -- Faithful stub of the WordPress core function name.
		return true;
	}
}

if ( ! function_exists( 'register_rest_route' ) ) {
	/**
	 * Stub: Record REST route registrations for assertions.
	 *
	 * @param string $route_namespace Namespace.
	 * @param string $route           Route pattern.
	 * @param array  $args            Route args.
	 * @return bool
	 */
	function register_rest_route( $route_namespace, $route, $args = [] ) {
		$GLOBALS['ppcert_test_rest_routes'][] = [
			'namespace' => $route_namespace,
			'route'     => $route,
			'args'      => $args,
		];
		return true;
	}
}

if ( ! class_exists( 'WP_REST_Request' ) ) {
	/**
	 * Stub: Minimal REST request.
	 */
	class WP_REST_Request {

		/**
		 * Request params.
		 *
		 * @var array
		 */
		private $params;

		/**
		 * Constructor.
		 *
		 * @param array $params Request params.
		 */
		public function __construct( $params = [] ) {
			$this->params = $params;
		}

		/**
		 * Get a param.
		 *
		 * @param string $key Param name.
		 * @return mixed
		 */
		public function get_param( $key ) {
			return isset( $this->params[ $key ] ) ? $this->params[ $key ] : null;
		}
	}
}

if ( ! class_exists( 'WP_REST_Response' ) ) {
	/**
	 * Stub: Minimal REST response.
	 */
	class WP_REST_Response {

		/**
		 * Response data.
		 *
		 * @var mixed
		 */
		private $data;

		/**
		 * HTTP status.
		 *
		 * @var int
		 */
		private $status;

		/**
		 * Headers.
		 *
		 * @var array
		 */
		private $headers = [];

		/**
		 * Constructor.
		 *
		 * @param mixed $data   Response data.
		 * @param int   $status HTTP status.
		 */
		public function __construct( $data = null, $status = 200 ) {
			$this->data   = $data;
			$this->status = $status;
		}

		/**
		 * Set a header.
		 *
		 * @param string $key   Header name.
		 * @param string $value Header value.
		 * @return void
		 */
		public function header( $key, $value ) {
			$this->headers[ $key ] = $value;
		}

		/**
		 * Get data.
		 *
		 * @return mixed
		 */
		public function get_data() {
			return $this->data;
		}

		/**
		 * Get status.
		 *
		 * @return int
		 */
		public function get_status() {
			return $this->status;
		}

		/**
		 * Get headers.
		 *
		 * @return array
		 */
		public function get_headers() {
			return $this->headers;
		}
	}
}

if ( ! function_exists( 'ppcert_verification_url' ) ) {
	/**
	 * Stub: Behavior-identical mirror of the plugin bootstrap's canonical
	 * verification URL builder (the bootstrap file itself is not loaded
	 * in unit tests).
	 *
	 * @param string $credential_id Credential ID.
	 * @return string
	 */
	function ppcert_verification_url( $credential_id ) {
		$normalized = PressPrimer_Certificate_Credential_ID_Service::normalize( $credential_id );

		$settings = get_option( 'ppcert_settings', [] );
		$page_id  = is_array( $settings ) && isset( $settings['verification_page_id'] ) ? absint( $settings['verification_page_id'] ) : 0;

		$base = $page_id > 0 ? get_permalink( $page_id ) : '';

		if ( ! $base ) {
			$base = home_url( '/' );
		}

		return add_query_arg( 'ppcert_id', rawurlencode( $normalized ), $base );
	}
}

if ( ! function_exists( 'esc_html' ) ) {
	/**
	 * Stub: HTML-escape.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	function esc_html( $text ) {
		return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
	}
}

if ( ! function_exists( 'esc_attr' ) ) {
	/**
	 * Stub: Attribute-escape.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	function esc_attr( $text ) {
		return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
	}
}

if ( ! function_exists( 'esc_html__' ) ) {
	/**
	 * Stub: Translate (identity) and HTML-escape.
	 *
	 * @param string $text   Text.
	 * @param string $domain Text domain (unused).
	 * @return string
	 */
	function esc_html__( $text, $domain = 'default' ) { // phpcs:ignore WordPress.WP.I18n
		return esc_html( $text );
	}
}

if ( ! function_exists( 'update_option' ) ) {
	/**
	 * Stub: Option write into the test options store.
	 *
	 * @param string $option Option name.
	 * @param mixed  $value  Value.
	 * @return bool
	 */
	function update_option( $option, $value ) {
		$GLOBALS['ppcert_test_options'][ $option ] = $value;
		return true;
	}
}

if ( ! function_exists( 'add_shortcode' ) ) {
	/**
	 * Stub: Record shortcode registrations.
	 *
	 * @param string   $tag      Shortcode tag.
	 * @param callable $callback Render callback.
	 * @return void
	 */
	function add_shortcode( $tag, $callback ) {
		$GLOBALS['ppcert_test_shortcodes'][ $tag ] = $callback;
	}
}

if ( ! function_exists( 'wp_register_script' ) ) {
	/**
	 * Stub: No-op asset registration.
	 *
	 * @return bool
	 */
	function wp_register_script() {
		return true;
	}
}

if ( ! function_exists( 'wp_register_style' ) ) {
	/**
	 * Stub: No-op asset registration.
	 *
	 * @return bool
	 */
	function wp_register_style() {
		return true;
	}
}

if ( ! function_exists( 'wp_enqueue_script' ) ) {
	/**
	 * Stub: No-op enqueue.
	 *
	 * @return void
	 */
	function wp_enqueue_script() {
	}
}

if ( ! function_exists( 'wp_enqueue_style' ) ) {
	/**
	 * Stub: No-op enqueue.
	 *
	 * @return void
	 */
	function wp_enqueue_style() {
	}
}

if ( ! function_exists( 'wp_localize_script' ) ) {
	/**
	 * Stub: Record localized data.
	 *
	 * @param string $handle Script handle.
	 * @param string $name   Object name.
	 * @param array  $data   Data.
	 * @return bool
	 */
	function wp_localize_script( $handle, $name, $data ) {
		$GLOBALS['ppcert_test_localized'][ $name ] = $data;
		return true;
	}
}

if ( ! function_exists( 'rest_url' ) ) {
	/**
	 * Stub: REST URL builder.
	 *
	 * @param string $path Route path.
	 * @return string
	 */
	function rest_url( $path = '' ) {
		return 'https://test.example/wp-json/' . ltrim( $path, '/' );
	}
}

if ( ! function_exists( 'add_query_arg' ) ) {
	/**
	 * Stub: Append one query arg.
	 *
	 * @param string $key   Arg name.
	 * @param string $value Arg value.
	 * @param string $url   Base URL.
	 * @return string
	 */
	function add_query_arg( $key, $value, $url ) {
		$separator = false === strpos( $url, '?' ) ? '?' : '&';
		return $url . $separator . $key . '=' . $value;
	}
}

if ( ! function_exists( 'wp_insert_post' ) ) {
	/**
	 * Stub: Create a post in the test posts store.
	 *
	 * @param array $postarr Post data.
	 * @return int Post id.
	 */
	function wp_insert_post( $postarr ) {
		$posts   = isset( $GLOBALS['ppcert_test_posts'] ) ? $GLOBALS['ppcert_test_posts'] : [];
		$post_id = count( $posts ) + 1000;

		$postarr['ID']                        = $post_id;
		$postarr['post_status']               = isset( $postarr['post_status'] ) ? $postarr['post_status'] : 'publish';
		$GLOBALS['ppcert_test_posts'][ $post_id ] = (object) $postarr;

		return $post_id;
	}
}

if ( ! function_exists( 'get_post' ) ) {
	/**
	 * Stub: Read a post from the test posts store.
	 *
	 * @param int $post_id Post id.
	 * @return object|null
	 */
	function get_post( $post_id ) {
		return isset( $GLOBALS['ppcert_test_posts'][ (int) $post_id ] ) ? $GLOBALS['ppcert_test_posts'][ (int) $post_id ] : null;
	}
}

if ( ! function_exists( 'get_permalink' ) ) {
	/**
	 * Stub: Permalink for a test post.
	 *
	 * @param int $post_id Post id.
	 * @return string|false
	 */
	function get_permalink( $post_id ) {
		return isset( $GLOBALS['ppcert_test_posts'][ (int) $post_id ] ) ? 'https://test.example/?page_id=' . (int) $post_id : false;
	}
}

if ( ! function_exists( 'wp_mail' ) ) {
	/**
	 * Stub: Capture outgoing mail.
	 *
	 * @param string       $to          Recipient.
	 * @param string       $subject     Subject.
	 * @param string       $message     Body.
	 * @param array|string $headers     Headers.
	 * @param array        $attachments Attachments.
	 * @return bool
	 */
	function wp_mail( $to, $subject, $message, $headers = [], $attachments = [] ) {
		$GLOBALS['ppcert_test_mail'][] = [
			'to'          => $to,
			'subject'     => $subject,
			'body'        => $message,
			'headers'     => $headers,
			'attachments' => $attachments,
		];
		return true;
	}
}

if ( ! function_exists( 'is_wp_error' ) ) {
	/**
	 * Stub: Whether a value is a WP_Error.
	 *
	 * @param mixed $thing Value to check.
	 * @return bool
	 */
	function is_wp_error( $thing ) {
		return $thing instanceof WP_Error;
	}
}

if ( ! class_exists( 'WP_Error' ) ) {
	/**
	 * Stub: Minimal WP_Error with the interface the plugin uses.
	 */
	class WP_Error {

		/**
		 * Map of code => array of messages.
		 *
		 * @var array
		 */
		public $errors = [];

		/**
		 * Map of code => data (most recent add wins, matching WP).
		 *
		 * @var array
		 */
		public $error_data = [];

		/**
		 * Constructor.
		 *
		 * @param string|int $code    Error code.
		 * @param string     $message Error message.
		 * @param mixed      $data    Error data.
		 */
		public function __construct( $code = '', $message = '', $data = '' ) {
			if ( '' !== $code ) {
				$this->add( $code, $message, $data );
			}
		}

		/**
		 * Add an error.
		 *
		 * @param string|int $code    Error code.
		 * @param string     $message Error message.
		 * @param mixed      $data    Error data.
		 */
		public function add( $code, $message, $data = '' ) {
			$this->errors[ $code ][] = $message;
			if ( '' !== $data ) {
				$this->error_data[ $code ] = $data;
			}
		}

		/**
		 * Get all error codes.
		 *
		 * @return array
		 */
		public function get_error_codes() {
			return array_keys( $this->errors );
		}

		/**
		 * Get the first error code (matches WP core).
		 *
		 * @return string|int
		 */
		public function get_error_code() {
			$codes = $this->get_error_codes();
			return $codes ? $codes[0] : '';
		}

		/**
		 * Get all messages, optionally for one code.
		 *
		 * @param string|int $code Error code, or '' for all.
		 * @return array
		 */
		public function get_error_messages( $code = '' ) {
			if ( '' === $code ) {
				$all = [];
				foreach ( $this->errors as $messages ) {
					$all = array_merge( $all, $messages );
				}
				return $all;
			}
			return isset( $this->errors[ $code ] ) ? $this->errors[ $code ] : [];
		}

		/**
		 * Get the first message for a code.
		 *
		 * @param string|int $code Error code, or '' for the first code.
		 * @return string
		 */
		public function get_error_message( $code = '' ) {
			if ( '' === $code ) {
				$codes = $this->get_error_codes();
				$code  = isset( $codes[0] ) ? $codes[0] : '';
			}
			$messages = $this->get_error_messages( $code );
			return isset( $messages[0] ) ? $messages[0] : '';
		}

		/**
		 * Get data for a code.
		 *
		 * @param string|int $code Error code, or '' for the first code.
		 * @return mixed
		 */
		public function get_error_data( $code = '' ) {
			if ( '' === $code ) {
				$codes = $this->get_error_codes();
				$code  = isset( $codes[0] ) ? $codes[0] : '';
			}
			return isset( $this->error_data[ $code ] ) ? $this->error_data[ $code ] : null;
		}

		/**
		 * Add data for a code (matches WP core).
		 *
		 * @param mixed      $data Error data.
		 * @param string|int $code Error code, or '' for the first code.
		 * @return void
		 */
		public function add_data( $data, $code = '' ) {
			if ( '' === $code ) {
				$codes = $this->get_error_codes();
				$code  = isset( $codes[0] ) ? $codes[0] : '';
			}

			$this->error_data[ $code ] = $data;
		}

		/**
		 * Whether any errors are recorded.
		 *
		 * @return bool
		 */
		public function has_errors() {
			return ! empty( $this->errors );
		}
	}
}
