<?php
/**
 * Credential identity seam tests
 *
 * Exercises Feature 2.1-004 FR-009 and Decision 006: the
 * `ppcert_credential_id` generator seam and its strict acceptance, the
 * display snapshot, the validity gate (with and without the pattern
 * option and after the generator is gone), URLs, file names, the admin
 * search, the verify script flag and placeholders, and the enumeration
 * hardening (the shared page/PDF rate limit, the fixed-window limiter,
 * and hashed preview names). Defaults must stay byte-identical to 2.0.
 *
 * @package PressPrimer_Certificate
 * @subpackage Tests
 * @since 2.1.0
 */

use PHPUnit\Framework\TestCase;

/**
 * Credential seams test case
 *
 * @since 2.1.0
 */
class Test_Credential_Seams extends TestCase {

	/**
	 * The fake wpdb for the current test.
	 *
	 * @var PPCert_Fake_WPDB
	 */
	private $wpdb;

	/**
	 * Seeded template row id.
	 *
	 * @var int
	 */
	private $template_id;

	/**
	 * Preview files to remove after each test.
	 *
	 * @var string[]
	 */
	private $cleanup = [];

	/**
	 * Seed a published template that prints the name and the credential.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		ppcert_tests_reset_hooks();
		ppcert_tests_reset_transients();
		$this->wpdb = ppcert_tests_reset_wpdb();

		PressPrimer_Certificate_View_Page::reset();

		$GLOBALS['ppcert_test_options']      = [];
		$GLOBALS['ppcert_test_mail']         = [];
		$GLOBALS['ppcert_test_localized']    = [];
		$GLOBALS['ppcert_test_current_user'] = 0;
		$GLOBALS['ppcert_test_user_caps']    = [];
		$GLOBALS['ppcert_test_bloginfo']     = [ 'name' => 'Sunrise Training Academy' ];
		$GLOBALS['ppcert_test_users']        = [
			7 => (object) [
				'display_name' => 'Dana Whitfield',
				'first_name'   => 'Dana',
				'last_name'    => 'Whitfield',
				'user_email'   => 'dana@example.test',
			],
		];
		$_SERVER['REMOTE_ADDR'] = '203.0.113.30';

		$this->template_id = $this->wpdb->seed_row(
			PressPrimer_Certificate_Template::table(),
			[
				'uuid'                  => 'tmpl-seams-0001',
				'title'                 => 'Completion Certificate',
				'status'                => 'published',
				'author_id'             => 1,
				'issuer_id'             => 3,
				'page_size'             => 'a4',
				'orientation'           => 'landscape',
				'layout_schema_version' => 1,
				'layout_json'           => ppcert_tests_seams_layout(),
				'deleted_at'            => null,
			]
		);
	}

	/**
	 * Remove preview files written during a test.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		foreach ( $this->cleanup as $credential ) {
			PressPrimer_Certificate_Preview_Service::delete( $credential );
		}

		$this->cleanup = [];
		unset( $_SERVER['REMOTE_ADDR'] );
		parent::tearDown();
	}

	/**
	 * Issue to user 7.
	 *
	 * @param array $overrides Issue arg overrides.
	 * @return int|WP_Error
	 */
	private function issue( array $overrides = [] ) {
		static $ref = 100;
		++$ref;

		return PressPrimer_Certificate_Issuance_Service::issue(
			array_merge(
				[
					'template_id'  => $this->template_id,
					'recipient_id' => 7,
					'source_type'  => 'ppq_quiz',
					'source_ref'   => (string) $ref,
					'issued_by'    => 1,
				],
				$overrides
			)
		);
	}

	/**
	 * The stored row for an id.
	 *
	 * @param int $id Certificate id.
	 * @return array
	 */
	private function row( $id ) {
		foreach ( $this->wpdb->rows( PressPrimer_Certificate_Certificate::table() ) as $row ) {
			if ( (int) $row['id'] === (int) $id ) {
				return $row;
			}
		}

		$this->fail( 'Certificate row ' . $id . ' not found.' );
	}

