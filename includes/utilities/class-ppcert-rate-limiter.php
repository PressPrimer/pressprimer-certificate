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
 * window and fixes its end; later hits count against it without moving
 * it (before 2.1 every hit reset the transient's expiry, so a steady
 * stream of requests never let the window close).
 *
 * Callers build their own transient keys (prefix discipline applies:
 * nothing shorter than ppcert_) and never put raw identifying data in
 * them - per-visitor buckets use client_key(), which hashes the
 * address; the test-email path keys on the user id.
 *
 * @since 2.0.0
 */
class PressPrimer_Certificate_Rate_Limiter {

	/**
	 * Count a hit against a limit
	 *
	 * @since 2.0.0
	 * @since 2.1.0 The window keeps its first expiry.
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

		$window = max( 1, (int) $window );
		$now    = time();
		$stored = get_transient( $transient_key );
		$count  = 0;
		$reset  = $now + $window;

		if ( is_array( $stored ) && isset( $stored['count'], $stored['reset'] ) && (int) $stored['reset'] > $now ) {
			$count = (int) $stored['count'];
			$reset = (int) $stored['reset'];
		} elseif ( is_numeric( $stored ) ) {
			// A 2.0 counter (a bare integer) still counts; its window
			// starts now.
			$count = (int) $stored;
		}

		if ( $count >= $limit ) {
			return false;
		}

		set_transient(
			$transient_key,
			[
				'count' => $count + 1,
				'reset' => $reset,
			],
			max( 1, $reset - $now )
		);

		return true;
	}

	/**
	 * The transient key for this visitor in a named bucket
	 *
	 * `ppcert_{bucket}_` plus a salted hash of the address; the raw IP is
	 * never stored. Verification's bucket is `verify` (its 1.0 key).
	 *
	 * @since 2.1.0
	 *
	 * @param string $bucket Bucket name (sanitize_key form).
	 * @return string
	 */
	public static function client_key( $bucket ) {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';

		return 'ppcert_' . sanitize_key( (string) $bucket ) . '_' . wp_hash( $ip . wp_salt() );
	}
}
