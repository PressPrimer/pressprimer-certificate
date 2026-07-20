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
		 * Whether any errors are recorded.
		 *
		 * @return bool
		 */
		public function has_errors() {
			return ! empty( $this->errors );
		}
	}
}
