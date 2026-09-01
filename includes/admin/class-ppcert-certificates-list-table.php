<?php
/**
 * Certificates list table
 *
 * The issued-certificates admin list (Feature 003 FR-003).
 *
 * @package PressPrimer_Certificate
 * @subpackage Admin
 * @since 1.0.0
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Certificates list table class
 *
 * List views stay PHP (WP_List_Table) per the ecosystem "List + Detail"
 * pattern; manual issuance mounts as a React modal beside the title.
 *
 * @since 1.0.0
 */
class PressPrimer_Certificate_Certificates_List_Table extends WP_List_Table {

	/**
	 * Page size
	 *
	 * @since 1.0.0
	 * @var int
	 */
	const PER_PAGE = 20;

	/**
	 * Constructor
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		parent::__construct(
			[
				'singular' => 'ppcert_certificate',
				'plural'   => 'ppcert_certificates',
				'ajax'     => false,
			]
		);
	}

	/**
	 * Columns (FR-003)
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	public function get_columns() {
		return [
			'credential_id' => __( 'Credential ID', 'pressprimer-certificate' ),
			'recipient'     => __( 'Recipient', 'pressprimer-certificate' ),
			'template'      => __( 'Template', 'pressprimer-certificate' ),
			'source'        => __( 'Source', 'pressprimer-certificate' ),
			'status'        => __( 'Status', 'pressprimer-certificate' ),
			'issued_at'     => __( 'Issued', 'pressprimer-certificate' ),
			'expires_at'    => __( 'Expires', 'pressprimer-certificate' ),
		];
	}

	/**
	 * Read a sanitized filter value from the request
	 *
	 * Display-only routing: capability enforcement happened at the
	 * screen, and every value re-sanitizes in the model query.
	 *
	 * @since 1.0.0
	 *
	 * @param string $key Query arg.
	 * @return string
	 */
	private function request_filter( $key ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only list filtering.
		return isset( $_GET[ $key ] ) ? sanitize_text_field( wp_unslash( $_GET[ $key ] ) ) : '';
	}

	/**
	 * Load items via the model query
	 *
	 * @since 1.0.0
	 */
	public function prepare_items() {
		$this->_column_headers = [ $this->get_columns(), [], [] ];

		$page = max( 1, $this->get_pagenum() );

		$result = PressPrimer_Certificate_Certificate::query(
			[
				'template_id'   => absint( $this->request_filter( 'template_id' ) ),
				'status'        => $this->request_filter( 'status' ),
				'source_type'   => $this->request_filter( 'source_type' ),
				'search'        => $this->request_filter( 's' ),
				'issued_after'  => $this->request_filter( 'issued_after' ),
				'issued_before' => $this->request_filter( 'issued_before' ),
				'page'          => $page,
				'per_page'      => self::PER_PAGE,
			]
		);

		$this->items = $result['items'];

		$this->set_pagination_args(
			[
				'total_items' => $result['total'],
				'per_page'    => self::PER_PAGE,
			]
		);
	}

