<?php
/**
 * Merge field registry
 *
 * One registry defines every merge field: the designer palette reads it
 * for labels and samples; the issuance engine reads it to resolve real
 * values into the immutable merge_data_json snapshot.
 *
 * @package PressPrimer_Certificate
 * @subpackage Services
 * @since 1.0.0
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Merge field registry class
 *
 * Implements Feature 002 FR-001/FR-002/FR-003/FR-005. Core registers the
 * recipient, certificate, and site groups; adapters register source
 * fields and addons extend - all through the same
 * `ppcert_register_merge_fields` filter (HOOKS.md). No DB storage: the
 * registry is code + filters, and resolved values live only in
 * certificate snapshots.
 *
 * Merge data keys are the inner token form (`recipient.display_name`,
 * no braces); the extraction helper strips braces from layout tokens.
 * Unresolvable or errored tokens resolve to "" - certificates never leak
 * template syntax (Edge US-5) - and each failure is recorded for the
 * issuance engine to note in the event meta (FR-005).
 *
 * @since 1.0.0
 */
class PressPrimer_Certificate_Merge_Field_Registry {

	/**
	 * Meta token key grammar (layout-schema.md): lowercase alphanumerics,
	 * underscores, hyphens, max 64 chars
	 *
	 * @since 1.0.0
	 * @var string
	 */
	const META_KEY_PATTERN = '/^[a-z0-9_\-]{1,64}$/';

	/**
	 * Failures recorded by the most recent resolve() call
	 *
	 * Each entry: [ 'token' => string, 'reason' => string ]. The issuance
	 * engine (Prompt 2.1) writes these into the issued event's meta as
	 * resolver_failed notes.
	 *
	 * @since 1.0.0
	 * @var array
	 */
	private static $last_failures = [];

	/**
	 * Get the full field registry for a context
	 *
	 * Defaults plus the `ppcert_register_merge_fields` filter. Entries are
	 * keyed by their token key; malformed filter additions are dropped.
	 *
	 * @since 1.0.0
	 *
	 * @param string $context 'designer' (palette building) or 'issue' (value resolution).
	 * @param array  $args    Optional scoping. 'trigger_types' (string[]) keeps
	 *                        adapter-tagged fields only when their contributing
	 *                        trigger type is listed; untagged (core) fields
	 *                        always pass. Absent = no scoping.
	 * @return array<string,array> Map of token key => field definition.
	 */
	public static function get_fields( $context, $args = [] ) {
		$defaults = self::get_core_fields();

		/** This filter is documented in docs/architecture/HOOKS.md */
		$fields = apply_filters( 'ppcert_register_merge_fields', $defaults, $context );

		if ( ! is_array( $fields ) ) {
			$fields = $defaults;
		}

		$clean = [];

		foreach ( $fields as $key => $field ) {
			// Adapters register group-keyed maps (HOOKS.md example shape:
			// $fields['source']['course_title']); core registers flat
			// token-keyed entries. Accept both, normalizing to flat.
			if ( is_array( $field ) && ! isset( $field['key'] ) && ! isset( $field['resolver'] ) && ! isset( $field['label'] ) ) {
				foreach ( $field as $sub_field ) {
					$normalized = self::normalize_field( $sub_field, (string) $key );
					if ( null !== $normalized ) {
						$clean[ $normalized['key'] ] = $normalized;
					}
				}
				continue;
			}

			$normalized = self::normalize_field( $field, '' );
			if ( null !== $normalized ) {
				$clean[ $normalized['key'] ] = $normalized;
			}
		}

		if ( isset( $args['trigger_types'] ) && is_array( $args['trigger_types'] ) ) {
			$scope = array_map( 'strval', $args['trigger_types'] );

			$clean = array_filter(
				$clean,
				static function ( $field ) use ( $scope ) {
					// Untagged (core) fields always pass; tagged fields
					// pass when ANY of their contributing trigger types
					// is in scope (shared keys like source.course_title
					// carry every course adapter's tag).
					return empty( $field['trigger_types'] )
						|| array_intersect( $field['trigger_types'], $scope );
				}
			);
		}

		return $clean;
	}

