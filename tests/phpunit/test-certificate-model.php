<?php
/**
 * Certificate model tests
 *
 * Status evaluation with read-time expiry (Feature 003 FR-005),
 * normalized credential lookup, and the revoke, reinstate, and
 * delete transitions.
 *
 * @package PressPrimer_Certificate
 * @subpackage Tests
 * @since 1.0.0
 */

use PHPUnit\Framework\TestCase;

/**
 * Certificate model test case
 *
 * @since 1.0.0
 */
class Test_Certificate_Model extends TestCase {

	/**
	 * The fake wpdb for the current test.
	 *
	 * @var PPCert_Fake_WPDB
	 */
	private $wpdb;

	/**
	 * Reset state and seed a certificate row.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		ppcert_tests_reset_hooks();
		$this->wpdb = ppcert_tests_reset_wpdb();
	}

	/**
	 * Seed a certificate row.
	 *
	 * @param array $overrides Column overrides.
	 * @return int Row id.
	 */
	private function seed_certificate( array $overrides = [] ) {
		return $this->wpdb->seed_row(
			PressPrimer_Certificate_Certificate::table(),
			array_merge(
				[
					'uuid'                 => 'cert-0000-0000-0000',
					'credential_id'        => '7Q4MK9P2XT3A',
					'template_id'          => 1,
					'recipient_id'         => 7,
					'issued_by'            => 1,
					'source_type'          => 'manual',
					'source_ref'           => null,
					'status'               => 'issued',
					'layout_snapshot_json' => '{"layout_schema_version":1}',
					'merge_data_json'      => '{"recipient.display_name":"Dana Whitfield"}',
					'issued_at'            => '2026-07-01 12:00:00',
					'expires_at'           => null,
					'revoked_at'           => null,
					'revoke_reason'        => null,
				],
				$overrides
			)
		);
	}

	/**
	 * Effective status: read-time expiry evaluation (FR-005).
	 *
	 * @return void
	 */
	public function test_effective_status_read_time_expiry() {
		$no_expiry = (object) [ 'status' => 'issued', 'expires_at' => null ];
		$this->assertSame( 'issued', PressPrimer_Certificate_Certificate::effective_status( $no_expiry ) );

		$future = (object) [ 'status' => 'issued', 'expires_at' => gmdate( 'Y-m-d H:i:s', time() + 86400 ) ];
		$this->assertSame( 'issued', PressPrimer_Certificate_Certificate::effective_status( $future ) );

		$past = (object) [ 'status' => 'issued', 'expires_at' => '2020-01-01 00:00:00' ];
		$this->assertSame( 'expired', PressPrimer_Certificate_Certificate::effective_status( $past ), 'Past expires_at reports expired without a row update' );

		$revoked_and_expired = (object) [ 'status' => 'revoked', 'expires_at' => '2020-01-01 00:00:00' ];
		$this->assertSame( 'revoked', PressPrimer_Certificate_Certificate::effective_status( $revoked_and_expired ), 'Revoked always wins' );
	}

	/**
	 * Credential lookup accepts any normalized input form.
	 *
	 * @return void
	 */
	public function test_get_by_credential_id_normalizes() {
		$this->seed_certificate();

		$found = PressPrimer_Certificate_Certificate::get_by_credential_id( '7q4m-k9p2-xt3a' );
		$this->assertNotNull( $found );
		$this->assertSame( '7Q4MK9P2XT3A', $found->credential_id );

		// Confusables typed from print: O for 0 would appear here if the ID
		// contained one; exercise separators and case at minimum.
		$this->assertNotNull( PressPrimer_Certificate_Certificate::get_by_credential_id( ' 7Q4M K9P2 XT3A ' ) );
		$this->assertNull( PressPrimer_Certificate_Certificate::get_by_credential_id( 'ZZZZ-ZZZZ-ZZZZ' ) );
		$this->assertNull( PressPrimer_Certificate_Certificate::get_by_credential_id( '' ) );
	}

	/**
	 * Hydration exposes decoded snapshot arrays without touching the
	 * stored JSON.
	 *
	 * @return void
	 */
	public function test_hydration() {
		$id  = $this->seed_certificate();
		$row = PressPrimer_Certificate_Certificate::get( $id );

		$this->assertSame( [ 'layout_schema_version' => 1 ], $row->layout_snapshot );
		$this->assertSame( 'Dana Whitfield', $row->merge_data['recipient.display_name'] );
		$this->assertSame( '{"layout_schema_version":1}', $row->layout_snapshot_json );
	}

