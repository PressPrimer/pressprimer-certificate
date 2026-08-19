<?php
/**
 * Merge fields REST controller
 *
 * The designer palette's registry feed and the meta-key pickers
 * (Feature 002 TR-002). All routes require ppcert_manage_templates -
 * meta keys and sampled values are site-owner data, never public.
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
 * Merge fields controller class
 *
 * Meta-key queries run DISTINCT-key lookups with LIMIT 50 and a
 * 5-minute transient cache (ppcert_meta_keys_*). The denylist
 * (underscore-prefixed keys, session tokens, capability keys) is
 * enforced server-side; sampled values truncate to 80 characters.
 *
 * @since 1.0.0
 */
class PressPrimer_Certificate_REST_Merge_Fields_Controller {

	/**
	 * Max keys per picker response (TR-002)
	 *
	 * @since 1.0.0
	 * @var int
	 */
	const KEY_LIMIT = 50;

	/**
	 * Picker cache lifetime in seconds (TR-002: 5 minutes)
	 *
	 * @since 1.0.0
	 * @var int
	 */
	const CACHE_TTL = 300;

	/**
	 * Sample value display cap (security requirement: 80 chars)
	 *
	 * @since 1.0.0
	 * @var int
	 */
	const SAMPLE_LENGTH = 80;

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
			'/merge-fields',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_registry' ],
				'permission_callback' => [ $this, 'can_manage' ],
				'args'                => [
					'trigger_types' => [
						'sanitize_callback' => [ __CLASS__, 'sanitize_trigger_types' ],
					],
				],
			]
		);

		register_rest_route(
			'ppcert/v1',
			'/merge-fields/user-meta-keys',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_user_meta_keys' ],
				'permission_callback' => [ $this, 'can_manage' ],
				'args'                => [
					'search' => [
						'sanitize_callback' => [ __CLASS__, 'sanitize_search' ],
					],
				],
			]
		);

		register_rest_route(
			'ppcert/v1',
			'/merge-fields/post-meta-keys',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_post_meta_keys' ],
				'permission_callback' => [ $this, 'can_manage' ],
				'args'                => [
					'post_id'      => [
						'sanitize_callback' => 'absint',
					],
					'trigger_type' => [
						'sanitize_callback' => 'sanitize_key',
					],
					'search'       => [
						'sanitize_callback' => [ __CLASS__, 'sanitize_search' ],
					],
				],
			]
		);
	}

	/**
	 * Capability check for every merge-fields route
	 *
	 * @since 1.0.0
	 *
	 * @return bool
	 */
	public function can_manage() {
		return current_user_can( PressPrimer_Certificate_Capabilities::CAP_MANAGE_TEMPLATES );
	}

	/**
	 * Sanitize a picker search term to the meta-key grammar
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	public static function sanitize_search( $value ) {
		$value = strtolower( (string) $value );

		return substr( (string) preg_replace( '/[^a-z0-9_\-]/', '', $value ), 0, 64 );
	}

	/**
	 * Sanitize the trigger_types scope: comma-separated type ids
	 *
	 * An empty string is a meaningful value ("scope to no triggers":
	 * core fields only), distinct from an absent parameter (no scoping).
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	public static function sanitize_trigger_types( $value ) {
		$ids = array_filter( array_map( 'sanitize_key', explode( ',', (string) $value ) ) );

		return implode( ',', $ids );
	}

	/**
	 * GET /merge-fields - the designer registry (groups + fields)
	 *
	 * Resolvers are server-side callables and never serialize into the
	 * response.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response
	 */
	public function get_registry( $request ) {
		// Absent parameter = unscoped registry; present (even empty) =
		// scope adapter fields to the listed trigger types.
		$scope_param = $request->get_param( 'trigger_types' );
		$args        = [];

		if ( null !== $scope_param ) {
			$args['trigger_types'] = array_filter( explode( ',', (string) $scope_param ) );
		}

		$fields = [];

		foreach ( PressPrimer_Certificate_Merge_Field_Registry::get_fields( 'designer', $args ) as $field ) {
			$fields[] = [
				'key'    => $field['key'],
				'group'  => $field['group'],
				'label'  => $field['label'],
				'sample' => $field['sample'],
			];
		}

		$groups = PressPrimer_Certificate_Merge_Field_Registry::get_groups( 'designer' );

		// Scoped to a single trigger type: label the source group with the
		// type's own noun ("Quiz", "Course") so the palette reads in the
		// user's terms. 1.0 templates carry at most one trigger.
		if ( isset( $args['trigger_types'] ) && 1 === count( $args['trigger_types'] ) ) {
			$type = PressPrimer_Certificate_Trigger_Registry::get_type( reset( $args['trigger_types'] ) );

			if ( null !== $type && '' !== $type['source_label'] ) {
				$groups['source'] = $type['source_label'];
			}
		}

		return new WP_REST_Response(
			[
				'groups' => $groups,
				'fields' => $fields,
			],
			200
		);
	}

	/**
	 * GET /merge-fields/user-meta-keys - searchable distinct user meta keys
	 *
	 * Each key carries one sampled value: the current user's when set,
	 * otherwise the most recent user holding the key (FR-004).
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response
	 */
	public function get_user_meta_keys( $request ) {
		global $wpdb;

		$search    = self::sanitize_search( $request->get_param( 'search' ) );
		$cache_key = 'ppcert_meta_keys_user_' . md5( $search );
		$cached    = get_transient( $cache_key );

		if ( is_array( $cached ) ) {
			return new WP_REST_Response( $cached, 200 );
		}

		$like        = '%' . $wpdb->esc_like( $search ) . '%';
		$hidden_like = $wpdb->esc_like( '_' ) . '%';

		$key_rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT DISTINCT meta_key FROM %i WHERE meta_key LIKE %s AND meta_key NOT LIKE %s ORDER BY meta_key ASC LIMIT %d',
				$wpdb->usermeta,
				$like,
				$hidden_like,
				self::KEY_LIMIT
			)
		);

		$items           = [];
		$current_user_id = get_current_user_id();

		foreach ( (array) $key_rows as $row ) {
			$key = (string) $row->meta_key;

			if ( ! preg_match( PressPrimer_Certificate_Merge_Field_Registry::META_KEY_PATTERN, $key ) ) {
				continue;
			}

			if ( PressPrimer_Certificate_Merge_Field_Registry::is_denylisted_user_meta_key( $key ) ) {
				continue;
			}

			$sample = '';

			if ( $current_user_id > 0 ) {
				$sample = (string) $wpdb->get_var(
					$wpdb->prepare(
						"SELECT meta_value FROM %i WHERE meta_key = %s AND user_id = %d AND meta_value != '' LIMIT 1",
						$wpdb->usermeta,
						$key,
						$current_user_id
					)
				);
			}

			if ( '' === $sample ) {
				$sample = (string) $wpdb->get_var(
					$wpdb->prepare(
						"SELECT meta_value FROM %i WHERE meta_key = %s AND meta_value != '' ORDER BY user_id DESC LIMIT 1",
						$wpdb->usermeta,
						$key
					)
				);
			}

			$items[] = [
				'key'    => $key,
				'sample' => self::prepare_sample( $sample ),
			];
		}

		set_transient( $cache_key, $items, self::CACHE_TTL );

		return new WP_REST_Response( $items, 200 );
	}

	/**
	 * GET /merge-fields/post-meta-keys - meta keys of one source post
	 *
	 * The post must be a source of a registered trigger type: its post
	 * type has to appear in the union of the types' source_post_types
	 * (TR-002). With no trigger types registered (no adapter active)
	 * every post is rejected.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_post_meta_keys( $request ) {
		global $wpdb;

		$post_id = absint( $request->get_param( 'post_id' ) );
		$type_id = sanitize_key( (string) $request->get_param( 'trigger_type' ) );

		// 'Any' triggers have no bound source post: preview against the
		// most recent published source of the trigger type (Feature
		// 1.1-002 FR-004). No sources yet is an empty list, not an
		// error.
		if ( $post_id < 1 && '' !== $type_id ) {
			$type       = PressPrimer_Certificate_Trigger_Registry::get_type( $type_id );
			$post_types = $type ? $type['source_post_types'] : [];

			if ( empty( $post_types ) ) {
				return new WP_REST_Response( [], 200 );
			}

			$recent = get_posts(
				[
					'post_type'   => $post_types,
					'post_status' => 'publish',
					'numberposts' => 1,
					'orderby'     => 'date',
					'order'       => 'DESC',
				]
			);

			if ( empty( $recent ) ) {
				return new WP_REST_Response( [], 200 );
			}

			$post_id = (int) $recent[0]->ID;
		}

		$post = $post_id > 0 ? get_post( $post_id ) : null;

		if ( ! $post || ! $this->is_source_post_type( (string) $post->post_type ) ) {
			return new WP_Error(
				'ppcert_invalid_source_post',
				__( 'That post is not a source for any registered trigger type.', 'pressprimer-certificate' ),
				[ 'status' => 400 ]
			);
		}

		$search    = self::sanitize_search( $request->get_param( 'search' ) );
		$cache_key = 'ppcert_meta_keys_post_' . $post_id . '_' . md5( $search );
		$cached    = get_transient( $cache_key );

		if ( is_array( $cached ) ) {
			return new WP_REST_Response( $cached, 200 );
		}

		$like        = '%' . $wpdb->esc_like( $search ) . '%';
		$hidden_like = $wpdb->esc_like( '_' ) . '%';

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT meta_key, meta_value FROM %i WHERE post_id = %d AND meta_key LIKE %s AND meta_key NOT LIKE %s ORDER BY meta_key ASC',
				$wpdb->postmeta,
				$post_id,
				$like,
				$hidden_like
			)
		);

		$items = [];
		$seen  = [];

		foreach ( (array) $rows as $row ) {
			$key = (string) $row->meta_key;

			if ( isset( $seen[ $key ] ) || ! preg_match( PressPrimer_Certificate_Merge_Field_Registry::META_KEY_PATTERN, $key ) ) {
				continue;
			}

			$seen[ $key ] = true;

			$items[] = [
				'key'    => $key,
				'sample' => self::prepare_sample( (string) $row->meta_value ),
			];

			if ( count( $items ) >= self::KEY_LIMIT ) {
				break;
			}
		}

		set_transient( $cache_key, $items, self::CACHE_TTL );

		return new WP_REST_Response( $items, 200 );
	}

	/**
	 * Whether a post type is a source post type of any trigger type
	 *
	 * @since 1.0.0
	 *
	 * @param string $post_type Post type slug.
	 * @return bool
	 */
	private function is_source_post_type( $post_type ) {
		foreach ( PressPrimer_Certificate_Trigger_Registry::get_types() as $type ) {
			if ( in_array( $post_type, $type['source_post_types'], true ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Prepare a sampled meta value for the picker
	 *
	 * Scalar display only: serialized arrays/objects sample as empty
	 * (they also resolve empty at issue time). Truncates to 80 chars.
	 *
	 * @since 1.0.0
	 *
	 * @param string $value Raw meta value.
	 * @return string
	 */
	private static function prepare_sample( $value ) {
		if ( preg_match( '/^(?:a|O|s|C):\d+:/', $value ) || 'b:0;' === $value || 'b:1;' === $value || 'N;' === $value ) {
			return '';
		}

		$value = sanitize_text_field( $value );

		if ( function_exists( 'mb_substr' ) ) {
			return mb_substr( $value, 0, self::SAMPLE_LENGTH );
		}

		return substr( $value, 0, self::SAMPLE_LENGTH );
	}
}