	/**
	 * Get group labels for the palette
	 *
	 * Core group labels plus a readable fallback for any group present in
	 * the registry (adapter/addon groups).
	 *
	 * @since 1.0.0
	 *
	 * @param string $context Registry context.
	 * @return array<string,string> Map of group id => label.
	 */
	public static function get_groups( $context = 'designer' ) {
		$labels = [
			'recipient'   => __( 'Recipient', 'pressprimer-certificate' ),
			'certificate' => __( 'Certificate', 'pressprimer-certificate' ),
			'site'        => __( 'Site', 'pressprimer-certificate' ),
			'source'      => __( 'Source', 'pressprimer-certificate' ),
		];

		foreach ( self::get_fields( $context ) as $field ) {
			if ( ! isset( $labels[ $field['group'] ] ) ) {
				$labels[ $field['group'] ] = ucwords( str_replace( [ '_', '-' ], ' ', $field['group'] ) );
			}
		}

		return $labels;
	}

	/**
	 * Extract the merge tokens used by a layout document
	 *
	 * Walks merge_field elements and returns their tokens in inner form
	 * (braces stripped), unique, in order of first appearance. Used by
	 * the issuance engine to know what to resolve (FR-005).
	 *
	 * @since 1.0.0
	 *
	 * @param array $layout Validated layout document.
	 * @return string[] Token keys, e.g. [ 'recipient.display_name' ].
	 */
	public static function extract_tokens( array $layout ) {
		$tokens = [];

		$elements = isset( $layout['elements'] ) && is_array( $layout['elements'] ) ? $layout['elements'] : [];

		foreach ( $elements as $element ) {
			if ( ! is_array( $element ) || ! isset( $element['type'] ) || 'merge_field' !== $element['type'] ) {
				continue;
			}

			$token = isset( $element['props']['token'] ) ? (string) $element['props']['token'] : '';
			$inner = self::strip_braces( $token );

			if ( '' !== $inner && ! in_array( $inner, $tokens, true ) ) {
				$tokens[] = $inner;
			}
		}

		return $tokens;
	}

	/**
	 * Resolve tokens into the merge data snapshot
	 *
	 * Resolves each token via its registered resolver (meta tokens per
	 * FR-003), applies the `ppcert_merge_data` filter, and returns the
	 * map the issuance engine snapshots. Unresolvable or errored tokens
	 * yield "" and are recorded (see get_last_resolution_failures()).
	 *
	 * @since 1.0.0
	 *
	 * @param int      $template_id Template row id.
	 * @param string[] $tokens      Token keys (inner form; braced input tolerated).
	 * @param array    $context     Issuance context (recipient_id, trigger_type,
	 *                              source_ref, source_post_id, credential_id,
	 *                              issued_at, ...).
	 * @return array<string,string> Map of token key => resolved value.
	 */
	public static function resolve( $template_id, array $tokens, array $context ) {
		self::$last_failures    = [];
		$context['template_id'] = absint( $template_id );

		$fields = self::get_fields( 'issue' );
		$values = [];

		foreach ( $tokens as $raw_token ) {
			$token = self::strip_braces( (string) $raw_token );

			if ( '' === $token || isset( $values[ $token ] ) ) {
				continue;
			}

			$values[ $token ] = self::resolve_token( $token, $fields, $context );
		}

		/** This filter is documented in docs/architecture/HOOKS.md */
		$values = apply_filters( 'ppcert_merge_data', $values, $context );

		return is_array( $values ) ? $values : [];
	}

	/**
	 * Failures recorded by the most recent resolve() call
	 *
	 * @since 1.0.0
	 *
	 * @return array Entries of [ 'token' => string, 'reason' => string ].
	 */
	public static function get_last_resolution_failures() {
		return self::$last_failures;
	}

