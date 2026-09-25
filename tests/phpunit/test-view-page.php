<?php
/**
 * Certificate view page tests (Feature 005 FR-002/FR-003, Prompt 4.6)
 *
 * The /certificate/{credential_id}/ content builder across issued,
 * revoked, and expired states; preview PNG caching; the text-card
 * fallback on render failure; and the rewrite registration.
 *
 * @package PressPrimer_Certificate
 * @subpackage Tests
 * @since 1.0.0
 */

use PHPUnit\Framework\TestCase;

/**
 * View page test case
 *
 * @since 1.0.0
 */
class Test_View_Page extends TestCase {

	/**
	 * Credentials whose cached previews need cleanup.
	 *
	 * @var string[]
	 */
	private $preview_credentials = [];

	/**
	 * Reset state.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		ppcert_tests_reset_hooks();
		ppcert_tests_reset_wpdb();
		PressPrimer_Certificate_View_Page::reset();

		$GLOBALS['ppcert_test_options']  = [];
		$GLOBALS['ppcert_test_rewrites'] = [];
		$GLOBALS['ppcert_test_users']    = [
			7 => (object) [
				'ID'           => 7,
				'display_name' => 'Dana Whitfield',
				'user_email'   => 'dana@example.test',
			],
		];
	}

	/**
	 * Remove cached preview files created during a test.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		foreach ( $this->preview_credentials as $credential ) {
			PressPrimer_Certificate_Preview_Service::delete( $credential );
		}

		$this->preview_credentials = [];
		parent::tearDown();
	}

	/**
	 * Build a hydrated-shape certificate object.
	 *
	 * @param array $overrides Property overrides.
	 * @return object
	 */
	private function certificate( array $overrides = [] ) {
		$snapshot = json_decode(
			(string) file_get_contents( PPCERT_PLUGIN_DIR . 'tests/phpunit/fixtures/sample-document.json' ),
			true
		);

		return (object) array_merge(
			[
				'id'              => 42,
				'credential_id'   => 'CRED00000042',
				'recipient_id'    => 7,
				'template_id'     => 1,
				'template_title'  => 'Completion Award',
				'status'          => 'issued',
				'issued_at'       => '2026-07-01 12:00:00',
				'expires_at'      => null,
				'layout_snapshot' => $snapshot,
				'merge_data'      => [ 'recipient.name' => 'Dana Whitfield' ],
			],
			$overrides
		);
	}

	/**
	 * ppcert_view_page_head (2.0, Feature 2.0-006 FR-004): fires during
	 * head output with the resolved certificate, and never when the
	 * request resolved no view page.
	 *
	 * @return void
	 */
	public function test_head_action_fires_with_resolved_certificate() {
		$fired = [];

		add_action(
			'ppcert_view_page_head',
			static function ( $certificate ) use ( &$fired ) {
				$fired[] = $certificate;
			}
		);

		// No resolved certificate: wp_head passes silently.
		PressPrimer_Certificate_View_Page::fire_head_action();
		$this->assertSame( [], $fired );

		// Resolve one (the request state inject_virtual_page() sets).
		$certificate = $this->certificate();

		$property = new ReflectionProperty( PressPrimer_Certificate_View_Page::class, 'certificate' );
		$property->setAccessible( true );
		$property->setValue( null, $certificate );

		PressPrimer_Certificate_View_Page::fire_head_action();

		$this->assertCount( 1, $fired );
		$this->assertSame( 'CRED00000042', (string) $fired[0]->credential_id );
	}

	/**
	 * The rewrite rule and query var register.
	 *
	 * @return void
	 */
	public function test_rewrite_and_query_var() {
		PressPrimer_Certificate_View_Page::register_rewrites();

		$this->assertArrayHasKey( '^certificate/([A-Za-z0-9\-]+)/?$', $GLOBALS['ppcert_test_rewrites'] );
		$this->assertSame(
			'index.php?ppcert_credential=$matches[1]',
			$GLOBALS['ppcert_test_rewrites']['^certificate/([A-Za-z0-9\-]+)/?$']['query']
		);
		$this->assertSame( 'top', $GLOBALS['ppcert_test_rewrites']['^certificate/([A-Za-z0-9\-]+)/?$']['position'] );

		$vars = PressPrimer_Certificate_View_Page::register_query_var( [ 'p' ] );
		$this->assertContains( 'ppcert_credential', $vars );
	}

