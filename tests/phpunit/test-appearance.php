<?php
/**
 * Appearance defaults tests (Prompt 5.2)
 *
 * The Appearance settings driving new-element defaults, the starter
 * color-role substitution on clone, and the safety fallbacks.
 *
 * @package PressPrimer_Certificate
 * @subpackage Tests
 * @since 1.0.0
 */

use PHPUnit\Framework\TestCase;

/**
 * Appearance test case
 *
 * @since 1.0.0
 */
class Test_Appearance extends TestCase {

	/**
	 * Reset state.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		ppcert_tests_reset_hooks();
		ppcert_tests_reset_wpdb();

		$GLOBALS['ppcert_test_options']     = [];
		$GLOBALS['ppcert_test_user_caps']   = true;
		$GLOBALS['ppcert_test_attachments'] = [];
	}

	/**
	 * Unset appearance leaves the built-in element defaults untouched.
	 *
	 * @return void
	 */
	public function test_unset_appearance_keeps_builtin_defaults() {
		$types = PressPrimer_Certificate_Element_Types::get_types();

		$this->assertSame( 'source-sans-3', $types['text']['default_props']['font_family'] );
		$this->assertSame( '#1f2937', $types['text']['default_props']['color'] );
		$this->assertSame( '#1f2937', $types['shape']['default_props']['stroke_color'] );
		$this->assertSame( '#000000', $types['qr']['default_props']['dark_color'] );
		$this->assertSame( 0, $types['signature']['default_props']['attachment_id'] );
		$this->assertSame( 0, $types['image']['default_props']['attachment_id'] );
	}

	/**
	 * Appearance settings drive every new-element default.
	 *
	 * @return void
	 */
	public function test_appearance_drives_element_defaults() {
		$GLOBALS['ppcert_test_options']['ppcert_settings'] = [
			'appearance_default_font'  => 'quicksand',
			'appearance_primary_color' => '#123456',
			'appearance_accent_color'  => '#654321',
			'appearance_signature_id'  => 41,
			'appearance_logo_id'       => 42,
		];

		$types = PressPrimer_Certificate_Element_Types::get_types();

		$this->assertSame( 'quicksand', $types['text']['default_props']['font_family'] );
		$this->assertSame( 'quicksand', $types['merge_field']['default_props']['font_family'] );
		$this->assertSame( '#123456', $types['text']['default_props']['color'] );
		$this->assertSame( '#123456', $types['qr']['default_props']['dark_color'] );
		$this->assertSame( '#654321', $types['shape']['default_props']['stroke_color'] );
		$this->assertSame( 41, $types['signature']['default_props']['attachment_id'] );
		$this->assertSame( 42, $types['image']['default_props']['attachment_id'] );
	}

	/**
	 * An unregistered stored font never reaches element defaults.
	 *
	 * @return void
	 */
	public function test_unregistered_font_falls_back() {
		$GLOBALS['ppcert_test_options']['ppcert_settings'] = [
			'appearance_default_font' => 'premium-font-gone',
		];

		$types = PressPrimer_Certificate_Element_Types::get_types();

		$this->assertSame( 'source-sans-3', $types['text']['default_props']['font_family'] );
	}

	/**
	 * Brand colors substitute only role-mapped hexes.
	 *
	 * @return void
	 */
	public function test_apply_brand_colors_substitutes_roles() {
		$GLOBALS['ppcert_test_options']['ppcert_settings'] = [
			'appearance_primary_color' => '#ff0000',
			'appearance_accent_color'  => '#00ff00',
		];

		$layout = [
			'layout_schema_version' => 1,
			'background'            => [ 'color' => '#fdfcf8' ],
			'elements'              => [
				[
					'id'    => 'el_title001',
					'type'  => 'text',
					'props' => [ 'color' => '#1F2A44' ],
				],
				[
					'id'    => 'el_border01',
					'type'  => 'shape',
					'props' => [
						'stroke_color' => '#b8860b',
						'fill_color'   => '',
					],
				],
				[
					'id'    => 'el_muted001',
					'type'  => 'text',
					'props' => [ 'color' => '#6b7280' ],
				],
			],
		];

		$roles = [
			'primary' => [ '#1f2a44' ],
			'accent'  => [ '#b8860b' ],
		];

		$branded = PressPrimer_Certificate_Appearance_Service::apply_brand_colors( $layout, $roles );

		// Case-insensitive substitution on mapped colors.
		$this->assertSame( '#ff0000', $branded['elements'][0]['props']['color'] );
		$this->assertSame( '#00ff00', $branded['elements'][1]['props']['stroke_color'] );

		// Unmapped colors and empty values stay untouched.
		$this->assertSame( '#6b7280', $branded['elements'][2]['props']['color'] );
		$this->assertSame( '', $branded['elements'][1]['props']['fill_color'] );
		$this->assertSame( '#fdfcf8', $branded['background']['color'] );
	}