	/**
	 * Resolve a single token
	 *
	 * @since 1.0.0
	 *
	 * @param string $token   Token key (inner form).
	 * @param array  $fields  Registry fields for the issue context.
	 * @param array  $context Issuance context.
	 * @return string Resolved value ('' on any failure).
	 */
	private static function resolve_token( $token, $fields, $context ) {
		// Meta tokens: {{group.meta.key}} resolve dynamically (FR-003).
		if ( preg_match( '/^([a-z0-9_]+)\.meta\.(.+)$/', $token, $matches ) ) {
			return self::resolve_meta_token( $token, $matches[1], $matches[2], $context );
		}

		if ( ! isset( $fields[ $token ] ) || ! is_callable( $fields[ $token ]['resolver'] ) ) {
			self::record_failure( $token, 'unregistered' );
			return '';
		}

		try {
			$value = call_user_func( $fields[ $token ]['resolver'], $context );
		} catch ( \Throwable $e ) {
			self::record_failure( $token, 'resolver_failed' );
			return '';
		}

		if ( ! is_scalar( $value ) ) {
			self::record_failure( $token, 'non_scalar' );
			return '';
		}

		return (string) $value;
	}

	/**
	 * Resolve a meta token (FR-003)
	 *
	 * Tokens under recipient.meta.* read user meta (denylist enforced);
	 * source.meta.* reads post meta from the adapter-supplied
	 * source_post_id. Scalar values only; anything else resolves empty.
	 *
	 * @since 1.0.0
	 *
	 * @param string $token   Full token key (for failure notes).
	 * @param string $group   Token group.
	 * @param string $key     Meta key.
	 * @param array  $context Issuance context.
	 * @return string Resolved value ('' on any failure).
	 */
	private static function resolve_meta_token( $token, $group, $key, $context ) {
		if ( ! preg_match( self::META_KEY_PATTERN, $key ) ) {
			self::record_failure( $token, 'invalid_meta_key' );
			return '';
		}

		if ( 'recipient' === $group ) {
			if ( self::is_denylisted_user_meta_key( $key ) ) {
				self::record_failure( $token, 'denylisted' );
				return '';
			}

			$recipient_id = isset( $context['recipient_id'] ) ? absint( $context['recipient_id'] ) : 0;

			if ( $recipient_id < 1 ) {
				self::record_failure( $token, 'missing_recipient' );
				return '';
			}

			$value = get_user_meta( $recipient_id, $key, true );
		} elseif ( 'source' === $group ) {
			$source_post_id = isset( $context['source_post_id'] ) ? absint( $context['source_post_id'] ) : 0;

			if ( $source_post_id < 1 ) {
				self::record_failure( $token, 'missing_source' );
				return '';
			}

			$value = get_post_meta( $source_post_id, $key, true );
		} else {
			self::record_failure( $token, 'unregistered' );
			return '';
		}

		// Scalar-only in 1.0: array/object meta resolves empty.
		if ( ! is_scalar( $value ) ) {
			self::record_failure( $token, 'non_scalar' );
			return '';
		}

		return (string) $value;
	}

	/**
	 * Whether a user meta key is denylisted for certificate resolution
	 *
	 * Keys beginning with an underscore, session tokens, and the
	 * capabilities/user-level keys never resolve (Feature 002 FR-003 and
	 * Security Requirements) - enforced here at resolution and again in
	 * the designer pickers (Prompt 3.4).
	 *
	 * @since 1.0.0
	 *
	 * @param string $key Meta key.
	 * @return bool
	 */
	public static function is_denylisted_user_meta_key( $key ) {
		if ( '' === $key || '_' === $key[0] ) {
			return true;
		}

		global $wpdb;
		$prefix = isset( $wpdb->prefix ) ? $wpdb->prefix : 'wp_';

		$denylist = [
			'session_tokens',
			$prefix . 'capabilities',
			$prefix . 'user_level',
		];

		return in_array( $key, $denylist, true );
	}

