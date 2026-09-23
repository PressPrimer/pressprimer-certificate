<?php
/**
 * Certificate link tests (Feature 2.0-008)
 *
 * The [ppcert_certificate_link] shortcode and the
 * pressprimer-certificate/certificate-link block: visibility rules,
 * scope resolution (current post, specific post, explicit type,
 * template mode, precedence), each action's URL and label, styling,
 * new-tab defaults, escaping, the action filter, and block parity.
 *
 * @package PressPrimer_Certificate
 * @subpackage Tests
 * @since 2.0.0
 */

use PHPUnit\Framework\TestCase;

/**
 * Certificate link test case
 *
 * @since 2.0.0
 */
class Test_Certificate_Link extends TestCase {

	/**
	 * The fake wpdb for the current test.
	 *
	 * @var PPCert_Fake_WPDB
	 */
	private $wpdb;

	/**
	 * Per-test seed counter (credentials are digit-only: the normalizer
	 * maps I/L/O to digits, so letters would not round-trip).
	 *
	 * @var int
	 */
	private $seq = 0;

	/**
	 * Reset state; user 7 is logged in; a LearnDash course post 42 and a
	 * quiz post 43 exist; a course trigger type declares sfwd-courses.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		ppcert_tests_reset_hooks();
		$this->wpdb = ppcert_tests_reset_wpdb();

		$this->seq = 0;

		$GLOBALS['ppcert_test_options']           = [];
		$GLOBALS['ppcert_test_current_user']      = 7;
		$GLOBALS['ppcert_test_queried_object_id'] = 0;
		$GLOBALS['ppcert_test_blocks']            = [];
		$GLOBALS['ppcert_test_localized']         = [];
		$GLOBALS['ppcert_test_posts']             = [
			42 => (object) [
				'ID'         => 42,
				'post_type'  => 'sfwd-courses',
				'post_title' => 'Botany 101',
			],
			43 => (object) [
				'ID'         => 43,
				'post_type'  => 'sfwd-quiz',
				'post_title' => 'Botany Final',
			],
		];

		add_filter(
			'ppcert_register_trigger_types',
			static function ( $types ) {
				$types[] = [
					'id'                => 'learndash_course_completed',
					'label'             => 'Course completed',
					'source_post_types' => [ 'sfwd-courses' ],
				];
				$types[] = [
					'id'                => 'learndash_quiz_passed',
					'label'             => 'Quiz passed',
					'source_post_types' => [ 'sfwd-quiz' ],
				];

				return $types;
			}
		);

		$this->wpdb->seed_row(
			PressPrimer_Certificate_Template::table(),
			[
				'id'         => 1,
				'title'      => 'Course Certificate',
				'status'     => 'published',
				'deleted_at' => null,
			]
		);
	}

	/**
	 * Clean globals.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		unset( $GLOBALS['ppcert_test_queried_object_id'], $GLOBALS['ppcert_test_posts'] );
		parent::tearDown();
	}

	/**
	 * Seed a certificate for user 7.
	 *
	 * @param array $overrides Column overrides.
	 * @return int Row id.
	 */
	private function seed_certificate( array $overrides = [] ) {
		$this->seq++;

		return $this->wpdb->seed_row(
			PressPrimer_Certificate_Certificate::table(),
			array_merge(
				[
					'uuid'                 => 'cert-link-' . $this->seq,
					'credential_id'        => self::credential( $this->seq ),
					'template_id'          => 1,
					'recipient_id'         => 7,
					'source_type'          => 'learndash_course_completed',
					'source_ref'           => '42',
					'status'               => 'issued',
					'layout_snapshot_json' => '{"layout_schema_version":1,"elements":[]}',
					'merge_data_json'      => '{}',
					'issued_at'            => '2026-07-' . str_pad( (string) min( 28, $this->seq ), 2, '0', STR_PAD_LEFT ) . ' 12:00:00',
					'expires_at'           => null,
				],
				$overrides
			)
		);
	}

	/**
	 * A digit-only twelve-character credential for a seed number.
	 *
	 * @param int $n Seed number.
	 * @return string
	 */
	private static function credential( $n ) {
		return '7' . str_pad( (string) $n, 11, '0', STR_PAD_LEFT );
	}

