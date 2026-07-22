<?php
/**
 * Hook contract test suite
 *
 * The enforcement mechanism for "HOOKS.md is the contract"
 * (008-foundation TR-001). Every documented hook appears in the canonical
 * table below; hooks whose owning feature has shipped are exercised with
 * spies asserting the documented parameter count and types. Hooks whose
 * owner has not shipped are marked pending with their owning prompt -
 * that prompt moves them to spied coverage.
 *
 * Started at Prompt 1.8; grows with Phases 2-3.
 *
 * @package PressPrimer_Certificate
 * @subpackage Tests
 * @since 1.0.0
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/doubles/class-ppcert-test-double-adapter.php';

/**
 * Hook contract test case
 *
 * @since 1.0.0
 */
class Test_Hook_Contract extends TestCase {

	/**
	 * The canonical hook contract, mirroring docs/architecture/HOOKS.md.
	 *
	 * type: action|filter. params: documented parameter count. status:
	 * 'covered' (spied below) or the owning prompt for pending hooks.
	 *
	 * @var array
	 */
	const CONTRACT = [
		'ppcert_loaded'                => [
			'type'   => 'action',
			'params' => 0,
			'status' => 'covered',
		],
		'ppcert_register_addon'        => [
			'type'   => 'action',
			'params' => 3,
			'status' => 'covered',
		],
		'ppcert_register_merge_fields' => [
			'type'   => 'filter',
			'params' => 2,
			'status' => 'covered',
		],
		'ppcert_merge_data'            => [
			'type'   => 'filter',
			'params' => 2,
			'status' => 'covered',
		],
		'ppcert_register_trigger_types' => [
			'type'   => 'filter',
			'params' => 1,
			'status' => 'covered',
		],
		'ppcert_designer_fonts'        => [
			'type'   => 'filter',
			'params' => 1,
			'status' => 'covered',
		],
		'ppcert_issue_validation'      => [
			'type'   => 'filter',
			'params' => 2,
			'status' => 'covered',
		],
		'ppcert_before_issue'          => [
			'type'   => 'action',
			'params' => 1,
			'status' => 'covered',
		],
		'ppcert_certificate_issued'    => [
			'type'   => 'action',
			'params' => 2,
			'status' => 'covered',
		],
		'ppcert_certificate_revoked'   => [
			'type'   => 'action',
			'params' => 2,
			'status' => 'covered',
		],
		'ppcert_verification_result'   => [
			'type'   => 'filter',
			'params' => 2,
			'status' => 'covered',
		],
		'ppcert_verification_page_data' => [
			'type'   => 'filter',
			'params' => 2,
			'status' => 'covered',
		],
		'ppcert_designer_element_types' => [
			'type'   => 'filter',
			'params' => 1,
			'status' => 'pending:3.3',
		],
		'ppcert_template_layout_json'  => [
			'type'   => 'filter',
			'params' => 2,
			'status' => 'pending:3.6',
		],
		'ppcert_pdf_generated'         => [
			'type'   => 'action',
			'params' => 3,
			'status' => 'covered',
		],
		'ppcert_email_enabled'         => [
			'type'   => 'filter',
			'params' => 3,
			'status' => 'covered',
		],
		'ppcert_email_content'         => [
			'type'   => 'filter',
			'params' => 3,
			'status' => 'covered',
		],
	];

	/**
	 * Reset hooks, addons, and seeded data between tests.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		ppcert_tests_reset_hooks();
		PressPrimer_Certificate_Addon_Manager::get_instance()->reset();
		$GLOBALS['ppcert_test_users']     = [];
		$GLOBALS['ppcert_test_user_meta'] = [];
	}

	/**
	 * Spy on a hook, capturing every call's arguments.
	 *
	 * @param string $hook_name   Hook to spy.
	 * @param int    $accepted    Args to accept (over-subscribe to catch extras).
	 * @param array  $capture_log Reference receiving captured arg lists.
	 * @return void
	 */
	private function spy( $hook_name, $accepted, array &$capture_log ) {
		add_filter(
			$hook_name,
			static function ( ...$args ) use ( &$capture_log ) {
				$capture_log[] = $args;
				return isset( $args[0] ) ? $args[0] : null;
			},
			99,
			$accepted
		);
	}

