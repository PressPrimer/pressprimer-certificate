<?php
/**
 * Existing defects, part 1 (2.1)
 *
 * One regression test per Feature 2.1-007 fix in Prompt 2.7, each
 * failing on 2.0.2: the trigger routes' per-template check (FR-001),
 * uninstall's upload folders and option order (FR-002), one render per
 * temp file (FR-003), revoked certificates never re-sent (FR-004), the
 * eraser clearing the person as an actor (FR-007), and the test
 * harness's update_option() following core.
 *
 * @package PressPrimer_Certificate
 * @subpackage Tests
 * @since 2.1.0
 */

use PHPUnit\Framework\TestCase;

/**
 * Existing defects test case
 *
 * @since 2.1.0
 */
class Test_Existing_Defects extends TestCase {

	/**
	 * The fake wpdb for the current test.
	 *
	 * @var PPCert_Fake_WPDB
	 */
	private $wpdb;

	/**
	 * Fresh state; an administrator acts.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		ppcert_tests_reset_hooks();
		ppcert_tests_reset_transients();
		$this->wpdb = ppcert_tests_reset_wpdb();

		$GLOBALS['ppcert_test_options']      = [ 'ppcert_settings' => [ 'email_issued_enabled' => 1 ] ];
		$GLOBALS['ppcert_test_mail']         = [];
		$GLOBALS['ppcert_test_current_user'] = 1;
		$GLOBALS['ppcert_test_user_caps']    = true;
		$GLOBALS['ppcert_test_bloginfo']     = [
			'name'        => 'Sunrise Training Academy',
			'admin_email' => 'admin@sunrise.example',
		];
		$GLOBALS['ppcert_test_users']        = [
			7 => (object) [
				'ID'           => 7,
				'display_name' => 'Dana Whitfield',
				'first_name'   => 'Dana',
				'last_name'    => 'Whitfield',
				'user_email'   => 'dana@example.test',
			],
			8 => (object) [
				'ID'           => 8,
				'display_name' => 'Sam Okafor',
				'user_email'   => 'sam@example.test',
			],
		];
	}

	/**
	 * Seed a published template.
	 *
	 * @param int $issuer_id Issuer id (0 = site template).
	 * @return int
	 */
	private function template( $issuer_id = 0 ) {
		return $this->wpdb->seed_row(
			PressPrimer_Certificate_Template::table(),
			[
				'uuid'                  => 'tmpl-defects-' . $issuer_id . '-' . wp_rand(),
				'title'                 => 'Completion Certificate',
				'status'                => 'published',
				'author_id'             => 1,
				'issuer_id'             => $issuer_id,
				'page_size'             => 'a4',
				'orientation'           => 'landscape',
				'layout_schema_version' => 1,
				'layout_json'           => ppcert_tests_seams_layout(),
				'deleted_at'            => null,
			]
		);
	}

	/**
	 * Issue a certificate.
	 *
	 * @param int $template_id  Template.
	 * @param int $recipient_id Recipient.
	 * @return int
	 */
	private function issue( $template_id, $recipient_id = 7 ) {
		return (int) PressPrimer_Certificate_Issuance_Service::issue(
			[
				'template_id'  => $template_id,
				'recipient_id' => $recipient_id,
				'source_type'  => 'manual',
				'issued_by'    => 1,
				'force'        => true,
			]
		);
	}

	/**
	 * FR-001: a template's triggers need the same per-template check as
	 * the template itself (School's rule, as a test filter: administrators
	 * edit anything; site templates are administrators' only; members
	 * edit their own organization's templates).
	 *
	 * @return void
	 */
	public function test_trigger_routes_check_the_template() {
		$site    = $this->template( 0 );
		$org_a   = $this->template( 11 );
		$org_b   = $this->template( 12 );
		$members = [ 5 => [ 11 ] ];

		add_filter(
			'ppcert_user_can_edit_template',
			static function ( $can, $template_id, $user_id ) use ( $members ) {
				if ( ! $can ) {
					return false;
				}

				if ( user_can( $user_id, 'manage_options' ) ) {
					return true;
				}

				$issuer = (int) PressPrimer_Certificate_Template::get( $template_id )->issuer_id;

				return $issuer > 0 && in_array( $issuer, isset( $members[ $user_id ] ) ? $members[ $user_id ] : [], true );
			},
			10,
			3
		);

		$controller = new PressPrimer_Certificate_REST_Triggers_Controller();
		$request    = static function ( $id ) {
			return new WP_REST_Request( [ 'id' => $id ] );
		};

		// A member of organization A (School grants the capability).
		$GLOBALS['ppcert_test_current_user'] = 5;
		$GLOBALS['ppcert_test_user_caps']    = [ PressPrimer_Certificate_Capabilities::CAP_MANAGE_TEMPLATES ];

		$this->assertTrue( $controller->can_manage_template( $request( $org_a ) ) );
		$this->assertFalse( $controller->can_manage_template( $request( $org_b ) ), "Another organization's template" );
		$this->assertFalse( $controller->can_manage_template( $request( $site ) ), 'A site template' );

		// The route table uses the check for both methods.
		$GLOBALS['ppcert_test_rest_routes'] = [];
		$controller->register_routes();
		$route = array_values(
			array_filter(
				$GLOBALS['ppcert_test_rest_routes'],
				static function ( $entry ) {
					return '/templates/(?P<id>\d+)/triggers' === $entry['route'];
				}
			)
		)[0];

		foreach ( $route['args'] as $method ) {
			$this->assertSame( [ $controller, 'can_manage_template' ], $method['permission_callback'], $method['methods'] );
		}

		// An administrator reaches all three; no capability, none.
		$GLOBALS['ppcert_test_user_caps'] = true;
		$this->assertTrue( $controller->can_manage_template( $request( $site ) ) );

		$GLOBALS['ppcert_test_user_caps'] = [];
		$this->assertFalse( $controller->can_manage_template( $request( $org_a ) ) );
	}

