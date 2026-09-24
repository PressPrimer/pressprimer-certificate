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
	 * Lifecycle events (2.0, Feature 2.0-006 FR-006): revoke and
	 * reinstate each record their event row with the acting user; the
	 * staff-only reason never lands in event meta; no-op transitions
	 * record nothing.
	 *
	 * @return void
	 */
	public function test_revoke_and_reinstate_record_events() {
		$GLOBALS['ppcert_test_current_user'] = 5;

		$id = $this->seed_certificate();

		PressPrimer_Certificate_Certificate::revoke( $id, 'Issued in error' );
		PressPrimer_Certificate_Certificate::revoke( $id, 'Again' ); // No-op.
		PressPrimer_Certificate_Certificate::reinstate( $id );
		PressPrimer_Certificate_Certificate::reinstate( $id ); // No-op.

		$events = array_values(
			array_filter(
				$this->wpdb->rows( PressPrimer_Certificate_Certificate::events_table() ),
				static function ( $row ) use ( $id ) {
					return (int) $row['certificate_id'] === $id;
				}
			)
		);

		$this->assertCount( 2, $events, 'One event per real transition; no-ops record nothing.' );
		$this->assertSame( 'revoked', $events[0]['event_type'] );
		$this->assertSame( 5, (int) $events[0]['actor_id'] );
		$this->assertStringNotContainsString(
			'Issued in error',
			(string) wp_json_encode( $events[0] ),
			'The staff-only reason stays on the certificate row.'
		);
		$this->assertSame( 'reinstated', $events[1]['event_type'] );
		$this->assertSame( 5, (int) $events[1]['actor_id'] );
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
	 * Revocation and deletion take the cached preview PNG offline via
	 * the lifecycle hooks (it lives at a static uploads URL, so it must
	 * not outlive the 410ing PDF).
	 *
	 * @return void
	 */
	public function test_revoke_and_delete_remove_cached_preview() {
		PressPrimer_Certificate_Preview_Service::init();

		$make_preview = function ( $credential ) {
			$path = PressPrimer_Certificate_Preview_Service::preview_path( $credential );
			if ( ! is_dir( dirname( $path ) ) ) {
				mkdir( dirname( $path ), 0777, true );
			}
			file_put_contents( $path, 'png-bytes' );
			return $path;
		};

		// Revocation removes the preview.
		$revoke_id    = $this->seed_certificate( [ 'credential_id' => 'PREVAAAA0001' ] );
		$revoke_png   = $make_preview( 'PREVAAAA0001' );
		$this->assertFileExists( $revoke_png );
		PressPrimer_Certificate_Certificate::revoke( $revoke_id, 'Test' );
		$this->assertFileDoesNotExist( $revoke_png, 'Revocation deletes the cached preview' );

		// Permanent deletion removes it too (row already gone when the
		// hook fires; the credential ID rides the hook).
		$delete_id  = $this->seed_certificate( [ 'credential_id' => 'PREVAAAA0002' ] );
		$delete_png = $make_preview( 'PREVAAAA0002' );
		$this->assertFileExists( $delete_png );
		PressPrimer_Certificate_Certificate::delete( $delete_id );
		$this->assertFileDoesNotExist( $delete_png, 'Deletion deletes the cached preview' );
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

	/**
	 * display_title() (Feature 1.1-006): stored name, else the joined
	 * template title, else the caller's fallback - from hydrated and raw
	 * rows alike.
	 *
	 * @return void
	 */
	public function test_display_title_chain() {
		$model = 'PressPrimer_Certificate_Certificate';

		// Stored name wins, hydrated form.
		$this->assertSame(
			'Botany 101 Certificate',
			$model::display_title(
				(object) [
					'merge_data'     => [ 'certificate.title' => 'Botany 101 Certificate' ],
					'template_title' => 'Course Certificate',
				]
			)
		);

		// Stored name wins, raw-row form.
		$this->assertSame(
			'Botany 101 Certificate',
			$model::display_title(
				(object) [
					'merge_data_json' => '{"certificate.title":"Botany 101 Certificate"}',
					'template_title'  => 'Course Certificate',
				]
			)
		);

		// Pre-1.1 certificate: no stored name, the template title.
		$this->assertSame(
			'Course Certificate',
			$model::display_title(
				(object) [
					'merge_data'     => [ 'recipient.full_name' => 'Dana' ],
					'template_title' => 'Course Certificate',
				]
			)
		);

		// Blank stored name is treated as absent.
		$this->assertSame(
			'Course Certificate',
			$model::display_title(
				(object) [
					'merge_data'     => [ 'certificate.title' => '  ' ],
					'template_title' => 'Course Certificate',
				]
			)
		);

		// Deleted template: the caller's fallback.
		$this->assertSame(
			'(deleted template)',
			$model::display_title( (object) [ 'template_title' => null ], '(deleted template)' )
		);
	}

	/**
	 * get_latest_for_recipient (2.0-008): guards, filters, ordering.
	 *
	 * @return void
	 */
	public function test_get_latest_for_recipient() {
		$this->wpdb->seed_row( PressPrimer_Certificate_Template::table(), [ 'id' => 1, 'title' => 'T1' ] );

		$this->seed_certificate( [ 'recipient_id' => 7, 'template_id' => 1, 'source_type' => 'lms_a', 'source_ref' => '42', 'issued_at' => '2026-01-01 00:00:00' ] );
		$this->seed_certificate( [ 'recipient_id' => 7, 'template_id' => 1, 'source_type' => 'lms_a', 'source_ref' => '42', 'issued_at' => '2026-03-01 00:00:00' ] );
		$this->seed_certificate( [ 'recipient_id' => 7, 'template_id' => 1, 'source_type' => 'lms_a', 'source_ref' => '42', 'issued_at' => '2026-05-01 00:00:00', 'status' => 'revoked' ] );
		$this->seed_certificate( [ 'recipient_id' => 7, 'template_id' => 2, 'source_type' => 'lms_b', 'source_ref' => '42', 'issued_at' => '2026-06-01 00:00:00' ] );
		$this->seed_certificate( [ 'recipient_id' => 8, 'template_id' => 1, 'source_type' => 'lms_a', 'source_ref' => '42', 'issued_at' => '2026-07-01 00:00:00' ] );

		$this->assertNull( PressPrimer_Certificate_Certificate::get_latest_for_recipient( 7, [] ), 'No scope at all never queries' );
		$this->assertSame( 0, $this->wpdb->read_queries );
		$this->assertNull( PressPrimer_Certificate_Certificate::get_latest_for_recipient( 7, [ 'source_ref' => '42' ] ), 'A ref without a type list is refused' );
		$this->assertNull( PressPrimer_Certificate_Certificate::get_latest_for_recipient( 0, [ 'template_id' => 1 ] ) );

		$row = PressPrimer_Certificate_Certificate::get_latest_for_recipient( 7, [ 'source_ref' => '42', 'source_types' => [ 'lms_a' ] ] );
		$this->assertSame( '2026-03-01 00:00:00', $row->issued_at, 'Newest non-revoked of the family' );

		$row = PressPrimer_Certificate_Certificate::get_latest_for_recipient( 7, [ 'source_ref' => '42', 'source_types' => [ 'lms_a', 'lms_b' ] ] );
		$this->assertSame( '2026-06-01 00:00:00', $row->issued_at, 'Type list widens the family' );

		$row = PressPrimer_Certificate_Certificate::get_latest_for_recipient( 7, [ 'template_id' => 1 ] );
		$this->assertSame( '2026-03-01 00:00:00', $row->issued_at, 'Template alone' );

		$this->assertNull( PressPrimer_Certificate_Certificate::get_latest_for_recipient( 7, [ 'template_id' => 2, 'source_ref' => '42', 'source_types' => [ 'lms_a' ] ] ), 'Both filters must match' );
		$this->assertNull( PressPrimer_Certificate_Certificate::get_latest_for_recipient( 9, [ 'template_id' => 1 ] ) );
	}

	/**
	 * The certificate scope (2.0, School contract): the list query and
	 * per-certificate access follow the ppcert_certificate_scope filter -
	 * the scope's issuers plus the user's own issues; null is unscoped.
	 *
	 * @return void
	 */
	public function test_scope_query_and_access() {
		$a    = $this->seed_certificate( [ 'uuid' => 'a', 'credential_id' => 'AAAA1111BBBB', 'issuer_id' => 1, 'issued_by' => 5, 'source_type' => 'manual' ] );
		$b    = $this->seed_certificate( [ 'uuid' => 'b', 'credential_id' => 'CCCC2222DDDD', 'issuer_id' => 2, 'issued_by' => 5, 'source_type' => 'lms_b' ] );
		$mine = $this->seed_certificate( [ 'uuid' => 'c', 'credential_id' => 'EEEE3333FFFF', 'issuer_id' => null, 'issued_by' => 9, 'source_type' => 'ppquiz' ] );

		$this->assertNull( PressPrimer_Certificate_Certificate::scope_for( 9 ), 'No filter: unscoped' );
		$this->assertCount( 3, PressPrimer_Certificate_Certificate::query( [] )['items'] );

		add_filter(
			'ppcert_certificate_scope',
			static function ( $scope, $user_id ) {
				return 9 === (int) $user_id ? [ 'issuer_ids' => [ 1, '1', 0 ], 'issued_by' => 9 ] : $scope;
			},
			10,
			2
		);

		$this->assertSame( [ 'issuer_ids' => [ 1 ], 'issued_by' => 9 ], PressPrimer_Certificate_Certificate::scope_for( 9 ), 'Normalized: ints, unique, no zero' );

		$GLOBALS['ppcert_test_current_user'] = 9;
		$ids = array_map(
			static function ( $row ) {
				return (int) $row->id;
			},
			PressPrimer_Certificate_Certificate::query( [] )['items']
		);
		$this->assertEqualsCanonicalizing( [ $a, $mine ], $ids, 'Issuer 1 plus own issues; issuer 2 hidden' );
		$this->assertSame( 2, PressPrimer_Certificate_Certificate::query( [] )['total'] );
		$this->assertSame( 2, PressPrimer_Certificate_Certificate::count_all() );
		$this->assertSame( [ 'manual', 'ppquiz' ], PressPrimer_Certificate_Certificate::source_types_for( 9 ), 'The Source filter offers only the sources present in the scope' );
		$this->assertSame( [ 'lms_b', 'manual', 'ppquiz' ], PressPrimer_Certificate_Certificate::source_types_for( 5 ), 'Unscoped users see every source present' );

		$this->assertTrue( PressPrimer_Certificate_Certificate::user_can_access( PressPrimer_Certificate_Certificate::get( $a ), 9 ) );
		$this->assertTrue( PressPrimer_Certificate_Certificate::user_can_access( PressPrimer_Certificate_Certificate::get( $mine ), 9 ) );
		$this->assertFalse( PressPrimer_Certificate_Certificate::user_can_access( PressPrimer_Certificate_Certificate::get( $b ), 9 ) );
		$this->assertFalse( PressPrimer_Certificate_Certificate::user_can_access( null, 9 ) );

		// An explicit scope argument overrides the current user's.
		$this->assertCount( 3, PressPrimer_Certificate_Certificate::query( [ 'scope' => null ] )['items'] );
		$this->assertCount( 1, PressPrimer_Certificate_Certificate::query( [ 'scope' => [ 'issuer_ids' => [ 2 ] ] ] )['items'] );

		$GLOBALS['ppcert_test_current_user'] = 5;
		$this->assertTrue( PressPrimer_Certificate_Certificate::user_can_access( PressPrimer_Certificate_Certificate::get( $b ), 5 ), 'Another user is unscoped' );
		$this->assertCount( 3, PressPrimer_Certificate_Certificate::query( [] )['items'] );
	}
}