	/**
	 * Issued certificate: preview image with alt text, the fact list,
	 * download and verify links, no status banner.
	 *
	 * @return void
	 */
	public function test_issued_certificate_renders_preview_and_actions() {
		$certificate                 = $this->certificate();
		$this->preview_credentials[] = 'CRED00000042';

		$html = PressPrimer_Certificate_View_Page::render_content( $certificate );

		$this->assertStringContainsString( 'ppcert-view__preview', $html );
		$this->assertStringContainsString( 'alt="Certificate preview for Dana Whitfield"', $html );
		$this->assertStringContainsString( 'CRED00000042.png', $html );

		$this->assertStringContainsString( '<dt>Recipient</dt><dd>Dana Whitfield</dd>', $html );
		$this->assertStringContainsString( '<dt>Certificate</dt><dd>Completion Award</dd>', $html );
		$this->assertStringContainsString( 'CRED-0000-0042', $html );

		$this->assertStringContainsString( 'ppcert/v1/certificates/CRED00000042/pdf', $html );
		$this->assertStringContainsString( 'ppcert_id=CRED00000042', $html );

		$this->assertStringNotContainsString( 'ppcert-view__banner', $html );

		// The preview PNG landed in the uploads cache.
		$path = PressPrimer_Certificate_Preview_Service::preview_path( 'CRED00000042' );
		$this->assertFileExists( $path );

		$info = getimagesize( $path );
		$this->assertSame( 'image/png', $info['mime'] );
	}

	/**
	 * A second render reuses the cached PNG instead of re-rendering.
	 *
	 * @return void
	 */
	public function test_preview_is_cached() {
		$certificate                 = $this->certificate();
		$this->preview_credentials[] = 'CRED00000042';

		PressPrimer_Certificate_View_Page::render_content( $certificate );

		$path  = PressPrimer_Certificate_Preview_Service::preview_path( 'CRED00000042' );
		$mtime = filemtime( $path );

		// A cached hit must not touch the file - even with a snapshot
		// that could no longer render.
		$certificate->layout_snapshot = null;
		$html                         = PressPrimer_Certificate_View_Page::render_content( $certificate );

		clearstatcache();
		$this->assertStringContainsString( 'CRED00000042.png', $html );
		$this->assertSame( $mtime, filemtime( $path ) );
	}

	/**
	 * Revoked: notice banner instead of the preview, no download link,
	 * verify link stays.
	 *
	 * @return void
	 */
	public function test_revoked_certificate_hides_preview_and_download() {
		$html = PressPrimer_Certificate_View_Page::render_content(
			$this->certificate( [ 'status' => 'revoked' ] )
		);

		$this->assertStringContainsString( 'ppcert-view__banner--revoked', $html );
		$this->assertStringContainsString( 'This certificate has been revoked.', $html );

		$this->assertStringNotContainsString( 'ppcert-view__preview', $html );
		$this->assertStringNotContainsString( '/pdf', $html );

		$this->assertStringContainsString( 'ppcert_id=CRED00000042', $html );
	}

	/**
	 * Expired: banner plus preview and download (the artifact existed;
	 * verification is where invalidity is authoritative).
	 *
	 * @return void
	 */
	public function test_expired_certificate_keeps_preview_and_download() {
		$this->preview_credentials[] = 'CRED00000042';

		$html = PressPrimer_Certificate_View_Page::render_content(
			$this->certificate( [ 'expires_at' => '2020-01-01 00:00:00' ] )
		);

		$this->assertStringContainsString( 'ppcert-view__banner--expired', $html );
		$this->assertStringContainsString( 'This certificate has expired.', $html );
		$this->assertStringContainsString( 'ppcert-view__preview', $html );
		$this->assertStringContainsString( 'ppcert/v1/certificates/CRED00000042/pdf', $html );

		// A past expiry labels its detail row "Expired", not "Expires".
		$this->assertStringContainsString( '<dt>Expired</dt>', $html );
		$this->assertStringNotContainsString( '<dt>Expires</dt>', $html );
	}

