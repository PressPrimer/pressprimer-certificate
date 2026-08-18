<?php
/**
 * Triggers REST controller
 *
 * Trigger types + source search for the designer's Award tab, and the
 * per-template replace-set (Feature 001 FR-006, TR-003; Feature 004
 * TR-002).
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
 * Triggers controller class
 *
 * The conditions_json payload is sanitized by walking the registered
 * type's schema (unknown keys stripped, types coerced, numbers clamped)
 * before save.
 * Triggers whose type is no longer registered (adapter deactivated) are
 * preserved as-is: they are already inert at issuance, and wiping their
 * conditions on an unrelated save would destroy configuration the user
 * gets back by reactivating the plugin.
 *
 * @since 1.0.0
 */
class PressPrimer_Certificate_REST_Triggers_Controller {

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
			'/trigger-types',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_types' ],
				'permission_callback' => [ $this, 'can_manage' ],
				'args'                => [
					'type'    => [
						'sanitize_callback' => 'sanitize_key',
					],
					'search'  => [
						'sanitize_callback' => 'sanitize_text_field',
					],
					'level'   => [
						'sanitize_callback' => 'sanitize_key',
					],
					'parents' => [
						'sanitize_callback' => [ __CLASS__, 'sanitize_parents' ],
					],
				],
			]
		);

		register_rest_route(
			'ppcert/v1',
			'/templates/(?P<id>\d+)/triggers',
			[
				[
					'methods'             => 'GET',
					'callback'            => [ $this, 'get_triggers' ],
					'permission_callback' => [ $this, 'can_manage' ],
				],
				[
					'methods'             => 'PUT',
					'callback'            => [ $this, 'replace_triggers' ],
					'permission_callback' => [ $this, 'can_manage' ],
				],
			]
		);
	}

	/**
	 * Capability check for every triggers route
	 *
	 * @since 1.0.0
	 *
	 * @return bool
	 */
	public function can_manage() {
		return current_user_can( PressPrimer_Certificate_Capabilities::CAP_MANAGE_TEMPLATES );
	}

	/**
	 * Sanitize the cascade parents map (level key => selected id)
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $value Raw value.
	 * @return array<string,string>
	 */
	public static function sanitize_parents( $value ) {
		$clean = [];

		foreach ( (array) $value as $key => $id ) {
			$key = sanitize_key( (string) $key );

			if ( '' !== $key && is_scalar( $id ) ) {
				$clean[ $key ] = sanitize_text_field( (string) $id );
			}
		}

		return $clean;
	}

	/**
	 * GET /trigger-types - types list, or a type's sources (?type=&search=)
	 *
	 * Only registered types appear: adapters register through
	 * ppcert_register_trigger_types when their LMS is detected, so an
	 * absent plugin simply contributes nothing.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_types( $request ) {
		$type_id = (string) $request->get_param( 'type' );

		if ( '' !== $type_id ) {
			return $this->get_sources(
				$type_id,
				(string) $request->get_param( 'search' ),
				(string) $request->get_param( 'level' ),
				self::sanitize_parents( $request->get_param( 'parents' ) )
			);
		}

		$types = [];

		foreach ( PressPrimer_Certificate_Trigger_Registry::get_types() as $type ) {
			$types[] = [
				'id'                => $type['id'],
				'label'             => $type['label'],
				'integration'       => $type['integration'],
				'short_label'       => $type['short_label'],
				'source_label'      => $type['source_label'],
				'has_sources'       => null !== $type['source_picker'],
				'source_levels'     => $type['source_levels'],
				'source_post_types' => $type['source_post_types'],
				'conditions_schema' => self::schema_for_client( $type['conditions_schema'] ),
			];
		}

		// FR-005 positioning: adapter lists render alphabetically -
		// integration first, then trigger - no LMS is headlined by
		// registration order.
		usort(
			$types,
			static function ( $a, $b ) {
				$by_integration = strcasecmp( $a['integration'], $b['integration'] );

				return 0 !== $by_integration ? $by_integration : strcasecmp( $a['short_label'], $b['short_label'] );
			}
		);

		return new WP_REST_Response( $types, 200 );
	}

	/**
	 * Sources for one type via its registered pickers
	 *
	 * No level: the source options themselves - scoped by parents for
	 * hierarchical types, flat otherwise. With ?level=: that cascade
	 * level's options (e.g. the course list, or a course's lessons).
	 *
	 * @since 1.0.0
	 *
	 * @param string $type_id Trigger type id.
	 * @param string $search  Search term.
	 * @param string $level   Cascade level key ('' = the sources).
	 * @param array  $parents Cascade selections (level key => id).
	 * @return WP_REST_Response|WP_Error
	 */
	private function get_sources( $type_id, $search, $level = '', $parents = [] ) {
		$type = PressPrimer_Certificate_Trigger_Registry::get_type( $type_id );

		if ( null === $type ) {
			return new WP_Error(
				'ppcert_unknown_trigger_type',
				__( 'Unknown trigger type.', 'pressprimer-certificate' ),
				[ 'status' => 400 ]
			);
		}

		if ( '' !== $level ) {
			$sources = null !== $type['level_picker']
				? call_user_func( $type['level_picker'], $level, $parents, $search )
				: [];
		} elseif ( ! empty( $parents ) && null !== $type['scoped_picker'] ) {
			$sources = call_user_func( $type['scoped_picker'], $parents, $search );
		} elseif ( null !== $type['source_picker'] ) {
			$sources = call_user_func( $type['source_picker'], $search );
		} else {
			return new WP_REST_Response( [], 200 );
		}

		$items = [];

		foreach ( (array) $sources as $source ) {
			if ( ! is_array( $source ) || ! isset( $source['id'] ) ) {
				continue;
			}

			$items[] = [
				'id'    => (string) $source['id'],
				'title' => isset( $source['title'] ) ? sanitize_text_field( (string) $source['title'] ) : (string) $source['id'],
			];

			if ( count( $items ) >= 50 ) {
				break;
			}
		}

		return new WP_REST_Response( $items, 200 );
	}

	/**
	 * GET /templates/{id}/triggers - enriched rows for the Award tab
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_triggers( $request ) {
		$template = PressPrimer_Certificate_Template::get( absint( $request->get_param( 'id' ) ) );

		if ( ! $template ) {
			return new WP_Error(
				'ppcert_template_not_found',
				__( 'Template not found.', 'pressprimer-certificate' ),
				[ 'status' => 404 ]
			);
		}

		return new WP_REST_Response(
			self::enrich( PressPrimer_Certificate_Trigger::get_for_template( (int) $template->id ) ),
			200
		);
	}

	/**
	 * PUT /templates/{id}/triggers - replace-set (FR-006)
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function replace_triggers( $request ) {
		$template = PressPrimer_Certificate_Template::get( absint( $request->get_param( 'id' ) ) );

		if ( ! $template ) {
			return new WP_Error(
				'ppcert_template_not_found',
				__( 'Template not found.', 'pressprimer-certificate' ),
				[ 'status' => 404 ]
			);
		}

		$raw = $request->get_param( 'triggers' );

		if ( ! is_array( $raw ) ) {
			return new WP_Error(
				'ppcert_invalid_triggers',
				__( 'The triggers payload must be an array.', 'pressprimer-certificate' ),
				[ 'status' => 400 ]
			);
		}

		$clean = [];

		foreach ( $raw as $trigger ) {
			if ( ! is_array( $trigger ) || empty( $trigger['trigger_type'] ) ) {
				continue;
			}

			$type_id    = sanitize_key( (string) $trigger['trigger_type'] );
			$registered = null !== PressPrimer_Certificate_Trigger_Registry::get_type( $type_id );
			$conditions = isset( $trigger['conditions'] ) && is_array( $trigger['conditions'] ) ? $trigger['conditions'] : [];
			$source_ref = isset( $trigger['source_ref'] ) ? (string) $trigger['source_ref'] : null;

			// Registered types: the schema walk is authoritative.
			// Unregistered (inert) types: preserve stored shape - the
			// schema is unknowable until the adapter returns.
			$clean_conditions = $registered
				? PressPrimer_Certificate_Trigger_Registry::sanitize_conditions( $type_id, $conditions )
				: array_map( 'sanitize_text_field', array_filter( $conditions, 'is_scalar' ) );

			// 'Any' triggers of hierarchical types must carry their
			// parent scope (leaf-only "Any", Feature 1.1-002).
			$scope_check = PressPrimer_Certificate_Trigger_Registry::validate_trigger_source(
				$type_id,
				(string) $source_ref,
				$clean_conditions
			);

			if ( is_wp_error( $scope_check ) ) {
				return $scope_check;
			}

			$clean[] = [
				'trigger_type' => $type_id,
				'source_ref'   => $source_ref,
				'conditions'   => $clean_conditions,
				'is_active'    => ! isset( $trigger['is_active'] ) || $trigger['is_active'],
			];
		}

		// 1.0 scope decision (2026-07-22): one trigger per template. The
		// schema and issuance engine stay multi-trigger capable so a
		// future release lifts this without a migration.
		if ( count( $clean ) > 1 ) {
			return new WP_Error(
				'ppcert_too_many_triggers',
				__( 'Certificates support a single award trigger in this version. Duplicate the template to award it another way.', 'pressprimer-certificate' ),
				[ 'status' => 400 ]
			);
		}

		$rows = PressPrimer_Certificate_Trigger::replace_for_template( (int) $template->id, $clean );

		return new WP_REST_Response( self::enrich( $rows ), 200 );
	}

	/**
	 * Enrich trigger rows for the panel: type labels, availability, and
	 * source resolution (the orphaned-source warning badge).
	 *
	 * @since 1.0.0
	 *
	 * @param object[] $rows Trigger rows.
	 * @return array[] Client shapes.
	 */
	private static function enrich( array $rows ) {
		$types = PressPrimer_Certificate_Trigger_Registry::get_types();

		// One picker call per type present (not per trigger).
		$source_maps = [];

		foreach ( $rows as $row ) {
			$type_id = (string) $row->trigger_type;

			if ( isset( $source_maps[ $type_id ] ) || ! isset( $types[ $type_id ] ) || null === $types[ $type_id ]['source_picker'] ) {
				continue;
			}

			$source_maps[ $type_id ] = [];

			foreach ( (array) call_user_func( $types[ $type_id ]['source_picker'], '' ) as $source ) {
				if ( is_array( $source ) && isset( $source['id'] ) ) {
					$source_maps[ $type_id ][ (string) $source['id'] ] = isset( $source['title'] ) ? (string) $source['title'] : (string) $source['id'];
				}
			}
		}

		$items = [];

		foreach ( $rows as $row ) {
			$type_id   = (string) $row->trigger_type;
			$available = isset( $types[ $type_id ] );
			$source    = null !== $row->source_ref ? (string) $row->source_ref : '';

			$source_label = '';
			$source_found = '' === $source;

			if ( '' !== $source && isset( $source_maps[ $type_id ][ $source ] ) ) {
				$source_label = $source_maps[ $type_id ][ $source ];
				$source_found = true;
			} elseif ( '' !== $source && $available ) {
				// Fall back to a direct post lookup (large catalogs where
				// the picker's unfiltered page misses the source).
				$post = is_numeric( $source ) ? get_post( (int) $source ) : null;

				if ( $post ) {
					$source_label = (string) $post->post_title;
					$source_found = true;
				}
			}

			$conditions = [];

			if ( ! empty( $row->conditions_json ) ) {
				$decoded    = json_decode( (string) $row->conditions_json, true );
				$conditions = is_array( $decoded ) ? $decoded : [];
			}

			$items[] = [
				'id'             => (int) $row->id,
				'trigger_type'   => $type_id,
				'type_label'     => $available ? $types[ $type_id ]['label'] : $type_id,
				// Known even for inert types (deactivated plugin): the
				// Award card names the plugin to reactivate instead of
				// dumping the raw type id.
				'integration'    => $available
					? $types[ $type_id ]['integration']
					: self::known_integration_label( $type_id ),
				'type_available' => $available,
				'source_ref'     => '' !== $source ? $source : null,
				'source_label'   => $source_label,
				'source_found'   => $source_found,
				'conditions'     => $conditions,
				'is_active'      => 1 === (int) $row->is_active,
			];
		}

		return $items;
	}

	/**
	 * Integration name for a bundled trigger type whose plugin is
	 * deactivated ('' for unknown/third-party types)
	 *
	 * The bundled adapter classes always exist - only their
	 * registration is availability-gated - so they can still name
	 * their integration.
	 *
	 * @since 1.0.0
	 *
	 * @param string $type_id Trigger type id.
	 * @return string
	 */
	private static function known_integration_label( $type_id ) {
		foreach ( PressPrimer_Certificate_Plugin::get_adapter_classes() as $adapter_class ) {
			if ( ! class_exists( $adapter_class ) ) {
				continue;
			}

			$adapter = new $adapter_class();

			if ( $adapter->get_id() === $type_id ) {
				return $adapter->get_integration_label();
			}
		}

		return '';
	}

	/**
	 * Strip non-client schema internals (callables never serialize)
	 *
	 * @since 1.0.0
	 *
	 * @param array $schema Conditions schema.
	 * @return array Client-safe schema.
	 */
	private static function schema_for_client( array $schema ) {
		$clean = [];

		foreach ( $schema as $key => $field ) {
			if ( ! is_string( $key ) || ! is_array( $field ) ) {
				continue;
			}

			$entry = [
				'type'    => isset( $field['type'] ) ? (string) $field['type'] : 'text',
				'label'   => isset( $field['label'] ) ? (string) $field['label'] : $key,
				'default' => array_key_exists( 'default', $field ) ? $field['default'] : null,
			];

			if ( isset( $field['help'] ) && is_string( $field['help'] ) && '' !== $field['help'] ) {
				$entry['help'] = $field['help'];
			}

			if ( isset( $field['min'] ) && is_numeric( $field['min'] ) ) {
				$entry['min'] = (float) $field['min'];
			}
			if ( isset( $field['max'] ) && is_numeric( $field['max'] ) ) {
				$entry['max'] = (float) $field['max'];
			}
			if ( isset( $field['options'] ) && is_array( $field['options'] ) ) {
				$entry['options'] = array_values( array_map( 'strval', $field['options'] ) );
			}

			$clean[ $key ] = $entry;
		}

		return $clean;
	}
}