	/**
	 * A main-query stand-in carrying the credential query var.
	 *
	 * @param string $credential Requested credential.
	 * @return object
	 */
	private function fake_query( $credential ) {
		$query = new class() {
			public $posts             = [];
			public $post              = null;
			public $post_count        = 0;
			public $found_posts       = 0;
			public $is_page           = false;
			public $is_singular       = false;
			public $is_single         = false;
			public $is_home           = false;
			public $is_archive        = false;
			public $is_404            = false;
			public $queried_object    = null;
			public $queried_object_id = 0;
			public $vars              = [];
			public function is_main_query() {
				return true;
			}
			public function get( $var ) {
				return isset( $this->vars[ $var ] ) ? $this->vars[ $var ] : '';
			}
			public function set_404() {
				$this->is_404 = true;
			}
		};

		$query->vars[ PressPrimer_Certificate_View_Page::QUERY_VAR ] = $credential;

		return $query;
	}

	/**
	 * Install a School-style generator: ACME-{year}-{5-digit sequence}.
	 *
	 * @param int $start First sequence number.
	 * @return void
	 */
	private function add_generator( $start = 42 ) {
		add_filter(
			'ppcert_credential_id',
			static function ( $candidate, $template, $context, $attempt ) use ( $start ) {
				$year    = substr( (string) $context['issued_at'], 0, 4 );
				$display = 'ACME-' . $year . '-' . str_pad( (string) ( $start + $attempt - 1 ), 5, '0', STR_PAD_LEFT );

				return [
					'id'      => PressPrimer_Certificate_Credential_ID_Service::normalize( $display ),
					'display' => $display,
				];
			},
			10,
			4
		);
	}

	/**
	 * With no generator, issuance, display, URLs, and the gate behave
	 * exactly as in 2.0: a core ID, no display snapshot, no option.
	 *
	 * @return void
	 */
	public function test_defaults_match_2_0() {
		$id  = $this->issue();
		$row = $this->row( $id );

		$this->assertTrue( PressPrimer_Certificate_Credential_ID_Service::is_core( $row['credential_id'] ) );
		$this->assertNull( $row['credential_display'] );
		$this->assertNull( $row['recipient_email'] );
		$this->assertFalse( PressPrimer_Certificate_Credential_ID_Service::pattern_ids_in_use() );

		$grouped = implode( '-', str_split( $row['credential_id'], 4 ) );
		$merge   = json_decode( $row['merge_data_json'], true );

		$this->assertSame( $grouped, $merge['certificate.credential_id'] );
		$this->assertSame( $grouped, PressPrimer_Certificate_Certificate::display_credential_id( (object) $row ) );
		$this->assertSame( 'https://test.example/certificate/' . $grouped . '/', PressPrimer_Certificate_View_Page::view_url( $row['credential_id'] ) );
		$this->assertSame( 'https://test.example/wp-json/ppcert/v1/certificates/' . $row['credential_id'] . '/pdf', PressPrimer_Certificate_View_Page::pdf_url( $row['credential_id'] ) );

		// The 2.0 gate: a core-shaped ID with a bad check character
		// still builds a URL (it simply 404s), junk does not.
		$this->assertNotSame( '', PressPrimer_Certificate_View_Page::view_url( 'AAAA-AAAA-AAAA' ) );
		$this->assertSame( '', PressPrimer_Certificate_View_Page::view_url( 'ACME-2026-00042' ) );
		$this->assertFalse( PressPrimer_Certificate_Credential_ID_Service::is_valid( 'ACME-2026-00042' ) );
	}