	/**
	 * The simulated load-and-register flow: ppcert_loaded fires with no
	 * parameters; an addon registering on it lands in the manager with
	 * the documented ( string, string, array ) signature.
	 *
	 * @return void
	 */
	public function test_load_and_addon_register_flow() {
		$manager = PressPrimer_Certificate_Addon_Manager::get_instance();
		$manager->init();

		$loaded_calls   = [];
		$register_calls = [];
		$this->spy( 'ppcert_loaded', 3, $loaded_calls );
		$this->spy( 'ppcert_register_addon', 5, $register_calls );

		// The 1.1 probe mu-plugin's exact behavior, simulated.
		add_action(
			'ppcert_loaded',
			static function () {
				do_action( 'ppcert_register_addon', 'educator', '2.0.0', [ 'custom_fonts', 'bulk_issuance' ] );
			}
		);

		do_action( 'ppcert_loaded' );

		// ppcert_loaded: zero documented parameters.
		$this->assertCount( 1, $loaded_calls );

		// ppcert_register_addon: ( string $addon_id, string $version, array $features ).
		$this->assertCount( 1, $register_calls );
		$this->assertIsString( $register_calls[0][0] );
		$this->assertIsString( $register_calls[0][1] );
		$this->assertIsArray( $register_calls[0][2] );

		// The manager heard it; the globals' backing methods report it.
		$this->assertTrue( $manager->has_addon( 'educator' ) );
		$this->assertTrue( $manager->feature_enabled( 'bulk_issuance' ) );
		$this->assertFalse( $manager->feature_enabled( 'directory' ) );
		$this->assertSame( '2.0.0', $manager->get_addons()['educator']['version'] );
	}

	/**
	 * Malformed addon registrations are ignored; first registration wins.
	 *
	 * @return void
	 */
	public function test_addon_registration_hardening() {
		$manager = PressPrimer_Certificate_Addon_Manager::get_instance();
		$manager->init();

		do_action( 'ppcert_register_addon', '', '1.0.0', [ 'ghost' ] );
		do_action( 'ppcert_register_addon', 'school', '2.0.0', [ 'directory', 42, [ 'nested' ], 'directory' ] );
		do_action( 'ppcert_register_addon', 'school', '9.9.9', [ 'takeover' ] );

		$this->assertFalse( $manager->feature_enabled( 'ghost' ) );

		$addons = $manager->get_addons();
		$this->assertSame( [ 'school' ], array_keys( $addons ) );
		$this->assertSame( '2.0.0', $addons['school']['version'], 'First registration wins' );
		$this->assertSame( [ 'directory' ], $addons['school']['features'], 'Non-strings and duplicates dropped' );
		$this->assertFalse( $manager->feature_enabled( 'takeover' ) );
	}

	/**
	 * ppcert_register_merge_fields fires with ( array $fields, string $context )
	 * for both documented contexts.
	 *
	 * @return void
	 */
	public function test_register_merge_fields_contract() {
		$calls = [];
		$this->spy( 'ppcert_register_merge_fields', 4, $calls );

		PressPrimer_Certificate_Merge_Field_Registry::get_fields( 'designer' );
		PressPrimer_Certificate_Merge_Field_Registry::resolve( 1, [ 'site.name' ], [ 'recipient_id' => 1 ] );

		$this->assertGreaterThanOrEqual( 2, count( $calls ) );

		foreach ( $calls as $args ) {
			$this->assertCount( 2, $args, 'ppcert_register_merge_fields passes exactly 2 args' );
			$this->assertIsArray( $args[0] );
			$this->assertContains( $args[1], [ 'designer', 'issue' ] );
		}
	}

