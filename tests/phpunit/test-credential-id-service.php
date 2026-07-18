<?php
/**
 * Credential ID service unit tests
 *
 * Exercises the format locked at the Prompt 1.4 checkpoint (Feature 003
 * FR-002): alphabet, checksum behavior, normalization, display, and
 * randomness distribution.
 *
 * @package PressPrimer_Certificate
 * @subpackage Tests
 * @since 1.0.0
 */

use PHPUnit\Framework\TestCase;

/**
 * Credential ID service test case
 *
 * @since 1.0.0
 */
class Test_Credential_ID_Service extends TestCase {

	/**
	 * Generated IDs are 12 chars from the Crockford alphabet and never
	 * contain the excluded ambiguous characters.
	 *
	 * @return void
	 */
	public function test_alphabet_exclusions() {
		$this->assertSame( 32, strlen( PressPrimer_Certificate_Credential_ID_Service::ALPHABET ) );

		foreach ( [ 'I', 'L', 'O', 'U' ] as $excluded ) {
			$this->assertStringNotContainsString(
				$excluded,
				PressPrimer_Certificate_Credential_ID_Service::ALPHABET
			);
		}

		for ( $i = 0; $i < 200; $i++ ) {
			$id = PressPrimer_Certificate_Credential_ID_Service::generate();

			$this->assertMatchesRegularExpression(
				'/^[0-9ABCDEFGHJKMNPQRSTVWXYZ]{12}$/',
				$id
			);
		}
	}

	/**
	 * Generated IDs always validate.
	 *
	 * @return void
	 */
	public function test_generated_ids_are_well_formed() {
		for ( $i = 0; $i < 200; $i++ ) {
			$id = PressPrimer_Certificate_Credential_ID_Service::generate();

			$this->assertTrue(
				PressPrimer_Certificate_Credential_ID_Service::is_well_formed( $id ),
				"Generated ID failed its own checksum: {$id}"
			);
		}
	}

	/**
	 * The checksum catches every single-character substitution.
	 *
	 * Exhaustive: for a set of generated IDs, every position substituted
	 * with every other alphabet symbol must fail validation.
	 *
	 * @return void
	 */
	public function test_checksum_catches_all_single_char_typos() {
		$alphabet = PressPrimer_Certificate_Credential_ID_Service::ALPHABET;

		for ( $sample = 0; $sample < 10; $sample++ ) {
			$id = PressPrimer_Certificate_Credential_ID_Service::generate();

			for ( $position = 0; $position < 12; $position++ ) {
				for ( $symbol = 0; $symbol < 32; $symbol++ ) {
					$replacement = $alphabet[ $symbol ];

					if ( $replacement === $id[ $position ] ) {
						continue;
					}

					$corrupted              = $id;
					$corrupted[ $position ] = $replacement;

					$this->assertFalse(
						PressPrimer_Certificate_Credential_ID_Service::is_well_formed( $corrupted ),
						"Single-char typo not caught: {$id} -> {$corrupted} (pos {$position})"
					);
				}
			}
		}
	}

	/**
	 * The checksum catches adjacent transpositions at the documented rate.
	 *
	 * True rate is 96.8% (a swap goes undetected only when the two symbols'
	 * values differ by exactly 16: 32 of the 992 ordered pairs). With 2000
	 * samples, sigma is ~0.4%, so the 94% assertion threshold sits >7 sigma
	 * below the mean - no flake risk.
	 *
	 * @return void
	 */
	public function test_checksum_catches_transpositions_sampling() {
		$attempted = 0;
		$caught    = 0;

		for ( $sample = 0; $sample < 2000; $sample++ ) {
			$id       = PressPrimer_Certificate_Credential_ID_Service::generate();
			$position = wp_rand( 0, 10 );

			if ( $id[ $position ] === $id[ $position + 1 ] ) {
				continue; // Swapping identical characters changes nothing.
			}

			$swapped                  = $id;
			$swapped[ $position ]     = $id[ $position + 1 ];
			$swapped[ $position + 1 ] = $id[ $position ];

			$attempted++;

			if ( ! PressPrimer_Certificate_Credential_ID_Service::is_well_formed( $swapped ) ) {
				$caught++;
			}
		}

		$this->assertGreaterThan( 1000, $attempted );
		$this->assertGreaterThan(
			0.94,
			$caught / $attempted,
			"Transposition detection rate too low: {$caught}/{$attempted}"
		);
	}