	/**
	 * Revoke: single transition path, hook contract, idempotency.
	 *
	 * @return void
	 */
	public function test_revoke_transition() {
		$id    = $this->seed_certificate();
		$calls = [];

		add_action(
			'ppcert_certificate_revoked',
			static function ( ...$args ) use ( &$calls ) {
				$calls[] = $args;
			},
			10,
			5
		);

		$result = PressPrimer_Certificate_Certificate::revoke( $id, 'Issued in error' );
		$this->assertTrue( $result );

		$row = PressPrimer_Certificate_Certificate::get( $id );
		$this->assertSame( 'revoked', $row->status );
		$this->assertSame( 'Issued in error', $row->revoke_reason );
		$this->assertNotEmpty( $row->revoked_at );
		$this->assertSame( 'revoked', PressPrimer_Certificate_Certificate::effective_status( $row ) );

		// Hook contract: ( int $certificate_id, string $reason ).
		$this->assertCount( 1, $calls );
		$this->assertCount( 2, $calls[0] );
		$this->assertSame( $id, $calls[0][0] );
		$this->assertSame( 'Issued in error', $calls[0][1] );

		// Idempotent: already revoked returns true without re-firing.
		$this->assertTrue( PressPrimer_Certificate_Certificate::revoke( $id, 'Again' ) );
		$this->assertCount( 1, $calls );

		// Unknown id errors.
		$this->assertInstanceOf( WP_Error::class, PressPrimer_Certificate_Certificate::revoke( 999 ) );
	}

	/**
	 * Reinstate undoes a revocation: status returns to issued, the
	 * revocation record clears, and the hook fires. Non-revoked rows
	 * no-op; unknown ids error.
	 *
	 * @return void
	 */
	public function test_reinstate_transition() {
		$id = $this->seed_certificate();

		PressPrimer_Certificate_Certificate::revoke( $id, 'Clerical error' );

		$calls = [];

		add_action(
			'ppcert_certificate_reinstated',
			static function ( ...$args ) use ( &$calls ) {
				$calls[] = $args;
			},
			10,
			5
		);

		$this->assertTrue( PressPrimer_Certificate_Certificate::reinstate( $id ) );

		$row = PressPrimer_Certificate_Certificate::get( $id );
		$this->assertSame( 'issued', $row->status );
		$this->assertEmpty( $row->revoked_at );
		$this->assertEmpty( $row->revoke_reason );
		$this->assertSame( 'issued', PressPrimer_Certificate_Certificate::effective_status( $row ) );

		// Hook contract: ( int $certificate_id ).
		$this->assertSame( [ [ $id ] ], $calls );

		// Non-revoked rows no-op without re-firing.
		$this->assertTrue( PressPrimer_Certificate_Certificate::reinstate( $id ) );
		$this->assertCount( 1, $calls );

		// A reinstated certificate past its expiry reports expired -
		// reinstatement never extends validity.
		$expired = $this->seed_certificate(
			[
				'credential_id' => 'RE1NEXP00001',
				'expires_at'    => '2020-01-01 00:00:00',
			]
		);
		PressPrimer_Certificate_Certificate::revoke( $expired );
		PressPrimer_Certificate_Certificate::reinstate( $expired );
		$this->assertSame(
			'expired',
			PressPrimer_Certificate_Certificate::effective_status( PressPrimer_Certificate_Certificate::get( $expired ) )
		);

		// Unknown id errors.
		$this->assertInstanceOf( WP_Error::class, PressPrimer_Certificate_Certificate::reinstate( 999 ) );
	}

	/**
	 * Delete permanently removes the row and its event history, fires
	 * the hook with the credential ID, and leaves other certificates'
	 * events untouched. Unknown ids error.
	 *
	 * @return void
	 */
	public function test_delete_removes_row_and_events() {
		$id    = $this->seed_certificate();
		$other = $this->seed_certificate( [ 'credential_id' => 'KEEPME000001' ] );

		PressPrimer_Certificate_Certificate::record_event( $id, 'verified' );
		PressPrimer_Certificate_Certificate::record_event( $id, 'downloaded', 1 );
		PressPrimer_Certificate_Certificate::record_event( $other, 'verified' );

		$calls = [];

		add_action(
			'ppcert_certificate_deleted',
			static function ( ...$args ) use ( &$calls ) {
				$calls[] = $args;
			},
			10,
			5
		);

		$this->assertTrue( PressPrimer_Certificate_Certificate::delete( $id ) );

		// The row and its history are gone; the credential no longer
		// verifies in any form.
		$this->assertNull( PressPrimer_Certificate_Certificate::get( $id ) );
		$this->assertNull( PressPrimer_Certificate_Certificate::get_by_credential_id( '7Q4MK9P2XT3A' ) );

		$remaining_events = $this->wpdb->rows( PressPrimer_Certificate_Certificate::events_table() );
		$this->assertCount( 1, $remaining_events, 'Only the other certificate\'s event survives' );

		$survivor = reset( $remaining_events );
		$this->assertSame( $other, (int) $survivor['certificate_id'] );

		// The other certificate itself is untouched.
		$this->assertNotNull( PressPrimer_Certificate_Certificate::get( $other ) );

		// Hook contract: ( int $certificate_id, string $credential_id ).
		$this->assertSame( [ [ $id, '7Q4MK9P2XT3A' ] ], $calls );

		// Unknown id errors without firing the hook.
		$this->assertInstanceOf( WP_Error::class, PressPrimer_Certificate_Certificate::delete( 999 ) );
		$this->assertCount( 1, $calls );
	}
}
