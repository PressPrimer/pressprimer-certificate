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
				'template_id' => absint( $this->request_filter( 'template_id' ) ),
				'status'      => $this->request_filter( 'status' ),
				'source_type' => $this->request_filter( 'source_type' ),
				'search'      => $this->request_filter( 's' ),
				'page'        => $page,
				'per_page'    => self::PER_PAGE,
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
	 * Filter dropdowns above the table (FR-003: template/status/source)
	 *
	 * @since 1.0.0
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

		echo '<div class="alignleft actions">';

		echo '<label class="screen-reader-text" for="ppcert-filter-template">'
			. esc_html__( 'Filter by template', 'pressprimer-certificate' ) . '</label>';
		echo '<select name="template_id" id="ppcert-filter-template">';
		echo '<option value="">' . esc_html__( 'All templates', 'pressprimer-certificate' ) . '</option>';

		foreach ( PressPrimer_Certificate_Template::get_all() as $template ) {
			printf(
				'<option value="%d" %s>%s</option>',
				(int) $template->id,
				selected( $current_template, (int) $template->id, false ),
				esc_html( (string) $template->title )
			);
		}

		echo '</select>';

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

		foreach ( $this->source_options( $current_source ) as $value => $label ) {
			printf(
				'<option value="%s" %s>%s</option>',
				esc_attr( $value ),
				selected( $current_source, $value, false ),
				esc_html( $label )
			);
		}

		echo '</select>';

		submit_button( __( 'Filter', 'pressprimer-certificate' ), '', 'filter_action', false );

		echo '</div>';
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
	private function source_options( $current ) {
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
			'view'     => '<a href="' . esc_url( ppcert_verification_url( (string) $item->credential_id ) ) . '">' . esc_html__( 'View', 'pressprimer-certificate' ) . '</a>',
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
				$template = PressPrimer_Certificate_Template::get( (int) $item->template_id );

				return esc_html( $template ? (string) $template->title : __( '(deleted template)', 'pressprimer-certificate' ) );

			case 'source':
				if ( 'manual' === (string) $item->source_type ) {
					return esc_html__( 'Manual', 'pressprimer-certificate' );
				}

				$type = PressPrimer_Certificate_Trigger_Registry::get_type( (string) $item->source_type );

				return esc_html( $type ? $type['label'] : (string) $item->source_type );

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
	 * @since 1.0.0
	 */
	public function no_items() {
		esc_html_e( 'No certificates issued yet. Add an award trigger to a published template, or issue one manually.', 'pressprimer-certificate' );
	}
}
