<?php
/**
 * Merge field registry unit tests
 *
 * Exercises Feature 002 FR-001/FR-002/FR-003/FR-005 via the registry
 * service: registry build, fallback chains, meta resolution with the
 * denylist, empty-on-failure behavior, token extraction, and filter
 * application order.
 *
 * @package PressPrimer_Certificate
 * @subpackage Tests
 * @since 1.0.0
 */

use PHPUnit\Framework\TestCase;

/**
 * Merge field registry test case
 *
 * @since 1.0.0
 */
class Test_Merge_Field_Registry extends TestCase {

	/**
	 * Reset hooks and seeded data between tests.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		ppcert_tests_reset_hooks();

		$GLOBALS['ppcert_test_users'] = [
			7 => (object) [
				'display_name' => 'jrivera',
				'first_name'   => 'Jordan',
				'last_name'    => 'Rivera',
				'user_email'   => 'jordan@example.com',
			],
			8 => (object) [
				'display_name' => 'plainuser',
				'first_name'   => '',
				'last_name'    => '',
				'user_email'   => 'plain@example.com',
			],
		];

		$GLOBALS['ppcert_test_user_meta'] = [
			7 => [
				'license_no'      => 'LIC-4471',
				'session_tokens'  => [ 'secret-token-hash' => [] ],
				'wp_capabilities' => [ 'subscriber' => true ],
				'_private_flag'   => 'hidden',
				'complex_pref'    => [ 'nested' => 'array' ],
			],
		];

		$GLOBALS['ppcert_test_post_meta'] = [
			500 => [
				'ce_hours' => '12.5',
			],
		];

		$GLOBALS['ppcert_test_bloginfo'] = [
			'name'        => 'Sunrise Training Academy',
			'description' => 'Learn. Grow. Achieve.',
			'url'         => 'https://sunrise.example',
		];
	}

	/**
	 * Baseline issuance context for user 7.
	 *
	 * @param array $overrides Context overrides.
	 * @return array
	 */
	private function context( array $overrides = [] ) {
		return array_merge(
			[
				'recipient_id'   => 7,
				'credential_id'  => '7Q4MK9P2XT3A',
				'issued_at'      => '2026-07-18 14:30:00',
				'trigger_type'   => 'manual',
				'source_ref'     => null,
				'source_post_id' => 500,
			],
			$overrides
		);
	}

	/**
	 * The registry exposes all core fields with samples and resolvers.
	 *
	 * @return void
	 */
	public function test_core_registry_shape() {
		$fields = PressPrimer_Certificate_Merge_Field_Registry::get_fields( 'designer' );

		$expected_keys = [
			'recipient.display_name',
			'recipient.first_name',
			'recipient.last_name',
			'recipient.full_name',
			'recipient.email',
			'certificate.issue_date',
			'certificate.credential_id',
			'certificate.issuer_name',
			'certificate.expiry_date',
			'site.name',
			'site.tagline',
			'site.url',
		];

		foreach ( $expected_keys as $key ) {
			$this->assertArrayHasKey( $key, $fields );
			$this->assertNotSame( '', $fields[ $key ]['sample'], "{$key} must carry a canvas sample" );
			$this->assertIsCallable( $fields[ $key ]['resolver'] );
		}

		$groups = PressPrimer_Certificate_Merge_Field_Registry::get_groups();
		$this->assertArrayHasKey( 'recipient', $groups );
		$this->assertArrayHasKey( 'certificate', $groups );
		$this->assertArrayHasKey( 'site', $groups );
	}

	/**
	 * full_name uses first + last, with display_name fallback when both
	 * are empty (Edge Cases).
	 *
	 * @return void
	 */
	public function test_full_name_fallback_chain() {
		$with_names = PressPrimer_Certificate_Merge_Field_Registry::resolve(
			1,
			[ 'recipient.full_name' ],
			$this->context()
		);
		$this->assertSame( 'Jordan Rivera', $with_names['recipient.full_name'] );

		$fallback = PressPrimer_Certificate_Merge_Field_Registry::resolve(
			1,
			[ 'recipient.full_name' ],
			$this->context( [ 'recipient_id' => 8 ] )
		);
		$this->assertSame( 'plainuser', $fallback['recipient.full_name'] );
	}