	/**
	 * Render failure: the text-card fallback with the certificate facts,
	 * and the logging action fires.
	 *
	 * @return void
	 */
	public function test_render_failure_falls_back_to_text_card() {
		$captured = [];

		add_action(
			'ppcert_preview_render_failed',
			function ( $error, $certificate_id ) use ( &$captured ) {
				$captured[] = [ $error, $certificate_id ];
			},
			10,
			2
		);

		// An invalid snapshot cannot rasterize.
		$html = PressPrimer_Certificate_View_Page::render_content(
			$this->certificate( [ 'layout_snapshot' => [ 'layout_schema_version' => 1 ] ] )
		);

		$this->assertStringContainsString( 'ppcert-view__card', $html );
		$this->assertStringContainsString( 'Dana Whitfield', $html );
		$this->assertStringContainsString( 'Completion Award', $html );
		$this->assertStringNotContainsString( '<img', $html );

		// Download stays available - the PDF route renders independently.
		$this->assertStringContainsString( 'ppcert/v1/certificates/CRED00000042/pdf', $html );

		$this->assertCount( 1, $captured );
		$this->assertInstanceOf( WP_Error::class, $captured[0][0] );
		$this->assertSame( 42, $captured[0][1] );
	}

	/**
	 * Escaping: an attacker-influenced recipient name renders inert.
	 *
	 * @return void
	 */
	public function test_recipient_name_is_escaped() {
		$html = PressPrimer_Certificate_View_Page::render_content(
			$this->certificate(
				[
					'status'     => 'revoked',
					'merge_data' => [ 'recipient.name' => '<script>alert(1)</script>' ],
				]
			)
		);

		$this->assertStringNotContainsString( '<script>', $html );
		$this->assertStringContainsString( '&lt;script&gt;', $html );
	}

	/**
	 * The recipient name falls back to the live user record when the
	 * snapshot has none.
	 *
	 * @return void
	 */
	public function test_recipient_name_falls_back_to_user_record() {
		$html = PressPrimer_Certificate_View_Page::render_content(
			$this->certificate(
				[
					'status'     => 'revoked',
					'merge_data' => [],
				]
			)
		);

		$this->assertStringContainsString( '<dt>Recipient</dt><dd>Dana Whitfield</dd>', $html );
	}

	/**
	 * The stored certificate name (Feature 1.1-006) replaces the template
	 * title in the details, escaped.
	 *
	 * @return void
	 */
	public function test_stored_certificate_name_leads_the_page() {
		$certificate             = $this->certificate();
		$certificate->merge_data = [
			'recipient.full_name' => 'Dana Whitfield',
			'certificate.title'   => 'Botany 101 <b>Certificate</b>',
		];

		$html = PressPrimer_Certificate_View_Page::render_content( $certificate );

		$this->assertStringContainsString( '<dt>Certificate</dt><dd>Botany 101 &lt;b&gt;Certificate&lt;/b&gt;</dd>', $html );
		$this->assertStringNotContainsString( '<dd>Completion Award</dd>', $html );
	}

	/**
	 * The view page's action links filter (2.0, Feature 2.0-006): added
	 * entries render escaped; malformed entries drop; the default
	 * download/verify pair survives untouched.
	 *
	 * @return void
	 */
	public function test_view_page_actions_filter() {
		$certificate                 = $this->certificate();
		$this->preview_credentials[] = 'CRED00000042';

		add_filter(
			'ppcert_view_page_actions',
			static function ( $actions, $row, $status ) {
				$actions['share'] = [
					'label'   => 'Share on LinkedIn <b>now</b>',
					'url'     => 'https://example.test/share?id=' . $row->credential_id,
					'class'   => 'ppcert-educator-share',
					'new_tab' => true,
				];
				$actions['junk']  = 'not-an-action';
				$actions['empty'] = [ 'label' => '', 'url' => '' ];

				return $actions;
			},
			10,
			3
		);

		$html = PressPrimer_Certificate_View_Page::render_content( $certificate );

		$this->assertStringContainsString( 'ppcert-educator-share', $html );
		$this->assertStringContainsString( 'Share on LinkedIn &lt;b&gt;now&lt;/b&gt;', $html, 'Labels escape at output' );
		$this->assertStringContainsString( 'https://example.test/share?id=CRED00000042', $html );
		$this->assertStringContainsString( 'Download PDF', $html );
		$this->assertStringContainsString( 'Verify this certificate', $html );
		$this->assertStringNotContainsString( 'not-an-action', $html, 'Malformed entries drop' );
	}