	/**
	 * Logged-out visitors get nothing, not even wrapper markup.
	 *
	 * @return void
	 */
	public function test_logged_out_renders_nothing() {
		$this->seed_certificate();
		$GLOBALS['ppcert_test_queried_object_id'] = 42;
		$GLOBALS['ppcert_test_current_user']      = 0;

		$this->assertSame( '', PressPrimer_Certificate_Certificate_Link::render_shortcode() );
	}

	/**
	 * The default scope is the current singular post: a matching
	 * certificate renders the download button (the default action);
	 * nothing matching
	 * renders nothing; outside a singular query nothing renders.
	 *
	 * @return void
	 */
	public function test_current_post_scope() {
		$this->seed_certificate();

		$GLOBALS['ppcert_test_queried_object_id'] = 42;
		$html = PressPrimer_Certificate_Certificate_Link::render_shortcode();

		$this->assertStringContainsString( 'class="ppcert-certificate-link ppcert-certificate-link--download"', $html );
		$this->assertStringContainsString( 'class="ppcert-button-primary"', $html );
		$this->assertStringContainsString( PressPrimer_Certificate_View_Page::pdf_url( self::credential( 1 ) ), $html );
		$this->assertStringContainsString( 'Download your certificate', $html );
		$this->assertStringNotContainsString( 'target=', $html, 'Download opens in the same tab by default' );

		// The quiz page: the certificate belongs to the course, not the quiz.
		$GLOBALS['ppcert_test_queried_object_id'] = 43;
		$this->assertSame( '', PressPrimer_Certificate_Certificate_Link::render_shortcode() );

		// Not a singular query.
		$GLOBALS['ppcert_test_queried_object_id'] = 0;
		$this->assertSame( '', PressPrimer_Certificate_Certificate_Link::render_shortcode() );
	}

	/**
	 * A plain page carrying a PressPrimer quiz block links the learner's
	 * certificate for that quiz with no configuration (Feature 2.0-008
	 * embedded detection); a page with nothing embedded renders nothing.
	 *
	 * @return void
	 */
	public function test_embedded_quiz_on_a_plain_page_resolves() {
		$GLOBALS['ppcert_test_posts'][44] = (object) [
			'ID'           => 44,
			'post_type'    => 'page',
			'post_title'   => 'Ethics quiz',
			'post_content' => '<!-- wp:paragraph --><p>Take the quiz.</p><!-- /wp:paragraph --><!-- wp:pressprimer-quiz/quiz {"quizId":14} /-->',
		];
		$GLOBALS['ppcert_test_posts'][45] = (object) [
			'ID'           => 45,
			'post_type'    => 'page',
			'post_title'   => 'About',
			'post_content' => '<!-- wp:paragraph --><p>Nothing embedded.</p><!-- /wp:paragraph -->',
		];

		$this->seed_certificate(
			[
				'source_type' => 'ppq_quiz',
				'source_ref'  => '14',
			]
		);

		$GLOBALS['ppcert_test_queried_object_id'] = 44;
		$html = PressPrimer_Certificate_Certificate_Link::render_shortcode();

		$this->assertStringContainsString( 'class="ppcert-certificate-link ppcert-certificate-link--download"', $html );
		$this->assertStringContainsString( PressPrimer_Certificate_View_Page::pdf_url( self::credential( 1 ) ), $html );

		$GLOBALS['ppcert_test_queried_object_id'] = 45;
		$this->assertSame( '', PressPrimer_Certificate_Certificate_Link::render_shortcode(), 'A page with nothing embedded resolves no scope.' );

		// An explicit type still wins over detection.
		$GLOBALS['ppcert_test_queried_object_id'] = 44;
		$this->assertSame(
			'',
			PressPrimer_Certificate_Certificate_Link::render_shortcode( [ 'source_type' => 'learndash_course_completed' ] ),
			'An explicit type disables detection and matches only that type.'
		);
	}