	/**
	 * A generator's ID is stored normalized with its display form
	 * snapshotted; it prints, displays, links, verifies with or without
	 * dashes, and keeps verifying after the generator is removed.
	 *
	 * @return void
	 */
	public function test_pattern_id_end_to_end() {
		$this->add_generator();

		$id   = $this->issue( [ 'issued_at' => '2026-03-15 09:00:00' ] );
		$row  = $this->row( $id );
		$cert = (object) $row;

		$this->assertSame( 'ACME202600042', $row['credential_id'] );
		$this->assertSame( 'ACME-2026-00042', $row['credential_display'] );
		$this->assertTrue( PressPrimer_Certificate_Credential_ID_Service::pattern_ids_in_use() );

		$merge = json_decode( $row['merge_data_json'], true );
		$this->assertSame( 'ACME-2026-00042', $merge['certificate.credential_id'], 'The certificate prints the display form' );

		$this->assertSame( 'ACME-2026-00042', PressPrimer_Certificate_Certificate::display_credential_id( $cert ) );
		$this->assertSame( 'certificate-acme-2026-00042.pdf', PressPrimer_Certificate_Certificate::pdf_filename( $cert ) );
		$this->assertSame( 'certificate-acme-2026-00042-card.pdf', PressPrimer_Certificate_Certificate::pdf_filename( $cert, 'card' ) );
		$this->assertSame( 'https://test.example/certificate/ACME-2026-00042/', PressPrimer_Certificate_View_Page::view_url( 'ACME202600042', 'ACME-2026-00042' ) );
		$this->assertSame( 'https://test.example/wp-json/ppcert/v1/certificates/ACME-2026-00042/pdf', PressPrimer_Certificate_View_Page::pdf_url( 'ACME202600042', 'ACME-2026-00042' ) );
		$this->assertSame( 'https://test.example/certificate/ACME202600042/', PressPrimer_Certificate_View_Page::view_url( 'ACME202600042', 'OTHER-1' ), 'A display that is not this ID never reaches the URL' );

		foreach ( [ 'ACME-2026-00042', 'acme 2026 00042', 'ACME202600042' ] as $input ) {
			$result = PressPrimer_Certificate_REST_Verification_Controller::lookup( $input );
			$this->assertSame( 'valid', $result['status'], $input );
			$this->assertSame( 'Dana Whitfield', $result['recipient_name'] );
			$this->assertSame( 'https://test.example/wp-json/ppcert/v1/certificates/ACME-2026-00042/pdf', $result['actions'][0]['url'] );
		}

		// The generator goes away (School deactivated): the stored IDs
		// keep verifying through the option.
		ppcert_tests_reset_hooks();

		$this->assertSame( 'valid', PressPrimer_Certificate_REST_Verification_Controller::lookup( 'ACME-2026-00042' )['status'] );
		$this->assertSame( 'not_found', PressPrimer_Certificate_REST_Verification_Controller::lookup( 'ACME-2026-00043' )['status'] );

		// The certificate page resolves the display-form URL.
		$view = PressPrimer_Certificate_View_Page::render_content( PressPrimer_Certificate_Certificate::get_for_verification( 'ACME-2026-00042' ) );
		$this->assertStringContainsString( '<dd>ACME-2026-00042</dd>', $view );
		$this->assertStringContainsString( 'ppcert/v1/certificates/ACME-2026-00042/pdf', $view );
	}

