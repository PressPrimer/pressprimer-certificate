<?php
/**
 * Privacy integration tests (Feature 008 FR-005, Prompt 4.6)
 *
 * The personal data exporter's shape, the eraser's removal of
 * certificate rows + events + credits + preview PNGs, batch paging,
 * and the export-then-erase verification round trip.
 *
 * @package PressPrimer_Certificate
 * @subpackage Tests
 * @since 1.0.0
 */

use PHPUnit\Framework\TestCase;

/**
 * Privacy test case
 *
 * @since 1.0.0
 */
class Test_Privacy extends TestCase {

	/**
	 * The fake wpdb for the current test.
	 *
	 * @var PPCert_Fake_WPDB
	 */
	private $wpdb;

	/**
	 * Reset state, seed the user and template.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		ppcert_tests_reset_hooks();
		$this->wpdb = ppcert_tests_reset_wpdb();

		$GLOBALS['ppcert_test_options'] = [];
		$GLOBALS['ppcert_test_users']   = [
			7 => (object) [
				'ID'           => 7,
				'display_name' => 'Dana Whitfield',
				'user_email'   => 'dana@example.test',
			],
		];

		$this->wpdb->seed_row(
			'wp_ppcert_templates',
			[
				'uuid'       => 'tpl-privacy',
				'title'      => 'Completion Award',
				'status'     => 'published',
				'deleted_at' => null,
			]
		);
	}

	/**
	 * Seed a certificate for user 7.
	 *
	 * @param array $overrides Column overrides.
	 * @return int Row id.
	 */
	private function seed_certificate( array $overrides = [] ) {
		static $seq = 0;
		$seq++;

		return $this->wpdb->seed_row(
			'wp_ppcert_certificates',
			array_merge(
				[
					'uuid'                 => 'cert-privacy-' . $seq,
					'credential_id'        => 'PR1V' . str_pad( (string) $seq, 8, '0', STR_PAD_LEFT ),
					'template_id'          => 1,
					'recipient_id'         => 7,
					'source_type'          => 'manual',
					'status'               => 'issued',
					'layout_snapshot_json' => '{"layout_schema_version":1,"elements":[]}',
					'merge_data_json'      => '{"recipient.name":"Dana Whitfield","source.quiz_title":"Safety Basics"}',
					'issued_at'            => '2026-07-01 12:00:00',
					'expires_at'           => null,
				],
				$overrides
			)
		);
	}

	/**
	 * Both handlers register through the WordPress privacy filters.
	 *
	 * @return void
	 */
	public function test_handlers_register() {
		$exporters = PressPrimer_Certificate_Privacy::register_exporter( [] );
		$erasers   = PressPrimer_Certificate_Privacy::register_eraser( [] );

		$this->assertArrayHasKey( 'ppcert-certificates', $exporters );
		$this->assertArrayHasKey( 'ppcert-certificates', $erasers );
		$this->assertIsCallable( $exporters['ppcert-certificates']['callback'] );
		$this->assertIsCallable( $erasers['ppcert-certificates']['callback'] );
	}

	/**
	 * Export: certificate facts, merge data values, and credit rows.
	 *
	 * @return void
	 */
	public function test_export_shape() {
		$this->seed_certificate();

		$this->wpdb->seed_row(
			'wp_ppcert_credit_types',
			[
				'uuid' => 'ct-1',
				'name' => 'CPD Hours',
				'slug' => 'cpd-hours',
			]
		);
		$this->wpdb->seed_row(
			'wp_ppcert_credits',
			[
				'certificate_id' => 1,
				'user_id'        => 7,
				'credit_type_id' => 1,
				'amount'         => '2.50',
				'awarded_at'     => '2026-07-01 12:00:00',
			]
		);

		$result = PressPrimer_Certificate_Privacy::export( 'dana@example.test' );

		$this->assertTrue( $result['done'] );
		$this->assertCount( 2, $result['data'] );

		$certificate_item = $result['data'][0];
		$this->assertSame( 'ppcert-certificates', $certificate_item['group_id'] );

		$pairs = [];
		foreach ( $certificate_item['data'] as $pair ) {
			$pairs[ $pair['name'] ] = $pair['value'];
		}

		$this->assertSame( 'PR1V-0000-0001', $pairs['Credential ID'] );
		$this->assertSame( 'Completion Award', $pairs['Certificate'] );
		$this->assertSame( 'manual', $pairs['Source'] );
		$this->assertSame( 'issued', $pairs['Status'] );
		$this->assertSame( 'Dana Whitfield', $pairs['recipient.name'] );
		$this->assertSame( 'Safety Basics', $pairs['source.quiz_title'] );

		$credit_item = $result['data'][1];
		$this->assertSame( 'ppcert-credits', $credit_item['group_id'] );

		$credit_pairs = [];
		foreach ( $credit_item['data'] as $pair ) {
			$credit_pairs[ $pair['name'] ] = $pair['value'];
		}

		$this->assertSame( 'CPD Hours', $credit_pairs['Credit type'] );
		$this->assertSame( '2.50', $credit_pairs['Amount'] );
	}