	/**
	 * No brand colors set: the layout passes through byte-identical.
	 *
	 * @return void
	 */
	public function test_apply_brand_colors_noop_when_unset() {
		$layout = [
			'elements' => [
				[
					'id'    => 'el_title001',
					'type'  => 'text',
					'props' => [ 'color' => '#1f2a44' ],
				],
			],
		];

		$branded = PressPrimer_Certificate_Appearance_Service::apply_brand_colors(
			$layout,
			[ 'primary' => [ '#1f2a44' ] ]
		);

		$this->assertSame( $layout, $branded );
	}

	/**
	 * Creating from a starter applies the brand colors to the stored
	 * layout; the formal starter's navy and gold become the site's
	 * primary and accent.
	 *
	 * @return void
	 */
	public function test_starter_clone_is_branded() {
		$GLOBALS['ppcert_test_options']['ppcert_settings'] = [
			'appearance_primary_color' => '#222222',
			'appearance_accent_color'  => '#c0ffee',
		];

		$controller = new PressPrimer_Certificate_REST_Templates_Controller();
		$response   = $controller->create_template(
			new WP_REST_Request( [ 'starter' => 'starter-formal-landscape' ] )
		);

		$this->assertNotInstanceOf( WP_Error::class, $response );

		$layout = $response->get_data()['layout'];
		$colors = [];

		foreach ( $layout['elements'] as $element ) {
			foreach ( [ 'color', 'stroke_color', 'dark_color' ] as $prop ) {
				if ( isset( $element['props'][ $prop ] ) && '' !== $element['props'][ $prop ] ) {
					$colors[] = strtolower( $element['props'][ $prop ] );
				}
			}
		}

		$this->assertContains( '#222222', $colors );
		$this->assertContains( '#c0ffee', $colors );
		$this->assertNotContains( '#1f2a44', $colors );
		$this->assertNotContains( '#b8860b', $colors );
	}
}

/**
 * Certificate size + earned date additions (Phase 5B items 6-7)
 *
 * @since 1.0.0
 */
class Test_Size_And_Earned_Date extends TestCase {

	/**
	 * The fake wpdb for the current test.
	 *
	 * @var PPCert_Fake_WPDB
	 */
	private $wpdb;

	/**
	 * Reset state.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		ppcert_tests_reset_hooks();
		$this->wpdb = ppcert_tests_reset_wpdb();

		$GLOBALS['ppcert_test_options']      = [];
		$GLOBALS['ppcert_test_user_caps']    = true;
		$GLOBALS['ppcert_test_current_user'] = 1;
		$GLOBALS['ppcert_test_users']        = [
			7 => (object) [
				'ID'           => 7,
				'display_name' => 'Dana Whitfield',
				'user_email'   => 'dana@example.test',
			],
		];
	}

	/**
	 * Appearance page size defaults to letter and honors a stored a4.
	 *
	 * @return void
	 */
	public function test_page_size_defaults_to_letter() {
		$this->assertSame( 'letter', PressPrimer_Certificate_Appearance_Service::get()['page_size'] );

		$GLOBALS['ppcert_test_options']['ppcert_settings'] = [ 'appearance_page_size' => 'a4' ];
		$this->assertSame( 'a4', PressPrimer_Certificate_Appearance_Service::get()['page_size'] );

		$GLOBALS['ppcert_test_options']['ppcert_settings'] = [ 'appearance_page_size' => 'tabloid' ];
		$this->assertSame( 'letter', PressPrimer_Certificate_Appearance_Service::get()['page_size'] );
	}