	/**
	 * A supplied credential ID (2.1, Feature 2.1-004 FR-017): stored in
	 * the stored form with its display, printed and verified like a
	 * pattern ID, winning over the generator; bad and taken IDs are
	 * refused before anything is written or announced; a suppressed
	 * duplicate keeps its own ID, whatever ID the row supplied.
	 *
	 * @return void
	 */
	public function test_supplied_credential_id() {
		$this->add_generator();

		$id  = $this->issue( [ 'credential_id' => ' hr-lot-2024-001 ' ] );
		$row = $this->row( $id );

		$this->assertSame( 'HR10T2024001', $row['credential_id'], 'Stored normalized (I/L to 1, O to 0, no hyphens)' );
		$this->assertSame( 'HR-LOT-2024-001', $row['credential_display'], 'Shown as supplied, uppercased' );
		$this->assertSame( 'HR-LOT-2024-001', json_decode( $row['merge_data_json'], true )['certificate.credential_id'] );
		$this->assertTrue( PressPrimer_Certificate_Credential_ID_Service::pattern_ids_in_use() );

		foreach ( [ 'HR-LOT-2024-001', 'hr lot 2024 001', 'HR10T2024001' ] as $input ) {
			$this->assertSame( 'valid', PressPrimer_Certificate_REST_Verification_Controller::lookup( $input )['status'], $input );
		}

		// A supplied ID without hyphens has no separate display form.
		$plain = $this->row( $this->issue( [ 'credential_id' => 'EXT00017' ] ) );
		$this->assertSame( 'EXT00017', $plain['credential_id'] );
		$this->assertNull( $plain['credential_display'] );

		// Taken: refused before anything is written or announced.
		$announced = 0;
		$listener  = static function () use ( &$announced ) {
			++$announced;
		};
		add_action( 'ppcert_before_issue', $listener );

		$count = count( $this->wpdb->rows( PressPrimer_Certificate_Certificate::table() ) );
		$taken = $this->issue( [ 'credential_id' => 'HR-L0T-2024-001' ] );
		$this->assertInstanceOf( 'WP_Error', $taken );
		$this->assertSame( 'ppcert_credential_id_taken', $taken->get_error_code(), 'The same ID once normalized' );
		$this->assertSame( 'That credential ID is already used by another certificate.', $taken->get_error_message() );

		// Breaking the rules.
		foreach ( [ 'AB1', 'two  spaces', 'A--B-1234', 'id_with_underscore', '-LEADING1', str_repeat( 'A', 33 ), str_repeat( 'A1-', 14 ) . 'A' ] as $bad ) {
			$refused = $this->issue( [ 'credential_id' => $bad ] );
			$this->assertInstanceOf( 'WP_Error', $refused, $bad );
			$this->assertSame( 'ppcert_credential_id_invalid', $refused->get_error_code(), $bad );
		}

		$this->assertCount( $count, $this->wpdb->rows( PressPrimer_Certificate_Certificate::table() ), 'Nothing written for a refused ID' );
		remove_action( 'ppcert_before_issue', $listener );
		$this->assertSame( 0, $announced, 'ppcert_before_issue never fires for a refused ID' );

		// A duplicate the person already holds is suppressed with its own ID.
		$first      = $this->issue( [ 'source_ref' => 'dup-1', 'credential_id' => 'DUP-0001' ] );
		$suppressed = PressPrimer_Certificate_Issuance_Service::issue(
			[
				'template_id'   => $this->template_id,
				'recipient_id'  => 7,
				'source_type'   => 'ppq_quiz',
				'source_ref'    => 'dup-1',
				'issued_by'     => 1,
				'credential_id' => 'DUP-0002',
			]
		);
		$this->assertSame( $first, $suppressed );
		$this->assertSame( 'DUP0001', $this->row( $first )['credential_id'] );

		// Suppression comes first, so a holder is never refused over the ID.
		foreach ( [ 'HR-LOT-2024-001', 'id_with_underscore' ] as $other ) {
			$again = PressPrimer_Certificate_Issuance_Service::issue(
				[
					'template_id'   => $this->template_id,
					'recipient_id'  => 7,
					'source_type'   => 'ppq_quiz',
					'source_ref'    => 'dup-1',
					'issued_by'     => 1,
					'credential_id' => $other,
				]
			);
			$this->assertSame( $first, $again, $other );
		}

		// Blank: the usual ID (here the generator's).
		$this->assertStringStartsWith( 'ACME', $this->row( $this->issue( [ 'credential_id' => '  ' ] ) )['credential_id'] );

		// The rule check alone.
		$this->assertSame( [ 'id' => 'ABCDEFGHJKMN', 'display' => 'ABCD-EFGH-JKMN' ], PressPrimer_Certificate_Credential_ID_Service::accept_supplied( 'abcd-efgh-jkmn' ) );
		$this->assertInstanceOf( 'WP_Error', PressPrimer_Certificate_Credential_ID_Service::accept_supplied( [ 'x' ] ) );
	}

	/**
	 * The generator runs once per insert attempt with the attempt number,
	 * issued_at, and the template's issuer; a collision draws again.
	 *
	 * @return void
	 */
	public function test_generator_called_per_attempt() {
		$this->wpdb->seed_row(
			PressPrimer_Certificate_Certificate::table(),
			[
				'credential_id' => 'ACME202600042',
				'template_id'   => $this->template_id,
				'recipient_id'  => 99,
				'source_type'   => 'manual',
				'status'        => 'issued',
			]
		);

		$seen = [];

		add_filter(
			'ppcert_credential_id',
			static function ( $candidate, $template, $context, $attempt ) use ( &$seen ) {
				$seen[] = [ $attempt, $context['issued_at'], $context['issuer_id'], strlen( $candidate['id'] ) ];

				return $candidate;
			},
			5,
			4
		);
		$this->add_generator();

		$id = $this->issue( [ 'issued_at' => '2026-03-15 09:00:00' ] );

		$this->assertSame( 'ACME-2026-00043', $this->row( $id )['credential_display'], 'The collision drew the next number' );
		$this->assertSame(
			[
				[ 1, '2026-03-15 09:00:00', 3, 12 ],
				[ 2, '2026-03-15 09:00:00', 3, 12 ],
			],
			$seen
		);
	}