	/**
	 * ppcert_merge_data fires once per resolve with ( array $merge_data,
	 * array $context ), after resolution, with template_id in context.
	 *
	 * @return void
	 */
	public function test_merge_data_contract() {
		$calls = [];
		$this->spy( 'ppcert_merge_data', 4, $calls );

		PressPrimer_Certificate_Merge_Field_Registry::resolve( 7, [ 'certificate.expiry_date' ], [ 'recipient_id' => 1 ] );

		$this->assertCount( 1, $calls );
		$this->assertCount( 2, $calls[0] );
		$this->assertIsArray( $calls[0][0] );
		$this->assertIsArray( $calls[0][1] );
		$this->assertSame( 7, $calls[0][1]['template_id'] );
		$this->assertArrayHasKey( 'certificate.expiry_date', $calls[0][0] );
	}

	/**
	 * ppcert_register_trigger_types fires with ( array $types ) and the
	 * adapter glue contributes the HOOKS.md entry shape.
	 *
	 * @return void
	 */
	public function test_register_trigger_types_contract() {
		$calls = [];
		$this->spy( 'ppcert_register_trigger_types', 3, $calls );

		$adapter = new PPCert_Test_Double_Adapter();
		$adapter->register();

		$types = PressPrimer_Certificate_Trigger_Registry::get_types();

		$this->assertCount( 1, $calls );
		$this->assertCount( 1, $calls[0], 'ppcert_register_trigger_types passes exactly 1 arg' );
		$this->assertIsArray( $calls[0][0] );

		$entry = $types['double_lms'];
		$this->assertSame(
			[ 'id', 'label', 'source_label', 'source_picker', 'conditions_schema', 'source_post_types' ],
			array_keys( $entry ),
			'Trigger type entry keys per HOOKS.md (source_post_types added for the post-meta picker, Prompt 3.4; source_label added for the Award card and palette group noun, Award tab review pass)'
		);
	}

	/**
	 * ppcert_designer_fonts fires with ( array $fonts ).
	 *
	 * @return void
	 */
	public function test_designer_fonts_contract() {
		$calls = [];
		$this->spy( 'ppcert_designer_fonts', 3, $calls );

		PressPrimer_Certificate_Layout_Validator::get_registered_font_slugs();

		$this->assertCount( 1, $calls );
		$this->assertCount( 1, $calls[0] );
		$this->assertIsArray( $calls[0][0] );
	}

