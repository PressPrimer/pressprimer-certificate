<?php
/**
 * Template model
 *
 * Read access to the wp_ppcert_templates table.
 *
 * @package PressPrimer_Certificate
 * @subpackage Models
 * @since 1.0.0
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Template model class
 *
 * Minimal in Phase 2: the issuance service needs template loading and
 * status verification. Create/update/list arrive with the templates REST
 * controller (Prompt 3.1); editing a template never alters issued
 * certificates (they carry their own snapshot).
 *
 * @since 1.0.0
 */
class PressPrimer_Certificate_Template {

	/**
	 * Email template mapping keys in settings_json and the row context
	 * each one requires (Decision 005; reminder key since 2.1)
	 *
	 * @since 2.1.0
	 * @var array<string,string>
	 */
	const EMAIL_MAPPING_KEYS = [
		'email_template_id'          => 'issuance',
		'reminder_email_template_id' => 'reminder',
	];

	/**
	 * Settings a duplicate never copies (2.1, Feature 2.1-007 FR-010):
	 * School's program publish switch and slug - a copy must not publish
	 * a second program page at the same address.
	 *
	 * @since 2.1.0
	 * @var string[]
	 */
	const DUPLICATE_EXCLUDED_SETTINGS = [ 'school_program_public', 'school_program_slug' ];

	/**
	 * Settings keys free sanitizes itself (never carried over: an absent
	 * core key on save means it was cleared)
	 *
	 * @since 2.1.0
	 * @var string[]
	 */
	const CORE_SETTINGS_KEYS = [
		'validity_mode',
		'validity_amount',
		'validity_unit',
		'validity_date',
		'certificate_name',
		'email_template_id',
		'reminder_email_template_id',
		'reminders_enabled',
		'reminder_offsets',
	];