	/**
	 * The page's own source outranks what it embeds, and the
	 * ppcert_certificate_link_embedded_sources filter lets integrations
	 * add sources of their own.
	 *
	 * @return void
	 */
	public function test_embedded_sources_precedence_and_filter() {
		// Course post 42 also embeds quiz 14; the learner holds both.
		$GLOBALS['ppcert_test_posts'][42]->post_content = '<!-- wp:pressprimer-quiz/quiz {"quizId":14} /-->';

		$this->seed_certificate(
			[
				'source_type' => 'ppq_quiz',
				'source_ref'  => '14',
			]
		);
		$this->seed_certificate();

		$GLOBALS['ppcert_test_queried_object_id'] = 42;
		$html = PressPrimer_Certificate_Certificate_Link::render_shortcode();

		$this->assertStringContainsString( PressPrimer_Certificate_View_Page::pdf_url( self::credential( 2 ) ), $html, 'The course certificate wins on the course page.' );
		$this->assertStringNotContainsString( PressPrimer_Certificate_View_Page::pdf_url( self::credential( 1 ) ), $html );

		// A third-party integration adds an embedded source through the filter.
		$GLOBALS['ppcert_test_posts'][46] = (object) [
			'ID'           => 46,
			'post_type'    => 'page',
			'post_title'   => 'Webinar',
			'post_content' => '[acme_webinar id="77"]',
		];

		add_filter(
			'ppcert_certificate_link_embedded_sources',
			static function ( $sources, $post ) {
				if ( false !== strpos( (string) $post->post_content, '[acme_webinar' ) ) {
					$sources[] = [
						'type' => 'acme_webinar',
						'ref'  => '77',
					];
				}
				$sources[] = [ 'type' => '', 'ref' => '1' ]; // Half-built entries are dropped.

				return $sources;
			},
			10,
			2
		);

		$this->seed_certificate(
			[
				'source_type' => 'acme_webinar',
				'source_ref'  => '77',
			]
		);

		$GLOBALS['ppcert_test_queried_object_id'] = 46;
		$this->assertStringContainsString( PressPrimer_Certificate_View_Page::pdf_url( self::credential( 3 ) ), PressPrimer_Certificate_Certificate_Link::render_shortcode() );
	}

	/**
	 * A same-numbered object of another family never matches: the type
	 * list comes from the post type. A quiz-type certificate with ref
	 * 42 does not render on course post 42.
	 *
	 * @return void
	 */
	public function test_type_inference_blocks_cross_family_match() {
		$this->seed_certificate( [ 'source_type' => 'learndash_quiz_passed' ] );
		$GLOBALS['ppcert_test_queried_object_id'] = 42;

		$this->assertSame( '', PressPrimer_Certificate_Certificate_Link::render_shortcode() );

		// An unknown post type (no trigger type declares it) resolves nothing.
		$GLOBALS['ppcert_test_posts'][44]         = (object) [
			'ID'        => 44,
			'post_type' => 'page',
		];
		$GLOBALS['ppcert_test_queried_object_id'] = 44;
		$this->assertSame( '', PressPrimer_Certificate_Certificate_Link::render_shortcode( [ 'source' => 'current' ] ) );
	}

	/**
	 * A specific post id scopes regardless of the page; an explicit
	 * source_type disables inference (the PressPrimer Quiz case, whose
	 * sources are not posts).
	 *
	 * @return void
	 */
	public function test_specific_source_and_explicit_type() {
		$this->seed_certificate();
		$this->seed_certificate(
			[
				'source_type'   => 'ppq_quiz',
				'source_ref'    => '9',
				'credential_id' => self::credential( 92 ),
			]
		);

		$html = PressPrimer_Certificate_Certificate_Link::render_shortcode( [ 'source' => '42' ] );
		$this->assertStringContainsString( PressPrimer_Certificate_View_Page::pdf_url( self::credential( 1 ) ), $html );

		$html = PressPrimer_Certificate_Certificate_Link::render_shortcode(
			[
				'source'      => '9',
				'source_type' => 'ppq_quiz',
			]
		);
		$this->assertStringContainsString( PressPrimer_Certificate_View_Page::pdf_url( self::credential( 92 ) ), $html );

		// Ref 9 without a type: post 9 does not exist, so no inference.
		$this->assertSame( '', PressPrimer_Certificate_Certificate_Link::render_shortcode( [ 'source' => '9' ] ) );
	}