	/**
	 * FR-002: uninstall removes every free upload folder recursively,
	 * keeps unrelated folders, and deletes options last.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 *
	 * @return void
	 */
	public function test_uninstall_removes_uploads_and_options_last() {
		$base = trailingslashit( wp_upload_dir()['basedir'] );

		$files = [
			'ppcert/previews/ABCD1234EFGH.png',
			'ppcert-previews/preview-1.pdf',
			'ppcert-fonts/source-sans-3.php',
			'ppcert-fonts/tcpdf/extra.php',
			'ppcert-educator-fonts/uploaded.ttf',
			'ppcert-educator-fonts/tcpdf/uploaded.php',
			'ppcert-educator/og/share.png',
			'ppcert-educator/kept.txt',
			'some-other-plugin/keep.txt',
		];

		foreach ( $files as $file ) {
			wp_mkdir_p( dirname( $base . $file ) );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture.
			file_put_contents( $base . $file, 'x' );
		}

		$GLOBALS['ppcert_test_options'] = [ 'ppcert_remove_data_on_uninstall' => '1' ];
		$GLOBALS['wpdb']                = new class() {
			/**
			 * Table prefix.
			 *
			 * @var string
			 */
			public $prefix = 'wp_';

			/**
			 * Options table.
			 *
			 * @var string
			 */
			public $options = 'wp_options';

			/**
			 * Post meta table.
			 *
			 * @var string
			 */
			public $postmeta = 'wp_postmeta';

			/**
			 * User meta table.
			 *
			 * @var string
			 */
			public $usermeta = 'wp_usermeta';

			/**
			 * Every query, in order.
			 *
			 * @var string[]
			 */
			public $log = [];

			/**
			 * Prepare (readable, for the log).
			 *
			 * @param string $query Query.
			 * @param mixed  ...$args Values.
			 * @return string
			 */
			public function prepare( $query, ...$args ) {
				return $query . ' | ' . implode( ' | ', $args );
			}

			/**
			 * Record a query.
			 *
			 * @param string $sql Query.
			 * @return int
			 */
			public function query( $sql ) {
				$this->log[] = $sql;
				return 0;
			}

			/**
			 * Escape LIKE wildcards.
			 *
			 * @param string $text Text.
			 * @return string
			 */
			public function esc_like( $text ) {
				return addcslashes( $text, '_%\\\\' );
			}
		};

		define( 'WP_UNINSTALL_PLUGIN', 'pressprimer-certificate/pressprimer-certificate.php' );
		require dirname( __DIR__, 2 ) . '/uninstall.php';

		foreach ( [ 'ppcert', 'ppcert-previews', 'ppcert-fonts', 'ppcert-educator-fonts', 'ppcert-educator/og' ] as $gone ) {
			$this->assertDirectoryDoesNotExist( $base . $gone, $gone );
		}

		$this->assertFileExists( $base . 'ppcert-educator/kept.txt', "Educator's own files stay for Educator's uninstall" );
		$this->assertFileExists( $base . 'some-other-plugin/keep.txt' );

		// Options go last among the site's data (only the network-wide
		// user meta follows).
		$log     = $GLOBALS['wpdb']->log;
		$options = array_search( 'DELETE FROM %i WHERE option_name LIKE %s | wp_options | ppcert\\_%', $log, true );
		$this->assertIsInt( $options );

		foreach ( array_slice( $log, $options + 1 ) as $later ) {
			$this->assertStringContainsString( 'wp_usermeta', $later, 'Only user meta follows the options' );
		}

		$this->assertGreaterThan( 5, $options, 'Tables, post meta, and transients go first' );
	}

