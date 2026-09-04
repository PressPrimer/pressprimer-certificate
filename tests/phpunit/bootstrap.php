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

// URL + version constants used by asset registration code paths.
define( 'PPCERT_PLUGIN_URL', 'https://test.example/wp-content/plugins/pressprimer-certificate/' );

if ( ! defined( 'PPCERT_VERSION' ) ) {
	define( 'PPCERT_VERSION', '1.0.0-test' );
}

// The migration chain's head, mirroring the production constant (the
// migrator tests drive maybe_migrate() against it).
if ( ! defined( 'PPCERT_DB_VERSION' ) ) {
	define( 'PPCERT_DB_VERSION', '2.0.0' );
}

// TCPDF font lookup roots in the plugin's converted-fonts directory,
// exactly as the production bootstrap defines it (the release ZIP strips
// TCPDF's bundled font collection; core metrics ship in fonts/tcpdf/).
if ( ! defined( 'K_PATH_FONTS' ) ) {
	define( 'K_PATH_FONTS', PPCERT_PLUGIN_DIR . 'fonts/tcpdf/' );
}

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

if ( ! function_exists( 'remove_filter' ) ) {
	/**
	 * Stub: Unregister a callback (identity + priority match, like core).
	 *
	 * @param string   $hook_name Hook name.
	 * @param callable $callback  Callback to remove.
	 * @param int      $priority  Priority it was added with.
	 * @return bool Whether anything was removed.
	 */
	function remove_filter( $hook_name, $callback, $priority = 10 ) {
		if ( empty( $GLOBALS['ppcert_test_hooks'][ $hook_name ] ) ) {
			return false;
		}

		$removed = false;

		foreach ( $GLOBALS['ppcert_test_hooks'][ $hook_name ] as $index => $entry ) {
			if ( $entry['callback'] === $callback && (int) $priority === $entry['priority'] ) {
				unset( $GLOBALS['ppcert_test_hooks'][ $hook_name ][ $index ] );
				$removed = true;
			}
		}

		return $removed;
	}
}