	/**
	 * Blank templates use the selected size, Letter by default.
	 *
	 * @return void
	 */
	public function test_blank_template_uses_selected_size() {
		$controller = new PressPrimer_Certificate_REST_Templates_Controller();

		$response = $controller->create_template( new WP_REST_Request( [] ) );
		$layout   = $response->get_data()['layout'];

		$this->assertSame( 'letter', $layout['page']['size'] );
		$this->assertSame( 792, (int) $layout['page']['width'] );
		$this->assertSame( 612, (int) $layout['page']['height'] );

		$GLOBALS['ppcert_test_options']['ppcert_settings'] = [ 'appearance_page_size' => 'a4' ];

		$response = $controller->create_template( new WP_REST_Request( [] ) );
		$layout   = $response->get_data()['layout'];

		$this->assertSame( 'a4', $layout['page']['size'] );
		$this->assertSame( 842, (int) $layout['page']['width'] );
	}

	/**
	 * Letter starters register alongside A4 with the -letter slug.
	 *
	 * @return void
	 */
	public function test_letter_starters_register() {
		$starters = PressPrimer_Certificate_Template::get_starters();

		foreach ( [ 'formal', 'modern', 'playful' ] as $design ) {
			foreach ( [ 'landscape', 'portrait' ] as $orientation ) {
				$base = "starter-{$design}-{$orientation}";

				$this->assertArrayHasKey( $base, $starters );
				$this->assertArrayHasKey( $base . '-letter', $starters );
				$this->assertSame( 'letter', $starters[ $base . '-letter' ]['layout']['page']['size'] );
				$this->assertSame(
					$starters[ $base ]['label'],
					$starters[ $base . '-letter' ]['label'],
					'Variants share the design label'
				);
				$this->assertSame(
					$starters[ $base ]['color_roles'],
					$starters[ $base . '-letter' ]['color_roles'],
					'Variants share color roles'
				);
			}
		}
	}

	/**
	 * Backdated manual issuance stores the earned date (local noon in
	 * UTC); today issues at the current moment; future dates reject.
	 *
	 * @return void
	 */
	public function test_manual_issue_earned_date() {
		$layout = [
			'layout_schema_version' => 1,
			'page'                  => [
				'size'        => 'letter',
				'orientation' => 'landscape',
				'width'       => 792,
				'height'      => 612,
			],
			'background'            => [ 'color' => '#ffffff' ],
			'elements'              => [],
		];

		$template_id = $this->wpdb->seed_row(
			'wp_ppcert_templates',
			[
				'uuid'                  => 'tpl-earned',
				'title'                 => 'Earned Date Test',
				'status'                => 'published',
				'author_id'             => 1,
				'page_size'             => 'letter',
				'orientation'           => 'landscape',
				'layout_schema_version' => 1,
				'layout_json'           => wp_json_encode( $layout ),
				'updated_at'            => '2026-07-01 00:00:00',
				'deleted_at'            => null,
			]
		);

		$controller = new PressPrimer_Certificate_REST_Certificates_Controller();

		// Backdated: stored as that date (noon local -> UTC).
		$response = $controller->issue(
			new WP_REST_Request(
				[
					'template_id'  => $template_id,
					'recipient_id' => 7,
					'earned_date'  => '2026-01-15',
				]
			)
		);

		$this->assertSame( 201, $response->get_status() );

		$rows = $this->wpdb->rows( 'wp_ppcert_certificates' );
		$this->assertStringStartsWith( '2026-01-15', $rows[0]['issued_at'] );

		// Future dates reject with no row.
		$future = $controller->issue(
			new WP_REST_Request(
				[
					'template_id'  => $template_id,
					'recipient_id' => 7,
					'earned_date'  => gmdate( 'Y-m-d', time() + 5 * DAY_IN_SECONDS ),
					'force'        => true,
				]
			)
		);

		$this->assertInstanceOf( WP_Error::class, $future );
		$this->assertSame( 'ppcert_invalid_earned_date', $future->get_error_code() );
		$this->assertCount( 1, $this->wpdb->rows( 'wp_ppcert_certificates' ) );

		// Today: stamps the current moment, not midnight.
		$today = $controller->issue(
			new WP_REST_Request(
				[
					'template_id'  => $template_id,
					'recipient_id' => 7,
					'earned_date'  => gmdate( 'Y-m-d' ),
					'force'        => true,
				]
			)
		);

		$this->assertSame( 201, $today->get_status() );

		$rows = $this->wpdb->rows( 'wp_ppcert_certificates' );
		$this->assertSame( gmdate( 'Y-m-d H:i' ), substr( $rows[1]['issued_at'], 0, 16 ) );
	}
}