	/**
	 * Filter controls above the table (2.0, Feature 2.0-002 FR-002/FR-003)
	 *
	 * The ecosystem list pattern (Assignment's Submissions screen is the
	 * reference implementation): native selects and date inputs inside
	 * extra_tablenav, one Filter submit, and a Reset Filters link that
	 * hides itself when nothing is active. All controls submit through
	 * the page's single GET form, so state lives in the URL.
	 *
	 * @since 1.0.0
	 * @since 2.0.0 Date range, labeled archived/deleted template options,
	 *              pill-semantics status filter, reset link.
	 *
	 * @param string $which 'top' or 'bottom'.
	 */
	protected function extra_tablenav( $which ) {
		if ( 'top' !== $which ) {
			return;
		}

		$current_template = absint( $this->request_filter( 'template_id' ) );
		$current_status   = $this->request_filter( 'status' );
		$current_source   = $this->request_filter( 'source_type' );
		$issued_after     = $this->request_filter( 'issued_after' );
		$issued_before    = $this->request_filter( 'issued_before' );

		echo '<div class="alignleft actions">';

		// Template filter: every non-deleted template (archived labeled),
		// plus deleted templates that still have certificates, so their
		// rows stay findable (Feature 2.0-002 Edge Cases).
		echo '<label class="screen-reader-text" for="ppcert-filter-template">'
			. esc_html__( 'Filter by template', 'pressprimer-certificate' ) . '</label>';
		echo '<select name="template_id" id="ppcert-filter-template">';
		echo '<option value="">' . esc_html__( 'All templates', 'pressprimer-certificate' ) . '</option>';

		foreach ( PressPrimer_Certificate_Template::get_certificate_filter_templates() as $template ) {
			$title = (string) $template->title;

			if ( ! empty( $template->deleted_at ) ) {
				/* translators: %s: template title */
				$title = sprintf( __( '%s (deleted)', 'pressprimer-certificate' ), $title );
			} elseif ( 'archived' === (string) $template->status ) {
				/* translators: %s: template title */
				$title = sprintf( __( '%s (archived)', 'pressprimer-certificate' ), $title );
			}

			printf(
				'<option value="%d" %s>%s</option>',
				(int) $template->id,
				selected( $current_template, (int) $template->id, false ),
				esc_html( $title )
			);
		}

		echo '</select>';

		// Status filter: the same read-time expiry semantics the status
		// pill displays (FR-002 - the two must never disagree).
		$statuses = [
			'issued'  => __( 'Issued', 'pressprimer-certificate' ),
			'revoked' => __( 'Revoked', 'pressprimer-certificate' ),
			'expired' => __( 'Expired', 'pressprimer-certificate' ),
		];

		echo '<label class="screen-reader-text" for="ppcert-filter-cert-status">'
			. esc_html__( 'Filter by status', 'pressprimer-certificate' ) . '</label>';
		echo '<select name="status" id="ppcert-filter-cert-status">';
		echo '<option value="">' . esc_html__( 'All statuses', 'pressprimer-certificate' ) . '</option>';

		foreach ( $statuses as $value => $label ) {
			printf(
				'<option value="%s" %s>%s</option>',
				esc_attr( $value ),
				selected( $current_status, $value, false ),
				esc_html( $label )
			);
		}

		echo '</select>';

		echo '<label class="screen-reader-text" for="ppcert-filter-source">'
			. esc_html__( 'Filter by source', 'pressprimer-certificate' ) . '</label>';
		echo '<select name="source_type" id="ppcert-filter-source">';
		echo '<option value="">' . esc_html__( 'All sources', 'pressprimer-certificate' ) . '</option>';

		foreach ( self::source_options( $current_source ) as $value => $label ) {
			printf(
				'<option value="%s" %s>%s</option>',
				esc_attr( $value ),
				selected( $current_source, $value, false ),
				esc_html( $label )
			);
		}

		echo '</select>';

		// Issued date range (FR-002): native date inputs, the Submissions
		// screen idiom. Dates are interpreted in the site timezone with
		// inclusive day edges (converted to UTC in the model query).
		echo '<label class="screen-reader-text" for="ppcert-filter-issued-after">'
			. esc_html__( 'Issued from date', 'pressprimer-certificate' ) . '</label>';
		printf(
			'<input type="date" name="issued_after" id="ppcert-filter-issued-after" value="%s" aria-label="%s" />',
			esc_attr( $issued_after ),
			esc_attr__( 'Issued from date', 'pressprimer-certificate' )
		);
		echo '<label for="ppcert-filter-issued-before"> '
			. esc_html_x( 'to', 'date range separator between the two issued-date inputs', 'pressprimer-certificate' ) . ' </label>';
		printf(
			'<input type="date" name="issued_before" id="ppcert-filter-issued-before" value="%s" aria-label="%s" />',
			esc_attr( $issued_before ),
			esc_attr__( 'Issued to date', 'pressprimer-certificate' )
		);

		submit_button( __( 'Filter', 'pressprimer-certificate' ), '', 'filter_action', false );

		$this->render_reset_link();

		echo '</div>';
	}

	/**
	 * Reset Filters link - rendered only when a filter is active
	 *
	 * The Submissions screen pattern: a plain link back to the bare list
	 * URL, invisible when there is nothing to reset.
	 *
	 * @since 2.0.0
	 */
	private function render_reset_link() {
		$active = false;

		foreach ( [ 'template_id', 'status', 'source_type', 'issued_after', 'issued_before', 's' ] as $key ) {
			if ( '' !== $this->request_filter( $key ) ) {
				$active = true;
				break;
			}
		}

		if ( ! $active ) {
			return;
		}

		printf(
			' <a href="%s" class="button">%s</a>',
			esc_url( admin_url( 'admin.php?page=ppcert-certificates' ) ),
			esc_html__( 'Reset Filters', 'pressprimer-certificate' )
		);
	}