	/**
	 * A fake main query for the injector.
	 *
	 * @param string $credential Query var value.
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
	 * The virtual page guard (2.0.1, production report): comments and
	 * pings closed for the stub post only, a zero count, an empty
	 * comments template, no edit link, no admin-bar Edit node - and
	 * real posts on the same page untouched.
	 *
	 * @return void
	 */
	public function test_virtual_page_guard_closes_comments_and_edit_links() {
		PressPrimer_Certificate_Virtual_Page::reset();
		$this->assertFalse( PressPrimer_Certificate_Virtual_Page::is_active() );

		PressPrimer_Certificate_Virtual_Page::guard();
		PressPrimer_Certificate_Virtual_Page::guard();
		$this->assertTrue( PressPrimer_Certificate_Virtual_Page::is_active() );
		$this->assertCount( 1, $GLOBALS['ppcert_test_hooks']['comments_template'], 'Idempotent' );

		$this->assertFalse( apply_filters( 'comments_open', true, 0 ) );
		$this->assertTrue( apply_filters( 'comments_open', true, 12 ), 'Real posts keep their state' );
		$this->assertFalse( apply_filters( 'pings_open', true, 0 ) );
		$this->assertSame( 0, apply_filters( 'get_comments_number', 20, 0 ) );
		$this->assertSame( 3, apply_filters( 'get_comments_number', 3, 12 ) );

		$GLOBALS['post'] = (object) [ 'ID' => 0 ];
		$template        = apply_filters( 'comments_template', '/theme/comments.php' );
		$this->assertSame( PPCERT_PLUGIN_DIR . 'templates/virtual-page-comments.php', $template );
		$this->assertFileExists( $template, 'The empty template ships with the plugin' );
		$this->assertStringNotContainsString( 'comments', strtolower( (string) preg_replace( '/\/\*.*?\*\//s', '', file_get_contents( $template ) ) ), 'Nothing renders from it' );

		$GLOBALS['post'] = (object) [ 'ID' => 12 ];
		$this->assertSame( '/theme/comments.php', apply_filters( 'comments_template', '/theme/comments.php' ), 'A real post keeps the theme template' );
		unset( $GLOBALS['post'] );

		$this->assertSame( '', apply_filters( 'get_edit_post_link', 'https://example.test/wp-admin/post.php?post=0&action=edit', 0 ) );
		$this->assertSame( 'https://example.test/wp-admin/post.php?post=12&action=edit', apply_filters( 'get_edit_post_link', 'https://example.test/wp-admin/post.php?post=12&action=edit', 12 ) );

		$bar = new class() {
			public $removed = [];
			public function remove_node( $id ) {
				$this->removed[] = $id;
			}
		};
		$GLOBALS['ppcert_test_queried_object_id'] = 0;
		PressPrimer_Certificate_Virtual_Page::remove_edit_node( $bar );
		$this->assertSame( [ 'edit' ], $bar->removed );

		$GLOBALS['ppcert_test_queried_object_id'] = 12;
		PressPrimer_Certificate_Virtual_Page::remove_edit_node( $bar );
		$this->assertSame( [ 'edit' ], $bar->removed, 'A real singular keeps its Edit node' );
		unset( $GLOBALS['ppcert_test_queried_object_id'] );

		PressPrimer_Certificate_Virtual_Page::reset();
	}

	/**
	 * Injecting the certificate stub arms the guard for the request.
	 *
	 * @return void
	 */
	public function test_inject_virtual_page_arms_the_guard() {
		PressPrimer_Certificate_Virtual_Page::reset();

		$GLOBALS['wpdb']->seed_row(
			PressPrimer_Certificate_Certificate::table(),
			[
				'uuid'                 => 'view-guard-1',
				'credential_id'        => 'CRED00000042',
				'recipient_id'         => 7,
				'template_id'          => 1,
				'status'               => 'issued',
				'issued_at'            => '2026-06-12 00:00:00',
				'expires_at'           => null,
				'layout_snapshot_json' => wp_json_encode( [ 'page' => [], 'elements' => [] ] ),
				'merge_data_json'      => wp_json_encode( [] ),
			]
		);

		$query = $this->fake_query( 'CRED00000042' );
		$posts = PressPrimer_Certificate_View_Page::inject_virtual_page( [], $query );

		$this->assertCount( 1, $posts );
		$this->assertSame( 0, (int) $posts[0]->ID, 'The stub post has no id' );
		$this->assertSame( 'closed', $posts[0]->comment_status );
		$this->assertTrue( $query->is_page );
		$this->assertTrue( PressPrimer_Certificate_Virtual_Page::is_active(), 'The injector arms the guard' );
		$this->assertArrayHasKey( 'comments_template', $GLOBALS['ppcert_test_hooks'] );
		$this->assertArrayHasKey( 'admin_bar_menu', $GLOBALS['ppcert_test_hooks'] );

		PressPrimer_Certificate_Virtual_Page::reset();
	}
}
