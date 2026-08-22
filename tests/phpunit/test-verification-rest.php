<?php
/**
 * Verification REST controller tests
 *
 * The plugin's highest-exposure surface: normalization, the checksum
 * gate, status precedence, the locked response shape, filter
 * re-assertion, event privacy, and the rate limiter.
 *
 * @package PressPrimer_Certificate
 * @subpackage Tests
 * @since 1.0.0
 */

use PHPUnit\Framework\TestCase;

/**
 * Verification REST test case
 *
 * @since 1.0.0
 */
class Test_Verification_REST extends TestCase {

	/**
	 * The locked response shape (TR-001). Changing this list is a
	 * public-contract change requiring explicit sign-off.
	 *
	 * @var string[]
	 */
	const LOCKED_SHAPE = [
		'valid',
		'status',
		'recipient_name',
		'subject',
		'issuer_name',
		'issued_at',
		'expires_at',
	];

	/**
	 * The fake wpdb.
	 *
	 * @var PPCert_Fake_WPDB
	 */
	private $wpdb;

	/**
	 * A well-formed credential ID for the seeded certificate.
	 *
	 * @var string
	 */
	private $credential;

	/**
	 * Seed a verifiable certificate.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		ppcert_tests_reset_hooks();
		ppcert_tests_reset_transients();
		$this->wpdb = ppcert_tests_reset_wpdb();

		$GLOBALS['ppcert_test_current_user'] = 0;
		$GLOBALS['ppcert_test_users']        = [];
		// Public visitors hold no capabilities - the realistic baseline
		// for this public, unauthenticated endpoint.
		$GLOBALS['ppcert_test_user_caps'] = [];
		$GLOBALS['ppcert_test_bloginfo']  = [ 'name' => 'Sunrise Training Academy' ];
		$_SERVER['REMOTE_ADDR']              = '203.0.113.10';

		$this->credential = PressPrimer_Certificate_Credential_ID_Service::generate();

		$this->wpdb->seed_row(
			PressPrimer_Certificate_Template::table(),
			[
				'id'    => 40,
				'title' => 'Advanced Botany Certification',
			]
		);

		$this->wpdb->seed_row(
			PressPrimer_Certificate_Certificate::table(),
			[
				'uuid'                 => 'cert-verify-0001',
				'credential_id'        => $this->credential,
				'template_id'          => 40,
				'recipient_id'         => 7,
				'issued_by'            => 1,
				'source_type'          => 'manual',
				'source_ref'           => null,
				'status'               => 'issued',
				'layout_snapshot_json' => '{"layout_schema_version":1}',
				'merge_data_json'      => '{"recipient.full_name":"Dana Whitfield","certificate.issuer_name":"Sunrise Training Academy"}',
				'issued_at'            => '2026-07-18 14:30:00',
				'expires_at'           => null,
			]
		);
	}

	/**
	 * Run a verification request.
	 *
	 * @param string $credential_id Raw credential input.
	 * @return WP_REST_Response
	 */
	private function call( $credential_id ) {
		$controller = new PressPrimer_Certificate_REST_Verification_Controller();

		return $controller->verify( new WP_REST_Request( [ 'credential_id' => $credential_id ] ) );
	}

	/**
	 * Shape lock (snapshot): exactly the locked keys, in order, with the
	 * documented types and the no-store header.
	 *
	 * @return void
	 */
	public function test_response_shape_locked() {
		$response = $this->call( $this->credential );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( self::LOCKED_SHAPE, array_keys( $data ), 'The response shape is a locked public contract' );

		$this->assertTrue( $data['valid'] );
		$this->assertSame( 'valid', $data['status'] );
		$this->assertSame( 'Dana Whitfield', $data['recipient_name'] );
		$this->assertSame( 'Advanced Botany Certification', $data['subject'] );
		$this->assertSame( 'Sunrise Training Academy', $data['issuer_name'] );
		$this->assertSame( '2026-07-18T14:30:00Z', $data['issued_at'], 'ISO 8601 UTC' );
		$this->assertNull( $data['expires_at'] );

		$headers = $response->get_headers();
		$this->assertSame( 'no-store', $headers['Cache-Control'] );
	}

	/**
	 * Lookup accepts any normalized input form (display grouping, case,
	 * confusables).
	 *
	 * @return void
	 */
	public function test_normalization_on_lookup() {
		$display = PressPrimer_Certificate_Credential_ID_Service::format_display( $this->credential );

		$response = $this->call( strtolower( $display ) );

		$this->assertTrue( $response->get_data()['valid'] );
	}