if ( ! function_exists( 'remove_action' ) ) {
	/**
	 * Stub: Unregister an action callback.
	 *
	 * @param string   $hook_name Hook name.
	 * @param callable $callback  Callback to remove.
	 * @param int      $priority  Priority it was added with.
	 * @return bool Whether anything was removed.
	 */
	function remove_action( $hook_name, $callback, $priority = 10 ) {
		return remove_filter( $hook_name, $callback, $priority );
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

// The migrator require_onces ABSPATH wp-admin/includes/upgrade.php before
// calling dbDelta(). Provide an empty stub at that path (dbDelta itself is
// defined below) so migration tests can exercise the real chain.
if ( ! file_exists( ABSPATH . 'wp-admin/includes/upgrade.php' ) ) {
	if ( ! is_dir( ABSPATH . 'wp-admin/includes' ) ) {
		mkdir( ABSPATH . 'wp-admin/includes', 0777, true );
	}

	file_put_contents(
		ABSPATH . 'wp-admin/includes/upgrade.php',
		"<?php // Test stub - dbDelta is defined by the PHPUnit bootstrap.\n"
	);
}

if ( ! function_exists( 'dbDelta' ) ) {
	/**
	 * Stub: dbDelta against the fake wpdb's schema registry.
	 *
	 * Parses each CREATE TABLE statement just far enough to register the
	 * table name and its column names with PPCert_Fake_WPDB. Re-running
	 * merges columns into an existing registration - the shim's stand-in
	 * for dbDelta's ADD COLUMN behavior. Row data is untouched (the fake
	 * stores schemaless rows). Tables listed in
	 * $GLOBALS['ppcert_test_dbdelta_skip_tables'] are skipped, so tests
	 * can simulate a partially failed migration for the migrator's
	 * verify-before-advance behavior.
	 *
	 * @param string $sql CREATE TABLE statements.
	 * @return array Empty (return value unused by the migrator).
	 */
	function dbDelta( $sql ) {
		$skip = isset( $GLOBALS['ppcert_test_dbdelta_skip_tables'] )
			? (array) $GLOBALS['ppcert_test_dbdelta_skip_tables']
			: [];

		preg_match_all( '/CREATE TABLE ([^\s(]+)\s*\((.*?)\)[^();]*;/s', (string) $sql, $statements, PREG_SET_ORDER );

		foreach ( $statements as $statement ) {
			$table = trim( $statement[1], '`' );

			if ( in_array( $table, $skip, true ) ) {
				continue;
			}

			$columns = [];

			foreach ( explode( "\n", $statement[2] ) as $line ) {
				$line = trim( $line );

				if ( '' === $line || preg_match( '/^(PRIMARY\s+KEY|UNIQUE\s+KEY|KEY|CONSTRAINT)\b/i', $line ) ) {
					continue;
				}

				$columns[] = trim( strtok( $line, " \t" ), '`' );
			}

			$GLOBALS['wpdb']->register_table( $table, $columns );
		}

		return [];
	}
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

		if ( false === $timestamp ) {
			return '';
		}

		// Optional site-timezone simulation (seconds east of UTC; default
		// 0 = UTC site). Lets tests pin local-vs-UTC conversion bugs.
		$offset = isset( $GLOBALS['ppcert_test_gmt_offset'] ) ? (int) $GLOBALS['ppcert_test_gmt_offset'] : 0;

		return gmdate( $format, $timestamp + $offset );
	}
}

if ( ! function_exists( 'get_users' ) ) {
	/**
	 * Stub: User query over $GLOBALS['ppcert_test_users'] supporting the
	 * arguments the certificate list/user-search use (search with *
	 * wildcards over name/email/login, fields => 'ID', number).
	 *
	 * @param array $args Query args.
	 * @return array Users or ids.
	 */
	function get_users( $args = [] ) {
		$users   = isset( $GLOBALS['ppcert_test_users'] ) ? $GLOBALS['ppcert_test_users'] : [];
		$term    = isset( $args['search'] ) ? strtolower( trim( (string) $args['search'], '*' ) ) : '';
		$matches = [];

		foreach ( $users as $user ) {
			$haystack = strtolower(
				( $user->display_name ?? '' ) . ' ' . ( $user->user_email ?? '' ) . ' ' . ( $user->user_login ?? '' )
			);

			if ( '' === $term || false !== strpos( $haystack, $term ) ) {
				$matches[] = $user;
			}
		}

		if ( isset( $args['number'] ) && (int) $args['number'] > 0 ) {
			$matches = array_slice( $matches, 0, (int) $args['number'] );
		}

		if ( isset( $args['fields'] ) && 'ID' === $args['fields'] ) {
			return array_map(
				static function ( $user ) {
					return (int) $user->ID;
				},
				$matches
			);
		}

		return $matches;
	}
}

if ( ! function_exists( 'get_gmt_from_date' ) ) {
	/**
	 * Stub: Convert a site-local datetime string to UTC (identity in
	 * tests - the test site runs on UTC).
	 *
	 * @param string $date_string Local datetime (Y-m-d H:i:s).
	 * @param string $format      Output format.
	 * @return string
	 */
	function get_gmt_from_date( $date_string, $format = 'Y-m-d H:i:s' ) {
		$timestamp = strtotime( $date_string . ' +0000' );

		if ( false === $timestamp ) {
			return '';
		}

		// Mirror of get_date_from_gmt(): local input shifts back by the
		// simulated site offset (default 0 = UTC site).
		$offset = isset( $GLOBALS['ppcert_test_gmt_offset'] ) ? (int) $GLOBALS['ppcert_test_gmt_offset'] : 0;

		return gmdate( $format, $timestamp - $offset );
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

if ( ! function_exists( 'get_posts' ) ) {
	/**
	 * Stub: Post query over $GLOBALS['ppcert_test_posts'] supporting the
	 * arguments the course adapters' source lookup uses (post_type,
	 * post_status, s title search, numberposts, orderby title ASC).
	 *
	 * @param array $args Query args.
	 * @return array Post objects.
	 */
	function get_posts( $args = [] ) {
		$posts   = isset( $GLOBALS['ppcert_test_posts'] ) ? $GLOBALS['ppcert_test_posts'] : [];
		$matches = [];

		foreach ( $posts as $post ) {
			if ( isset( $args['post_type'] ) ) {
				$wanted = (array) $args['post_type'];

				if ( ! isset( $post->post_type ) || ! in_array( $post->post_type, $wanted, true ) ) {
					continue;
				}
			}

			if ( isset( $args['post_status'] ) && ( ! isset( $post->post_status ) || $post->post_status !== $args['post_status'] ) ) {
				continue;
			}

			if ( isset( $args['post_parent'] ) && (int) ( $post->post_parent ?? 0 ) !== (int) $args['post_parent'] ) {
				continue;
			}

			if ( isset( $args['post_parent__in'] ) && ! in_array( (int) ( $post->post_parent ?? 0 ), array_map( 'intval', (array) $args['post_parent__in'] ), true ) ) {
				continue;
			}

			if ( ! empty( $args['s'] ) && false === stripos( (string) $post->post_title, (string) $args['s'] ) ) {
				continue;
			}

			$matches[] = $post;
		}

		if ( isset( $args['orderby'] ) && 'title' === $args['orderby'] ) {
			usort(
				$matches,
				static function ( $a, $b ) {
					return strcasecmp( (string) $a->post_title, (string) $b->post_title );
				}
			);
		}

		// Newest-first by post_date (the 1.1 any-trigger meta preview).
		if ( isset( $args['orderby'] ) && 'date' === $args['orderby'] ) {
			usort(
				$matches,
				static function ( $a, $b ) {
					return strcmp( (string) ( $b->post_date ?? '' ), (string) ( $a->post_date ?? '' ) );
				}
			);
		}

		$limit = isset( $args['numberposts'] ) ? (int) $args['numberposts'] : -1;

		return $limit > 0 ? array_slice( $matches, 0, $limit ) : $matches;
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

if ( ! function_exists( 'delete_transient' ) ) {
	/**
	 * Stub: Transient delete.
	 *
	 * @param string $key Transient key.
	 * @return bool
	 */
	function delete_transient( $key ) {
		unset( $GLOBALS['ppcert_test_transients'][ $key ] );
		return true;
	}
}

if ( ! function_exists( 'wp_doing_ajax' ) ) {
	/**
	 * Stub: Never an AJAX request in tests.
	 *
	 * @return bool
	 */
	function wp_doing_ajax() {
		return ! empty( $GLOBALS['ppcert_test_doing_ajax'] );
	}
}

if ( ! function_exists( 'wp_doing_cron' ) ) {
	/**
	 * Stub: Never a cron request in tests.
	 *
	 * @return bool
	 */
	function wp_doing_cron() {
		return ! empty( $GLOBALS['ppcert_test_doing_cron'] );
	}
}

if ( ! function_exists( 'is_network_admin' ) ) {
	/**
	 * Stub: Never the network admin in tests.
	 *
	 * @return bool
	 */
	function is_network_admin() {
		return ! empty( $GLOBALS['ppcert_test_network_admin'] );
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
		 * JSON body params.
		 *
		 * @var array
		 */
		private $json;

		/**
		 * Constructor.
		 *
		 * @param array $params Request params.
		 * @param array $json   JSON body params.
		 */
		public function __construct( $params = [], $json = [] ) {
			$this->params = $params;
			$this->json   = $json;
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

		/**
		 * Get the decoded JSON body.
		 *
		 * @return array
		 */
		public function get_json_params() {
			return $this->json;
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

		return add_query_arg( 'ppcert_id', rawurlencode( $normalized ), ppcert_verification_page_url() );
	}
}

if ( ! function_exists( 'ppcert_verification_page_url' ) ) {
	/**
	 * Mirror of the bootstrap helper: the verification page base URL.
	 *
	 * @return string
	 */
	function ppcert_verification_page_url() {
		$settings = get_option( 'ppcert_settings', [] );
		$page_id  = is_array( $settings ) && isset( $settings['verification_page_id'] ) ? absint( $settings['verification_page_id'] ) : 0;

		$base = $page_id > 0 ? get_permalink( $page_id ) : '';

		if ( ! $base ) {
			$base = home_url( '/' );
		}

		return $base;
	}
}

// Behavior-identical mirrors of the plugin bootstrap's public API
// delegations (2.0, Feature 2.0-007 FR-001) - the bootstrap file itself
// is not loaded in unit tests.
if ( ! function_exists( 'ppcert_addon_manager' ) ) {
	/**
	 * Mirror: the addon manager singleton (null when the class is absent).
	 *
	 * @return PressPrimer_Certificate_Addon_Manager|null
	 */
	function ppcert_addon_manager() {
		if ( ! class_exists( 'PressPrimer_Certificate_Addon_Manager' ) ) {
			return null;
		}

		return PressPrimer_Certificate_Addon_Manager::get_instance();
	}
}

if ( ! function_exists( 'ppcert_issue_certificate' ) ) {
	/**
	 * Mirror: issue a certificate.
	 *
	 * @param array $args Issuance arguments.
	 * @return int|WP_Error
	 */
	function ppcert_issue_certificate( array $args ) {
		return PressPrimer_Certificate_Issuance_Service::issue( $args );
	}
}

if ( ! function_exists( 'ppcert_render_certificate_pdf' ) ) {
	/**
	 * Mirror: render an issued certificate to a PDF temp file.
	 *
	 * @param int    $certificate_id Certificate row id.
	 * @param string $context        Render context.
	 * @return string|WP_Error
	 */
	function ppcert_render_certificate_pdf( $certificate_id, $context = 'download' ) {
		return PressPrimer_Certificate_PDF_Renderer::render_certificate( $certificate_id, $context );
	}
}

if ( ! function_exists( 'ppcert_certificate_view_url' ) ) {
	/**
	 * Mirror: public share URL for a certificate's view page.
	 *
	 * @param string $credential_id Credential ID.
	 * @return string
	 */
	function ppcert_certificate_view_url( $credential_id ) {
		return PressPrimer_Certificate_View_Page::view_url( $credential_id );
	}
}

if ( ! function_exists( 'ppcert_certificate_pdf_url' ) ) {
	/**
	 * Mirror: public PDF download URL for a certificate.
	 *
	 * @param string $credential_id Credential ID.
	 * @return string
	 */
	function ppcert_certificate_pdf_url( $credential_id ) {
		return PressPrimer_Certificate_View_Page::pdf_url( $credential_id );
	}
}

if ( ! function_exists( 'ppcert_get_templates' ) ) {
	/**
	 * Mirror: template summaries for integration pickers.
	 *
	 * @param array $args Optional filters.
	 * @return array[]
	 */
	function ppcert_get_templates( array $args = [] ) {
		$status    = isset( $args['status'] ) ? sanitize_key( (string) $args['status'] ) : '';
		$summaries = [];

		foreach ( PressPrimer_Certificate_Template::get_all() as $template ) {
			if ( '' !== $status && (string) $template->status !== $status ) {
				continue;
			}

			$summaries[] = [
				'id'     => (int) $template->id,
				'title'  => (string) $template->title,
				'status' => (string) $template->status,
			];
		}

		return $summaries;
	}
}

if ( ! function_exists( 'ppcert_find_certificate' ) ) {
	/**
	 * Mirror: find a recipient's existing certificate for a source.
	 *
	 * @param int         $recipient_id Recipient user id.
	 * @param int         $template_id  Template row id.
	 * @param string      $source_type  Source type id.
	 * @param string|null $source_ref   Source reference.
	 * @return object|null
	 */
	function ppcert_find_certificate( $recipient_id, $template_id, $source_type, $source_ref = null ) {
		return PressPrimer_Certificate_Certificate::find_duplicate(
			absint( $recipient_id ),
			absint( $template_id ),
			sanitize_key( (string) $source_type ),
			null === $source_ref || '' === $source_ref ? null : (string) $source_ref
		);
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

if ( ! function_exists( 'esc_attr__' ) ) {
	/**
	 * Stub: Translate + attribute-escape.
	 *
	 * @param string $text   Text.
	 * @param string $domain Domain (unused).
	 * @return string
	 */
	function esc_attr__( $text, $domain = 'default' ) { // phpcs:ignore WordPress.WP.I18n
		return esc_attr( $text );
	}
}

if ( ! function_exists( 'is_admin' ) ) {
	/**
	 * Stub: Whether the current request is an admin screen.
	 *
	 * Defaults false (front-end); tests set $GLOBALS['ppcert_test_is_admin']
	 * to exercise admin-only registration paths (used by the Educator
	 * addon's shared-harness suite).
	 *
	 * @return bool
	 */
	function is_admin() {
		return ! empty( $GLOBALS['ppcert_test_is_admin'] );
	}
}

if ( ! function_exists( 'esc_html_e' ) ) {
	/**
	 * Stub: Echo an HTML-escaped translated string.
	 *
	 * @param string $text   Text.
	 * @param string $domain Domain.
	 */
	function esc_html_e( $text, $domain = 'default' ) { // phpcs:ignore WordPress.WP.I18n
		echo esc_html( $text ); // phpcs:ignore WordPress.Security.EscapeOutput
	}
}

if ( ! function_exists( 'esc_attr_e' ) ) {
	/**
	 * Stub: Echo an attribute-escaped translated string.
	 *
	 * @param string $text   Text.
	 * @param string $domain Domain.
	 */
	function esc_attr_e( $text, $domain = 'default' ) { // phpcs:ignore WordPress.WP.I18n
		echo esc_attr( $text ); // phpcs:ignore WordPress.Security.EscapeOutput
	}
}

if ( ! function_exists( 'sanitize_html_class' ) ) {
	/**
	 * Stub: Strip characters not valid in an HTML class name.
	 *
	 * @param string $classname Raw class.
	 * @param string $fallback  Fallback when nothing survives.
	 * @return string
	 */
	function sanitize_html_class( $classname, $fallback = '' ) {
		$sanitized = preg_replace( '/[^A-Za-z0-9_-]/', '', (string) $classname );

		return '' === $sanitized ? $fallback : $sanitized;
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

if ( ! function_exists( 'size_format' ) ) {
	/**
	 * Stub: Human-readable byte size (MB/KB only - test inputs).
	 *
	 * @param int $bytes Byte count.
	 * @return string
	 */
	function size_format( $bytes ) {
		if ( $bytes >= 1048576 ) {
			return round( $bytes / 1048576, 1 ) . ' MB';
		}

		return round( $bytes / 1024 ) . ' KB';
	}
}

if ( ! function_exists( 'sanitize_file_name' ) ) {
	/**
	 * Stub: Strip path and special characters from a file name.
	 *
	 * @param string $filename Raw name.
	 * @return string
	 */
	function sanitize_file_name( $filename ) {
		$filename = basename( str_replace( '\\', '/', (string) $filename ) );

		return preg_replace( '/[^A-Za-z0-9._\-]/', '-', $filename );
	}
}

if ( ! function_exists( '_n' ) ) {
	/**
	 * Stub: Singular/plural selection (no translation).
	 *
	 * @param string $single Singular form.
	 * @param string $plural Plural form.
	 * @param int    $number Count.
	 * @param string $domain Domain.
	 * @return string
	 */
	function _n( $single, $plural, $number, $domain = 'default' ) { // phpcs:ignore WordPress.WP.I18n
		return 1 === (int) $number ? $single : $plural;
	}
}

if ( ! function_exists( 'rest_sanitize_boolean' ) ) {
	/**
	 * Stub: REST boolean coercion.
	 *
	 * @param mixed $value Raw value.
	 * @return bool
	 */
	function rest_sanitize_boolean( $value ) {
		if ( is_string( $value ) ) {
			return in_array( strtolower( $value ), [ '1', 'true', 'yes' ], true );
		}

		return (bool) $value;
	}
}

if ( ! function_exists( 'delete_option' ) ) {
	/**
	 * Stub: Remove an option.
	 *
	 * @param string $option Option name.
	 * @return bool
	 */
	function delete_option( $option ) {
		unset( $GLOBALS['ppcert_test_options'][ $option ] );
		return true;
	}
}

if ( ! function_exists( 'plugin_basename' ) ) {
	/**
	 * Stub: plugin-dir-relative basename (dir/file.php).
	 *
	 * @param string $file Absolute plugin file path.
	 * @return string
	 */
	function plugin_basename( $file ) {
		return basename( dirname( (string) $file ) ) . '/' . basename( (string) $file );
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

if ( ! function_exists( 'admin_url' ) ) {
	/**
	 * Stub: Admin URL builder.
	 *
	 * @param string $path Path relative to wp-admin.
	 * @return string
	 */
	function admin_url( $path = '' ) {
		return 'https://test.example/wp-admin/' . ltrim( $path, '/' );
	}
}

if ( ! function_exists( 'update_user_meta' ) ) {
	/**
	 * Stub: Write user meta into the test registry.
	 *
	 * @param int    $user_id User id.
	 * @param string $key     Meta key.
	 * @param mixed  $value   Meta value.
	 * @return bool
	 */
	function update_user_meta( $user_id, $key, $value ) {
		$GLOBALS['ppcert_test_user_meta'][ (int) $user_id ][ $key ] = $value;
		return true;
	}
}

if ( ! function_exists( 'delete_user_meta' ) ) {
	/**
	 * Stub: Delete user meta from the test registry.
	 *
	 * @param int    $user_id User id.
	 * @param string $key     Meta key.
	 * @return bool
	 */
	function delete_user_meta( $user_id, $key ) {
		unset( $GLOBALS['ppcert_test_user_meta'][ (int) $user_id ][ $key ] );
		return true;
	}
}

if ( ! function_exists( 'wp_create_nonce' ) ) {
	/**
	 * Stub: Deterministic nonce.
	 *
	 * @param string $action Nonce action.
	 * @return string
	 */
	function wp_create_nonce( $action = -1 ) {
		return 'nonce-' . $action;
	}
}

if ( ! function_exists( 'user_can' ) ) {
	/**
	 * Stub: Per-user capability check.
	 *
	 * Mirrors the current_user_can() stub - tests grant capabilities
	 * via $GLOBALS['ppcert_test_user_caps'] (user id is ignored).
	 *
	 * @param int    $user_id    User id.
	 * @param string $capability Capability name.
	 * @return bool
	 */
	function user_can( $user_id, $capability ) {
		return current_user_can( $capability );
	}
}

if ( ! function_exists( 'is_user_logged_in' ) ) {
	/**
	 * Stub: Logged-in check from the current-user global.
	 *
	 * @return bool
	 */
	function is_user_logged_in() {
		return get_current_user_id() > 0;
	}
}

if ( ! function_exists( 'esc_url_raw' ) ) {
	/**
	 * Stub: Raw URL sanitizer (pass-through for tests).
	 *
	 * @param string $url URL.
	 * @return string
	 */
	function esc_url_raw( $url ) {
		return (string) $url;
	}
}

if ( ! function_exists( 'wp_remote_post' ) ) {
	/**
	 * Stub: Record outbound POSTs; no network I/O ever happens.
	 *
	 * Tests read $GLOBALS['ppcert_test_remote_posts'].
	 *
	 * @param string $url  Target URL.
	 * @param array  $args Request args.
	 * @return array Fake response.
	 */
	function wp_remote_post( $url, $args = [] ) {
		$GLOBALS['ppcert_test_remote_posts'][] = [
			'url'  => $url,
			'args' => $args,
		];

		// Tests may script the response (e.g. the Educator addon's
		// license status-transition tests); default unchanged.
		if ( isset( $GLOBALS['ppcert_test_remote_response'] ) ) {
			return $GLOBALS['ppcert_test_remote_response'];
		}

		return [ 'response' => [ 'code' => 200 ] ];
	}
}

if ( ! function_exists( 'wp_remote_retrieve_body' ) ) {
	/**
	 * Stub: Read the body from a stubbed HTTP response.
	 *
	 * @param array|WP_Error $response Response.
	 * @return string
	 */
	function wp_remote_retrieve_body( $response ) {
		if ( is_wp_error( $response ) || ! is_array( $response ) ) {
			return '';
		}

		return isset( $response['body'] ) ? (string) $response['body'] : '';
	}
}

if ( ! function_exists( 'rest_ensure_response' ) ) {
	/**
	 * Stub: Wrap data in a WP_REST_Response unless it already is one.
	 *
	 * @param mixed $response Data or response object.
	 * @return WP_REST_Response|mixed
	 */
	function rest_ensure_response( $response ) {
		if ( is_wp_error( $response ) || $response instanceof WP_REST_Response ) {
			return $response;
		}

		return new WP_REST_Response( $response );
	}
}

if ( ! function_exists( 'wp_nonce_url' ) ) {
	/**
	 * Stub: Append a nonce query arg.
	 *
	 * @param string $actionurl Base URL.
	 * @param string $action    Nonce action.
	 * @return string
	 */
	function wp_nonce_url( $actionurl, $action = -1 ) {
		$separator = false === strpos( $actionurl, '?' ) ? '?' : '&';
		return $actionurl . $separator . '_wpnonce=' . wp_create_nonce( $action );
	}
}

if ( ! function_exists( 'add_query_arg' ) ) {
	/**
	 * Stub: Append one query arg; the two-arg form uses a fixed
	 * current-request URL like WordPress uses \$_SERVER['REQUEST_URI'].
	 *
	 * @param string      $key   Arg name.
	 * @param string      $value Arg value.
	 * @param string|null $url   Base URL (current request when omitted).
	 * @return string
	 */
	function add_query_arg( $key, $value = null, $url = null ) {
		// Array form: add_query_arg( [ k => v, ... ], $url ).
		if ( is_array( $key ) ) {
			$url = null === $value ? 'https://test.example/wp-admin/user-edit.php?user_id=7' : (string) $value;

			foreach ( $key as $arg => $arg_value ) {
				$separator = false === strpos( $url, '?' ) ? '?' : '&';
				$url      .= $separator . $arg . '=' . rawurlencode( (string) $arg_value );
			}

			return $url;
		}

		if ( null === $url ) {
			$url = 'https://test.example/wp-admin/user-edit.php?user_id=7';
		}

		$separator = false === strpos( $url, '?' ) ? '?' : '&';
		return $url . $separator . $key . '=' . $value;
	}
}

if ( ! function_exists( 'remove_query_arg' ) ) {
	/**
	 * Stub: Strip query args from a URL (current request when omitted).
	 *
	 * @param string|array $keys Arg name(s).
	 * @param string|null  $url  Base URL (current request when omitted).
	 * @return string
	 */
	function remove_query_arg( $keys, $url = null ) {
		if ( null === $url ) {
			$url = 'https://test.example/wp-admin/user-edit.php?user_id=7';
		}

		foreach ( (array) $keys as $key ) {
			$url = preg_replace( '/([?&])' . preg_quote( $key, '/' ) . '=[^&]*(&|$)/', '$1', $url );
		}

		return rtrim( (string) preg_replace( '/[?&]$/', '', (string) $url ), '?&' );
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
		// Failure knob: set $GLOBALS['ppcert_test_mail_fail'] to a reason
		// string to simulate a mailer failure (fires wp_mail_failed with
		// it, like PHPMailer does).
		if ( ! empty( $GLOBALS['ppcert_test_mail_fail'] ) ) {
			do_action(
				'wp_mail_failed',
				new WP_Error( 'wp_mail_failed', (string) $GLOBALS['ppcert_test_mail_fail'] )
			);
			return false;
		}

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

if ( ! class_exists( 'PPCert_Test_User' ) ) {
	/**
	 * Minimal WP_User stand-in for wp_get_current_user().
	 */
	class PPCert_Test_User { // phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound -- Bootstrap stub.
		/**
		 * User id.
		 *
		 * @var int
		 */
		public $ID = 0;

		/**
		 * Display name.
		 *
		 * @var string
		 */
		public $display_name = '';

		/**
		 * Email address.
		 *
		 * @var string
		 */
		public $user_email = '';

		/**
		 * First name.
		 *
		 * @var string
		 */
		public $first_name = '';

		/**
		 * Hydrate from a test-user object.
		 *
		 * @param int         $id   User id.
		 * @param object|null $data Test user record.
		 */
		public function __construct( $id, $data ) {
			$this->ID = (int) $id;

			if ( is_object( $data ) ) {
				$this->display_name = (string) ( $data->display_name ?? '' );
				$this->user_email   = (string) ( $data->user_email ?? '' );
				$this->first_name   = (string) ( $data->first_name ?? '' );
			}
		}

		/**
		 * Whether the user exists (WP_User semantics).
		 *
		 * @return bool
		 */
		public function exists() {
			return $this->ID > 0;
		}
	}
}

if ( ! function_exists( 'wp_get_current_user' ) ) {
	/**
	 * Stub: The current user as a WP_User-shaped object.
	 *
	 * Reads $GLOBALS['ppcert_test_current_user'] +
	 * $GLOBALS['ppcert_test_users'] like the other user stubs.
	 *
	 * @return PPCert_Test_User
	 */
	function wp_get_current_user() {
		$id    = isset( $GLOBALS['ppcert_test_current_user'] ) ? (int) $GLOBALS['ppcert_test_current_user'] : 0;
		$users = isset( $GLOBALS['ppcert_test_users'] ) ? $GLOBALS['ppcert_test_users'] : [];

		return new PPCert_Test_User( $id, $id > 0 && isset( $users[ $id ] ) ? $users[ $id ] : null );
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

if ( ! function_exists( 'esc_url' ) ) {
	/**
	 * Stub: Pass-through URL escaper (assertions target the URL value).
	 *
	 * @param string $url URL.
	 * @return string
	 */
	function esc_url( $url ) {
		return (string) $url;
	}
}

if ( ! function_exists( 'get_user_by' ) ) {
	/**
	 * Stub: Look a user up by email or id in the test users store.
	 *
	 * @param string     $field 'email' or 'id'.
	 * @param string|int $value Lookup value.
	 * @return object|false
	 */
	function get_user_by( $field, $value ) {
		$users = isset( $GLOBALS['ppcert_test_users'] ) ? $GLOBALS['ppcert_test_users'] : [];

		if ( 'id' === $field ) {
			return isset( $users[ (int) $value ] ) ? $users[ (int) $value ] : false;
		}

		if ( 'email' === $field ) {
			foreach ( $users as $user ) {
				if ( isset( $user->user_email ) && strtolower( $user->user_email ) === strtolower( (string) $value ) ) {
					return $user;
				}
			}
		}

		return false;
	}
}

if ( ! function_exists( 'register_block_type' ) ) {
	/**
	 * Stub: Capture block registrations.
	 *
	 * Tests read $GLOBALS['ppcert_test_blocks'][name] = $args.
	 *
	 * @param string $name Block name.
	 * @param array  $args Block arguments.
	 * @return bool
	 */
	function register_block_type( $name, $args = [] ) {
		$GLOBALS['ppcert_test_blocks'][ $name ] = $args;
		return true;
	}
}

if ( ! function_exists( 'add_rewrite_rule' ) ) {
	/**
	 * Stub: Capture rewrite rule registrations.
	 *
	 * Tests read $GLOBALS['ppcert_test_rewrites'][regex] = $query.
	 *
	 * @param string $regex    Rule regex.
	 * @param string $query    Query mapping.
	 * @param string $position 'top' or 'bottom'.
	 * @return void
	 */
	function add_rewrite_rule( $regex, $query, $position = 'bottom' ) {
		$GLOBALS['ppcert_test_rewrites'][ $regex ] = [
			'query'    => $query,
			'position' => $position,
		];
	}
}

if ( ! function_exists( 'status_header' ) ) {
	/**
	 * Stub: Capture the sent status code.
	 *
	 * @param int $code HTTP status code.
	 * @return void
	 */
	function status_header( $code ) {
		$GLOBALS['ppcert_test_status_header'] = (int) $code;
	}
}

if ( ! function_exists( 'nocache_headers' ) ) {
	/**
	 * Stub: No-op cache header suppression.
	 *
	 * @return void
	 */
	function nocache_headers() {
	}
}

if ( ! class_exists( 'WP_Post' ) ) {
	/**
	 * Stub: Property-bag post object (matches core's constructor shape).
	 */
	#[\AllowDynamicProperties]
	class WP_Post {

		/**
		 * Copy properties off the source object.
		 *
		 * @param object $post Source data.
		 */
		public function __construct( $post ) {
			foreach ( get_object_vars( $post ) as $key => $value ) {
				$this->$key = $value;
			}
		}
	}
}

if ( ! defined( 'YEAR_IN_SECONDS' ) ) {
	define( 'YEAR_IN_SECONDS', 31536000 );
}

if ( ! defined( 'DAY_IN_SECONDS' ) ) {
	define( 'DAY_IN_SECONDS', 86400 );
}

if ( ! function_exists( 'sanitize_email' ) ) {
	/**
	 * Stub: Strip characters not allowed in email addresses.
	 *
	 * @param string $email Email address.
	 * @return string
	 */
	function sanitize_email( $email ) {
		return (string) preg_replace( '/[^a-zA-Z0-9.!#$%&\'*+\/=?^_`{|}~@\-]/', '', (string) $email );
	}
}

if ( ! function_exists( 'is_email' ) ) {
	/**
	 * Stub: Loose email validity check.
	 *
	 * @param string $email Email address.
	 * @return string|false The email when valid.
	 */
	function is_email( $email ) {
		return false !== filter_var( (string) $email, FILTER_VALIDATE_EMAIL ) ? (string) $email : false;
	}
}

if ( ! function_exists( 'wp_next_scheduled' ) ) {
	/**
	 * Stub: Cron schedule store.
	 *
	 * Tests read/write $GLOBALS['ppcert_test_cron'][hook] = timestamp.
	 *
	 * @param string $hook Hook name.
	 * @return int|false
	 */
	function wp_next_scheduled( $hook ) {
		return isset( $GLOBALS['ppcert_test_cron'][ $hook ] ) ? $GLOBALS['ppcert_test_cron'][ $hook ] : false;
	}
}

if ( ! function_exists( 'wp_schedule_event' ) ) {
	/**
	 * Stub: Record a scheduled event.
	 *
	 * @param int    $timestamp  First run.
	 * @param string $recurrence Recurrence key.
	 * @param string $hook       Hook name.
	 * @return bool
	 */
	function wp_schedule_event( $timestamp, $recurrence, $hook ) {
		$GLOBALS['ppcert_test_cron'][ $hook ] = (int) $timestamp;
		$GLOBALS['ppcert_test_cron_recurrence'][ $hook ] = (string) $recurrence;
		return true;
	}
}

if ( ! function_exists( 'wp_unschedule_event' ) ) {
	/**
	 * Stub: Remove a scheduled event.
	 *
	 * @param int    $timestamp Scheduled time.
	 * @param string $hook      Hook name.
	 * @return bool
	 */
	function wp_unschedule_event( $timestamp, $hook ) {
		unset( $GLOBALS['ppcert_test_cron'][ $hook ] );
		return true;
	}
}

if ( ! function_exists( 'wp_kses_post' ) ) {
	/**
	 * Stub: Pass-through post-context sanitizer (assertions target markup).
	 *
	 * @param string $content Content.
	 * @return string
	 */
	function wp_kses_post( $content ) {
		return (string) $content;
	}
}

if ( ! function_exists( 'wp_kses' ) ) {
	/**
	 * Stub: Pass-through allowlist sanitizer (assertions target markup).
	 *
	 * @param string $content      Content.
	 * @param array  $allowed_html Allowed tags (unused in the stub).
	 * @return string
	 */
	function wp_kses( $content, $allowed_html = [] ) {
		return (string) $content;
	}
}

if ( ! function_exists( 'paginate_links' ) ) {
	/**
	 * Stub: Simple numbered page links honoring base/current/total.
	 *
	 * @param array $args Arguments.
	 * @return string
	 */
	function paginate_links( $args = [] ) {
		$total   = isset( $args['total'] ) ? (int) $args['total'] : 1;
		$current = isset( $args['current'] ) ? (int) $args['current'] : 1;
		$base    = isset( $args['base'] ) ? (string) $args['base'] : '?page=%#%';

		if ( $total < 2 ) {
			return '';
		}

		$links = [];

		for ( $i = 1; $i <= $total; $i++ ) {
			$links[] = $i === $current
				? '<span class="page-numbers current">' . $i . '</span>'
				: '<a class="page-numbers" href="' . str_replace( '%#%', (string) $i, $base ) . '">' . $i . '</a>';
		}

		return implode( ' ', $links );
	}
}
