<?php
/**
 * Lifecycle and settings seam tests (2.1)
 *
 * Exercises Feature 2.1-004 FR-003 (email mapping keys and context
 * checks), FR-004 (ppcert_issue_tokens), FR-011 (per-certificate and
 * bulk permission filters), FR-012 (explicit actors), FR-013 (the
 * pre-delete action from delete() and the privacy eraser), FR-015
 * (template settings carry-over), and FR-016 (issuer name, trigger
 * changes). Each seam's default reproduces 2.0; a test consumer proves
 * it works.
 *
 * @package PressPrimer_Certificate
 * @subpackage Tests
 * @since 2.1.0
 */

use PHPUnit\Framework\TestCase;

/**
 * Lifecycle seams test case
 *
 * @since 2.1.0
 */
class Test_Lifecycle_Seams extends TestCase {

	/**
	 * The fake wpdb for the current test.
	 *
	 * @var PPCert_Fake_WPDB
	 */
	private $wpdb;

	/**
	 * Seeded template id.
	 *
	 * @var int
	 */
	private $template_id;

	/**
	 * Seed a template and an acting administrator.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		ppcert_tests_reset_hooks();
		$this->wpdb = ppcert_tests_reset_wpdb();

		PressPrimer_Certificate_Addon_Manager::get_instance()->reset();

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
		];

		$this->template_id = $this->wpdb->seed_row(
			PressPrimer_Certificate_Template::table(),
			[
				'uuid'                  => 'tmpl-lifecycle-1',
				'title'                 => 'Completion Certificate',
				'status'                => 'published',
				'author_id'             => 1,
				'issuer_id'             => 4,
				'page_size'             => 'a4',
				'orientation'           => 'landscape',
				'layout_schema_version' => 1,
				'layout_json'           => ppcert_tests_seams_layout(),
				'deleted_at'            => null,
			]
		);
	}

	/**
	 * Issue a certificate to user 7.
	 *
	 * @param array $overrides Issue arg overrides.
	 * @return int
	 */
	private function issue( array $overrides = [] ) {
		static $ref = 500;
		++$ref;

		return PressPrimer_Certificate_Issuance_Service::issue(
			array_merge(
				[
					'template_id'  => $this->template_id,
					'recipient_id' => 7,
					'source_type'  => 'manual',
					'source_ref'   => (string) $ref,
					'issued_by'    => 1,
				],
				$overrides
			)
		);
	}

	/**
	 * Event rows of one type.
	 *
	 * @param string $type Event type.
	 * @return array[]
	 */
	private function events( $type ) {
		return array_values(
			array_filter(
				$this->wpdb->rows( PressPrimer_Certificate_Certificate::events_table() ),
				static function ( $row ) use ( $type ) {
					return $type === $row['event_type'];
				}
			)
		);
	}

	/**
	 * Explicit actors land on the event rows and reach listeners through
	 * current_actor(); the default stays the current user.
	 *
	 * @return void
	 */
	public function test_explicit_actors() {
		$id   = $this->issue();
		$seen = [];

		foreach ( [ 'ppcert_certificate_revoked', 'ppcert_certificate_reinstated', 'ppcert_before_certificate_delete', 'ppcert_certificate_deleted' ] as $hook ) {
			add_action(
				$hook,
				static function () use ( $hook, &$seen ) {
					$seen[ $hook ] = PressPrimer_Certificate_Certificate::current_actor();
				}
			);
		}

		PressPrimer_Certificate_Certificate::revoke( $id, 'Test', 42 );
		PressPrimer_Certificate_Certificate::reinstate( $id, 43 );
		PressPrimer_Certificate_Email_Service::resend( $id, 44 );

		$this->assertSame( 42, (int) $this->events( 'revoked' )[0]['actor_id'] );
		$this->assertSame( 43, (int) $this->events( 'reinstated' )[0]['actor_id'] );
		$this->assertSame( 44, (int) $this->events( 'reissued' )[0]['actor_id'] );
		$this->assertSame( 42, $seen['ppcert_certificate_revoked'] );
		$this->assertSame( 43, $seen['ppcert_certificate_reinstated'] );
		$this->assertSame( 1, PressPrimer_Certificate_Certificate::current_actor(), 'Back to the current user afterward' );

		// Defaults: the current user, as in 2.0.
		PressPrimer_Certificate_Certificate::revoke( $id );
		PressPrimer_Certificate_Certificate::reinstate( $id );
		$this->assertSame( 1, (int) $this->events( 'revoked' )[1]['actor_id'] );

		PressPrimer_Certificate_Certificate::delete( $id, 45, 'bulk' );
		$this->assertSame( 45, $seen['ppcert_before_certificate_delete'] );
		$this->assertSame( 45, $seen['ppcert_certificate_deleted'] );

		// A system path with no user records no actor.
		$GLOBALS['ppcert_test_current_user'] = 0;
		$other                               = $this->issue();
		PressPrimer_Certificate_Certificate::revoke( $other );
		$revoked = $this->events( 'revoked' );
		$this->assertSame( $other, (int) end( $revoked )['certificate_id'] );
		$this->assertNull( end( $revoked )['actor_id'] );
	}

