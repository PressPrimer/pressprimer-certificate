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
 * Core credential IDs are never sequential and never derived from row
 * IDs (Feature 003 Security Requirements). Uniqueness is enforced by the
 * UNIQUE KEY on wp_ppcert_certificates.credential_id with insert-time
 * collision retry in the issuance service.
 *
 * Since 2.1.0 an addon may supply a pattern ID through the
 * `ppcert_credential_id` filter (School credential ID formats, Decision
 * 006): stored normalized (so every lookup keeps working), with its
 * display form snapshotted in `credential_display`. Pattern IDs can be
 * sequential; that is the addon's disclosed choice, not the core format.
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
	 * Option recording that the site holds pattern (non-core) IDs
	 *
	 * Set at the first accepted non-core insert (Decision 006 point 6).
	 * From then on the default validity check accepts any normalized
	 * 4-32 character ID and lets the exact lookup decide, so pattern IDs
	 * keep verifying when the addon that generated them is inactive.
	 *
	 * @since 2.1.0
	 * @var string
	 */
	const PATTERN_IDS_OPTION = 'ppcert_pattern_ids_in_use';

	/**
	 * Shape of a normalized pattern ID (Decision 006 point 1)
	 *
	 * @since 2.1.0
	 * @var string
	 */
	const PATTERN_SHAPE = '/^[0-9A-Z]{4,32}$/';

	/**
	 * Shape of a display form: letters, digits, single hyphens
	 *
	 * @since 2.1.0
	 * @var string
	 */
	const DISPLAY_SHAPE = '/^[0-9A-Za-z]+(-[0-9A-Za-z]+)*$/';

	/**
	 * Longest accepted display form
	 *
	 * @since 2.1.0
	 * @var int
	 */
	const DISPLAY_MAX_LENGTH = 40;

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
	 * A snapshotted display form (a pattern ID's `credential_display`)
	 * wins; otherwise the stored form groups as XXXX-XXXX-XXXX.
	 * Invalid-length input is returned unchanged (display formatting
	 * never invents validity). Callers holding a certificate row use
	 * PressPrimer_Certificate_Certificate::display_credential_id(), which
	 * also applies the `ppcert_credential_id_display` filter.
	 *
	 * @since 1.0.0
	 * @since 2.1.0 The $display argument.
	 *
	 * @param string $credential_id Stored-form credential ID.
	 * @param string $display       Optional snapshotted display form.
	 * @return string Display form.
	 */
	public static function format_display( $credential_id, $display = '' ) {
		if ( '' !== (string) $display ) {
			return (string) $display;
		}

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
	 * Whether a credential ID may exist (the lookup gate)
	 *
	 * The single pre-database gate for every public lookup (REST
	 * verification, the no-JS form, Enterprise's API) and for building
	 * certificate URLs. Core IDs pass on their checksum. Once the site
	 * holds pattern IDs, any normalized 4-32 character ID passes and the
	 * exact lookup decides (Decision 006 point 6). Filterable through
	 * `ppcert_credential_id_is_valid`.
	 *
	 * @since 2.1.0
	 *
	 * @param string $input Credential ID in any accepted input form.
	 * @return bool
	 */
	public static function is_valid( $input ) {
		$normalized = self::normalize( $input );
		$valid      = self::is_well_formed( $normalized )
			|| ( self::pattern_ids_in_use() && (bool) preg_match( self::PATTERN_SHAPE, $normalized ) );

		/**
		 * Filters whether a normalized credential ID may exist.
		 *
		 * @since 2.1.0
		 *
		 * @param bool   $valid      Default: the core checksum, or the pattern
		 *                           shape once the site holds pattern IDs.
		 * @param string $normalized The normalized candidate.
		 */
		return (bool) apply_filters( 'ppcert_credential_id_is_valid', $valid, $normalized );
	}

	/**
	 * Whether the site holds any pattern (non-core) IDs
	 *
	 * @since 2.1.0
	 *
	 * @return bool
	 */
	public static function pattern_ids_in_use() {
		return (bool) get_option( self::PATTERN_IDS_OPTION, false );
	}

	/**
	 * Accept or reject an addon-supplied ID candidate
	 *
	 * The `ppcert_credential_id` filter returns `[ 'id', 'display' ]`.
	 * Accepted only when `id` is already normalized and 4-32 characters
	 * of [0-9A-Z], and `display` (optional) is at most 40 characters of
	 * letters, digits, and single hyphens that normalizes to `id`
	 * (Decision 006 point 1). Anything else returns null and the caller
	 * keeps the core ID.
	 *
	 * @since 2.1.0
	 *
	 * @param mixed $candidate Filter result.
	 * @return array|null [ 'id' => string, 'display' => string ], or null.
	 */
	public static function accept_candidate( $candidate ) {
		if ( ! is_array( $candidate ) || ! isset( $candidate['id'] ) || ! is_string( $candidate['id'] ) ) {
			return null;
		}

		$id      = $candidate['id'];
		$display = isset( $candidate['display'] ) && is_string( $candidate['display'] ) ? $candidate['display'] : '';

		if ( self::normalize( $id ) !== $id || ! preg_match( self::PATTERN_SHAPE, $id ) ) {
			return null;
		}

		if ( '' !== $display && (
			strlen( $display ) > self::DISPLAY_MAX_LENGTH
			|| ! preg_match( self::DISPLAY_SHAPE, $display )
			|| self::normalize( $display ) !== $id
		) ) {
			return null;
		}

		return [
			'id'      => $id,
			'display' => $display,
		];
	}

	/**
	 * Accept or reject a credential ID a caller supplies for one
	 * certificate (2.1, Feature 2.1-004 FR-017: Educator's Bulk Award CSV
	 * carries IDs from another system)
	 *
	 * The same rules as an addon's pattern ID (Decision 006 point 1): the
	 * value as shown is at most 40 letters, digits, and single hyphens,
	 * uppercased; its stored form (normalize()) is 4-32 characters of
	 * [0-9A-Z]. Whether the ID is free is the issuer's check (the unique
	 * key is the last word).
	 *
	 * @since 2.1.0
	 *
	 * @param mixed $input The ID as supplied.
	 * @return array|WP_Error [ 'id' => stored form, 'display' => display form or '' ].
	 */
	public static function accept_supplied( $input ) {
		$display = is_scalar( $input ) ? strtoupper( trim( (string) $input ) ) : '';
		$id      = self::normalize( $display );

		if ( '' === $display
			|| strlen( $display ) > self::DISPLAY_MAX_LENGTH
			|| ! preg_match( self::DISPLAY_SHAPE, $display )
			|| ! preg_match( self::PATTERN_SHAPE, $id ) ) {
			return new WP_Error(
				'ppcert_credential_id_invalid',
				__( 'A credential ID needs 4 to 32 letters and numbers, with single hyphens between groups (40 characters at most).', 'pressprimer-certificate' )
			);
		}

		return [
			'id'      => $id,
			'display' => $display !== $id ? $display : '',
		];
	}

	/**
	 * Whether an ID is a core-format ID (shape and checksum)
	 *
	 * @since 2.1.0
	 *
	 * @param string $id Stored-form ID.
	 * @return bool
	 */
	public static function is_core( $id ) {
		return self::is_well_formed( $id ) && self::normalize( $id ) === (string) $id;
	}

	/**
	 * The example ID shown in verification placeholders
	 *
	 * @since 2.1.0
	 *
	 * @return string
	 */
	public static function example() {
		/**
		 * Filters the example credential ID shown in verification inputs.
		 *
		 * @since 2.1.0
		 *
		 * @param string $example Default 'XXXX-XXXX-XXXX'.
		 */
		return (string) apply_filters( 'ppcert_credential_id_example', 'XXXX-XXXX-XXXX' );
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