	/**
	 * Core certificate and site fields resolve from the context.
	 *
	 * @return void
	 */
	public function test_certificate_and_site_fields() {
		$values = PressPrimer_Certificate_Merge_Field_Registry::resolve(
			1,
			[ 'certificate.issue_date', 'certificate.credential_id', 'certificate.issuer_name', 'certificate.expiry_date', 'site.name', 'site.url' ],
			$this->context()
		);

		$this->assertSame( 'July 18, 2026', $values['certificate.issue_date'] );
		$this->assertSame( '7Q4M-K9P2-XT3A', $values['certificate.credential_id'], 'Credential ID resolves in display form' );
		$this->assertSame( 'Sunrise Training Academy', $values['certificate.issuer_name'] );
		$this->assertSame( '', $values['certificate.expiry_date'], 'Expiry is empty in 1.0 issuance' );
		$this->assertSame( 'Sunrise Training Academy', $values['site.name'] );
		$this->assertSame( 'https://sunrise.example', $values['site.url'] );
	}

	/**
	 * Meta tokens resolve user and post meta; the denylist blanks
	 * sensitive user meta at resolution (FR-003).
	 *
	 * @return void
	 */
	public function test_meta_resolution_and_denylist() {
		$values = PressPrimer_Certificate_Merge_Field_Registry::resolve(
			1,
			[
				'recipient.meta.license_no',
				'source.meta.ce_hours',
				'recipient.meta.session_tokens',
				'recipient.meta.wp_capabilities',
				'recipient.meta._private_flag',
			],
			$this->context()
		);

		$this->assertSame( 'LIC-4471', $values['recipient.meta.license_no'] );
		$this->assertSame( '12.5', $values['source.meta.ce_hours'] );
		$this->assertSame( '', $values['recipient.meta.session_tokens'], 'session_tokens is denylisted' );
		$this->assertSame( '', $values['recipient.meta.wp_capabilities'], 'capabilities key is denylisted' );
		$this->assertSame( '', $values['recipient.meta._private_flag'], 'underscore-prefixed keys are denylisted' );

		$reasons = array_column( PressPrimer_Certificate_Merge_Field_Registry::get_last_resolution_failures(), 'reason' );
		$this->assertContains( 'denylisted', $reasons );
	}

	/**
	 * Array/object meta resolves empty: scalar-only in 1.0 (FR-003).
	 *
	 * @return void
	 */
	public function test_scalar_only_meta() {
		$values = PressPrimer_Certificate_Merge_Field_Registry::resolve(
			1,
			[ 'recipient.meta.complex_pref' ],
			$this->context()
		);

		$this->assertSame( '', $values['recipient.meta.complex_pref'] );

		$failures = PressPrimer_Certificate_Merge_Field_Registry::get_last_resolution_failures();
		$this->assertSame( 'non_scalar', $failures[0]['reason'] );
	}

	/**
	 * Unresolvable tokens yield "" and record failures - certificates
	 * never leak template syntax (FR-005, Edge US-5).
	 *
	 * @return void
	 */
	public function test_unresolvable_yields_empty() {
		$values = PressPrimer_Certificate_Merge_Field_Registry::resolve(
			1,
			[ 'ghost.field', 'source.meta.ce_hours', 'recipient.meta.Bad Key!' ],
			$this->context( [ 'source_post_id' => 0 ] )
		);

		$this->assertSame( '', $values['ghost.field'] );
		$this->assertSame( '', $values['source.meta.ce_hours'], 'Missing source_post_id resolves empty' );
		$this->assertSame( '', $values['recipient.meta.Bad Key!'], 'Grammar-invalid meta key resolves empty' );

		$reasons = array_column( PressPrimer_Certificate_Merge_Field_Registry::get_last_resolution_failures(), 'reason' );
		$this->assertContains( 'unregistered', $reasons );
		$this->assertContains( 'missing_source', $reasons );
		$this->assertContains( 'invalid_meta_key', $reasons );
	}

	/**
	 * A throwing resolver yields "" with a resolver_failed note instead of
	 * breaking issuance (FR-005 error boundary).
	 *
	 * @return void
	 */
	public function test_throwing_resolver_yields_empty() {
		add_filter(
			'ppcert_register_merge_fields',
			static function ( $fields ) {
				$fields['custom.explosive'] = [
					'group'    => 'custom',
					'key'      => 'custom.explosive',
					'label'    => 'Explosive',
					'sample'   => 'Boom',
					'resolver' => static function ( $context ) {
						throw new RuntimeException( 'resolver blew up' );
					},
				];
				return $fields;
			}
		);

		$values = PressPrimer_Certificate_Merge_Field_Registry::resolve(
			1,
			[ 'custom.explosive' ],
			$this->context()
		);

		$this->assertSame( '', $values['custom.explosive'] );
		$failures = PressPrimer_Certificate_Merge_Field_Registry::get_last_resolution_failures();
		$this->assertSame( 'resolver_failed', $failures[0]['reason'] );
	}