	/**
	 * The pre-delete action fires before removal with the full row and
	 * the source, from delete() and from the privacy eraser.
	 *
	 * @return void
	 */
	public function test_before_delete_fires_with_row() {
		$id    = $this->issue();
		$calls = [];

		add_action(
			'ppcert_before_certificate_delete',
			function ( $certificate_id, $certificate, $context ) use ( &$calls ) {
				$calls[] = [
					$certificate_id,
					(string) $certificate->credential_id,
					$context,
					null !== PressPrimer_Certificate_Certificate::get( $certificate_id ),
				];
			},
			10,
			3
		);

		$credential = (string) PressPrimer_Certificate_Certificate::get( $id )->credential_id;

		PressPrimer_Certificate_Certificate::delete( $id );

		$this->assertSame( [ [ $id, $credential, 'admin', true ] ], $calls, 'Before removal: the row is still readable' );
		$this->assertNull( PressPrimer_Certificate_Certificate::get( $id ) );

		$erased = $this->issue();
		PressPrimer_Certificate_Privacy::erase( 'dana@example.test' );

		$this->assertSame( $erased, $calls[1][0] );
		$this->assertSame( 'privacy', $calls[1][2] );
	}

	/**
	 * Per-certificate permission: the 2.0 rule by default; the filter
	 * narrows (School: delete is owner-only) but never past the
	 * capability.
	 *
	 * @return void
	 */
	public function test_user_can_manage_certificate() {
		$certificate = PressPrimer_Certificate_Certificate::get( $this->issue() );

		foreach ( PressPrimer_Certificate_Certificate::MANAGE_ACTIONS as $action ) {
			$this->assertTrue( PressPrimer_Certificate_Certificate::user_can_manage( $certificate, 1, $action ), $action );
		}

		$this->assertFalse( PressPrimer_Certificate_Certificate::user_can_manage( $certificate, 1, 'archive' ), 'Unknown actions are refused' );
		$this->assertFalse( PressPrimer_Certificate_Certificate::user_can_manage( null, 1, 'revoke' ) );

		// The user's scope excludes this issuer: 2.0's refusal.
		add_filter(
			'ppcert_certificate_scope',
			static function () {
				return [
					'issuer_ids' => [ 9 ],
					'issued_by'  => 99,
				];
			}
		);
		$this->assertFalse( PressPrimer_Certificate_Certificate::user_can_manage( $certificate, 1, 'revoke' ) );

		ppcert_tests_reset_hooks();
		add_filter(
			'ppcert_user_can_manage_certificate',
			static function ( $can, $user_id, $action ) {
				return $can && 'delete' !== $action;
			},
			10,
			3
		);
		$this->assertTrue( PressPrimer_Certificate_Certificate::user_can_manage( $certificate, 1, 'revoke' ) );
		$this->assertFalse( PressPrimer_Certificate_Certificate::user_can_manage( $certificate, 1, 'delete' ) );

		ppcert_tests_reset_hooks();
		add_filter( 'ppcert_user_can_manage_certificate', '__return_true' );
		$GLOBALS['ppcert_test_user_caps'] = [];
		$this->assertFalse( PressPrimer_Certificate_Certificate::user_can_manage( $certificate, 1, 'revoke' ), 'Never past the capability' );
	}