	/**
	 * Core field definitions (FR-002)
	 *
	 * @since 1.0.0
	 *
	 * @return array<string,array>
	 */
	private static function get_core_fields() {
		return [
			'recipient.display_name'    => [
				'group'    => 'recipient',
				'key'      => 'recipient.display_name',
				'label'    => __( 'Recipient Name', 'pressprimer-certificate' ),
				'sample'   => __( 'Jordan Rivera', 'pressprimer-certificate' ),
				'resolver' => [ __CLASS__, 'resolve_display_name' ],
			],
			'recipient.first_name'      => [
				'group'    => 'recipient',
				'key'      => 'recipient.first_name',
				'label'    => __( 'First Name', 'pressprimer-certificate' ),
				'sample'   => __( 'Jordan', 'pressprimer-certificate' ),
				'resolver' => [ __CLASS__, 'resolve_first_name' ],
			],
			'recipient.last_name'       => [
				'group'    => 'recipient',
				'key'      => 'recipient.last_name',
				'label'    => __( 'Last Name', 'pressprimer-certificate' ),
				'sample'   => __( 'Rivera', 'pressprimer-certificate' ),
				'resolver' => [ __CLASS__, 'resolve_last_name' ],
			],
			'recipient.full_name'       => [
				'group'    => 'recipient',
				'key'      => 'recipient.full_name',
				'label'    => __( 'Full Name', 'pressprimer-certificate' ),
				'sample'   => __( 'Jordan Rivera', 'pressprimer-certificate' ),
				'resolver' => [ __CLASS__, 'resolve_full_name' ],
			],
			'recipient.email'           => [
				'group'    => 'recipient',
				'key'      => 'recipient.email',
				'label'    => __( 'Email', 'pressprimer-certificate' ),
				'sample'   => 'jordan@example.com',
				'resolver' => [ __CLASS__, 'resolve_email' ],
			],
			'certificate.issue_date'    => [
				'group'    => 'certificate',
				'key'      => 'certificate.issue_date',
				'label'    => __( 'Issue Date', 'pressprimer-certificate' ),
				'sample'   => __( 'June 12, 2026', 'pressprimer-certificate' ),
				'resolver' => [ __CLASS__, 'resolve_issue_date' ],
			],
			'certificate.credential_id' => [
				'group'    => 'certificate',
				'key'      => 'certificate.credential_id',
				'label'    => __( 'Credential ID', 'pressprimer-certificate' ),
				'sample'   => '7Q4M-K9P2-XT3A',
				'resolver' => [ __CLASS__, 'resolve_credential_id' ],
			],
			'certificate.issuer_name'   => [
				'group'    => 'certificate',
				'key'      => 'certificate.issuer_name',
				'label'    => __( 'Issuer Name', 'pressprimer-certificate' ),
				'sample'   => __( 'Sunrise Training Academy', 'pressprimer-certificate' ),
				'resolver' => [ __CLASS__, 'resolve_issuer_name' ],
			],
			'certificate.expiry_date'   => [
				'group'    => 'certificate',
				'key'      => 'certificate.expiry_date',
				'label'    => __( 'Expiry Date', 'pressprimer-certificate' ),
				'sample'   => __( 'June 12, 2028', 'pressprimer-certificate' ),
				'resolver' => [ __CLASS__, 'resolve_expiry_date' ],
			],
			'site.name'                 => [
				'group'    => 'site',
				'key'      => 'site.name',
				'label'    => __( 'Site Name', 'pressprimer-certificate' ),
				'sample'   => __( 'Sunrise Training Academy', 'pressprimer-certificate' ),
				'resolver' => [ __CLASS__, 'resolve_site_name' ],
			],
			'site.tagline'              => [
				'group'    => 'site',
				'key'      => 'site.tagline',
				'label'    => __( 'Site Tagline', 'pressprimer-certificate' ),
				'sample'   => __( 'Learn. Grow. Achieve.', 'pressprimer-certificate' ),
				'resolver' => [ __CLASS__, 'resolve_site_tagline' ],
			],
			'site.url'                  => [
				'group'    => 'site',
				'key'      => 'site.url',
				'label'    => __( 'Site URL', 'pressprimer-certificate' ),
				'sample'   => 'https://example.com',
				'resolver' => [ __CLASS__, 'resolve_site_url' ],
			],
		];
	}