	/**
	 * An unknown email exports nothing and completes.
	 *
	 * @return void
	 */
	public function test_export_unknown_email() {
		$this->seed_certificate();

		$result = PressPrimer_Certificate_Privacy::export( 'nobody@example.test' );

		$this->assertTrue( $result['done'] );
		$this->assertSame( [], $result['data'] );
	}

	/**
	 * Erase: rows, events, credits, and the preview PNG all go; the
	 * message notes that verification is gone; the credential no longer
	 * verifies.
	 *
	 * @return void
	 */
	public function test_erase_removes_everything() {
		$id = $this->seed_certificate( [ 'credential_id' => 'PR1VERASE001' ] );

		$this->wpdb->seed_row(
			'wp_ppcert_events',
			[
				'certificate_id' => $id,
				'event_type'     => 'issued',
				'created_at'     => '2026-07-01 12:00:00',
			]
		);
		$this->wpdb->seed_row(
			'wp_ppcert_credits',
			[
				'certificate_id' => $id,
				'user_id'        => 7,
				'credit_type_id' => 1,
				'amount'         => '1.00',
				'awarded_at'     => '2026-07-01 12:00:00',
			]
		);

		// A cached preview PNG on disk.
		$path = PressPrimer_Certificate_Preview_Service::preview_path( 'PR1VERASE001' );
		wp_mkdir_p( dirname( $path ) );
		file_put_contents( $path, 'png-bytes' );
		$this->assertFileExists( $path );

		$result = PressPrimer_Certificate_Privacy::erase( 'dana@example.test' );

		$this->assertTrue( $result['done'] );
		$this->assertGreaterThanOrEqual( 2, $result['items_removed'] );
		$this->assertSame( 0, $result['items_retained'] );
		$this->assertStringContainsString( 'no longer be verified', $result['messages'][0] );

		$this->assertCount( 0, $this->wpdb->rows( 'wp_ppcert_certificates' ) );
		$this->assertCount( 0, $this->wpdb->rows( 'wp_ppcert_events' ) );
		$this->assertCount( 0, $this->wpdb->rows( 'wp_ppcert_credits' ) );
		$this->assertFileDoesNotExist( $path );

		// The verification round trip: erased means Not Found.
		$this->assertNull(
			PressPrimer_Certificate_Certificate::get_for_verification( 'PR1VERASE001' )
		);
	}

	/**
	 * Erasure pages: a batch-and-a-half takes two passes.
	 *
	 * @return void
	 */
	public function test_erase_pages_through_batches() {
		$total = PressPrimer_Certificate_Privacy::BATCH_SIZE + 3;

		for ( $i = 0; $i < $total; $i++ ) {
			$this->seed_certificate();
		}

		$first = PressPrimer_Certificate_Privacy::erase( 'dana@example.test' );
		$this->assertFalse( $first['done'] );
		$this->assertSame( PressPrimer_Certificate_Privacy::BATCH_SIZE, $first['items_removed'] );

		$second = PressPrimer_Certificate_Privacy::erase( 'dana@example.test', 2 );
		$this->assertTrue( $second['done'] );
		$this->assertSame( 3, $second['items_removed'] );

		$this->assertCount( 0, $this->wpdb->rows( 'wp_ppcert_certificates' ) );
	}

	/**
	 * An unknown email erases nothing and completes.
	 *
	 * @return void
	 */
	public function test_erase_unknown_email() {
		$this->seed_certificate();

		$result = PressPrimer_Certificate_Privacy::erase( 'nobody@example.test' );

		$this->assertTrue( $result['done'] );
		$this->assertSame( 0, $result['items_removed'] );
		$this->assertCount( 1, $this->wpdb->rows( 'wp_ppcert_certificates' ) );
	}
}