	/**
	 * Get the full templates table name
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;

		return $wpdb->prefix . 'ppcert_templates';
	}

	/**
	 * Get all templates (not soft-deleted), newest-updated first
	 *
	 * @since 1.0.0
	 *
	 * @return object[] Hydrated rows.
	 */
	public static function get_all() {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE deleted_at IS NULL ORDER BY updated_at DESC',
				self::table()
			)
		);

		return array_map( [ __CLASS__, 'hydrate' ], (array) $rows );
	}

	/**
	 * Query templates for the admin list: filters, search, pagination
	 *
	 * Fixed-shape sentinel SQL (the Certificate::query pattern): every
	 * filter is present in the statement with a sentinel guard, so the
	 * prepared shape never varies. The integration filter matches
	 * templates owning a trigger whose type is in the CSV list via
	 * FIND_IN_SET (no dynamic placeholder counts).
	 *
	 * @since 1.0.0
	 *
	 * @param array $args {
	 *     @type string     $status        Status filter ('' = all).
	 *     @type string     $search        Title search ('' = none).
	 *     @type string[]   $trigger_types Trigger type ids ([] = all).
	 *     @type string     $issuer        Issuer filter ('' = all, '0' =
	 *                                     site templates, 'N' = issuer N).
	 *                                     Since 2.0 (School contract).
	 *     @type int[]|null $issuer_scope  Visible issuer ids for scoped
	 *                                     callers (null = unscoped; [] =
	 *                                     nothing). Site templates (NULL
	 *                                     issuer) never pass a scope.
	 *                                     Since 2.0 (School contract).
	 *     @type int        $page          1-based page.
	 *     @type int        $per_page     Page size (default 20).
	 * }
	 * @return array { items: object[], total: int }
	 */
	public static function query( array $args = [] ) {
		global $wpdb;

		$status    = isset( $args['status'] ) ? sanitize_key( (string) $args['status'] ) : '';
		$search    = isset( $args['search'] ) ? (string) $args['search'] : '';
		$types     = isset( $args['trigger_types'] ) && is_array( $args['trigger_types'] ) ? $args['trigger_types'] : [];
		$types_csv = implode( ',', array_map( 'sanitize_key', $types ) );
		$per_page  = isset( $args['per_page'] ) && absint( $args['per_page'] ) > 0 ? min( 100, absint( $args['per_page'] ) ) : 20;
		$page      = isset( $args['page'] ) && absint( $args['page'] ) > 0 ? absint( $args['page'] ) : 1;

		// Issuer filter: '' = all, '0' = site templates (NULL), 'N' =
		// issuer N. Compared against COALESCE so the shape stays fixed.
		$issuer = isset( $args['issuer'] ) && '' !== (string) $args['issuer']
			? (string) absint( $args['issuer'] )
			: '';

		// Member scoping (School): null = unscoped; an id list restricts
		// the list to those issuers' templates. Site templates (NULL
		// issuer) are the site's own and do not pass a scope (2026-09-24).
		$scoped    = isset( $args['issuer_scope'] ) && is_array( $args['issuer_scope'] ) ? 1 : 0;
		$scope_csv = $scoped ? implode( ',', array_map( 'absint', $args['issuer_scope'] ) ) : '';

		$search_like = '' !== $search ? '%' . $wpdb->esc_like( $search ) . '%' : '';

		$total = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM %i t WHERE t.deleted_at IS NULL AND ( %s = '' OR t.status = %s ) AND ( %s = '' OR t.title LIKE %s ) AND ( %s = '' OR EXISTS ( SELECT 1 FROM %i tr WHERE tr.template_id = t.id AND FIND_IN_SET( tr.trigger_type, %s ) ) ) AND ( %s = '' OR CAST( COALESCE( t.issuer_id, 0 ) AS CHAR ) = %s ) AND ( %d = 0 OR ( t.issuer_id IS NOT NULL AND FIND_IN_SET( t.issuer_id, %s ) ) )",
				self::table(),
				$status,
				$status,
				$search_like,
				$search_like,
				$types_csv,
				PressPrimer_Certificate_Trigger::table(),
				$types_csv,
				$issuer,
				$issuer,
				$scoped,
				$scope_csv
			)
		);

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT t.* FROM %i t WHERE t.deleted_at IS NULL AND ( %s = '' OR t.status = %s ) AND ( %s = '' OR t.title LIKE %s ) AND ( %s = '' OR EXISTS ( SELECT 1 FROM %i tr WHERE tr.template_id = t.id AND FIND_IN_SET( tr.trigger_type, %s ) ) ) AND ( %s = '' OR CAST( COALESCE( t.issuer_id, 0 ) AS CHAR ) = %s ) AND ( %d = 0 OR ( t.issuer_id IS NOT NULL AND FIND_IN_SET( t.issuer_id, %s ) ) ) ORDER BY t.updated_at DESC LIMIT %d OFFSET %d",
				self::table(),
				$status,
				$status,
				$search_like,
				$search_like,
				$types_csv,
				PressPrimer_Certificate_Trigger::table(),
				$types_csv,
				$issuer,
				$issuer,
				$scoped,
				$scope_csv,
				$per_page,
				( $page - 1 ) * $per_page
			)
		);

		return [
			'items' => array_map( [ __CLASS__, 'hydrate' ], (array) $rows ),
			'total' => $total,
		];
	}

	/**
	 * Duplicate a template
	 *
	 * Copies the design and (since 2.1, Feature 2.1-007 FR-010) the
	 * settings: the certificate name, validity, email choices, reminders,
	 * and addons' settings (an inactive addon's keys carry over as on
	 * save), except School's program publish switch and slug. Triggers
	 * deliberately do NOT copy: the single-trigger rule makes duplication
	 * the reuse path for awarding the same design from a DIFFERENT
	 * trigger, and copying the original trigger would double-award every
	 * completion. The organization follows the duplicating user's default,
	 * as for new templates.
	 *
	 * @since 1.0.0
	 * @since 2.1.0 Settings copy.
	 *
	 * @param int $id        Source template row id.
	 * @param int $author_id Author for the copy.
	 * @return int|WP_Error New template row id.
	 */
	public static function duplicate( $id, $author_id ) {
		global $wpdb;

		$source = self::get( $id );

		if ( ! $source || ! is_array( $source->layout ) ) {
			return new WP_Error(
				'ppcert_invalid_template',
				__( 'Template not found.', 'pressprimer-certificate' )
			);
		}

		$stored   = isset( $source->settings ) && is_array( $source->settings ) ? $source->settings : [];
		$settings = self::carry_over_settings( self::sanitize_settings( $stored ), $stored );

		foreach ( self::DUPLICATE_EXCLUDED_SETTINGS as $excluded ) {
			unset( $settings[ $excluded ] );
		}

		/**
		 * Filters the settings a duplicated template starts with.
		 *
		 * @since 2.1.0
		 *
		 * @param array  $settings The copied settings (School's program
		 *                         publish switch and slug already removed).
		 * @param object $source   The template being copied.
		 */
		$settings = apply_filters( 'ppcert_template_duplicate_settings', $settings, $source );
		$settings = is_array( $settings ) ? $settings : [];

		$new_id = self::create(
			[
				/* translators: %s: source template title */
				'title'     => substr( sprintf( __( '%s (Copy)', 'pressprimer-certificate' ), (string) $source->title ), 0, 200 ),
				'layout'    => $source->layout,
				'author_id' => absint( $author_id ),
				'status'    => 'draft',
				'settings'  => $settings,
			]
		);

		if ( ! is_wp_error( $new_id ) ) {
			// create() sanitizes again, which drops an inactive addon's
			// keys; store the copied set as it is.
			$created = self::get( (int) $new_id );

			if ( $created && $created->settings !== $settings ) {
				$wpdb->update(
					self::table(),
					[
						'settings_json' => empty( $settings ) ? null : wp_json_encode( $settings ),
						'updated_at'    => current_time( 'mysql', true ),
					],
					[ 'id' => (int) $new_id ],
					[ '%s', '%s' ],
					[ '%d' ]
				);
			}

			/**
			 * Fires after a template is duplicated (2.0, audit contract).
			 *
			 * @since 2.0.0
			 *
			 * @param int $new_id    The new template id.
			 * @param int $source_id The template it was copied from.
			 */
			do_action( 'ppcert_template_duplicated', (int) $new_id, absint( $id ) );
		}

		return $new_id;
	}

	/**
	 * Create a template row
	 *
	 * The layout MUST already be validator-clean - the REST controller
	 * runs the validator before calling this (CODE-STRUCTURE rule 3: only
	 * the validator sanitizes layout documents).
	 *
	 * @since 1.0.0
	 *
	 * @param array $args {
	 *     @type string $title     Template title.
	 *     @type array  $layout    Validator-clean layout document.
	 *     @type int    $author_id Author user id.
	 *     @type string $status    'draft' (default) or 'published'.
	 *     @type array  $settings  Optional settings (sanitized field by
	 *                             field; since 2.0 - starter clones carry
	 *                             a default certificate_name).
	 * }
	 * @return int|WP_Error New template row id.
	 */
	public static function create( array $args ) {
		global $wpdb;

		$layout = isset( $args['layout'] ) && is_array( $args['layout'] ) ? $args['layout'] : null;

		if ( null === $layout ) {
			return new WP_Error(
				'ppcert_invalid_layout',
				__( 'A layout document is required.', 'pressprimer-certificate' )
			);
		}

		$status = isset( $args['status'] ) && in_array( $args['status'], [ 'draft', 'published' ], true )
			? $args['status']
			: 'draft';

		$settings = isset( $args['settings'] ) && is_array( $args['settings'] )
			? self::sanitize_settings( $args['settings'] )
			: [];

		$now = current_time( 'mysql', true );

		$inserted = $wpdb->insert(
			self::table(),
			[
				'uuid'                  => wp_generate_uuid4(),
				'title'                 => substr( sanitize_text_field( isset( $args['title'] ) ? (string) $args['title'] : '' ), 0, 200 ),
				'status'                => $status,
				'author_id'             => isset( $args['author_id'] ) ? absint( $args['author_id'] ) : 0,
				'page_size'             => isset( $layout['page']['size'] ) ? (string) $layout['page']['size'] : 'a4',
				'orientation'           => isset( $layout['page']['orientation'] ) ? (string) $layout['page']['orientation'] : 'landscape',
				'layout_schema_version' => isset( $layout['layout_schema_version'] ) ? (int) $layout['layout_schema_version'] : 1,
				'layout_json'           => wp_json_encode( $layout ),
				'settings_json'         => empty( $settings ) ? null : wp_json_encode( $settings ),
				'is_starter'            => 0,
				'created_at'            => $now,
				'updated_at'            => $now,
			],
			[ '%s', '%s', '%s', '%d', '%s', '%s', '%d', '%s', '%s', '%d', '%s', '%s' ]
		);

		if ( ! $inserted ) {
			return new WP_Error(
				'ppcert_template_create_failed',
				__( 'The template could not be created.', 'pressprimer-certificate' )
			);
		}

		$template_id = (int) $wpdb->insert_id;

		/**
		 * Fires after a template row is created (2.0, audit contract).
		 * Duplication fires this and then ppcert_template_duplicated.
		 *
		 * @since 2.0.0
		 *
		 * @param int   $template_id New template id.
		 * @param array $args        Creation arguments (title, status, author_id, layout, settings).
		 */
		do_action( 'ppcert_template_created', $template_id, $args );

		return $template_id;
	}

	/**
	 * Update a template row (layout, title, status)
	 *
	 * The layout MUST already be validator-clean (the REST controller
	 * runs the validator first). updated_at advances to now; the caller
	 * handles conflict detection before calling.
	 *
	 * @since 1.0.0
	 *
	 * @param int   $id   Template row id.
	 * @param array $args {
	 *     @type array    $layout    Validator-clean layout document.
	 *     @type string   $title     Template title.
	 *     @type string   $status    'draft' | 'published' | 'archived'.
	 *     @type int|null $issuer_id Issuer of record for future issues
	 *                               (0/null = site template). Since 2.0
	 *                               (School contract); already-issued
	 *                               certificates keep their stamp.
	 * }
	 * @return object|WP_Error The updated, hydrated row.
	 */
	public static function update( $id, array $args ) {
		global $wpdb;

		$row = self::get( $id );

		if ( ! $row ) {
			return new WP_Error(
				'ppcert_template_not_found',
				__( 'Template not found.', 'pressprimer-certificate' )
			);
		}

		$data   = [];
		$format = [];

		if ( isset( $args['layout'] ) && is_array( $args['layout'] ) ) {
			$layout                        = $args['layout'];
			$data['layout_json']           = wp_json_encode( $layout );
			$data['layout_schema_version'] = isset( $layout['layout_schema_version'] ) ? (int) $layout['layout_schema_version'] : 1;
			$data['page_size']             = isset( $layout['page']['size'] ) ? (string) $layout['page']['size'] : $row->page_size;
			$data['orientation']           = isset( $layout['page']['orientation'] ) ? (string) $layout['page']['orientation'] : $row->orientation;
			$format[]                      = '%s';
			$format[]                      = '%d';
			$format[]                      = '%s';
			$format[]                      = '%s';
		}

		if ( isset( $args['title'] ) && '' !== trim( (string) $args['title'] ) ) {
			$data['title'] = substr( sanitize_text_field( (string) $args['title'] ), 0, 200 );
			$format[]      = '%s';
		}

		if ( isset( $args['status'] ) && in_array( $args['status'], [ 'draft', 'published', 'archived' ], true ) ) {
			$data['status'] = $args['status'];
			$format[]       = '%s';
		}

		if ( isset( $args['settings'] ) && is_array( $args['settings'] ) ) {
			$settings              = self::carry_over_settings(
				self::sanitize_settings( $args['settings'] ),
				isset( $row->settings ) && is_array( $row->settings ) ? $row->settings : []
			);
			$data['settings_json'] = empty( $settings ) ? null : wp_json_encode( $settings );
			$format[]              = '%s';
		}

		if ( array_key_exists( 'issuer_id', $args ) ) {
			$issuer_id         = absint( $args['issuer_id'] );
			$data['issuer_id'] = $issuer_id > 0 ? $issuer_id : null;
			$format[]          = '%d';
		}

		$data['updated_at'] = current_time( 'mysql', true );
		$format[]           = '%s';

		$updated = $wpdb->update(
			self::table(),
			$data,
			[ 'id' => absint( $id ) ],
			$format,
			[ '%d' ]
		);

		if ( false === $updated ) {
			return new WP_Error(
				'ppcert_template_update_failed',
				__( 'The template could not be saved.', 'pressprimer-certificate' )
			);
		}

		return self::get( $id );
	}

	/**
	 * Soft-delete a template (trash)
	 *
	 * Issued certificates keep rendering from their own snapshots, so a
	 * trashed template never affects existing credentials.
	 *
	 * @since 1.0.0
	 *
	 * @param int $id Template row id.
	 * @return true|WP_Error
	 */
	public static function trash( $id ) {
		global $wpdb;

		if ( ! self::get( $id ) ) {
			return new WP_Error(
				'ppcert_template_not_found',
				__( 'Template not found.', 'pressprimer-certificate' )
			);
		}

		$updated = $wpdb->update(
			self::table(),
			[
				'deleted_at' => current_time( 'mysql', true ),
				'updated_at' => current_time( 'mysql', true ),
			],
			[ 'id' => absint( $id ) ],
			[ '%s', '%s' ],
			[ '%d' ]
		);

		if ( false === $updated ) {
			return new WP_Error(
				'ppcert_template_trash_failed',
				__( 'The template could not be moved to the trash.', 'pressprimer-certificate' )
			);
		}

		/**
		 * Fires after a template is moved to the trash (2.0, audit contract).
		 *
		 * @since 2.0.0
		 *
		 * @param int $template_id Template id.
		 */
		do_action( 'ppcert_template_trashed', absint( $id ) );

		return true;
	}

	/**
	 * Templates for the certificates screen's filter (2.0, Feature
	 * 2.0-002 FR-002)
	 *
	 * Every non-deleted template (archived included - the UI labels
	 * them), PLUS soft-deleted templates that still have certificates,
	 * so those certificates stay findable through the filter (Feature
	 * 002 Edge Cases). Title order.
	 *
	 * @since 2.0.0
	 *
	 * @return object[] Hydrated rows (deleted_at set on deleted rows).
	 */
	public static function get_certificate_filter_templates() {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT t.* FROM %i t WHERE EXISTS ( SELECT 1 FROM %i c WHERE c.template_id = t.id ) OR t.deleted_at IS NULL ORDER BY t.title ASC',
				self::table(),
				PressPrimer_Certificate_Certificate::table()
			)
		);

		return array_map( [ __CLASS__, 'hydrate' ], (array) $rows );
	}

	/**
	 * Get the bundled starter definitions from templates/*.json
	 *
	 * Each file carries a _meta block (slug, label); the returned layout
	 * has _meta stripped.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string,array> Map of slug => [ slug, label, layout ].
	 */
	public static function get_starters() {
		static $starters = null;

		if ( null !== $starters ) {
			return $starters;
		}

		$starters = [];
		$files    = glob( PPCERT_PLUGIN_DIR . 'templates/starter-*.json' );

		foreach ( (array) $files as $file ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local bundled file, not a remote URL.
			$decoded = json_decode( (string) file_get_contents( $file ), true );

			if ( ! is_array( $decoded ) || ! isset( $decoded['_meta']['slug'] ) ) {
				continue;
			}

			$slug  = sanitize_key( (string) $decoded['_meta']['slug'] );
			$label = isset( $decoded['_meta']['label'] ) ? (string) $decoded['_meta']['label'] : $slug;
			$roles = isset( $decoded['_meta']['color_roles'] ) && is_array( $decoded['_meta']['color_roles'] )
				? $decoded['_meta']['color_roles']
				: [];
			$slots = isset( $decoded['_meta']['image_slots'] ) && is_array( $decoded['_meta']['image_slots'] )
				? $decoded['_meta']['image_slots']
				: [];
			// Default display-name pattern (2.0, Feature 2.0-001 FR-002):
			// cloned into the new template's settings so the 1.1 naming
			// feature is on display from the first award.
			$name = isset( $decoded['_meta']['certificate_name'] )
				? sanitize_text_field( (string) $decoded['_meta']['certificate_name'] )
				: '';

			unset( $decoded['_meta'] );

			$starters[ $slug ] = [
				'slug'             => $slug,
				'label'            => $label,
				'layout'           => $decoded,
				'color_roles'      => $roles,
				'image_slots'      => $slots,
				'certificate_name' => $name,
			];
		}

		return $starters;
	}

	/**
	 * Get one template by row id
	 *
	 * Soft-deleted templates (deleted_at set) are not returned. The row's
	 * layout_json is decoded onto a `layout` property; the raw JSON string
	 * is preserved for snapshotting.
	 *
	 * @since 1.0.0
	 *
	 * @param int $id Template row id.
	 * @return object|null Row object with decoded layout, or null.
	 */
	public static function get( $id ) {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE id = %d AND deleted_at IS NULL',
				self::table(),
				absint( $id )
			)
		);

		return $row ? self::hydrate( $row ) : null;
	}

	/**
	 * Hydrate a raw row: decode layout_json onto a layout property
	 *
	 * @since 1.0.0
	 *
	 * @param object $row Raw wpdb row.
	 * @return object Hydrated row.
	 */
	private static function hydrate( $row ) {
		$row->layout   = null;
		$row->settings = [];

		if ( ! empty( $row->layout_json ) ) {
			$decoded = json_decode( $row->layout_json, true );

			if ( is_array( $decoded ) ) {
				$row->layout = $decoded;
			}
		}

		// json_decode is not sanitization - stored settings were
		// sanitized field by field in sanitize_settings() on save.
		if ( ! empty( $row->settings_json ) ) {
			$decoded = json_decode( $row->settings_json, true );

			if ( is_array( $decoded ) ) {
				$row->settings = $decoded;
			}
		}

		return $row;
	}

	/**
	 * Sanitize template settings field by field
	 *
	 * Certificate validity is the one setting in 1.0. Three modes:
	 * absent (never expires, the default), 'period' (an amount of
	 * days, months, or years counted from the earned date), or 'date'
	 * (a fixed calendar date, e.g. a cohort's season end). Unknown
	 * keys and out-of-bounds values are stripped.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $settings Raw settings (decoded array).
	 * @return array Sanitized settings.
	 */
	public static function sanitize_settings( $settings ) {
		$settings = is_array( $settings ) ? $settings : [];
		$clean    = [];

		$mode = isset( $settings['validity_mode'] ) ? sanitize_key( (string) $settings['validity_mode'] ) : '';

		if ( 'period' === $mode ) {
			$unit   = isset( $settings['validity_unit'] ) ? sanitize_key( (string) $settings['validity_unit'] ) : '';
			$amount = isset( $settings['validity_amount'] ) && is_numeric( $settings['validity_amount'] )
				? absint( $settings['validity_amount'] )
				: 0;

			$bounds = [
				'days'   => 3650,
				'months' => 120,
				'years'  => 50,
			];

			if ( isset( $bounds[ $unit ] ) && $amount >= 1 && $amount <= $bounds[ $unit ] ) {
				$clean = [
					'validity_mode'   => 'period',
					'validity_amount' => $amount,
					'validity_unit'   => $unit,
				];
			}
		} elseif ( 'date' === $mode ) {
			$date = isset( $settings['validity_date'] ) ? sanitize_text_field( (string) $settings['validity_date'] ) : '';

			if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
				$clean = [
					'validity_mode' => 'date',
					'validity_date' => $date,
				];
			}
		}

		// Certificate name pattern (Feature 1.1-006): plain text with
		// merge tokens allowed (braces survive sanitize_text_field);
		// independent of the validity mode.
		$name = isset( $settings['certificate_name'] ) ? sanitize_text_field( (string) $settings['certificate_name'] ) : '';

		if ( '' !== $name ) {
			$clean['certificate_name'] = function_exists( 'mb_substr' ) ? mb_substr( $name, 0, 200 ) : substr( $name, 0, 200 );
		}

		// Email template mappings (2.0, Decision 005; the reminder key
		// and the context check since 2.1, Feature 2.1-004 FR-003): each
		// a positive integer referencing a non-deleted
		// wp_ppcert_email_templates row of the key's context at save
		// time; anything else is dropped and the default email applies.
		// Archived rows keep their mapping (the resolution chain skips
		// them at send time).
		foreach ( self::EMAIL_MAPPING_KEYS as $key => $email_context ) {
			$mapped_id = self::clean_email_template_id( $settings, $key, $email_context );

			if ( $mapped_id > 0 ) {
				$clean[ $key ] = $mapped_id;
			}
		}

		// Expiry reminder settings (2.0, Educator E-005 - premium-written
		// keys sanitized here per the Decision 005 precedent, like
		// email_template_id above). reminders_enabled is a plain flag;
		// reminder_offsets is a bounded list of whole days before
		// expiry (1-365, unique, largest first, at most five). An
		// enabled template with no offsets list uses the addon's
		// configured defaults at scan time.
		if ( ! empty( $settings['reminders_enabled'] ) ) {
			$clean['reminders_enabled'] = true;
		}

		if ( isset( $settings['reminder_offsets'] ) && is_array( $settings['reminder_offsets'] ) ) {
			$offsets = [];

			foreach ( $settings['reminder_offsets'] as $offset ) {
				if ( is_numeric( $offset ) ) {
					$days = absint( $offset );

					if ( $days >= 1 && $days <= 365 ) {
						$offsets[] = $days;
					}
				}
			}

			$offsets = array_values( array_unique( $offsets ) );
			rsort( $offsets );
			$offsets = array_slice( $offsets, 0, 5 );

			if ( ! empty( $offsets ) ) {
				$clean['reminder_offsets'] = $offsets;
			}
		}

		/**
		 * Filters the sanitized template settings.
		 *
		 * The addon settings registration surface (2.0, School contract
		 * item 4): addons read their own keys from the RAW submitted
		 * settings, sanitize each one type-appropriately, and add them to
		 * the clean array. Keys nobody claims never reach storage.
		 *
		 * @since 2.0.0
		 *
		 * @param array $clean    Sanitized settings.
		 * @param array $settings Raw submitted settings.
		 */
		return (array) apply_filters( 'ppcert_template_sanitize_settings', $clean, $settings );
	}

	/**
	 * Keep stored addon settings whose owner is inactive (2.1, Feature
	 * 2.1-004 FR-015)
	 *
	 * The settings_json column is replaced wholesale on save, and keys no
	 * active sanitizer claims never reach storage, so before 2.1 saving while
	 * School was inactive dropped its `school_*` keys. Every stored
	 * non-core key the sanitized result lacks is kept - the STORED value,
	 * never request input - unless an active owner claims the key's
	 * prefix: every registered addon owns `{addon id}_` (so School 2.0.x,
	 * which clears a switch by omitting its key, stays authoritative),
	 * and `ppcert_template_settings_owned_prefixes` adds others.
	 *
	 * @since 2.1.0
	 *
	 * @param array $clean  Sanitized settings being saved.
	 * @param array $stored The settings stored on the row.
	 * @return array
	 */
	private static function carry_over_settings( array $clean, array $stored ) {
		/**
		 * Filters the template-settings key prefixes owned by active
		 * addons.
		 *
		 * An active addon lists the prefixes of the keys it sanitizes
		 * (School: 'school_'), so its own sanitizer decides whether a
		 * stored key survives a save. Keys of inactive addons carry over.
		 *
		 * @since 2.1.0
		 *
		 * @param string[] $prefixes Owned prefixes. Default: each registered
		 *                           addon's id plus '_' ('school_').
		 */
		$owned = apply_filters( 'ppcert_template_settings_owned_prefixes', self::addon_prefixes() );
		$owned = array_values( array_filter( is_array( $owned ) ? $owned : [], 'is_string' ) );

		foreach ( $stored as $key => $value ) {
			if ( ! is_string( $key ) || array_key_exists( $key, $clean ) || in_array( $key, self::CORE_SETTINGS_KEYS, true ) ) {
				continue;
			}

			foreach ( $owned as $prefix ) {
				if ( '' !== $prefix && 0 === strpos( $key, $prefix ) ) {
					continue 2;
				}
			}

			$clean[ $key ] = $value;
		}

		return $clean;
	}

	/**
	 * `{addon id}_` for every registered (active) addon
	 *
	 * @since 2.1.0
	 *
	 * @return string[]
	 */
	private static function addon_prefixes() {
		$manager = function_exists( 'ppcert_addon_manager' ) ? ppcert_addon_manager() : null;

		if ( ! $manager || ! method_exists( $manager, 'get_addons' ) ) {
			return [];
		}

		$prefixes = [];

		foreach ( array_keys( (array) $manager->get_addons() ) as $addon_id ) {
			$addon_id = sanitize_key( (string) $addon_id );

			if ( '' !== $addon_id ) {
				$prefixes[] = $addon_id . '_';
			}
		}

		return $prefixes;
	}

	/**
	 * One email template mapping, validated
	 *
	 * The round-trip cast rejects negatives and fractions rather than
	 * absint-coercing them onto another row.
	 *
	 * @since 2.1.0
	 *
	 * @param array  $settings      Raw settings.
	 * @param string $key           Settings key.
	 * @param string $email_context Required row context.
	 * @return int Row id, or 0 when the mapping is invalid.
	 */
	private static function clean_email_template_id( array $settings, $key, $email_context ) {
		if ( ! isset( $settings[ $key ] ) || ! is_numeric( $settings[ $key ] ) ) {
			return 0;
		}

		$mapped_id = (int) $settings[ $key ];

		if ( $mapped_id < 1
			|| (string) $mapped_id !== (string) $settings[ $key ]
			|| ! PressPrimer_Certificate_Email_Template::exists( $mapped_id, $email_context ) ) {
			return 0;
		}

		return $mapped_id;
	}
}
