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
 * 1.0 registers two blocks: pressprimer-certificate/verify
 * ([ppcert_verify]) and pressprimer-certificate/my-certificates
 * ([ppcert_my_certificates] - restored to free 1.0 on 2026-07-26;
 * the Educator "wallet" means wallet-SIZED printable variants). 2.0
 * adds pressprimer-certificate/certificate-link
 * ([ppcert_certificate_link], Feature 2.0-008) - the first block with
 * attributes, each mirroring a shortcode attribute.
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
					// The branding map's name (2.0, Enterprise white-label).
					'title' => PressPrimer_Certificate_Admin::branding()['name'],
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
		$this->register_certificate_link_block();
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

	/**
	 * Trigger types the Certificate Link block offers as source types
	 *
	 * Registered types with selectable sources, as [ id, label ] with the
	 * label "Integration · Short label" (or the plain label when the
	 * two match). Value-only types have nothing a link could scope to.
	 *
	 * @since 2.0.0
	 *
	 * @return array<int, array{id: string, label: string}>
	 */
	public static function certificate_link_trigger_types() {
		$options = [];

		if ( ! class_exists( 'PressPrimer_Certificate_Trigger_Registry' ) ) {
			return $options;
		}

		foreach ( PressPrimer_Certificate_Trigger_Registry::get_types() as $type ) {
			if ( empty( $type['id'] ) || ( isset( $type['has_sources'] ) && false === $type['has_sources'] ) ) {
				continue;
			}

			$integration = isset( $type['integration'] ) ? (string) $type['integration'] : '';
			$short       = isset( $type['short_label'] ) ? (string) $type['short_label'] : ( isset( $type['label'] ) ? (string) $type['label'] : (string) $type['id'] );

			$options[] = [
				'id'    => (string) $type['id'],
				'label' => '' !== $integration && $integration !== $short ? $integration . ' · ' . $short : $short,
			];
		}

		return $options;
	}

	/**
	 * Localize the Certificate Link editor data
	 *
	 * Runs on enqueue_block_editor_assets so the trigger registry is
	 * complete (integrations register on ppcert_loaded, after block
	 * registration). Published templates feed the Template select; the
	 * trigger types feed the Source type select (the block's source_type
	 * is a trigger type id).
	 *
	 * @since 2.0.0
	 */
	public function localize_certificate_link_block() {
		wp_localize_script(
			'ppcert-certificate-link-block-editor',
			'ppcert_certificate_link_block_data',
			[
				'templates'    => function_exists( 'ppcert_get_templates' ) ? ppcert_get_templates( [ 'status' => 'published' ] ) : [],
				'triggerTypes' => self::certificate_link_trigger_types(),
			]
		);
	}

	/**
	 * Register the Certificate Link block
	 *
	 * The [ppcert_certificate_link] equivalent (Feature 2.0-008). Every
	 * attribute here maps to a shortcode attribute: `source` +
	 * `sourceId` fold into the shortcode's `source` (current / a post id
	 * / none); `newTab` is nullable so the per-action default applies
	 * until an author decides. The templates select is localized from
	 * ppcert_get_templates() - a small list, no REST call and no
	 * capability mismatch for editors.
	 *
	 * @since 2.0.0
	 */
	private function register_certificate_link_block() {
		$asset_file = PPCERT_PLUGIN_DIR . 'build/blocks/certificate-link/index.asset.php';

		if ( ! file_exists( $asset_file ) ) {
			return;
		}

		$asset = require $asset_file;

		wp_register_script(
			'ppcert-certificate-link-block-editor',
			PPCERT_PLUGIN_URL . 'build/blocks/certificate-link/index.js',
			$asset['dependencies'],
			$asset['version'],
			true
		);

		// Editor data is localized when the editor loads, not here:
		// blocks register before ppcert_loaded fires, so at this point
		// no integration has attached its trigger types yet and the
		// Source type list would always be empty.
		add_action( 'enqueue_block_editor_assets', [ $this, 'localize_certificate_link_block' ] );

		register_block_type(
			'pressprimer-certificate/certificate-link',
			[
				'api_version'     => 3,
				'title'           => __( 'Certificate Link', 'pressprimer-certificate' ),
				'description'     => __( "A button to the logged-in learner's certificate for this course, lesson, quiz, or assignment. Shows only once it is earned.", 'pressprimer-certificate' ),
				'category'        => 'pressprimer-certificate',
				'icon'            => 'admin-links',
				'supports'        => [
					'html'  => false,
					'align' => true,
				],
				'attributes'      => [
					'source'     => [
						'type'    => 'string',
						'default' => 'current',
					],
					'sourceId'   => [
						'type'    => 'integer',
						'default' => 0,
					],
					'sourceType' => [
						'type'    => 'string',
						'default' => '',
					],
					'template'   => [
						'type'    => 'integer',
						'default' => 0,
					],
					'action'     => [
						'type'    => 'string',
						'default' => 'download',
					],
					'text'       => [
						'type'    => 'string',
						'default' => '',
					],
					'message'    => [
						'type'    => 'string',
						'default' => '',
					],
					'style'      => [
						'type'    => 'string',
						'default' => 'button',
					],
					'newTab'     => [
						'type'    => [ 'boolean', 'null' ],
						'default' => null,
					],
				],
				'editor_script'   => 'ppcert-certificate-link-block-editor',
				'render_callback' => [ $this, 'render_certificate_link_block' ],
			]
		);
	}

	/**
	 * Render the Certificate Link block
	 *
	 * Maps block attributes onto the shortcode attributes and wraps the
	 * shortcode handler. An empty shortcode result stays empty - the
	 * block adds no wrapper when there is nothing to show (FR-002).
	 *
	 * @since 2.0.0
	 *
	 * @param array $attributes Block attributes.
	 * @return string Rendered block HTML, or ''.
	 */
	public function render_certificate_link_block( $attributes = [] ) {
		$attributes = is_array( $attributes ) ? $attributes : [];

		$source = isset( $attributes['source'] ) ? sanitize_key( (string) $attributes['source'] ) : 'current';

		if ( 'specific' === $source ) {
			$source = isset( $attributes['sourceId'] ) ? (string) absint( $attributes['sourceId'] ) : '0';
		} elseif ( ! in_array( $source, [ 'current', 'none' ], true ) ) {
			$source = 'current';
		}

		$atts = [
			'source'      => $source,
			'source_type' => isset( $attributes['sourceType'] ) ? (string) $attributes['sourceType'] : '',
			'template'    => isset( $attributes['template'] ) ? absint( $attributes['template'] ) : 0,
			'action'      => isset( $attributes['action'] ) ? (string) $attributes['action'] : 'download',
			'text'        => isset( $attributes['text'] ) ? (string) $attributes['text'] : '',
			'message'     => isset( $attributes['message'] ) ? (string) $attributes['message'] : '',
			'style'       => isset( $attributes['style'] ) ? (string) $attributes['style'] : 'button',
			'new_tab'     => array_key_exists( 'newTab', $attributes ) && null !== $attributes['newTab']
				? ( $attributes['newTab'] ? '1' : '0' )
				: null,
		];

		$inner = PressPrimer_Certificate_Certificate_Link::render_shortcode( $atts );

		if ( '' === $inner ) {
			return '';
		}

		return '<div class="wp-block-pressprimer-certificate-certificate-link">' . $inner . '</div>';
	}
}