	/**
	 * Source filter options: manual plus registered trigger types (plus
	 * the currently filtered value so an inert type's filter survives)
	 *
	 * @since 1.0.0
	 *
	 * @param string $current Currently selected source type.
	 * @return array<string,string>
	 */
	public static function source_options( $current ) {
		$options = [ 'manual' => __( 'Manual', 'pressprimer-certificate' ) ];

		foreach ( PressPrimer_Certificate_Trigger_Registry::get_types() as $type ) {
			$options[ $type['id'] ] = $type['label'];
		}

		if ( '' !== $current && ! isset( $options[ $current ] ) ) {
			$options[ $current ] = $current;
		}

		return $options;
	}

	/**
	 * Credential ID column with the View + Download PDF row actions
	 *
	 * @since 1.0.0
	 *
	 * @param object $item Certificate row.
	 * @return string
	 */
	public function column_credential_id( $item ) {
		$display = PressPrimer_Certificate_Credential_ID_Service::format_display( (string) $item->credential_id );

		$download_url = wp_nonce_url(
			add_query_arg(
				[
					'action'         => 'ppcert_download_certificate',
					'certificate_id' => (int) $item->id,
				],
				admin_url( 'admin-post.php' )
			),
			'ppcert_download_certificate_' . (int) $item->id
		);

		$actions = [
			'view'     => '<a href="' . esc_url( ppcert_verification_url( (string) $item->credential_id ) ) . '" target="_blank" rel="noopener">' . esc_html__( 'View', 'pressprimer-certificate' )
				. '<span class="screen-reader-text"> ' . esc_html__( '(opens in a new tab)', 'pressprimer-certificate' ) . '</span></a>',
			'download' => '<a href="' . esc_url( $download_url ) . '">' . esc_html__( 'Download PDF', 'pressprimer-certificate' ) . '</a>',
		];

		// Lifecycle actions (issue capability): Revoke confirms through
		// the house modal (ppcert-admin.js intercepts the flagged link
		// and collects the optional reason); Reinstate undoes a
		// mistaken revocation directly (it restores validity - no
		// confirm).
		if ( current_user_can( PressPrimer_Certificate_Capabilities::CAP_ISSUE_CERTIFICATES ) ) {
			if ( 'revoked' === (string) $item->status ) {
				$reinstate_url = wp_nonce_url(
					add_query_arg(
						[
							'page'           => 'ppcert-certificates',
							'action'         => 'ppcert-reinstate',
							'certificate_id' => (int) $item->id,
						],
						admin_url( 'admin.php' )
					),
					'ppcert_reinstate_certificate_' . (int) $item->id
				);

				$actions['reinstate'] = '<a href="' . esc_url( $reinstate_url ) . '">' . esc_html__( 'Reinstate', 'pressprimer-certificate' ) . '</a>';
			} else {
				$revoke_url = wp_nonce_url(
					add_query_arg(
						[
							'page'           => 'ppcert-certificates',
							'action'         => 'ppcert-revoke',
							'certificate_id' => (int) $item->id,
						],
						admin_url( 'admin.php' )
					),
					'ppcert_revoke_certificate_' . (int) $item->id
				);

				$actions['revoke'] = '<a href="' . esc_url( $revoke_url ) . '" class="submitdelete ppcert-confirm-link"'
					. ' data-ppcert-title="' . esc_attr__( 'Revoke certificate', 'pressprimer-certificate' ) . '"'
					. ' data-ppcert-message="' . esc_attr(
						sprintf(
							/* translators: %s: credential ID. */
							__( '%s will verify as revoked and its PDF download will be disabled. You can reinstate it later if this is a mistake.', 'pressprimer-certificate' ),
							$display
						)
					) . '"'
					. ' data-ppcert-confirm="' . esc_attr__( 'Revoke', 'pressprimer-certificate' ) . '"'
					. ' data-ppcert-cancel="' . esc_attr__( 'Cancel', 'pressprimer-certificate' ) . '"'
					. ' data-ppcert-input-label="' . esc_attr__( 'Reason (optional, shown to staff only):', 'pressprimer-certificate' ) . '"'
					. ' data-ppcert-input-name="revoke_reason"'
					. '>' . esc_html__( 'Revoke', 'pressprimer-certificate' ) . '</a>';

				// Resend the delivery email (not offered for revoked
				// certificates - their downloads are disabled).
				$resend_url = wp_nonce_url(
					add_query_arg(
						[
							'page'           => 'ppcert-certificates',
							'action'         => 'ppcert-resend-email',
							'certificate_id' => (int) $item->id,
						],
						admin_url( 'admin.php' )
					),
					'ppcert_resend_email_' . (int) $item->id
				);

				$actions['resend'] = '<a href="' . esc_url( $resend_url ) . '">' . esc_html__( 'Resend email', 'pressprimer-certificate' ) . '</a>';
			}

			// Permanent deletion (test data and mistakes) - always
			// available, confirmed through the house modal.
			$delete_url = wp_nonce_url(
				add_query_arg(
					[
						'page'           => 'ppcert-certificates',
						'action'         => 'ppcert-delete',
						'certificate_id' => (int) $item->id,
					],
					admin_url( 'admin.php' )
				),
				'ppcert_delete_certificate_' . (int) $item->id
			);

			$actions['delete'] = '<a href="' . esc_url( $delete_url ) . '" class="submitdelete ppcert-confirm-link"'
				. ' data-ppcert-title="' . esc_attr__( 'Delete certificate', 'pressprimer-certificate' ) . '"'
				. ' data-ppcert-message="' . esc_attr(
					sprintf(
						/* translators: %s: credential ID. */
						__( '%s and its history will be permanently deleted, and the credential will no longer verify at all. This cannot be undone. To invalidate an earned credential while keeping its record, use Revoke instead.', 'pressprimer-certificate' ),
						$display
					)
				) . '"'
				. ' data-ppcert-confirm="' . esc_attr__( 'Delete permanently', 'pressprimer-certificate' ) . '"'
				. ' data-ppcert-cancel="' . esc_attr__( 'Cancel', 'pressprimer-certificate' ) . '"'
				. '>' . esc_html__( 'Delete', 'pressprimer-certificate' ) . '</a>';
		}

		return '<strong>' . esc_html( $display ) . '</strong>' . $this->row_actions( $actions );
	}

