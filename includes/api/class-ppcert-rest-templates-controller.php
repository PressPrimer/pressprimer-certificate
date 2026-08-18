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
				[
					'methods'             => 'GET',
					'callback'            => [ $this, 'get_template' ],
					'permission_callback' => [ $this, 'can_manage' ],
				],
				[
					'methods'             => 'PUT',
					'callback'            => [ $this, 'update_template' ],
					'permission_callback' => [ $this, 'can_manage' ],
				],
				[
					'methods'             => 'DELETE',
					'callback'            => [ $this, 'trash_template' ],
					'permission_callback' => [ $this, 'can_manage' ],
				],
			]
		);

		register_rest_route(
			'ppcert/v1',
			'/templates/(?P<id>\d+)/preview',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'preview_template' ],
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

			// Brand the clone: the Appearance color selections substitute
			// the starter's role-mapped colors (shapes take primary/accent,
			// text and QR ink take the text color), and the default
			// signature/logo fill the starter's image slots.
			$layout = PressPrimer_Certificate_Appearance_Service::apply_brand_colors(
				$layout,
				$starters[ $starter_slug ]['color_roles']
			);
			$layout = PressPrimer_Certificate_Appearance_Service::apply_image_slots(
				$layout,
				$starters[ $starter_slug ]['image_slots']
			);

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
	 * PUT /templates/{id} - save layout / title / status (FR-007)
	 *
	 * The submitted layout runs through the validator; the response
	 * carries the REBUILT document and the client adopts it verbatim -
	 * whatever the client sent, storage and canvas end up identical to
	 * the validator's output. An expected_updated_at mismatch returns
	 * 409 so the designer can warn before overwriting another session's
	 * work (force=true overrides).
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_template( $request ) {
		$row = PressPrimer_Certificate_Template::get( absint( $request->get_param( 'id' ) ) );

		if ( ! $row ) {
			return new WP_Error(
				'ppcert_template_not_found',
				__( 'Template not found.', 'pressprimer-certificate' ),
				[ 'status' => 404 ]
			);
		}

		// Conflict gate before any mutation.
		$expected = (string) $request->get_param( 'expected_updated_at' );
		$current  = str_replace( ' ', 'T', (string) $row->updated_at ) . 'Z';

		if ( '' !== $expected && $expected !== $current && ! $request->get_param( 'force' ) ) {
			return new WP_Error(
				'ppcert_template_conflict',
				__( 'This template was changed elsewhere since you opened it.', 'pressprimer-certificate' ),
				[
					'status'     => 409,
					'updated_at' => $current,
				]
			);
		}

		$args = [];

		$raw_layout = $request->get_param( 'layout' );

		if ( null !== $raw_layout ) {
			if ( ! is_array( $raw_layout ) ) {
				return new WP_Error(
					'ppcert_invalid_layout',
					__( 'The layout document must be an object.', 'pressprimer-certificate' ),
					[ 'status' => 400 ]
				);
			}

			// The single sanitization path for layout documents: every
			// submitted field is rebuilt against the schema.
			$clean = PressPrimer_Certificate_Layout_Validator::validate( $raw_layout );

			if ( is_wp_error( $clean ) ) {
				$clean->add_data( [ 'status' => 400 ] );
				return $clean;
			}

			$args['layout'] = $clean;
		}

		$title = $request->get_param( 'title' );
		if ( null !== $title ) {
			$args['title'] = (string) $title;
		}

		$status = $request->get_param( 'status' );
		if ( null !== $status ) {
			if ( ! in_array( $status, [ 'draft', 'published', 'archived' ], true ) ) {
				return new WP_Error(
					'ppcert_invalid_status',
					__( 'Status must be draft, published, or archived.', 'pressprimer-certificate' ),
					[ 'status' => 400 ]
				);
			}

			$args['status'] = $status;
		}

		$settings = $request->get_param( 'settings' );
		if ( null !== $settings ) {
			if ( ! is_array( $settings ) ) {
				return new WP_Error(
					'ppcert_invalid_settings',
					__( 'Template settings must be an object.', 'pressprimer-certificate' ),
					[ 'status' => 400 ]
				);
			}

			// Sanitized field by field in the model (validity_months).
			$args['settings'] = $settings;
		}

		$updated = PressPrimer_Certificate_Template::update( (int) $row->id, $args );

		if ( is_wp_error( $updated ) ) {
			$updated->add_data( [ 'status' => 500 ] );
			return $updated;
		}

		return new WP_REST_Response( self::full( $updated ), 200 );
	}

	/**
	 * DELETE /templates/{id} - move to trash (soft delete)
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function trash_template( $request ) {
		$result = PressPrimer_Certificate_Template::trash( absint( $request->get_param( 'id' ) ) );

		if ( is_wp_error( $result ) ) {
			$result->add_data( [ 'status' => 404 ] );
			return $result;
		}

		return new WP_REST_Response( [ 'trashed' => true ], 200 );
	}

	/**
	 * POST /templates/{id}/preview - sample-data PDF (FR-007)
	 *
	 * Renders the submitted layout (or the saved one) with registry
	 * sample values through the same pipeline issuance uses - no
	 * separate preview renderer. Returns a short-lived URL.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function preview_template( $request ) {
		$row = PressPrimer_Certificate_Template::get( absint( $request->get_param( 'id' ) ) );

		if ( ! $row ) {
			return new WP_Error(
				'ppcert_template_not_found',
				__( 'Template not found.', 'pressprimer-certificate' ),
				[ 'status' => 404 ]
			);
		}

		$raw_layout = $request->get_param( 'layout' );
		$layout     = is_array( $raw_layout ) ? $raw_layout : $row->layout;

		$clean = PressPrimer_Certificate_Layout_Validator::validate( (array) $layout );

		if ( is_wp_error( $clean ) ) {
			$clean->add_data( [ 'status' => 400 ] );
			return $clean;
		}

		// Registry samples as merge data (FR-004: the designer never
		// hard-codes samples).
		$merge_data = [];

		foreach ( PressPrimer_Certificate_Merge_Field_Registry::get_fields( 'designer' ) as $key => $field ) {
			$merge_data[ $key ] = (string) $field['sample'];
		}

		$sample_credential = isset( $merge_data['certificate.credential_id'] )
			? preg_replace( '/[^A-Z0-9]/', '', strtoupper( $merge_data['certificate.credential_id'] ) )
			: 'SAMPLE000000';

		$renderer = new PressPrimer_Certificate_PDF_Renderer();
		$path     = $renderer->render_pdf(
			$clean,
			$merge_data,
			[
				'context'        => 'preview',
				'credential_id'  => $sample_credential,
				'certificate_id' => 0,
				'title'          => (string) $row->title,
			]
		);

		if ( is_wp_error( $path ) ) {
			$path->add_data( [ 'status' => 500 ] );
			return $path;
		}

		$stored = self::store_preview( $path, (int) $row->id );

		if ( is_wp_error( $stored ) ) {
			return $stored;
		}

		return new WP_REST_Response( $stored, 200 );
	}

	/**
	 * Move a rendered preview into the short-lived previews directory
	 *
	 * Files land in uploads/ppcert-previews/ under unguessable names;
	 * anything older than an hour is swept on each new preview.
	 *
	 * @since 1.0.0
	 *
	 * @param string $temp_path Rendered temp file (consumed).
	 * @param int    $template_id Template id (filename hint only).
	 * @return array|WP_Error { url }.
	 */
	private static function store_preview( $temp_path, $template_id ) {
		$uploads = wp_upload_dir();

		if ( ! empty( $uploads['error'] ) ) {
			wp_delete_file( $temp_path );

			return new WP_Error(
				'ppcert_preview_storage',
				__( 'The uploads directory is not writable.', 'pressprimer-certificate' ),
				[ 'status' => 500 ]
			);
		}

		$dir = trailingslashit( $uploads['basedir'] ) . 'ppcert-previews';

		if ( ! wp_mkdir_p( $dir ) ) {
			wp_delete_file( $temp_path );

			return new WP_Error(
				'ppcert_preview_storage',
				__( 'The preview directory could not be created.', 'pressprimer-certificate' ),
				[ 'status' => 500 ]
			);
		}

		if ( ! file_exists( $dir . '/index.html' ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Empty directory-listing guard file, standard plugin pattern.
			file_put_contents( $dir . '/index.html', '' );
		}

		// Sweep stale previews (older than an hour).
		foreach ( (array) glob( $dir . '/preview-*.pdf' ) as $old ) {
			if ( filemtime( $old ) < time() - HOUR_IN_SECONDS ) {
				wp_delete_file( $old );
			}
		}

		$name   = sprintf( 'preview-%d-%s.pdf', $template_id, wp_generate_password( 20, false, false ) );
		$target = $dir . '/' . $name;

		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.rename_rename -- Moving our own freshly rendered temp file on the local filesystem; WP_Filesystem is not initialized in REST context and failure is handled below.
		if ( ! @rename( $temp_path, $target ) ) {
			wp_delete_file( $temp_path );

			return new WP_Error(
				'ppcert_preview_storage',
				__( 'The preview file could not be stored.', 'pressprimer-certificate' ),
				[ 'status' => 500 ]
			);
		}

		return [
			'url' => trailingslashit( $uploads['baseurl'] ) . 'ppcert-previews/' . $name,
		];
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
		$data             = self::summary( $row );
		$data['layout']   = $row->layout;
		$data['settings'] = isset( $row->settings ) && is_array( $row->settings ) ? $row->settings : [];

		return $data;
	}

	/**
	 * The blank layout (the low-emphasis "Start blank" card)
	 *
	 * Landscape at the Appearance default certificate size (Letter
	 * unless the site chose A4 - Phase 5B item 7).
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	private static function blank_layout() {
		$size = PressPrimer_Certificate_Appearance_Service::get()['page_size'];
		$dims = 'a4' === $size ? [ 842, 595 ] : [ 792, 612 ];

		return [
			'layout_schema_version' => PressPrimer_Certificate_Layout_Validator::SCHEMA_VERSION,
			'page'                  => [
				'size'        => $size,
				'orientation' => 'landscape',
				'width'       => $dims[0],
				'height'      => $dims[1],
			],
			'background'            => [
				'color'         => '#ffffff',
				'attachment_id' => 0,
			],
			'elements'              => [],
		];
	}
}