	/**
	 * The checksum gate: a malformed ID never reaches the database, and
	 * its response is identical in shape AND content to a missing one.
	 *
	 * @return void
	 */
	public function test_checksum_gate_and_no_oracle() {
		// Corrupt the check character (well-formed length, bad checksum).
		$alphabet  = PressPrimer_Certificate_Credential_ID_Service::ALPHABET;
		$corrupted = substr( $this->credential, 0, 11 )
			. $alphabet[ ( strpos( $alphabet, $this->credential[11] ) + 1 ) % 32 ];

		$queries_before = $this->wpdb->read_queries;
		$malformed      = $this->call( $corrupted );
		$this->assertSame( $queries_before, $this->wpdb->read_queries, 'Malformed IDs never touch the database' );

		// A well-formed ID that simply does not exist.
		$missing_id = PressPrimer_Certificate_Credential_ID_Service::generate();
		$missing    = $this->call( $missing_id );
		$this->assertGreaterThan( $queries_before, $this->wpdb->read_queries, 'Well-formed missing IDs do query (single lookup)' );

		$this->assertSame( $malformed->get_data(), $missing->get_data(), 'Malformed and missing are indistinguishable' );
		$this->assertSame( $malformed->get_status(), $missing->get_status() );
		$this->assertSame( self::LOCKED_SHAPE, array_keys( $malformed->get_data() ) );
		$this->assertSame( 'not_found', $malformed->get_data()['status'] );
	}

	/**
	 * Status precedence: revoked beats expired beats valid; expiry is
	 * evaluated at read time.
	 *
	 * @return void
	 */
	public function test_status_precedence() {
		// Expired: past expires_at on an issued row.
		$this->wpdb->mutate_row(
			PressPrimer_Certificate_Certificate::table(),
			1,
			[ 'expires_at' => '2020-01-01 00:00:00' ]
		);

		$expired = $this->call( $this->credential )->get_data();
		$this->assertFalse( $expired['valid'] );
		$this->assertSame( 'expired', $expired['status'] );
		$this->assertSame( '2020-01-01T00:00:00Z', $expired['expires_at'] );

		// Revoked wins over expired.
		$this->wpdb->mutate_row(
			PressPrimer_Certificate_Certificate::table(),
			1,
			[ 'status' => 'revoked' ]
		);

		$revoked = $this->call( $this->credential )->get_data();
		$this->assertFalse( $revoked['valid'] );
		$this->assertSame( 'revoked', $revoked['status'] );
	}

	/**
	 * The filter may brand/extend but can never weaken: valid and status
	 * are re-asserted after filtering.
	 *
	 * @return void
	 */
	public function test_filter_cannot_weaken() {
		$this->wpdb->mutate_row(
			PressPrimer_Certificate_Certificate::table(),
			1,
			[ 'status' => 'revoked' ]
		);

		$captured = [];
		add_filter(
			'ppcert_verification_result',
			static function ( $result, $certificate ) use ( &$captured ) {
				$captured[] = [ $result, $certificate ];
				// A hostile/buggy addon tries to flip the verdict and brand.
				$result['valid']       = true;
				$result['status']      = 'valid';
				$result['issuer_logo'] = 'https://example.com/logo.png';
				return $result;
			},
			10,
			2
		);

		$data = $this->call( $this->credential )->get_data();

		$this->assertFalse( $data['valid'], 'Core re-asserts valid after the filter' );
		$this->assertSame( 'revoked', $data['status'], 'Core re-asserts status after the filter' );
		$this->assertSame( 'https://example.com/logo.png', $data['issuer_logo'], 'Extension keys pass through' );

		// Hook contract: ( array $result, object $certificate ).
		$this->assertCount( 1, $captured );
		$this->assertIsArray( $captured[0][0] );
		$this->assertIsObject( $captured[0][1] );
	}

	/**
	 * The verified event is privacy-minimal: no IP anywhere, actor null
	 * unless logged in; misses write no event.
	 *
	 * @return void
	 */
	public function test_verified_event_privacy() {
		$this->call( $this->credential );

		$events = $this->wpdb->rows( PressPrimer_Certificate_Certificate::events_table() );
		$this->assertCount( 1, $events );
		$this->assertSame( 'verified', $events[0]['event_type'] );
		$this->assertNull( $events[0]['actor_id'], 'Anonymous lookups store a null actor' );
		$this->assertNull( $events[0]['meta_json'], 'No meta - and never an IP' );

		// Logged-in verifier (e.g. a teacher) records the actor.
		$GLOBALS['ppcert_test_current_user'] = 5;
		$this->call( $this->credential );
		$events = $this->wpdb->rows( PressPrimer_Certificate_Certificate::events_table() );
		$this->assertSame( 5, (int) $events[1]['actor_id'] );

		// Not-found and malformed lookups write nothing.
		$this->call( 'ZZZZZZZZZZZZ' );
		$this->assertCount( 2, $this->wpdb->rows( PressPrimer_Certificate_Certificate::events_table() ) );
	}

