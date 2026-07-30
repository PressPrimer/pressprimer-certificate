<?php
/**
 * Templates list table
 *
 * The Templates admin list (WP_List_Table per ecosystem convention):
 * trigger column, status + integration filters, title search,
 * pagination, and the Edit / Duplicate / Trash row actions
 * (Phase 5B items 3-5).
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
 * Templates list table class
 *
 * List views stay PHP (WP_List_Table) and link into the React designer -
 * the ecosystem "List + Detail" pattern.
 *
 * @since 1.0.0
 */
class PressPrimer_Certificate_Templates_List_Table extends WP_List_Table {

	/**
	 * Rows per page
	 *
	 * @since 1.0.0
	 * @var int
	 */
	const PER_PAGE = 20;

	/**
	 * Triggers per template for the current page
	 *
	 * @since 1.0.0
	 * @var array
	 */
	private $triggers = [];

	/**
	 * Trigger type display details (integration + short label)
	 *
	 * @since 1.0.0
	 * @var array
	 */
	private $type_details = [];

	/**
	 * Constructor
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		parent::__construct(
			[
				'singular' => 'ppcert_template',
				'plural'   => 'ppcert_templates',
				'ajax'     => false,
			]
		);
	}

	/**
	 * Columns
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	public function get_columns() {
		return [
			'title'      => __( 'Title', 'pressprimer-certificate' ),
			'status'     => __( 'Status', 'pressprimer-certificate' ),
			'trigger'    => __( 'Trigger', 'pressprimer-certificate' ),
			'page'       => __( 'Page', 'pressprimer-certificate' ),
			'updated_at' => __( 'Last Updated', 'pressprimer-certificate' ),
		];
	}

	/**
	 * Read a list filter parameter
	 *
	 * Display-only routing values on a read-only list request; each value
	 * is sanitized on read (no nonce by design, matching core list
	 * tables).
	 *
	 * @since 1.0.0
	 *
	 * @param string $key Parameter name.
	 * @return string
	 */
	private function filter_param( $key ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only list filtering.
		return isset( $_GET[ $key ] ) ? sanitize_text_field( wp_unslash( $_GET[ $key ] ) ) : '';
	}

	/**
	 * Load items with filters, search, and pagination
	 *
	 * @since 1.0.0
	 */
	public function prepare_items() {
		$this->_column_headers = [ $this->get_columns(), [], [] ];

		$integration = $this->filter_param( 'integration' );
		$map         = PressPrimer_Certificate_Plugin::get_integration_map();

		$result = PressPrimer_Certificate_Template::query(
			[
				'status'        => sanitize_key( $this->filter_param( 'status' ) ),
				'search'        => $this->filter_param( 's' ),
				'trigger_types' => '' !== $integration && isset( $map[ $integration ] ) ? $map[ $integration ] : [],
				'page'          => max( 1, absint( $this->filter_param( 'paged' ) ) ),
				'per_page'      => self::PER_PAGE,
			]
		);

		$this->items        = $result['items'];
		$this->type_details = PressPrimer_Certificate_Plugin::get_trigger_type_details();
		$this->triggers     = PressPrimer_Certificate_Trigger::get_for_templates(
			array_map(
				static function ( $item ) {
					return (int) $item->id;
				},
				$result['items']
			)
		);

		$this->set_pagination_args(
			[
				'total_items' => $result['total'],
				'per_page'    => self::PER_PAGE,
				'total_pages' => (int) ceil( $result['total'] / self::PER_PAGE ),
			]
		);
	}

	/**
	 * Status + integration filter dropdowns
	 *
	 * @since 1.0.0
	 *
	 * @param string $which 'top' or 'bottom'.
	 */
	protected function extra_tablenav( $which ) {
		if ( 'top' !== $which ) {
			return;
		}

		$status      = sanitize_key( $this->filter_param( 'status' ) );
		$integration = $this->filter_param( 'integration' );

		$statuses = [
			'draft'     => __( 'Draft', 'pressprimer-certificate' ),
			'published' => __( 'Published', 'pressprimer-certificate' ),
		];

		echo '<div class="alignleft actions">';

		echo '<label class="screen-reader-text" for="ppcert-filter-status">'
			. esc_html__( 'Filter by status', 'pressprimer-certificate' ) . '</label>';
		echo '<select name="status" id="ppcert-filter-status">';
		echo '<option value="">' . esc_html__( 'All statuses', 'pressprimer-certificate' ) . '</option>';

		foreach ( $statuses as $value => $label ) {
			echo '<option value="' . esc_attr( $value ) . '"' . selected( $status, $value, false ) . '>'
				. esc_html( $label ) . '</option>';
		}

		echo '</select>';

		// Only integrations with at least one trigger row: the full
		// adapter map would list every plugin the suite supports,
		// installed or not, and grows with each release. Deactivated
		// integrations whose triggers still exist stay listed.
		$used_types   = PressPrimer_Certificate_Trigger::get_used_types();
		$integrations = array_filter(
			PressPrimer_Certificate_Plugin::get_integration_map(),
			static function ( $type_ids ) use ( $used_types ) {
				return [] !== array_intersect( (array) $type_ids, $used_types );
			}
		);

		if ( [] !== $integrations ) {
			echo '<label class="screen-reader-text" for="ppcert-filter-integration">'
				. esc_html__( 'Filter by trigger integration', 'pressprimer-certificate' ) . '</label>';
			echo '<select name="integration" id="ppcert-filter-integration">';
			echo '<option value="">' . esc_html__( 'All integrations', 'pressprimer-certificate' ) . '</option>';

			foreach ( array_keys( $integrations ) as $label ) {
				echo '<option value="' . esc_attr( $label ) . '"' . selected( $integration, $label, false ) . '>'
					. esc_html( $label ) . '</option>';
			}

			echo '</select>';
		}

		submit_button( __( 'Filter', 'pressprimer-certificate' ), '', 'filter_action', false );

		echo '</div>';
	}

