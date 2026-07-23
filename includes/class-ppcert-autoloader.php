<?php
/**
 * Class autoloader
 *
 * Maps PressPrimer_Certificate_* class names to their include files.
 *
 * @package PressPrimer_Certificate
 * @since 1.0.0
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Autoloader class
 *
 * Registers an SPL autoloader that maps class names to WordPress-style
 * file names: PressPrimer_Certificate_Issuance_Service becomes
 * class-ppcert-issuance-service.php. Abstract classes and interfaces use
 * the abstract-ppcert-*.php and interface-ppcert-*.php file prefixes
 * (e.g. PressPrimer_Certificate_LMS_Adapter maps to
 * abstract-ppcert-lms-adapter.php per docs/architecture/CODE-STRUCTURE.md).
 *
 * @since 1.0.0
 */
class PressPrimer_Certificate_Autoloader {

	/**
	 * Subdirectories of includes/ to search, in order
	 *
	 * Keep in sync with docs/architecture/CODE-STRUCTURE.md.
	 *
	 * @since 1.0.0
	 * @var string[]
	 */
	private static $directories = [
		'models',
		'admin',
		'api',
		'frontend',
		'services',
		'integrations',
		'database',
		'utilities',
		'blocks',
	];

	/**
	 * File name prefixes to try, in order of likelihood
	 *
	 * @since 1.0.0
	 * @var string[]
	 */
	private static $file_prefixes = [
		'class',
		'abstract',
		'interface',
	];

	/**
	 * Register the autoloader
	 *
	 * @since 1.0.0
	 */
	public static function register() {
		spl_autoload_register( [ __CLASS__, 'autoload' ] );
	}

	/**
	 * Autoload a plugin class
	 *
	 * @since 1.0.0
	 *
	 * @param string $class The fully qualified class name being requested.
	 */
	public static function autoload( $class ) {
		if ( 0 !== strpos( $class, 'PressPrimer_Certificate_' ) ) {
			return;
		}

		// PressPrimer_Certificate_Issuance_Service -> issuance-service
		$class_without_prefix = substr( $class, strlen( 'PressPrimer_Certificate_' ) );
		$slug                 = strtolower( str_replace( '_', '-', $class_without_prefix ) );

		foreach ( self::$file_prefixes as $file_prefix ) {
			$file = $file_prefix . '-ppcert-' . $slug . '.php';

			// Check includes/ root first
			$path = PPCERT_PLUGIN_DIR . 'includes/' . $file;
			if ( file_exists( $path ) ) {
				require_once $path;
				return;
			}

			// Then each registered subdirectory
			foreach ( self::$directories as $dir ) {
				$path = PPCERT_PLUGIN_DIR . 'includes/' . $dir . '/' . $file;
				if ( file_exists( $path ) ) {
					require_once $path;
					return;
				}
			}
		}
	}
}