	/**
	 * Filter application order: ppcert_register_merge_fields extends the
	 * registry before resolution; ppcert_merge_data runs after resolution
	 * over the final map (TR-003).
	 *
	 * @return void
	 */
	public function test_filter_application_order() {
		$order = [];

		add_filter(
			'ppcert_register_merge_fields',
			static function ( $fields, $context ) use ( &$order ) {
				$order[]                  = "register:{$context}";
				$fields['custom.greeting'] = [
					'group'    => 'custom',
					'key'      => 'custom.greeting',
					'label'    => 'Greeting',
					'sample'   => 'Hello',
					'resolver' => static function ( $context ) {
						return 'Hello';
					},
				];
				return $fields;
			},
			10,
			2
		);

		add_filter(
			'ppcert_merge_data',
			static function ( $merge_data, $context ) use ( &$order ) {
				$order[] = 'merge_data';
				$merge_data['custom.greeting'] .= ' World';
				return $merge_data;
			},
			10,
			2
		);

		$values = PressPrimer_Certificate_Merge_Field_Registry::resolve( 42, [ 'custom.greeting' ], $this->context() );

		$this->assertSame( 'Hello World', $values['custom.greeting'], 'ppcert_merge_data sees resolved values' );
		$this->assertSame( [ 'register:issue', 'merge_data' ], $order );
	}

	/**
	 * The ppcert_merge_data context carries template_id and recipient_id.
	 *
	 * @return void
	 */
	public function test_merge_data_context() {
		$captured = null;

		add_filter(
			'ppcert_merge_data',
			static function ( $merge_data, $context ) use ( &$captured ) {
				$captured = $context;
				return $merge_data;
			},
			10,
			2
		);

		PressPrimer_Certificate_Merge_Field_Registry::resolve( 42, [ 'site.name' ], $this->context() );

		$this->assertSame( 42, $captured['template_id'] );
		$this->assertSame( 7, $captured['recipient_id'] );
	}

	/**
	 * Adapter-style group-keyed registration (HOOKS.md example shape) is
	 * normalized into the flat registry.
	 *
	 * @return void
	 */
	public function test_group_keyed_registration_normalized() {
		add_filter(
			'ppcert_register_merge_fields',
			static function ( $fields ) {
				$fields['source']['course_title'] = [
					'key'      => 'source.course_title',
					'label'    => 'Course Title',
					'sample'   => 'Introduction to Botany',
					'resolver' => static function ( $context ) {
						return 'Introduction to Botany';
					},
				];
				return $fields;
			}
		);

		$fields = PressPrimer_Certificate_Merge_Field_Registry::get_fields( 'designer' );

		$this->assertArrayHasKey( 'source.course_title', $fields );
		$this->assertSame( 'source', $fields['source.course_title']['group'] );

		$values = PressPrimer_Certificate_Merge_Field_Registry::resolve( 1, [ 'source.course_title' ], $this->context() );
		$this->assertSame( 'Introduction to Botany', $values['source.course_title'] );
	}

	/**
	 * Token extraction: merge_field tokens only, braces stripped, unique,
	 * in order of first appearance.
	 *
	 * @return void
	 */
	public function test_token_extraction() {
		$layout = [
			'layout_schema_version' => 1,
			'elements'              => [
				[
					'type'  => 'text',
					'props' => [ 'content' => 'Certificate of Completion' ],
				],
				[
					'type'  => 'merge_field',
					'props' => [ 'token' => '{{recipient.display_name}}' ],
				],
				[
					'type'  => 'merge_field',
					'props' => [ 'token' => '{{certificate.credential_id}}' ],
				],
				[
					'type'  => 'merge_field',
					'props' => [ 'token' => '{{recipient.display_name}}' ],
				],
			],
		];

		$this->assertSame(
			[ 'recipient.display_name', 'certificate.credential_id' ],
			PressPrimer_Certificate_Merge_Field_Registry::extract_tokens( $layout )
		);

		$this->assertSame( [], PressPrimer_Certificate_Merge_Field_Registry::extract_tokens( [ 'elements' => [] ] ) );
	}
}