	/*
	 * ------------------------------------------------------------------
	 * Core resolvers. Each receives the issuance context (FR-001).
	 * ------------------------------------------------------------------
	 */

	/**
	 * Resolve recipient display name.
	 *
	 * @since 1.0.0
	 *
	 * @param array $context Issuance context.
	 * @return string
	 */
	public static function resolve_display_name( array $context ) {
		$user = self::get_recipient( $context );
		return $user ? (string) $user->display_name : '';
	}

	/**
	 * Resolve recipient first name.
	 *
	 * @since 1.0.0
	 *
	 * @param array $context Issuance context.
	 * @return string
	 */
	public static function resolve_first_name( array $context ) {
		$user = self::get_recipient( $context );
		return $user ? (string) $user->first_name : '';
	}

	/**
	 * Resolve recipient last name.
	 *
	 * @since 1.0.0
	 *
	 * @param array $context Issuance context.
	 * @return string
	 */
	public static function resolve_last_name( array $context ) {
		$user = self::get_recipient( $context );
		return $user ? (string) $user->last_name : '';
	}

	/**
	 * Resolve recipient full name: first + last, display_name fallback
	 * (Feature 002 Edge Cases).
	 *
	 * @since 1.0.0
	 *
	 * @param array $context Issuance context.
	 * @return string
	 */
	public static function resolve_full_name( array $context ) {
		$user = self::get_recipient( $context );

		if ( ! $user ) {
			return '';
		}

		$full = trim( (string) $user->first_name . ' ' . (string) $user->last_name );

		return '' !== $full ? $full : (string) $user->display_name;
	}

	/**
	 * Resolve recipient email.
	 *
	 * @since 1.0.0
	 *
	 * @param array $context Issuance context.
	 * @return string
	 */
	public static function resolve_email( array $context ) {
		$user = self::get_recipient( $context );
		return $user ? (string) $user->user_email : '';
	}

	/**
	 * Resolve the issue date: site date format, from the UTC issued_at
	 * (CLAUDE.md Datetime Standard: UTC in, localized out).
	 *
	 * @since 1.0.0
	 *
	 * @param array $context Issuance context.
	 * @return string
	 */
	public static function resolve_issue_date( array $context ) {
		$issued_at = isset( $context['issued_at'] ) ? (string) $context['issued_at'] : '';

		if ( '' === $issued_at ) {
			return '';
		}

		return (string) get_date_from_gmt( $issued_at, get_option( 'date_format' ) );
	}

	/**
	 * Resolve the credential ID in display form (XXXX-XXXX-XXXX) - the
	 * printed certificate shows the human-formatted grouping.
	 *
	 * @since 1.0.0
	 *
	 * @param array $context Issuance context.
	 * @return string
	 */
	public static function resolve_credential_id( array $context ) {
		$credential_id = isset( $context['credential_id'] ) ? (string) $context['credential_id'] : '';

		if ( '' === $credential_id ) {
			return '';
		}

		return PressPrimer_Certificate_Credential_ID_Service::format_display( $credential_id );
	}

	/**
	 * Resolve the issuer name: site name in 1.0; the issuer entity fills
	 * this same field in School 2.0 with no new token.
	 *
	 * @since 1.0.0
	 *
	 * @param array $context Issuance context.
	 * @return string
	 */
	public static function resolve_issuer_name( array $context ) {
		return (string) get_bloginfo( 'name' );
	}

	/**
	 * Resolve the expiry date: empty in 1.0 issuance (the field exists so
	 * Educator 2.0 needs no new token).
	 *
	 * @since 1.0.0
	 *
	 * @param array $context Issuance context.
	 * @return string
	 */
	public static function resolve_expiry_date( array $context ) {
		$expires_at = isset( $context['expires_at'] ) ? (string) $context['expires_at'] : '';

		if ( '' === $expires_at ) {
			return '';
		}

		// UTC in, localized out (CLAUDE.md Datetime Standard).
		return (string) get_date_from_gmt( $expires_at, get_option( 'date_format' ) );
	}