	/**
	 * Malformed candidates fall back to the core ID silently.
	 *
	 * @return void
	 */
	public function test_bad_candidates_keep_core_id() {
		$bad = [
			'not normalized'          => [
				'id'      => 'acme-1',
				'display' => '',
			],
			'confusable O'            => [
				'id'      => 'ACMEO1',
				'display' => '',
			],
			'too short'               => [
				'id'      => 'AB1',
				'display' => '',
			],
			'too long'                => [
				'id'      => str_repeat( 'A', 33 ),
				'display' => '',
			],
			'display of another id'   => [
				'id'      => 'ACME202600042',
				'display' => 'ACME-2026-00043',
			],
			'display with spaces'     => [
				'id'      => 'ACME202600042',
				'display' => 'ACME 2026 00042',
			],
			'display too long'        => [
				'id'      => 'A1A1A1A1A1A1A1A1A1A1A1',
				'display' => implode( '-', str_split( 'A1A1A1A1A1A1A1A1A1A1A1', 1 ) ),
			],
			'not an array'            => 'ACME202600042',
			'id missing'              => [ 'display' => 'ACME-1' ],
		];

		foreach ( $bad as $label => $candidate ) {
			ppcert_tests_reset_hooks();
			add_filter(
				'ppcert_credential_id',
				static function () use ( $candidate ) {
					return $candidate;
				}
			);

			$row = $this->row( $this->issue() );

			$this->assertTrue( PressPrimer_Certificate_Credential_ID_Service::is_core( $row['credential_id'] ), $label );
			$this->assertNull( $row['credential_display'], $label );
		}

		$this->assertFalse( PressPrimer_Certificate_Credential_ID_Service::pattern_ids_in_use() );
	}

	/**
	 * The validity gate: checksum by default, pattern shape once the
	 * option is set, and the filter has the last word.
	 *
	 * @return void
	 */
	public function test_validity_gate() {
		$core = PressPrimer_Certificate_Credential_ID_Service::generate();

		$this->assertTrue( PressPrimer_Certificate_Credential_ID_Service::is_valid( $core ) );
		$this->assertFalse( PressPrimer_Certificate_Credential_ID_Service::is_valid( 'ACME202600042' ) );
		$this->assertSame( 'not_found', PressPrimer_Certificate_REST_Verification_Controller::lookup( 'ACME202600042' )['status'] );

		update_option( PressPrimer_Certificate_Credential_ID_Service::PATTERN_IDS_OPTION, 1 );

		$this->assertTrue( PressPrimer_Certificate_Credential_ID_Service::is_valid( 'acme-2026-00042' ) );
		$this->assertTrue( PressPrimer_Certificate_Credential_ID_Service::is_valid( $core ) );
		$this->assertFalse( PressPrimer_Certificate_Credential_ID_Service::is_valid( 'AB1' ), 'Shorter than 4' );
		$this->assertFalse( PressPrimer_Certificate_Credential_ID_Service::is_valid( 'ACME/2026' ), 'Outside the alphabet' );

		add_filter(
			'ppcert_credential_id_is_valid',
			static function ( $valid, $normalized ) {
				return $valid && 0 === strpos( $normalized, 'ACME' );
			},
			10,
			2
		);

		$this->assertTrue( PressPrimer_Certificate_Credential_ID_Service::is_valid( 'ACME-2026-00042' ) );
		$this->assertFalse( PressPrimer_Certificate_Credential_ID_Service::is_valid( 'BETA-2026-00042' ) );
	}