	/**
	 * Bulk permission and scope: unscoped users by default; the filters
	 * grant scoped owners and narrow their scope.
	 *
	 * @return void
	 */
	public function test_bulk_permission_and_scope() {
		$this->assertTrue( PressPrimer_Certificate_Certificate::user_can_bulk_manage( 1, 'revoke' ) );
		$this->assertNull( PressPrimer_Certificate_Certificate::bulk_scope( 1 ) );

		add_filter(
			'ppcert_certificate_scope',
			static function () {
				return [
					'issuer_ids' => [ 4, 5 ],
					'issued_by'  => 1,
				];
			}
		);

		$this->assertFalse( PressPrimer_Certificate_Certificate::user_can_bulk_manage( 1, 'revoke' ), 'Scoped users need a grant' );
		$this->assertSame(
			[
				'issuer_ids' => [ 4, 5 ],
				'issued_by'  => 1,
			],
			PressPrimer_Certificate_Certificate::bulk_scope( 1 ),
			'Default: the normal scope'
		);

		add_filter( 'ppcert_user_can_bulk_manage_certificates', '__return_true' );
		add_filter(
			'ppcert_bulk_certificate_scope',
			static function () {
				return [
					'issuer_ids' => [ 4 ],
					'issued_by'  => 0,
				];
			}
		);

		$this->assertTrue( PressPrimer_Certificate_Certificate::user_can_bulk_manage( 1, 'delete' ) );
		$this->assertSame(
			[
				'issuer_ids' => [ 4 ],
				'issued_by'  => 0,
			],
			PressPrimer_Certificate_Certificate::bulk_scope( 1 ),
			'Owned issuers, no issued_by branch'
		);

		$GLOBALS['ppcert_test_user_caps'] = [];
		$this->assertFalse( PressPrimer_Certificate_Certificate::user_can_bulk_manage( 1, 'delete' ), 'Never past the capability' );
	}

	/**
	 * Settings carry-over: a stored addon key survives a save while its
	 * owner is inactive; an active owner (registered addon or declared
	 * prefix) decides; request input never revives a key; core keys
	 * clear.
	 *
	 * @return void
	 */
	public function test_settings_carry_over() {
		$this->wpdb->mutate_row(
			PressPrimer_Certificate_Template::table(),
			$this->template_id,
			[
				'settings_json' => wp_json_encode(
					[
						'certificate_name'      => 'Old name',
						'school_cc'             => 'office@example.test',
						'school_program_public' => true,
					]
				),
			]
		);

		$save = function ( array $settings ) {
			PressPrimer_Certificate_Template::update( $this->template_id, [ 'settings' => $settings ] );

			return PressPrimer_Certificate_Template::get( $this->template_id )->settings;
		};

		// School inactive: its keys carry over; the cleared core key does
		// not; a request value never lands.
		$settings = $save( [ 'school_cc' => 'attacker@example.test' ] );
		$this->assertSame( 'office@example.test', $settings['school_cc'] );
		$this->assertTrue( $settings['school_program_public'] );
		$this->assertArrayNotHasKey( 'certificate_name', $settings );

		// School active (registered, 2.0.x without the new filter): its
		// sanitizer is authoritative - a switch cleared by omission stays
		// cleared.
		PressPrimer_Certificate_Addon_Manager::get_instance()->register_addon( 'school', '2.0.1' );
		add_filter(
			'ppcert_template_sanitize_settings',
			static function ( $clean, $raw ) {
				if ( ! empty( $raw['school_cc'] ) ) {
					$clean['school_cc'] = sanitize_text_field( $raw['school_cc'] );
				}

				return $clean;
			},
			10,
			2
		);

		$settings = $save( [ 'school_cc' => 'registrar@example.test' ] );
		$this->assertSame( 'registrar@example.test', $settings['school_cc'] );
		$this->assertArrayNotHasKey( 'school_program_public', $settings, 'Cleared by omission while active' );

		// A declared prefix works without a registration.
		PressPrimer_Certificate_Addon_Manager::get_instance()->reset();
		ppcert_tests_reset_hooks();
		$this->wpdb->mutate_row(
			PressPrimer_Certificate_Template::table(),
			$this->template_id,
			[ 'settings_json' => wp_json_encode( [ 'acme_flag' => true ] ) ]
		);
		add_filter(
			'ppcert_template_settings_owned_prefixes',
			static function ( $prefixes ) {
				$prefixes[] = 'acme_';
				return $prefixes;
			}
		);
		$this->assertArrayNotHasKey( 'acme_flag', (array) $save( [] ) );
	}