	/**
	 * Normalization table: separators stripped, case folded, confusables
	 * mapped (I/L -> 1, O -> 0).
	 *
	 * @return void
	 */
	public function test_normalize_table() {
		$cases = [
			'abcd-efgh-1234'   => 'ABCDEFGH1234',
			'ABCD EFGH 1234'   => 'ABCDEFGH1234',
			'abcd_efgh.1234'   => 'ABCDEFGH1234',
			'IL0O-i1lo-XYZ2'   => '11001110XYZ2', // I->1 L->1 O->0, both cases.
			'  7q4m-k9p2-xt3a' => '7Q4MK9P2XT3A',
		];

		foreach ( $cases as $input => $expected ) {
			$this->assertSame(
				$expected,
				PressPrimer_Certificate_Credential_ID_Service::normalize( $input ),
				"Normalize failed for input: {$input}"
			);
		}
	}

	/**
	 * Confusable input verifies: an ID transcribed with I/L/O for 1/1/0
	 * still passes is_well_formed after normalization.
	 *
	 * @return void
	 */
	public function test_confusables_still_validate() {
		// Find a generated ID containing a 0 or 1 to swap for a confusable.
		do {
			$id = PressPrimer_Certificate_Credential_ID_Service::generate();
		} while ( false === strpos( $id, '0' ) && false === strpos( $id, '1' ) );

		$transcribed = strtr( $id, [ '0' => 'O', '1' => 'l' ] );

		$this->assertNotSame( $id, $transcribed );
		$this->assertTrue( PressPrimer_Certificate_Credential_ID_Service::is_well_formed( $transcribed ) );
		$this->assertSame( $id, PressPrimer_Certificate_Credential_ID_Service::normalize( $transcribed ) );
	}

	/**
	 * Display round-trip: stored -> XXXX-XXXX-XXXX -> normalize -> stored.
	 *
	 * @return void
	 */
	public function test_display_round_trip() {
		for ( $i = 0; $i < 50; $i++ ) {
			$id      = PressPrimer_Certificate_Credential_ID_Service::generate();
			$display = PressPrimer_Certificate_Credential_ID_Service::format_display( $id );

			$this->assertMatchesRegularExpression( '/^[0-9A-Z]{4}-[0-9A-Z]{4}-[0-9A-Z]{4}$/', $display );
			$this->assertSame( $id, PressPrimer_Certificate_Credential_ID_Service::normalize( $display ) );
			$this->assertTrue( PressPrimer_Certificate_Credential_ID_Service::is_well_formed( $display ) );
		}

		// Invalid-length input is returned unchanged, never grouped.
		$this->assertSame( 'SHORT', PressPrimer_Certificate_Credential_ID_Service::format_display( 'SHORT' ) );
	}

	/**
	 * Malformed candidates are rejected: wrong length, out-of-alphabet
	 * characters, corrupted check character.
	 *
	 * @return void
	 */
	public function test_malformed_rejected() {
		$this->assertFalse( PressPrimer_Certificate_Credential_ID_Service::is_well_formed( '' ) );
		$this->assertFalse( PressPrimer_Certificate_Credential_ID_Service::is_well_formed( 'ABC' ) );
		$this->assertFalse( PressPrimer_Certificate_Credential_ID_Service::is_well_formed( 'ABCDEFGH123456789' ) );
		// 'U' is not in the alphabet and does not normalize away.
		$this->assertFalse( PressPrimer_Certificate_Credential_ID_Service::is_well_formed( 'UUUU-UUUU-UUUU' ) );

		$id            = PressPrimer_Certificate_Credential_ID_Service::generate();
		$check_char    = $id[11];
		$alphabet      = PressPrimer_Certificate_Credential_ID_Service::ALPHABET;
		$wrong_check   = $alphabet[ ( strpos( $alphabet, $check_char ) + 1 ) % 32 ];
		$corrupted     = substr( $id, 0, 11 ) . $wrong_check;

		$this->assertFalse( PressPrimer_Certificate_Credential_ID_Service::is_well_formed( $corrupted ) );
	}

	/**
	 * Distribution sanity: every alphabet symbol appears at every random
	 * position across a sample (no character position bias).
	 *
	 * With 2000 samples the chance of any symbol missing from a position
	 * is ~e^-63 per symbol - this can never flake.
	 *
	 * @return void
	 */
	public function test_distribution_no_position_bias() {
		$seen = array_fill( 0, 11, [] );

		for ( $i = 0; $i < 2000; $i++ ) {
			$id = PressPrimer_Certificate_Credential_ID_Service::generate();

			for ( $position = 0; $position < 11; $position++ ) {
				$seen[ $position ][ $id[ $position ] ] = true;
			}
		}

		for ( $position = 0; $position < 11; $position++ ) {
			$this->assertCount(
				32,
				$seen[ $position ],
				"Position {$position} did not produce all 32 symbols across 2000 samples"
			);
		}
	}
}