	/**
	 * The display filter reaches every display path through the row.
	 *
	 * @return void
	 */
	public function test_display_filter() {
		$row = (object) $this->row( $this->issue() );

		add_filter(
			'ppcert_credential_id_display',
			static function ( $display, $certificate ) {
				return 'ID ' . $display . ' #' . $certificate->id;
			},
			10,
			2
		);

		$this->assertStringStartsWith( 'ID ', PressPrimer_Certificate_Certificate::display_credential_id( $row ) );
		$this->assertStringEndsWith( '#' . $row->id, PressPrimer_Certificate_Certificate::display_credential_id( $row ) );
		$this->assertMatchesRegularExpression( '/^certificate-id[a-z0-9\-]+' . (int) $row->id . '\.pdf$/', PressPrimer_Certificate_Certificate::pdf_filename( $row ), 'File names stay file-safe under any filter' );
	}

	/**
	 * Admin search: pattern IDs by display form; core IDs with or
	 * without a valid check character (the 2.0 shape gate); names and
	 * titles still match alongside.
	 *
	 * @return void
	 */
	public function test_admin_search_finds_pattern_ids() {
		$core_id = $this->issue();
		$this->add_generator();
		$pattern_id = $this->issue();

		$found = PressPrimer_Certificate_Certificate::query( [ 'search' => 'acme-2026-00042' ] );
		$this->assertSame( [ $pattern_id ], array_map( 'intval', array_column( $found['items'], 'id' ) ) );

		$core = $this->row( $core_id )['credential_id'];
		$found = PressPrimer_Certificate_Certificate::query( [ 'search' => strtolower( implode( '-', str_split( $core, 4 ) ) ) ] );
		$this->assertSame( [ $core_id ], array_map( 'intval', array_column( $found['items'], 'id' ) ) );
	}

	/**
	 * The verify script's strict flag and the example placeholder.
	 *
	 * @return void
	 */
	public function test_verify_flag_and_example() {
		$html = PressPrimer_Certificate_Verification_Page::render_shortcode();

		$this->assertSame( '1', $GLOBALS['ppcert_test_localized']['ppcert_verify_data']['strictChecksum'] );
		$this->assertStringContainsString( 'placeholder="XXXX-XXXX-XXXX"', $html );

		update_option( PressPrimer_Certificate_Credential_ID_Service::PATTERN_IDS_OPTION, 1 );
		add_filter(
			'ppcert_credential_id_example',
			static function () {
				return 'ACME-2026-00042';
			}
		);

		$html = PressPrimer_Certificate_Verification_Page::render_shortcode();

		$this->assertSame( '0', $GLOBALS['ppcert_test_localized']['ppcert_verify_data']['strictChecksum'] );
		$this->assertStringContainsString( 'placeholder="ACME-2026-00042"', $html );
	}

	/**
	 * Rate_Limiter keeps the first window's end: steady hits inside the
	 * window never extend it, and a 2.0 integer counter still counts.
	 *
	 * @return void
	 */
	public function test_rate_limiter_fixed_window() {
		$key = 'ppcert_test_window';

		$this->assertTrue( PressPrimer_Certificate_Rate_Limiter::allow( $key, 2, 60 ) );
		$reset = $GLOBALS['ppcert_test_transients'][ $key ]['value']['reset'];
		$this->assertTrue( PressPrimer_Certificate_Rate_Limiter::allow( $key, 2, 60 ) );
		$this->assertSame( $reset, $GLOBALS['ppcert_test_transients'][ $key ]['value']['reset'], 'A later hit never moves the window' );
		$this->assertLessThanOrEqual( $reset, $GLOBALS['ppcert_test_transients'][ $key ]['expires'], 'The transient expires with the window' );
		$this->assertFalse( PressPrimer_Certificate_Rate_Limiter::allow( $key, 2, 60 ) );

		// The window ends: counting starts over.
		$GLOBALS['ppcert_test_transients'][ $key ]['value']['reset'] = time() - 1;
		$this->assertTrue( PressPrimer_Certificate_Rate_Limiter::allow( $key, 2, 60 ) );
		$this->assertSame( 1, $GLOBALS['ppcert_test_transients'][ $key ]['value']['count'] );

		// A 2.0 bare-integer counter.
		set_transient( 'ppcert_test_legacy', 2, 60 );
		$this->assertFalse( PressPrimer_Certificate_Rate_Limiter::allow( 'ppcert_test_legacy', 2, 60 ) );

		$this->assertTrue( PressPrimer_Certificate_Rate_Limiter::allow( $key, 0, 60 ), 'A limit below 1 disables' );
		$this->assertStringNotContainsString( '203.0.113', PressPrimer_Certificate_Rate_Limiter::client_key( 'view' ) );
		$this->assertStringStartsWith( 'ppcert_view_', PressPrimer_Certificate_Rate_Limiter::client_key( 'view' ) );
	}