	/**
	 * Email mapping keys: each must name an existing row of its own
	 * context.
	 *
	 * @return void
	 */
	public function test_email_mapping_keys_check_context() {
		$issuance = $this->wpdb->seed_row(
			PressPrimer_Certificate_Email_Template::table(),
			[
				'title'      => 'Award',
				'context'    => 'issuance',
				'subject'    => 'S',
				'body'       => 'B',
				'status'     => 'active',
				'deleted_at' => null,
			]
		);
		$reminder = $this->wpdb->seed_row(
			PressPrimer_Certificate_Email_Template::table(),
			[
				'title'      => 'Reminder',
				'context'    => 'reminder',
				'subject'    => 'S',
				'body'       => 'B',
				'status'     => 'active',
				'deleted_at' => null,
			]
		);

		$clean = PressPrimer_Certificate_Template::sanitize_settings(
			[
				'email_template_id'          => $issuance,
				'reminder_email_template_id' => $reminder,
			]
		);
		$this->assertSame( $issuance, $clean['email_template_id'] );
		$this->assertSame( $reminder, $clean['reminder_email_template_id'] );

		$swapped = PressPrimer_Certificate_Template::sanitize_settings(
			[
				'email_template_id'          => $reminder,
				'reminder_email_template_id' => $issuance,
			]
		);
		$this->assertSame( [], $swapped, 'A row of the other context never validates' );

		// Educator honors the reminder mapping through resolve_content().
		add_filter(
			'ppcert_email_template_id',
			static function ( $id, $stored ) {
				return $stored;
			},
			10,
			2
		);
		$template           = PressPrimer_Certificate_Template::get( $this->template_id );
		$template->settings = $clean;
		$this->assertSame( 'S', PressPrimer_Certificate_Email_Service::resolve_content( $template, 'reminder' )['subject'] );
	}

	/**
	 * ppcert_issuer_name: the default is the site name, byte for byte;
	 * a consumer names the organization on the snapshot and in emails.
	 *
	 * @return void
	 */
	public function test_issuer_name_filter() {
		$GLOBALS['ppcert_test_bloginfo']['name'] = 'Sunrise  Training & Co';
		$this->assertSame( 'Sunrise  Training & Co', PressPrimer_Certificate_Merge_Field_Registry::resolve_issuer_name( [] ), 'Unfiltered: the site name untouched' );
		$GLOBALS['ppcert_test_bloginfo']['name'] = 'Sunrise Training Academy';

		$contexts = [];

		add_filter(
			'ppcert_issuer_name',
			static function ( $name, $context ) use ( &$contexts ) {
				$contexts[] = $context;

				return 4 === (int) $context['issuer_id'] ? 'Acme <b>Institute</b>' : $name;
			},
			10,
			2
		);

		$this->assertSame( 'Acme Institute', PressPrimer_Certificate_Merge_Field_Registry::resolve_issuer_name( [ 'issuer_id' => 4 ] ) );
		$this->assertSame( 'Sunrise Training Academy', PressPrimer_Certificate_Merge_Field_Registry::resolve_issuer_name( [ 'issuer_id' => 0 ] ) );

		$GLOBALS['ppcert_test_options']['ppcert_settings']['email_issued_body'] = 'From {issuer_name}';
		$GLOBALS['ppcert_test_mail'] = [];
		$this->issue();

		$this->assertStringContainsString( 'From Acme Institute', $GLOBALS['ppcert_test_mail'][0]['body'] );
		$this->assertSame( 4, (int) end( $contexts )['issuer_id'] );
	}

	/**
	 * ppcert_issue_tokens: an addon's token resolves into the snapshot.
	 *
	 * @return void
	 */
	public function test_issue_tokens_filter() {
		add_filter(
			'ppcert_issue_tokens',
			static function ( $tokens ) {
				$tokens[] = '{{recipient.last_name}}';
				return $tokens;
			}
		);

		$certificate = PressPrimer_Certificate_Certificate::get( $this->issue() );

		$this->assertSame( 'Whitfield', $certificate->merge_data['recipient.last_name'] );
		$this->assertContains( 'recipient.last_name', PressPrimer_Certificate_Issuance_Service::template_tokens( PressPrimer_Certificate_Template::get( $this->template_id ) ) );
	}

	/**
	 * ppcert_template_triggers_replaced fires with the new and old sets.
	 *
	 * @return void
	 */
	public function test_triggers_replaced_action() {
		$calls = [];

		add_action(
			'ppcert_template_triggers_replaced',
			static function ( $template_id, $triggers, $previous ) use ( &$calls ) {
				$calls[] = [ $template_id, count( $triggers ), count( $previous ) ];
			},
			10,
			3
		);

		PressPrimer_Certificate_Trigger::replace_for_template(
			$this->template_id,
			[
				[
					'trigger_type' => 'ppq_quiz',
					'source_ref'   => '5',
				],
			]
		);
		PressPrimer_Certificate_Trigger::replace_for_template( $this->template_id, [] );

		$this->assertSame(
			[
				[ $this->template_id, 1, 0 ],
				[ $this->template_id, 0, 1 ],
			],
			$calls
		);
	}
}
