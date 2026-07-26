<?php
/**
 * Gutenberg blocks
 *
 * Block registration for the free plugin. House rule (2026-07-23):
 * every shortcode ships with an equivalent block, and every shortcode
 * attribute with an equivalent block control - the block's
 * render_callback wraps the shortcode renderer so both surfaces share
 * one PHP code path (the Quiz class-ppq-blocks.php pattern).
 *
 * 1.0 registers one block: pressprimer-certificate/verify, the
 * [ppcert_verify] equivalent. (The my-certificates wallet block moved
 * to Educator 2.0 with the wallet - scope decision 2026-07-23.)
 *
 * @package PressPrimer_Certificate
 * @subpackage Blocks
 * @since 1.0.0
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Blocks class
 *
 * @since 1.0.0
 */
class PressPrimer_Certificate_Blocks {

	/**
	 * Initialize: category + block registration
	 *
	 * Called during init (priority 0) from the plugin loader, inside
	 * the window where register_block_type is valid.
	 *
	 * @since 1.0.0
	 */
	public function init() {
		add_filter( 'block_categories_all', [ $this, 'register_category' ] );

		$this->register_blocks();
	}

	/**
	 * Register the PressPrimer Certificate block category
	 *
	 * @since 1.0.0
	 *
	 * @param array $categories Block categories.
	 * @return array
	 */
	public function register_category( $categories ) {
		return array_merge(
			[
				[
					'slug'  => 'pressprimer-certificate',
					'title' => __( 'PressPrimer Certificate', 'pressprimer-certificate' ),
				],
			],
			(array) $categories
		);
	}

	/**
	 * Register all blocks
	 *
	 * @since 1.0.0
	 */
	public function register_blocks() {
		if ( ! function_exists( 'register_block_type' ) ) {
			return;
		}

		$this->register_verify_block();
		$this->register_my_certificates_block();
	}

	/**
	 * Register the verification block
	 *
	 * The [ppcert_verify] equivalent. The shortcode takes no attributes
	 * in 1.0, so the block declares none - when the shortcode grows an
	 * attribute, the block gains the matching control in the same
	 * commit (shortcode/block parity rule).
	 *
	 * @since 1.0.0
	 */
	private function register_verify_block() {
		$asset_file = PPCERT_PLUGIN_DIR . 'build/blocks/verify/index.asset.php';

		if ( ! file_exists( $asset_file ) ) {
			return;
		}

		$asset = require $asset_file;

		wp_register_script(
			'ppcert-verify-block-editor',
			PPCERT_PLUGIN_URL . 'build/blocks/verify/index.js',
			$asset['dependencies'],
			$asset['version'],
			true
		);

		register_block_type(
			'pressprimer-certificate/verify',
			[
				'api_version'     => 3,
				'title'           => __( 'Certificate Verification', 'pressprimer-certificate' ),
				'description'     => __( 'A credential ID lookup form visitors use to verify certificates.', 'pressprimer-certificate' ),
				'category'        => 'pressprimer-certificate',
				'icon'            => 'shield',
				'supports'        => [
					'html'  => false,
					'align' => true,
				],
				'editor_script'   => 'ppcert-verify-block-editor',
				'render_callback' => [ $this, 'render_verify_block' ],
			]
		);
	}

	/**
	 * Render the verification block
	 *
	 * One renderer for both surfaces: the block wraps the shortcode
	 * handler's output (which enqueues the front-end assets itself).
	 *
	 * @since 1.0.0
	 *
	 * @return string Rendered block HTML.
	 */
	public function render_verify_block() {
		return '<div class="wp-block-pressprimer-certificate-verify">'
			. PressPrimer_Certificate_Verification_Page::render_shortcode()
			. '</div>';
	}

	/**
	 * Register the My Certificates block
	 *
	 * The [ppcert_my_certificates] equivalent - the logged-in visitor's
	 * certificate list. No attributes in 1.0, matching the shortcode
	 * (parity rule).
	 *
	 * @since 1.0.0
	 */
	private function register_my_certificates_block() {
		$asset_file = PPCERT_PLUGIN_DIR . 'build/blocks/my-certificates/index.asset.php';

		if ( ! file_exists( $asset_file ) ) {
			return;
		}

		$asset = require $asset_file;

		wp_register_script(
			'ppcert-my-certificates-block-editor',
			PPCERT_PLUGIN_URL . 'build/blocks/my-certificates/index.js',
			$asset['dependencies'],
			$asset['version'],
			true
		);

		register_block_type(
			'pressprimer-certificate/my-certificates',
			[
				'api_version'     => 3,
				'title'           => __( 'My Certificates', 'pressprimer-certificate' ),
				'description'     => __( "Lists the logged-in visitor's earned certificates with verification and download links.", 'pressprimer-certificate' ),
				'category'        => 'pressprimer-certificate',
				'icon'            => 'awards',
				'supports'        => [
					'html'  => false,
					'align' => true,
				],
				'editor_script'   => 'ppcert-my-certificates-block-editor',
				'render_callback' => [ $this, 'render_my_certificates_block' ],
			]
		);
	}

	/**
	 * Render the My Certificates block
	 *
	 * One renderer for both surfaces: the block wraps the shortcode
	 * handler's output (which enqueues the front-end assets itself).
	 *
	 * @since 1.0.0
	 *
	 * @return string Rendered block HTML.
	 */
	public function render_my_certificates_block() {
		return '<div class="wp-block-pressprimer-certificate-my-certificates">'
			. PressPrimer_Certificate_My_Certificates::render_shortcode()
			. '</div>';
	}
}
