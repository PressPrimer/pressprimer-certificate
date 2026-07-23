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
	 *     @type string   $status        Status filter ('' = all).
	 *     @type string   $search        Title search ('' = none).
	 *     @type string[] $trigger_types Trigger type ids ([] = all).
	 *     @type int      $page          1-based page.
	 *     @type int      $per_page      Page size (default 20).
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

		$search_like = '' !== $search ? '%' . $wpdb->esc_like( $search ) . '%' : '';

		$total = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM %i t WHERE t.deleted_at IS NULL AND ( %s = '' OR t.status = %s ) AND ( %s = '' OR t.title LIKE %s ) AND ( %s = '' OR EXISTS ( SELECT 1 FROM %i tr WHERE tr.template_id = t.id AND FIND_IN_SET( tr.trigger_type, %s ) ) )",
				self::table(),
				$status,
				$status,
				$search_like,
				$search_like,
				$types_csv,
				PressPrimer_Certificate_Trigger::table(),
				$types_csv
			)
		);

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT t.* FROM %i t WHERE t.deleted_at IS NULL AND ( %s = '' OR t.status = %s ) AND ( %s = '' OR t.title LIKE %s ) AND ( %s = '' OR EXISTS ( SELECT 1 FROM %i tr WHERE tr.template_id = t.id AND FIND_IN_SET( tr.trigger_type, %s ) ) ) ORDER BY t.updated_at DESC LIMIT %d OFFSET %d",
				self::table(),
				$status,
				$status,
				$search_like,
				$search_like,
				$types_csv,
				PressPrimer_Certificate_Trigger::table(),
				$types_csv,
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
	 * Copies the design only - triggers deliberately do NOT copy: the
	 * single-trigger rule makes duplication the reuse path for awarding
	 * the same design from a DIFFERENT trigger, and copying the original
	 * trigger would double-award every completion.
	 *
	 * @since 1.0.0
	 *
	 * @param int $id        Source template row id.
	 * @param int $author_id Author for the copy.
	 * @return int|WP_Error New template row id.
	 */
	public static function duplicate( $id, $author_id ) {
		$source = self::get( $id );

		if ( ! $source || ! is_array( $source->layout ) ) {
			return new WP_Error(
				'ppcert_invalid_template',
				__( 'Template not found.', 'pressprimer-certificate' )
			);
		}

		return self::create(
			[
				/* translators: %s: source template title */
				'title'     => substr( sprintf( __( '%s (Copy)', 'pressprimer-certificate' ), (string) $source->title ), 0, 200 ),
				'layout'    => $source->layout,
				'author_id' => absint( $author_id ),
				'status'    => 'draft',
			]
		);
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
				'is_starter'            => 0,
				'created_at'            => $now,
				'updated_at'            => $now,
			],
			[ '%s', '%s', '%s', '%d', '%s', '%s', '%d', '%s', '%d', '%s', '%s' ]
		);

		if ( ! $inserted ) {
			return new WP_Error(
				'ppcert_template_create_failed',
				__( 'The template could not be created.', 'pressprimer-certificate' )
			);
		}

		return (int) $wpdb->insert_id;
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
	 *     @type array  $layout Validator-clean layout document.
	 *     @type string $title  Template title.
	 *     @type string $status 'draft' | 'published' | 'archived'.
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

		return true;
	}

	/**
	 * Get the bundled starter definitions from templates/*.json
	 *
	 * Each file carries a _meta block (slug, label); the returned layout
	 * has _meta stripped. Starters are provisional until Prompt 5.1
	 * finalizes the set (ROADMAP Open Decision 6).
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

			unset( $decoded['_meta'] );

			$starters[ $slug ] = [
				'slug'        => $slug,
				'label'       => $label,
				'layout'      => $decoded,
				'color_roles' => $roles,
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
		$row->layout = null;

		if ( ! empty( $row->layout_json ) ) {
			$decoded = json_decode( $row->layout_json, true );

			if ( is_array( $decoded ) ) {
				$row->layout = $decoded;
			}
		}

		return $row;
	}
}