	/**
	 * Template mode: `template` alone ignores the source; both set
	 * requires both; `source="none"` without a template renders nothing.
	 *
	 * @return void
	 */
	public function test_template_mode_and_precedence() {
		$this->seed_certificate(
			[
				'template_id'   => 1,
				'source_type'   => 'manual',
				'source_ref'    => null,
				'credential_id' => self::credential( 93 ),
			]
		);
		$GLOBALS['ppcert_test_queried_object_id'] = 0;

		$html = PressPrimer_Certificate_Certificate_Link::render_shortcode( [ 'template' => '1' ] );
		$this->assertStringContainsString( PressPrimer_Certificate_View_Page::pdf_url( self::credential( 93 ) ), $html, 'Template alone: no source scope' );

		$this->assertSame(
			'',
			PressPrimer_Certificate_Certificate_Link::render_shortcode( [ 'template' => '2' ] ),
			'Another template: nothing'
		);

		$GLOBALS['ppcert_test_queried_object_id'] = 42;
		$this->assertSame(
			'',
			PressPrimer_Certificate_Certificate_Link::render_shortcode(
				[
					'template' => '1',
					'source'   => 'current',
				]
			),
			'Both set: the manual certificate has no source, so both cannot match'
		);

		$this->assertSame(
			'',
			PressPrimer_Certificate_Certificate_Link::render_shortcode( [ 'source' => 'none' ] ),
			'No source and no template: nothing, never "any certificate at all"'
		);
	}

	/**
	 * Revoked-only renders nothing; expired renders; the most recent
	 * non-revoked certificate wins.
	 *
	 * @return void
	 */
	public function test_status_rules_and_latest_wins() {
		$GLOBALS['ppcert_test_queried_object_id'] = 42;

		$this->seed_certificate( [ 'status' => 'revoked' ] );
		$this->assertSame( '', PressPrimer_Certificate_Certificate_Link::render_shortcode() );

		$this->seed_certificate(
			[
				'credential_id' => self::credential( 94 ),
				'expires_at'    => '2020-01-01 00:00:00',
				'issued_at'     => '2019-01-01 00:00:00',
			]
		);
		$html = PressPrimer_Certificate_Certificate_Link::render_shortcode();
		$this->assertStringContainsString( PressPrimer_Certificate_View_Page::pdf_url( self::credential( 94 ) ), $html, 'Expired still renders' );

		$this->seed_certificate(
			[
				'credential_id' => self::credential( 95 ),
				'issued_at'     => '2026-08-01 00:00:00',
			]
		);
		$this->seed_certificate(
			[
				'credential_id' => self::credential( 96 ),
				'issued_at'     => '2026-09-01 00:00:00',
				'status'        => 'revoked',
			]
		);
		$html = PressPrimer_Certificate_Certificate_Link::render_shortcode();
		$this->assertStringContainsString( PressPrimer_Certificate_View_Page::pdf_url( self::credential( 95 ) ), $html, 'Newest non-revoked wins over an older one and a newer revoked one' );
	}

	/**
	 * Each action targets its URL with its default label and class;
	 * verify defaults to a new tab; explicit new_tab and text override.
	 *
	 * @return void
	 */
	public function test_actions_labels_and_new_tab() {
		$this->seed_certificate();
		$GLOBALS['ppcert_test_queried_object_id'] = 42;

		$download = PressPrimer_Certificate_Certificate_Link::render_shortcode( [ 'action' => 'download' ] );
		$this->assertStringContainsString( PressPrimer_Certificate_View_Page::pdf_url( self::credential( 1 ) ), $download );
		$this->assertStringContainsString( 'Download your certificate', $download );
		$this->assertStringContainsString( 'ppcert-button-primary', $download );

		$verify = PressPrimer_Certificate_Certificate_Link::render_shortcode( [ 'action' => 'verify' ] );
		$this->assertStringContainsString( ppcert_verification_url( self::credential( 1 ) ), $verify );
		$this->assertStringContainsString( 'Verify your certificate', $verify );
		$this->assertStringContainsString( 'ppcert-button-secondary', $verify );
		$this->assertStringContainsString( 'target="_blank" rel="noopener"', $verify, 'Verify defaults to a new tab' );

		$same_tab = PressPrimer_Certificate_Certificate_Link::render_shortcode(
			[
				'action'  => 'verify',
				'new_tab' => '0',
			]
		);
		$this->assertStringNotContainsString( 'target=', $same_tab );

		$custom = PressPrimer_Certificate_Certificate_Link::render_shortcode(
			[
				'text'    => 'Grab it',
				'new_tab' => 'yes',
			]
		);
		$this->assertStringContainsString( '>Grab it<', $custom );
		$this->assertStringContainsString( 'target="_blank"', $custom );

		$unknown = PressPrimer_Certificate_Certificate_Link::render_shortcode( [ 'action' => 'delete' ] );
		$this->assertStringContainsString( 'ppcert-certificate-link--download', $unknown, 'Unknown actions fall back to the download default' );
	}

