<?php
/**
 * Rate limiter utility
 *
 * Transient-counter rate limiting shared by the public verification
 * path and the test-email endpoint.
 *
 * @package PressPrimer_Certificate
 * @subpackage Utilities
 * @since 2.0.0
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Rate limiter class
 *
 * Extracted from the verification controller's inline counter (2.0,
 * Feature 2.0-003 TR-003) so every throttled surface shares one
 * implementation. Counting is a fixed window: the first hit starts the
 * window, and the counter expires with its transient.
 *
 * Callers build their own transient keys (prefix discipline applies:
 * nothing shorter than ppcert_) and never put raw identifying data in
 * them - the verification path hashes the address, the test-email path
 * keys on the user id.
 *
 * @since 2.0.0
 */
class PressPrimer_Certificate_Rate_Limiter {

	/**
	 * Count a hit against a limit
	 *
	 * @since 2.0.0
	 *
	 * @param string $transient_key Full transient key (ppcert_-prefixed).
	 * @param int    $limit         Hits allowed per window; < 1 disables.
	 * @param int    $window        Window length in seconds.
	 * @return bool True when this hit is within the limit.
	 */
	public static function allow( $transient_key, $limit, $window ) {
		if ( $limit < 1 ) {
			return true; // Explicitly disabled.
		}

		$count = (int) get_transient( $transient_key );

		if ( $count >= $limit ) {
			return false;
		}

		set_transient( $transient_key, $count + 1, $window );

		return true;
	}
}
