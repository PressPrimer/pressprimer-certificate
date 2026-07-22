<?php
/**
 * Templates REST controller
 *
 * Designer-facing template routes under ppcert/v1.
 *
 * @package PressPrimer_Certificate
 * @subpackage API
 * @since 1.0.0
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Templates REST controller class
 *
 * Prompt 3.1 scope: list, create (from starter or blank), and load.
 * Save (PUT with the validator-rebuilt response), preview, and trash
 * arrive in Prompt 3.6 (Feature 001 TR-003).
 *
 * Every route requires ppcert_manage_templates (+ the REST nonce via
 * cookie auth). Inbound layouts pass through the PHP validator - the
 * only class that sanitizes layout documents.
 *
 * @since 1.0.0
 */
class PressPrimer_Certificate_REST_Templates_Controller {

	/**
	 * Initialize the controller
	 *
	 * @since 1.0.0
	 */
	public function init() {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	/**
	 * Register routes
	 *
	 * @since 1.0.0
	 */
	public function register_routes() {
		register_rest_route(
			'ppcert/v1',
			'/templates',
			[
				[
					'methods'             => 'GET',
					'callback'            => [ $this, 'list_templates' ],
					'permission_callback' => [ $this, 'can_manage' ],
				],
				[
					'methods'             => 'POST',
					'callback'            => [ $this, 'create_template' ],
					'permission_callback' => [ $this, 'can_manage' ],
					'args'                => [
						'starter' => [
							'sanitize_callback' => 'sanitize_key',
						],
						'title'   => [
							'sanitize_callback' => 'sanitize_text_field',
						],
					],
				],
			]
		);

		register_rest_route(
			'ppcert/v1',
			'/templates/(?P<id>\d+)',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_template' ],
				'permission_callback' => [ $this, 'can_manage' ],
			]
		);
	}

	/**
	 * Capability check for every templates route
	 *
	 * @since 1.0.0
	 *
	 * @return bool
	 */
	public function can_manage() {
		return current_user_can( PressPrimer_Certificate_Capabilities::CAP_MANAGE_TEMPLATES );
	}

	/**
	 * GET /templates - list for the admin (no layout payloads)
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response
	 */
	public function list_templates( $request ) {
		$items = [];

		foreach ( PressPrimer_Certificate_Template::get_all() as $row ) {
			$items[] = self::summary( $row );
		}

		return new WP_REST_Response( $items, 200 );
	}

	/**
	 * POST /templates - create from a starter or blank (FR-001)
	 *
	 * Clones the starter's layout into a new row (is_starter = 0) and
	 * returns the full template so the designer opens immediately.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_template( $request ) {
		$starter_slug = (string) $request->get_param( 'starter' );
		$title        = (string) $request->get_param( 'title' );

		if ( '' !== $starter_slug ) {
			$starters = PressPrimer_Certificate_Template::get_starters();

			if ( ! isset( $starters[ $starter_slug ] ) ) {
				return new WP_Error(
					'ppcert_unknown_starter',
					__( 'Unknown starter template.', 'pressprimer-certificate' ),
					[ 'status' => 400 ]
				);
			}

			$layout = $starters[ $starter_slug ]['layout'];

			if ( '' === $title ) {
				$title = $starters[ $starter_slug ]['label'];
			}
		} else {
			$layout = self::blank_layout();

			if ( '' === $title ) {
				$title = __( 'Untitled Certificate', 'pressprimer-certificate' );
			}
		}

		// Layout documents only enter storage validator-clean.
		$clean = PressPrimer_Certificate_Layout_Validator::validate( $layout );

		if ( is_wp_error( $clean ) ) {
			$clean->add_data( [ 'status' => 500 ] );
			return $clean;
		}

		$template_id = PressPrimer_Certificate_Template::create(
			[
				'title'     => $title,
				'layout'    => $clean,
				'author_id' => get_current_user_id(),
				'status'    => 'draft',
			]
		);

		if ( is_wp_error( $template_id ) ) {
			$template_id->add_data( [ 'status' => 500 ] );
			return $template_id;
		}

		$row = PressPrimer_Certificate_Template::get( $template_id );

		return new WP_REST_Response( self::full( $row ), 201 );
	}

	/**
	 * GET /templates/{id} - load for the designer
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_template( $request ) {
		$row = PressPrimer_Certificate_Template::get( absint( $request->get_param( 'id' ) ) );

		if ( ! $row ) {
			return new WP_Error(
				'ppcert_template_not_found',
				__( 'Template not found.', 'pressprimer-certificate' ),
				[ 'status' => 404 ]
			);
		}

		return new WP_REST_Response( self::full( $row ), 200 );
	}

	/**
	 * List-item shape (no layout payload)
	 *
	 * @since 1.0.0
	 *
	 * @param object $row Hydrated template row.
	 * @return array
	 */
	private static function summary( $row ) {
		return [
			'id'          => (int) $row->id,
			'title'       => (string) $row->title,
			'status'      => (string) $row->status,
			'page_size'   => (string) $row->page_size,
			'orientation' => (string) $row->orientation,
			'updated_at'  => str_replace( ' ', 'T', (string) $row->updated_at ) . 'Z',
		];
	}

	/**
	 * Full template shape (designer load)
	 *
	 * @since 1.0.0
	 *
	 * @param object $row Hydrated template row.
	 * @return array
	 */
	private static function full( $row ) {
		$data           = self::summary( $row );
		$data['layout'] = $row->layout;

		return $data;
	}

	/**
	 * The blank layout (the low-emphasis "Start blank" card)
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	private static function blank_layout() {
		return [
			'layout_schema_version' => 1,
			'page'                  => [
				'size'        => 'a4',
				'orientation' => 'landscape',
				'width'       => 842,
				'height'      => 595,
			],
			'background'            => [
				'color'         => '#ffffff',
				'attachment_id' => 0,
			],
			'elements'              => [],
		];
	}
}