	/**
	 * Plain-link style and label escaping.
	 *
	 * @return void
	 */
	public function test_link_style_and_escaping() {
		$this->seed_certificate();
		$GLOBALS['ppcert_test_queried_object_id'] = 42;

		$html = PressPrimer_Certificate_Certificate_Link::render_shortcode(
			[
				'style' => 'link',
				'text'  => '<script>alert(1)</script>Certificate & more',
			]
		);

		$this->assertStringContainsString( 'class="ppcert-certificate-link__link"', $html );
		$this->assertStringNotContainsString( '<script', $html );
		$this->assertStringContainsString( 'Certificate &amp; more', $html );
	}

	/**
	 * An optional message renders the link as a card, with the text
	 * above the button; it never renders without the button, and it
	 * keeps only light inline emphasis.
	 *
	 * @return void
	 */
	public function test_message_renders_a_card_only_with_the_link() {
		$this->seed_certificate();
		$GLOBALS['ppcert_test_queried_object_id'] = 42;

		$html = PressPrimer_Certificate_Certificate_Link::render_shortcode(
			[
				'message' => "Congratulations on <strong>completing</strong> the course!\nDownload it below. <script>alert(1)</script><a href=\"x\">no links</a>",
				'action'  => 'download',
			]
		);

		$this->assertStringStartsWith( '<div class="ppcert-certificate-link ppcert-certificate-link--card ppcert-certificate-link--download">', $html );
		$this->assertStringContainsString( '<p class="ppcert-certificate-link__message">Congratulations on <strong>completing</strong> the course!<br />', $html );
		$this->assertStringContainsString( 'Download it below. alert(1)no links</p>', $html, 'Scripts and links are stripped to their text.' );
		$this->assertStringContainsString( '<span class="ppcert-certificate-link__action"><a', $html );
		$this->assertStringContainsString( 'Download your certificate', $html );

		// No message: the bare inline span, as before.
		$plain = PressPrimer_Certificate_Certificate_Link::render_shortcode();
		$this->assertStringStartsWith( '<span class="ppcert-certificate-link ppcert-certificate-link--download">', $plain );
		$this->assertStringNotContainsString( 'ppcert-certificate-link--card', $plain );

		// A message alone never shows: the quiz page has no certificate.
		$GLOBALS['ppcert_test_queried_object_id'] = 43;
		$this->assertSame( '', PressPrimer_Certificate_Certificate_Link::render_shortcode( [ 'message' => 'Congratulations!' ] ) );

		// Whitespace-only messages count as none.
		$GLOBALS['ppcert_test_queried_object_id'] = 42;
		$this->assertStringNotContainsString( 'ppcert-certificate-link--card', PressPrimer_Certificate_Certificate_Link::render_shortcode( [ 'message' => "  \n " ] ) );
	}

	/**
	 * The action filter can replace the entry or suppress the link.
	 *
	 * @return void
	 */
	public function test_action_filter() {
		$this->seed_certificate();
		$GLOBALS['ppcert_test_queried_object_id'] = 42;

		add_filter(
			'ppcert_certificate_link_action',
			static function ( $action, $certificate, $atts ) {
				$action['label'] = 'Share ' . $certificate->credential_id . ' (' . $atts['action'] . ')';
				$action['url']   = 'https://share.example/' . $certificate->credential_id;

				return $action;
			},
			10,
			3
		);

		$html = PressPrimer_Certificate_Certificate_Link::render_shortcode();
		$this->assertStringContainsString( 'https://share.example/' . self::credential( 1 ), $html );
		$this->assertStringContainsString( 'Share ' . self::credential( 1 ) . ' (download)', $html, 'The filter sees the default action' );

		add_filter(
			'ppcert_certificate_link_action',
			static function () {
				return [];
			},
			20
		);
		$this->assertSame( '', PressPrimer_Certificate_Certificate_Link::render_shortcode() );
	}