	/**
	 * The certificate page and the public PDF route share one per-visitor
	 * allowance; over it, the page serves a 429 and the route a 429 with
	 * Retry-After. Verification keeps its own bucket.
	 *
	 * @return void
	 */
	public function test_view_and_pdf_share_rate_limit() {
		add_filter(
			'ppcert_view_rate_limit',
			static function () {
				return [
					'limit'  => 2,
					'window' => 30,
				];
			}
		);

		$query = $this->fake_query( 'AAAA-AAAA-AAAA' );

		PressPrimer_Certificate_View_Page::inject_virtual_page( [], $query );
		$this->assertSame( 404, $GLOBALS['ppcert_test_status_header'] );

		$controller = new PressPrimer_Certificate_REST_Certificates_Controller();
		$missing    = $controller->download_pdf( new WP_REST_Request( [ 'credential_id' => 'AAAA-AAAA-AAAA' ] ) );
		$this->assertInstanceOf( WP_Error::class, $missing, 'Second request: within the limit' );

		$limited = $controller->download_pdf( new WP_REST_Request( [ 'credential_id' => 'AAAA-AAAA-AAAA' ] ) );
		$this->assertInstanceOf( WP_REST_Response::class, $limited );
		$this->assertSame( 429, $limited->get_status() );
		$this->assertSame( '30', $limited->get_headers()['Retry-After'] );

		PressPrimer_Certificate_View_Page::reset();
		$posts = PressPrimer_Certificate_View_Page::inject_virtual_page( [], $query );
		$this->assertSame( 429, $GLOBALS['ppcert_test_status_header'] );
		$this->assertCount( 1, $posts, 'A themed 429 page, not an empty 404' );
		$this->assertSame( 'Too Many Requests', $posts[0]->post_title );
		$this->assertTrue( apply_filters( 'pre_handle_404', false, $query ), 'WordPress may not overwrite the 429 with a 200' );

		// Verification is a separate bucket.
		$this->assertTrue( PressPrimer_Certificate_REST_Verification_Controller::check_rate_limit() );
	}

	/**
	 * Preview PNGs are named with a secret from the certificate UUID; the
	 * 2.0 name is replaced on the next view and every name is deleted.
	 *
	 * @return void
	 */
	public function test_preview_names_carry_uuid_secret() {
		$certificate = PressPrimer_Certificate_Certificate::get( $this->issue() );
		$credential  = (string) $certificate->credential_id;

		$this->cleanup[] = $credential;

		$legacy = PressPrimer_Certificate_Preview_Service::preview_path( $credential );
		wp_mkdir_p( dirname( $legacy ) );
		file_put_contents( $legacy, 'old' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture.

		$url = PressPrimer_Certificate_Preview_Service::get_or_create( $certificate );

		$this->assertIsString( $url );
		$this->assertMatchesRegularExpression( '#/ppcert/previews/' . $credential . '-[0-9a-f]{12}\.png$#', $url );
		$this->assertStringEndsWith( substr( wp_hash( (string) $certificate->uuid ), 0, 12 ) . '.png', $url );
		$this->assertFileDoesNotExist( $legacy, 'The guessable 2.0 name is gone' );

		$hashed = PressPrimer_Certificate_Preview_Service::preview_path( $credential, (string) $certificate->uuid );
		$this->assertFileExists( $hashed );
		$this->assertSame( $url, PressPrimer_Certificate_Preview_Service::get_or_create( $certificate ), 'Cached' );

		$this->assertTrue( PressPrimer_Certificate_Preview_Service::delete( $credential ) );
		$this->assertFileDoesNotExist( $hashed );
		$this->assertSame( '', PressPrimer_Certificate_Preview_Service::preview_path( 'ACME/../x' ), 'Unsafe input builds no path' );
	}
}