	/**
	 * FR-003: two renders of one certificate are two files with the
	 * same clean name; the render's folder goes once the file does.
	 *
	 * @return void
	 */
	public function test_each_render_has_its_own_file() {
		$id = $this->issue( $this->template( 0 ) );

		$first  = PressPrimer_Certificate_PDF_Renderer::render_certificate( $id, 'email' );
		$second = PressPrimer_Certificate_PDF_Renderer::render_certificate( $id, 'email' );

		$this->assertNotSame( $first, $second );
		$this->assertSame( basename( $first ), basename( $second ) );
		$this->assertFileExists( $first );
		$this->assertFileExists( $second );

		wp_delete_file( $first );
		PressPrimer_Certificate_PDF_Renderer::remove_empty_temp_folder( dirname( $first ) );
		$this->assertDirectoryDoesNotExist( dirname( $first ) );

		// A folder still holding its file stays.
		PressPrimer_Certificate_PDF_Renderer::remove_empty_temp_folder( dirname( $second ) );
		$this->assertFileExists( $second );
		wp_delete_file( $second );
		do_action( 'shutdown' );
		$this->assertDirectoryDoesNotExist( dirname( $second ), 'The shutdown cleanup removes the emptied folder' );
	}

	/**
	 * FR-004: a revoked certificate is never re-sent, whoever asks.
	 *
	 * @return void
	 */
	public function test_resend_refuses_revoked_certificates() {
		$id = $this->issue( $this->template( 0 ) );
		PressPrimer_Certificate_Certificate::revoke( $id );
		$GLOBALS['ppcert_test_mail'] = [];

		$this->assertFalse( PressPrimer_Certificate_Email_Service::resend( $id ) );
		$this->assertSame( [], $GLOBALS['ppcert_test_mail'] );

		PressPrimer_Certificate_Certificate::reinstate( $id );
		$this->assertTrue( PressPrimer_Certificate_Email_Service::resend( $id ), 'Reinstated, it sends again' );
		$this->assertCount( 1, $GLOBALS['ppcert_test_mail'] );
	}

	/**
	 * FR-007: erasure clears the person's user id from events of other
	 * people's certificates, keeps the events, and counts them.
	 *
	 * @return void
	 */
	public function test_eraser_clears_the_person_as_an_actor() {
		$template = $this->template( 0 );
		$theirs   = $this->issue( $template, 7 );
		$others   = $this->issue( $template, 8 );
		$events   = PressPrimer_Certificate_Certificate::events_table();

		// Dana (7) verified and downloaded Sam's certificate; user 1 did too.
		PressPrimer_Certificate_Certificate::record_event( $others, 'verified', 7 );
		PressPrimer_Certificate_Certificate::record_event( $others, 'downloaded', 7 );
		PressPrimer_Certificate_Certificate::record_event( $others, 'downloaded', 1 );

		$result = PressPrimer_Certificate_Privacy::erase( 'dana@example.test' );

		$this->assertTrue( $result['done'] );
		$this->assertNull( PressPrimer_Certificate_Certificate::get( $theirs ), "Dana's own certificate is erased" );
		$this->assertNotNull( PressPrimer_Certificate_Certificate::get( $others ) );

		$actors = [];

		foreach ( $this->wpdb->rows( $events ) as $row ) {
			if ( (int) $row['certificate_id'] === $others && in_array( $row['event_type'], [ 'verified', 'downloaded' ], true ) ) {
				$actors[] = null === $row['actor_id'] ? null : (int) $row['actor_id'];
			}
		}

		$this->assertSame( [ null, null, 1 ], $actors, "Dana's events stay, without her id; others keep theirs" );
		$this->assertSame( 3, $result['items_removed'], 'One certificate plus two activity records' );
		$this->assertContains( 'This person was removed from 2 activity records on other certificates.', $result['messages'] );
	}

	/**
	 * The harness's update_option() follows core: an unchanged value
	 * (including false on an absent option) writes nothing.
	 *
	 * @return void
	 */
	public function test_update_option_stub_follows_core() {
		$GLOBALS['ppcert_test_options'] = [];

		$this->assertFalse( update_option( 'ppcert_switch', false ), 'false on an absent option: no write' );
		$this->assertArrayNotHasKey( 'ppcert_switch', $GLOBALS['ppcert_test_options'] );

		$this->assertTrue( update_option( 'ppcert_switch', true ) );
		$this->assertFalse( update_option( 'ppcert_switch', true ), 'Unchanged: no write' );
		$this->assertTrue( update_option( 'ppcert_switch', false ), 'Changed to false: written' );
		$this->assertFalse( get_option( 'ppcert_switch', 'default' ) );

		$this->assertTrue( update_option( 'ppcert_list', [ 'a' => 1 ] ) );
		$this->assertFalse( update_option( 'ppcert_list', [ 'a' => 1 ] ), 'An equal array: no write' );
	}
}
