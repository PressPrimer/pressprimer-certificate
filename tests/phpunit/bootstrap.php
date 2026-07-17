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

if ( ! function_exists( 'apply_filters' ) ) {
	/**
	 * Stub: Pass the value through, allowing a test override.
	 *
	 * Tests may set $GLOBALS['ppcert_test_filters']['hook_name'] to a
	 * callable receiving the value (further args ignored).
	 *
	 * @param string $hook_name The filter hook.
	 * @param mixed  $value     The value to filter.
	 * @return mixed
	 */
	function apply_filters( $hook_name, $value ) {
		if ( isset( $GLOBALS['ppcert_test_filters'][ $hook_name ] ) ) {
			return call_user_func( $GLOBALS['ppcert_test_filters'][ $hook_name ], $value );
		}
		return $value;
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