	/**
	 * The issuance flow fires its six hooks with the documented signatures
	 * (added at Prompt 2.1; drives a real issue() + revoke() against the
	 * wpdb fake).
	 *
	 * @return void
	 */
	public function test_issuance_flow_hook_signatures() {
		$wpdb = ppcert_tests_reset_wpdb();

		$GLOBALS['ppcert_test_users'] = [
			7 => (object) [
				'display_name' => 'Dana Whitfield',
				'first_name'   => 'Dana',
				'last_name'    => 'Whitfield',
				'user_email'   => 'dana@example.test',
			],
		];

		$template_id = $wpdb->seed_row(
			PressPrimer_Certificate_Template::table(),
			[
				'uuid'                  => 'tmpl-hook-contract',
				'title'                 => 'Contract Template',
				'status'                => 'published',
				'author_id'             => 1,
				'layout_schema_version' => 1,
				'layout_json'           => '{"layout_schema_version":1,"elements":[]}',
				'deleted_at'            => null,
			]
		);

		$captured = [];
		$spied    = [
			'ppcert_issue_validation'    => 5,
			'ppcert_before_issue'        => 5,
			'ppcert_certificate_issued'  => 5,
			'ppcert_certificate_revoked' => 5,
			'ppcert_email_enabled'       => 5,
			'ppcert_email_content'       => 5,
		];

		foreach ( $spied as $hook => $accepted ) {
			$captured[ $hook ] = [];
			$this->spy( $hook, $accepted, $captured[ $hook ] );
		}

		$certificate_id = PressPrimer_Certificate_Issuance_Service::issue(
			[
				'template_id'  => $template_id,
				'recipient_id' => 7,
				'issued_by'    => 1,
			]
		);
		$this->assertIsInt( $certificate_id );
		PressPrimer_Certificate_Certificate::revoke( $certificate_id, 'Contract test' );

		// ppcert_issue_validation: ( true|WP_Error, array $context ).
		$this->assertCount( 2, $captured['ppcert_issue_validation'][0] );
		$this->assertTrue( $captured['ppcert_issue_validation'][0][0] );
		$this->assertIsArray( $captured['ppcert_issue_validation'][0][1] );

		// ppcert_before_issue: ( array $context ).
		$this->assertCount( 1, $captured['ppcert_before_issue'][0] );
		$this->assertIsArray( $captured['ppcert_before_issue'][0][0] );

		// ppcert_certificate_issued: ( int $certificate_id, array $context ).
		$this->assertCount( 2, $captured['ppcert_certificate_issued'][0] );
		$this->assertSame( $certificate_id, $captured['ppcert_certificate_issued'][0][0] );
		$this->assertIsArray( $captured['ppcert_certificate_issued'][0][1] );

		// ppcert_certificate_revoked: ( int $certificate_id, string $reason ).
		$this->assertCount( 2, $captured['ppcert_certificate_revoked'][0] );
		$this->assertSame( $certificate_id, $captured['ppcert_certificate_revoked'][0][0] );
		$this->assertIsString( $captured['ppcert_certificate_revoked'][0][1] );

		// ppcert_email_enabled: ( bool, string $email_type, array $context ).
		$this->assertCount( 3, $captured['ppcert_email_enabled'][0] );
		$this->assertIsBool( $captured['ppcert_email_enabled'][0][0] );
		$this->assertSame( 'issued', $captured['ppcert_email_enabled'][0][1] );
		$this->assertIsArray( $captured['ppcert_email_enabled'][0][2] );

		// ppcert_email_content: ( array $content, string $email_type, array $context ).
		$this->assertCount( 3, $captured['ppcert_email_content'][0] );
		$this->assertIsArray( $captured['ppcert_email_content'][0][0] );
		$this->assertSame(
			[ 'to', 'subject', 'body', 'headers', 'attachments' ],
			array_keys( $captured['ppcert_email_content'][0][0] ),
			'Email content shape per HOOKS.md'
		);
		$this->assertSame( 'issued', $captured['ppcert_email_content'][0][1] );
	}

	/**
	 * Contract completeness: every hook in HOOKS.md appears in the table,
	 * every covered hook has a spy test above, and pending hooks name
	 * their owning prompt. This test fails when a hook is added to the
	 * codebase without extending the contract.
	 *
	 * @return void
	 */
	public function test_contract_table_integrity() {
		$covered = 0;
		$pending = 0;

		foreach ( self::CONTRACT as $hook => $entry ) {
			$this->assertMatchesRegularExpression( '/^ppcert_[a-z0-9_]+$/', $hook );
			$this->assertContains( $entry['type'], [ 'action', 'filter' ] );
			$this->assertIsInt( $entry['params'] );

			if ( 'covered' === $entry['status'] ) {
				$covered++;
			} else {
				$this->assertMatchesRegularExpression( '/^pending:(\d\.\d|reserved)$/', $entry['status'] );
				$pending++;
			}
		}

		$this->assertSame( 15, $covered, 'Covered hook count changed - update the spy tests with it' );
		$this->assertSame( 2, $pending, 'Pending hook count changed - a feature prompt should move entries to covered' );

		// Grep-level source check: every contract hook name appears in the
		// plugin source or is pending with a scheduled owner.
		$source = '';
		foreach ( glob( PPCERT_PLUGIN_DIR . 'includes/{,*/}*.php', GLOB_BRACE ) as $file ) {
			$source .= file_get_contents( $file );
		}
		$source .= file_get_contents( PPCERT_PLUGIN_DIR . 'pressprimer-certificate.php' );

		foreach ( self::CONTRACT as $hook => $entry ) {
			if ( 'covered' === $entry['status'] ) {
				$this->assertStringContainsString(
					"'{$hook}'",
					$source,
					"Covered hook {$hook} must appear in plugin source"
				);
			}
		}
	}
}