	/**
	 * Default column rendering
	 *
	 * @since 1.0.0
	 *
	 * @param object $item        Certificate row.
	 * @param string $column_name Column key.
	 * @return string
	 */
	public function column_default( $item, $column_name ) {
		switch ( $column_name ) {
			case 'recipient':
				$user = get_userdata( (int) $item->recipient_id );

				return esc_html( $user ? (string) $user->display_name : __( '(deleted user)', 'pressprimer-certificate' ) );

			case 'template':
				$template       = PressPrimer_Certificate_Template::get( (int) $item->template_id );
				$template_title = $template ? (string) $template->title : __( '(deleted template)', 'pressprimer-certificate' );

				// The certificate's own name leads (Feature 1.1-006); the
				// template title shows beneath it only when they differ.
				$item->template_title = $template ? (string) $template->title : null;
				$name                 = PressPrimer_Certificate_Certificate::display_title( $item, $template_title );

				if ( $name === $template_title ) {
					return esc_html( $name );
				}

				return esc_html( $name ) . '<span class="ppcert-list-secondary">' . esc_html( $template_title ) . '</span>';

			case 'source':
				if ( 'manual' === (string) $item->source_type ) {
					return esc_html__( 'Manual', 'pressprimer-certificate' );
				}

				$type = PressPrimer_Certificate_Trigger_Registry::get_type( (string) $item->source_type );

				if ( $type ) {
					return esc_html( $type['label'] );
				}

				// Unregistered at render time (deactivated integration):
				// the adapter-class details still label it, exactly like
				// the templates list (2.0, Feature 2.0-007 FR-004) - a
				// raw source_type id is the last resort.
				$details = PressPrimer_Certificate_Plugin::get_trigger_type_details();

				if ( isset( $details[ (string) $item->source_type ] ) ) {
					return esc_html( $details[ (string) $item->source_type ]['short_label'] );
				}

				return esc_html( (string) $item->source_type );

			case 'status':
				$labels = [
					'issued'  => __( 'Issued', 'pressprimer-certificate' ),
					'revoked' => __( 'Revoked', 'pressprimer-certificate' ),
					'expired' => __( 'Expired', 'pressprimer-certificate' ),
				];

				$status = PressPrimer_Certificate_Certificate::effective_status( $item );
				$output = esc_html( isset( $labels[ $status ] ) ? $labels[ $status ] : $status );

				// The revocation reason surfaces to staff beneath the
				// status (collected by the Revoke confirmation modal).
				if ( 'revoked' === $status && ! empty( $item->revoke_reason ) ) {
					$output .= '<br /><span class="description">' . esc_html( (string) $item->revoke_reason ) . '</span>';
				}

				return $output;

			case 'expires_at':
				if ( empty( $item->expires_at ) ) {
					return '<span aria-hidden="true">&#8212;</span><span class="screen-reader-text">'
						. esc_html__( 'Never expires', 'pressprimer-certificate' ) . '</span>';
				}

				// UTC in, localized out (CLAUDE.md Datetime Standard).
				return esc_html( get_date_from_gmt( (string) $item->expires_at, get_option( 'date_format' ) ) );

			case 'issued_at':
				// UTC in, localized out (CLAUDE.md Datetime Standard).
				return esc_html(
					get_date_from_gmt(
						(string) $item->issued_at,
						get_option( 'date_format' ) . ' ' . get_option( 'time_format' )
					)
				);

			default:
				return '';
		}
	}