	/**
	 * Site admins never record verified events - browsers preload the
	 * verify links admin screens list, writing phantom verifications
	 * while an admin merely browses. The verification result itself is
	 * unaffected.
	 *
	 * @return void
	 */
	public function test_admin_lookups_record_no_event() {
		$GLOBALS['ppcert_test_current_user'] = 1;
		$GLOBALS['ppcert_test_user_caps']    = [ 'manage_options' ];

		$result = $this->call( $this->credential )->get_data();

		$this->assertTrue( $result['valid'] );
		$this->assertCount( 0, $this->wpdb->rows( PressPrimer_Certificate_Certificate::events_table() ) );

		// A teacher-level user (no manage_options) still records.
		$GLOBALS['ppcert_test_current_user'] = 5;
		$GLOBALS['ppcert_test_user_caps']    = [ 'ppcert_view_certificates' ];

		$this->call( $this->credential );

		$events = $this->wpdb->rows( PressPrimer_Certificate_Certificate::events_table() );
		$this->assertCount( 1, $events );
		$this->assertSame( 5, (int) $events[0]['actor_id'] );
	}

	/**
	 * Rate limiter: the 31st request in the window returns 429 cheaply -
	 * before any database work - and other IPs are unaffected.
	 *
	 * @return void
	 */
	public function test_rate_limiter() {
		for ( $i = 0; $i < 30; $i++ ) {
			$this->assertSame( 200, $this->call( $this->credential )->get_status(), "Request {$i} within the limit" );
		}

		$queries_before = $this->wpdb->read_queries;
		$limited        = $this->call( $this->credential );

		$this->assertSame( 429, $limited->get_status() );
		$this->assertSame( $queries_before, $this->wpdb->read_queries, '429 happens before any DB work' );
		$this->assertSame( 'no-store', $limited->get_headers()['Cache-Control'] );
		$this->assertArrayHasKey( 'Retry-After', $limited->get_headers() );
		$this->assertArrayHasKey( 'message', $limited->get_data() );

		// A different requester has an independent counter.
		$_SERVER['REMOTE_ADDR'] = '203.0.113.99';
		$this->assertSame( 200, $this->call( $this->credential )->get_status() );

		// The raw IP never appears in a transient key.
		foreach ( array_keys( $GLOBALS['ppcert_test_transients'] ) as $key ) {
			$this->assertStringNotContainsString( '203.0.113', $key, 'Rate-limit keys are salted hashes, never raw IPs' );
		}
	}

	/**
	 * The route registers publicly under ppcert/v1.
	 *
	 * @return void
	 */
	public function test_route_registration() {
		$GLOBALS['ppcert_test_rest_routes'] = [];

		$controller = new PressPrimer_Certificate_REST_Verification_Controller();
		$controller->init();
		do_action( 'rest_api_init' );

		$routes = $GLOBALS['ppcert_test_rest_routes'];
		$this->assertCount( 1, $routes );
		$this->assertSame( 'ppcert/v1', $routes[0]['namespace'] );
		$this->assertStringContainsString( 'verify', $routes[0]['route'] );
		$this->assertSame( '__return_true', $routes[0]['args']['permission_callback'] );
	}

	/**
	 * The public verification subject is the stored certificate name when
	 * one exists (Feature 1.1-006); the template title otherwise (the
	 * locked-shape test above).
	 *
	 * @return void
	 */
	public function test_subject_uses_stored_certificate_name() {
		$rows = $this->wpdb->rows( PressPrimer_Certificate_Certificate::table() );
		$row  = $rows[0];

		$this->wpdb->mutate_row(
			PressPrimer_Certificate_Certificate::table(),
			(int) $row['id'],
			[ 'merge_data_json' => '{"recipient.full_name":"Dana Whitfield","certificate.title":"Botany 101 Certificate"}' ]
		);

		$controller = new PressPrimer_Certificate_REST_Verification_Controller();
		$response   = $controller->verify( new WP_REST_Request( [ 'credential_id' => (string) $row['credential_id'] ] ) );

		$this->assertSame( 'Botany 101 Certificate', $response->get_data()['subject'] );
	}
}