	/**
	 * Block parity: registration declares the attributes and the dynamic
	 * render callback; the render maps attributes onto the shortcode
	 * and stays empty when the shortcode is empty.
	 *
	 * @return void
	 */
	public function test_block_registration_and_render() {
		$blocks = new PressPrimer_Certificate_Blocks();
		$blocks->register_blocks();

		if ( ! isset( $GLOBALS['ppcert_test_blocks']['pressprimer-certificate/certificate-link'] ) ) {
			$this->markTestSkipped( 'Block build artifacts are absent; registration is skipped without build/.' );
		}

		$registered = $GLOBALS['ppcert_test_blocks']['pressprimer-certificate/certificate-link'];
		$this->assertSame(
			[ 'source', 'sourceId', 'sourceType', 'template', 'action', 'text', 'message', 'style', 'newTab' ],
			array_keys( $registered['attributes'] ),
			'Every shortcode attribute has a block attribute'
		);
		$this->assertSame( [ $blocks, 'render_certificate_link_block' ], $registered['render_callback'] );
		// Editor data is localized when the editor loads, not at
		// registration: integrations register their trigger types on
		// ppcert_loaded, after blocks register, so a type attached now
		// must still reach the editor.
		$this->assertArrayNotHasKey( 'ppcert_certificate_link_block_data', $GLOBALS['ppcert_test_localized'], 'Nothing is localized at registration.' );

		add_filter(
			'ppcert_register_trigger_types',
			static function ( $types ) {
				$types[] = [
					'id'          => 'ppq_quiz',
					'label'       => 'Quiz passed',
					'integration' => 'PressPrimer Quiz',
					'short_label' => 'Quiz passed',
					'has_sources' => true,
				];
				$types[] = [
					'id'          => 'test_credits',
					'label'       => 'Credits earned',
					'has_sources' => false,
				];
				return $types;
			}
		);

		do_action( 'enqueue_block_editor_assets' );

		$this->assertArrayHasKey( 'ppcert_certificate_link_block_data', $GLOBALS['ppcert_test_localized'] );
		$data = $GLOBALS['ppcert_test_localized']['ppcert_certificate_link_block_data'];
		$this->assertArrayHasKey( 'templates', $data );

		$type_ids = array_column( $data['triggerTypes'], 'id' );
		$this->assertContains( 'ppq_quiz', $type_ids, 'A type registered after block registration reaches the editor.' );
		$this->assertNotContains( 'test_credits', $type_ids, 'Value-only types have no source to link and are left out.' );

		$ppq = $data['triggerTypes'][ array_search( 'ppq_quiz', $type_ids, true ) ];
		$this->assertSame( 'PressPrimer Quiz · Quiz passed', $ppq['label'] );

		$this->seed_certificate();
		$GLOBALS['ppcert_test_queried_object_id'] = 42;

		$html = $blocks->render_certificate_link_block(
			[
				'source' => 'current',
				'action' => 'download',
				'newTab' => true,
			]
		);
		$this->assertStringStartsWith( '<div class="wp-block-pressprimer-certificate-certificate-link">', $html );
		$this->assertStringContainsString( PressPrimer_Certificate_View_Page::pdf_url( self::credential( 1 ) ), $html );
		$this->assertStringContainsString( 'target="_blank"', $html );

		$specific = $blocks->render_certificate_link_block(
			[
				'source'   => 'specific',
				'sourceId' => 42,
			]
		);
		$this->assertStringContainsString( PressPrimer_Certificate_View_Page::pdf_url( self::credential( 1 ) ), $specific, 'The block default is the download' );

		$this->assertSame(
			'',
			$blocks->render_certificate_link_block(
				[
					'source'   => 'specific',
					'sourceId' => 43,
				]
			),
			'Nothing to show: no wrapper either'
		);
	}
}
