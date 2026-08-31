<?php
/**
 * Credential ID service
 *
 * Generates and validates public credential identifiers.
 *
 * @package PressPrimer_Certificate
 * @subpackage Services
 * @since 1.0.0
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Credential ID service class
 *
 * Implements the format locked at the Prompt 1.4 checkpoint (Feature 003
 * FR-002, signed off 2026-07-17):
 *
 * - 12 characters from the Crockford Base32 alphabet (0-9 A-Z excluding
 *   I, L, O, U): characters 1-11 random via wp_rand (55 bits,
 *   non-guessable at any realistic issuance volume), character 12 a
 *   mod-32 check character
 * - Displayed XXXX-XXXX-XXXX; stored and compared ungrouped
 * - Lookup is case-insensitive and ignores separators; I/L normalize to 1
 *   and O to 0 (human transcription of printed certificates)
 *
 * The check character uses odd position weights, which detects 100% of
 * single-character substitutions and 96.8% of adjacent transpositions -
 * a fail-fast filter so the public verification endpoint rejects typos
 * before any database work. It is a UX/anti-junk measure, not a security
 * boundary; the 55 random bits are the security property.
 *
 * Credential IDs are never sequential and never derived from row IDs
 * (Feature 003 Security Requirements). Uniqueness is enforced by the
 * UNIQUE KEY on wp_ppcert_certificates.credential_id with insert-time
 * collision retry in the issuance service.
 *
 * @since 1.0.0
 */
class PressPrimer_Certificate_Credential_ID_Service {

	/**
	 * Crockford Base32 alphabet (no I, L, O, U); index = symbol value
	 *
	 * @since 1.0.0
	 * @var string
	 */
	const ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

	/**
	 * Number of random characters
	 *
	 * @since 1.0.0
	 * @var int
	 */
	const RANDOM_LENGTH = 11;

	/**
	 * Total credential ID length (random characters + check character)
	 *
	 * @since 1.0.0
	 * @var int
	 */
	const LENGTH = 12;

	/**
	 * Display group size (XXXX-XXXX-XXXX)
	 *
	 * @since 1.0.0
	 * @var int
	 */
	const DISPLAY_GROUP = 4;

	/**
	 * Position weights for the check character
	 *
	 * All odd (coprime with 32) so every single-character substitution
	 * changes the checksum; consecutive-odd spacing catches 96.8% of
	 * adjacent transpositions (a swap slips only when the two symbol
	 * values differ by exactly 16).
	 *
	 * @since 1.0.0
	 * @var int[]
	 */
	const CHECK_WEIGHTS = [ 1, 3, 5, 7, 9, 11, 13, 15, 17, 19, 21 ];

	/**
	 * Generate a new credential ID
	 *
	 * Returns the 12-character stored form (11 random + check character).
	 * Callers persist it against the UNIQUE key and retry on collision
	 * (issuance pipeline step 6).
	 *
	 * @since 1.0.0
	 *
	 * @return string 12-character credential ID.
	 */
	public static function generate() {
		$random = '';

		for ( $i = 0; $i < self::RANDOM_LENGTH; $i++ ) {
			$random .= self::ALPHABET[ wp_rand( 0, 31 ) ];
		}

		return $random . self::check_char( $random );
	}

	/**
	 * Format a credential ID for display
	 *
	 * Groups the stored form as XXXX-XXXX-XXXX. Invalid-length input is
	 * returned unchanged (display formatting never invents validity).
	 *
	 * @since 1.0.0
	 *
	 * @param string $credential_id Stored-form credential ID.
	 * @return string Display form.
	 */
	public static function format_display( $credential_id ) {
		$credential_id = (string) $credential_id;

		if ( self::LENGTH !== strlen( $credential_id ) ) {
			return $credential_id;
		}

		return implode( '-', str_split( $credential_id, self::DISPLAY_GROUP ) );
	}

	/**
	 * Normalize user input to the stored form
	 *
	 * Strips separators (spaces, hyphens, underscores, dots), uppercases,
	 * and maps the Crockford confusables I/L to 1 and O to 0. Does NOT
	 * validate - pair with is_well_formed().
	 *
	 * @since 1.0.0
	 *
	 * @param string $input Raw user input.
	 * @return string Normalized credential ID candidate.
	 */
	public static function normalize( $input ) {
		$normalized = strtoupper( (string) $input );
		$normalized = preg_replace( '/[\s\-_.]/', '', $normalized );

		return strtr(
			$normalized,
			[
				'I' => '1',
				'L' => '1',
				'O' => '0',
			]
		);
	}

	/**
	 * Whether a credential ID candidate is well-formed
	 *
	 * Normalizes, then checks length, alphabet membership, and the check
	 * character. A false result means the ID cannot exist - the public
	 * verification endpoint uses this as its pre-database gate.
	 *
	 * @since 1.0.0
	 *
	 * @param string $input Credential ID in any accepted input form.
	 * @return bool True when length, alphabet, and checksum all pass.
	 */
	public static function is_well_formed( $input ) {
		$candidate = self::normalize( $input );

		if ( ! self::is_credential_shaped( $candidate ) ) {
			return false;
		}

		$random = substr( $candidate, 0, self::RANDOM_LENGTH );

		return self::check_char( $random ) === $candidate[ self::RANDOM_LENGTH ];
	}

	/**
	 * Whether a normalized candidate has the ID's length/character shape
	 *
	 * Shape only - NO checksum (that is is_well_formed()'s job). The list
	 * search uses this gate (2.0, Feature 2.0-002 FR-001): anything
	 * shaped like a credential resolves as an exact credential lookup, a
	 * mistyped check character included - it simply matches no row.
	 *
	 * @since 2.0.0
	 *
	 * @param string $candidate NORMALIZED candidate (see normalize()).
	 * @return bool
	 */
	public static function is_credential_shaped( $candidate ) {
		return self::LENGTH === strlen( (string) $candidate )
			&& (bool) preg_match( '/^[' . self::ALPHABET . ']+$/', (string) $candidate );
	}

	/**
	 * Compute the check character for the random portion
	 *
	 * Weighted sum of symbol values mod 32, encoded in the same alphabet.
	 *
	 * @since 1.0.0
	 *
	 * @param string $random The 11 random characters.
	 * @return string Single check character.
	 */
	private static function check_char( $random ) {
		$sum = 0;

		for ( $i = 0; $i < self::RANDOM_LENGTH; $i++ ) {
			$sum += strpos( self::ALPHABET, $random[ $i ] ) * self::CHECK_WEIGHTS[ $i ];
		}

		return self::ALPHABET[ $sum % 32 ];
	}
}