	/**
	 * Resolve the site name.
	 *
	 * @since 1.0.0
	 *
	 * @param array $context Issuance context.
	 * @return string
	 */
	public static function resolve_site_name( array $context ) {
		return (string) get_bloginfo( 'name' );
	}

	/**
	 * Resolve the site tagline.
	 *
	 * @since 1.0.0
	 *
	 * @param array $context Issuance context.
	 * @return string
	 */
	public static function resolve_site_tagline( array $context ) {
		return (string) get_bloginfo( 'description' );
	}

	/**
	 * Resolve the site URL.
	 *
	 * @since 1.0.0
	 *
	 * @param array $context Issuance context.
	 * @return string
	 */
	public static function resolve_site_url( array $context ) {
		return (string) get_bloginfo( 'url' );
	}

	/*
	 * ------------------------------------------------------------------
	 * Internals.
	 * ------------------------------------------------------------------
	 */

	/**
	 * Get the recipient user object from the context.
	 *
	 * @since 1.0.0
	 *
	 * @param array $context Issuance context.
	 * @return WP_User|false
	 */
	private static function get_recipient( array $context ) {
		$recipient_id = isset( $context['recipient_id'] ) ? absint( $context['recipient_id'] ) : 0;

		return $recipient_id > 0 ? get_userdata( $recipient_id ) : false;
	}

	/**
	 * Strip token braces: '{{recipient.display_name}}' => 'recipient.display_name'.
	 *
	 * @since 1.0.0
	 *
	 * @param string $token Token in either form.
	 * @return string Inner token key.
	 */
	private static function strip_braces( $token ) {
		return trim( str_replace( [ '{{', '}}' ], '', trim( $token ) ) );
	}

	/**
	 * Normalize a field definition; null when malformed.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed  $field Raw field definition.
	 * @param string $group Group hint when registered group-keyed.
	 * @return array|null
	 */
	private static function normalize_field( $field, $group ) {
		if ( ! is_array( $field ) ) {
			return null;
		}

		$key = isset( $field['key'] ) ? (string) $field['key'] : '';

		if ( '' === $key ) {
			return null;
		}

		$field_group = isset( $field['group'] ) ? (string) $field['group'] : $group;

		if ( '' === $field_group ) {
			$parts       = explode( '.', $key );
			$field_group = $parts[0];
		}

		// Empty = core field (always in scope); adapters tag their
		// fields with their trigger type id(s) for designer scoping. A
		// key shared by several trigger types (source.course_title
		// across course adapters) carries all of their tags.
		$trigger_types = [];

		if ( isset( $field['trigger_types'] ) && is_array( $field['trigger_types'] ) ) {
			$trigger_types = array_values( array_filter( array_map( 'strval', $field['trigger_types'] ) ) );
		} elseif ( isset( $field['trigger_type'] ) && is_string( $field['trigger_type'] ) && '' !== $field['trigger_type'] ) {
			$trigger_types = [ $field['trigger_type'] ];
		}

		return [
			'group'         => $field_group,
			'key'           => $key,
			'label'         => isset( $field['label'] ) ? (string) $field['label'] : $key,
			'sample'        => isset( $field['sample'] ) && is_scalar( $field['sample'] ) ? (string) $field['sample'] : '',
			'resolver'      => isset( $field['resolver'] ) && is_callable( $field['resolver'] ) ? $field['resolver'] : null,
			'trigger_types' => $trigger_types,
		];
	}

	/**
	 * Record a resolution failure.
	 *
	 * @since 1.0.0
	 *
	 * @param string $token  Token key.
	 * @param string $reason Failure reason slug.
	 */
	private static function record_failure( $token, $reason ) {
		self::$last_failures[] = [
			'token'  => $token,
			'reason' => $reason,
		];
	}
}