	/**
	 * Title column with the Edit / Duplicate / Trash row actions
	 *
	 * @since 1.0.0
	 *
	 * @param object $item Template row.
	 * @return string
	 */
	public function column_title( $item ) {
		$edit_url = add_query_arg(
			[
				'page'        => 'ppcert-templates',
				'action'      => 'edit',
				'template_id' => (int) $item->id,
			],
			admin_url( 'admin.php' )
		);

		$title = '' !== (string) $item->title
			? (string) $item->title
			: __( '(untitled)', 'pressprimer-certificate' );

		$duplicate_url = wp_nonce_url(
			add_query_arg(
				[
					'page'        => 'ppcert-templates',
					'action'      => 'duplicate',
					'template_id' => (int) $item->id,
				],
				admin_url( 'admin.php' )
			),
			'ppcert_duplicate_template_' . (int) $item->id
		);

		$trash_url = wp_nonce_url(
			add_query_arg(
				[
					'page'        => 'ppcert-templates',
					'action'      => 'trash',
					'template_id' => (int) $item->id,
				],
				admin_url( 'admin.php' )
			),
			'ppcert_trash_template_' . (int) $item->id
		);

		$actions = [
			'edit'      => '<a href="' . esc_url( $edit_url ) . '">' . esc_html__( 'Edit', 'pressprimer-certificate' ) . '</a>',
			'duplicate' => '<a href="' . esc_url( $duplicate_url ) . '">' . esc_html__( 'Duplicate', 'pressprimer-certificate' ) . '</a>',
			'trash'     => '<a href="' . esc_url( $trash_url ) . '" class="submitdelete">' . esc_html__( 'Trash', 'pressprimer-certificate' ) . '</a>',
		];

		return '<strong><a href="' . esc_url( $edit_url ) . '">' . esc_html( $title ) . '</a></strong>'
			. $this->row_actions( $actions );
	}

	/**
	 * Default column rendering
	 *
	 * @since 1.0.0
	 *
	 * @param object $item        Template row.
	 * @param string $column_name Column key.
	 * @return string
	 */
	public function column_default( $item, $column_name ) {
		switch ( $column_name ) {
			case 'status':
				$labels = [
					'draft'     => __( 'Draft', 'pressprimer-certificate' ),
					'published' => __( 'Published', 'pressprimer-certificate' ),
					'archived'  => __( 'Archived', 'pressprimer-certificate' ),
				];

				$status = (string) $item->status;

				return esc_html( isset( $labels[ $status ] ) ? $labels[ $status ] : $status );

			case 'trigger':
				return $this->render_trigger_column( $item );

			case 'page':
				return esc_html( strtoupper( (string) $item->page_size ) . ' · ' . ucfirst( (string) $item->orientation ) );

			case 'updated_at':
				// UTC in, localized out (CLAUDE.md Datetime Standard).
				return esc_html(
					get_date_from_gmt(
						(string) $item->updated_at,
						get_option( 'date_format' ) . ' ' . get_option( 'time_format' )
					)
				);

			default:
				return '';
		}
	}

	/**
	 * The Trigger column: integration and trigger short label
	 *
	 * @since 1.0.0
	 *
	 * @param object $item Template row.
	 * @return string
	 */
	private function render_trigger_column( $item ) {
		$triggers = isset( $this->triggers[ (int) $item->id ] ) ? $this->triggers[ (int) $item->id ] : [];

		if ( empty( $triggers ) ) {
			return '<span aria-hidden="true">&#8212;</span><span class="screen-reader-text">'
				. esc_html__( 'No trigger', 'pressprimer-certificate' ) . '</span>';
		}

		$parts = [];

		foreach ( $triggers as $trigger ) {
			$type = (string) $trigger->trigger_type;

			if ( isset( $this->type_details[ $type ] ) ) {
				$label = $this->type_details[ $type ]['integration'] . ' · ' . $this->type_details[ $type ]['short_label'];
			} else {
				$label = $type;
			}

			if ( ! $trigger->is_active ) {
				$label = sprintf(
					/* translators: %s: trigger label */
					__( '%s (inactive)', 'pressprimer-certificate' ),
					$label
				);
			}

			$parts[] = esc_html( $label );
		}

		return implode( '<br />', $parts );
	}

	/**
	 * Empty-state message
	 *
	 * @since 1.0.0
	 */
	public function no_items() {
		esc_html_e( 'No certificate templates found. Click "Add New" to start from a template, or adjust the filters above.', 'pressprimer-certificate' );
	}
}
