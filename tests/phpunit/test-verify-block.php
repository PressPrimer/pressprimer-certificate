<?php
/**
 * Verify block tests (shortcode/block parity rule, Prompt 4.6)
 *
 * The pressprimer-certificate/verify block renders through the same
 * PHP path as [ppcert_verify], the block category registers, and the
 * block registration declares the dynamic render callback.
 *
 * @package PressPrimer_Certificate
 * @subpackage Tests
 * @since 1.0.0
 */

use PHPUnit\Framework\TestCase;

/**
 * Verify block test case
 *
 * @since 1.0.0
 */
class Test_Verify_Block extends TestCase {

	/**
	 * Reset state.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		ppcert_tests_reset_hooks();
		ppcert_tests_reset_transients();
		ppcert_tests_reset_wpdb();

		$GLOBALS['ppcert_test_options']   = [];
		$GLOBALS['ppcert_test_localized'] = [];
		$GLOBALS['ppcert_test_blocks']    = [];
		unset( $_GET['ppcert_id'] );
	}

	/**
	 * The block renderer wraps the shortcode renderer: same form, same
	 * aria-live region, plus the block wrapper class.
	 *
	 * @return void
	 */
	public function test_block_render_matches_shortcode() {
		$blocks = new PressPrimer_Certificate_Blocks();
		$html   = $blocks->render_verify_block();

		$this->assertStringContainsString( 'wp-block-pressprimer-certificate-verify', $html );

		$shortcode = PressPrimer_Certificate_Verification_Page::render_shortcode();

		$this->assertSame(
			'<div class="wp-block-pressprimer-certificate-verify">' . $shortcode . '</div>',
			$html
		);
	}

	/**
	 * The category registers ahead of core categories.
	 *
	 * @return void
	 */
	public function test_category_registers_first() {
		$blocks = new PressPrimer_Certificate_Blocks();

		$categories = $blocks->register_category(
			[
				[
					'slug'  => 'text',
					'title' => 'Text',
				],
			]
		);

		$this->assertSame( 'pressprimer-certificate', $categories[0]['slug'] );
		$this->assertCount( 2, $categories );
	}

	/**
	 * Registration declares a dynamic block: render callback, no
	 * attributes (the shortcode has none in 1.0 - parity), category and
	 * api_version set.
	 *
	 * @return void
	 */
	public function test_block_registration_shape() {
		if ( ! file_exists( PPCERT_PLUGIN_DIR . 'build/blocks/verify/index.asset.php' ) ) {
			$this->markTestSkipped( 'Block editor bundle not built (run npm run build).' );
		}

		$blocks = new PressPrimer_Certificate_Blocks();
		$blocks->register_blocks();

		$this->assertArrayHasKey( 'pressprimer-certificate/verify', $GLOBALS['ppcert_test_blocks'] );

		$args = $GLOBALS['ppcert_test_blocks']['pressprimer-certificate/verify'];

		$this->assertSame( 3, $args['api_version'] );
		$this->assertSame( 'pressprimer-certificate', $args['category'] );
		$this->assertIsCallable( $args['render_callback'] );
		$this->assertArrayNotHasKey( 'attributes', $args );
	}
}