	/**
	 * Empty-state message
	 *
	 * When filters are active the message names the criteria (Feature
	 * 2.0-002 FR-004) so "no results" reads as "no results FOR THIS",
	 * never as "nothing issued".
	 *
	 * @since 1.0.0
	 */
	public function no_items() {
		$criteria = [];

		$search = $this->request_filter( 's' );
		if ( '' !== $search ) {
			/* translators: %s: search term */
			$criteria[] = sprintf( __( 'search "%s"', 'pressprimer-certificate' ), $search );
		}

		$template_id = absint( $this->request_filter( 'template_id' ) );
		if ( $template_id > 0 ) {
			$template = PressPrimer_Certificate_Template::get( $template_id );
			/* translators: %s: template title */
			$criteria[] = sprintf( __( 'template "%s"', 'pressprimer-certificate' ), $template ? (string) $template->title : (string) $template_id );
		}

		$status = $this->request_filter( 'status' );
		if ( '' !== $status ) {
			$labels = [
				'issued'  => __( 'Issued', 'pressprimer-certificate' ),
				'revoked' => __( 'Revoked', 'pressprimer-certificate' ),
				'expired' => __( 'Expired', 'pressprimer-certificate' ),
			];
			/* translators: %s: status label */
			$criteria[] = sprintf( __( 'status %s', 'pressprimer-certificate' ), isset( $labels[ $status ] ) ? $labels[ $status ] : $status );
		}

		$source = $this->request_filter( 'source_type' );
		if ( '' !== $source ) {
			$sources = self::source_options( $source );
			/* translators: %s: source label */
			$criteria[] = sprintf( __( 'source %s', 'pressprimer-certificate' ), isset( $sources[ $source ] ) ? $sources[ $source ] : $source );
		}

		$after  = $this->request_filter( 'issued_after' );
		$before = $this->request_filter( 'issued_before' );
		if ( '' !== $after || '' !== $before ) {
			if ( '' !== $after && '' !== $before ) {
				/* translators: 1: from date, 2: to date */
				$criteria[] = sprintf( __( 'issued %1$s to %2$s', 'pressprimer-certificate' ), $after, $before );
			} elseif ( '' !== $after ) {
				/* translators: %s: from date */
				$criteria[] = sprintf( __( 'issued from %s', 'pressprimer-certificate' ), $after );
			} else {
				/* translators: %s: to date */
				$criteria[] = sprintf( __( 'issued up to %s', 'pressprimer-certificate' ), $before );
			}
		}

		if ( empty( $criteria ) ) {
			esc_html_e( 'No certificates issued yet. Add an award trigger to a published template, or issue one manually.', 'pressprimer-certificate' );
			return;
		}

		printf(
			/* translators: %s: comma-separated list of the active filters */
			esc_html__( 'No certificates match the current filters: %s. Clear or adjust the filters above.', 'pressprimer-certificate' ),
			esc_html( implode( ', ', $criteria ) )
		);
	}
}
