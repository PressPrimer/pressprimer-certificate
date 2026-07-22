<?php
/**
 * Templates list table
 *
 * The Templates admin list (WP_List_Table per ecosystem convention).
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
 * the ecosystem "List + Detail" pattern. Filters/search/pagination grow
 * in Prompt 3.6 alongside the full templates controller.
 *
 * @since 1.0.0
 */
class PressPrimer_Certificate_Templates_List_Table extends WP_List_Table {

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
			'page'       => __( 'Page', 'pressprimer-certificate' ),
			'updated_at' => __( 'Last Updated', 'pressprimer-certificate' ),
		];
	}

	/**
	 * Load items
	 *
	 * @since 1.0.0
	 */
	public function prepare_items() {
		$this->_column_headers = [ $this->get_columns(), [], [] ];
		$this->items           = PressPrimer_Certificate_Template::get_all();
	}

	/**
	 * Title column with the Edit row action
	 *
	 * @since 1.0.0
	 *
	 * @param object $item Template row.
	 * @return string
	 */
	public function column_title( $item ) {
		$edit_url = add_query_arg(
			[
				'page'        => 'pressprimer-certificate',
				'action'      => 'edit',
				'template_id' => (int) $item->id,
			],
			admin_url( 'admin.php' )
		);

		$title = '' !== (string) $item->title
			? (string) $item->title
			: __( '(untitled)', 'pressprimer-certificate' );

		$actions = [
			'edit' => '<a href="' . esc_url( $edit_url ) . '">' . esc_html__( 'Edit', 'pressprimer-certificate' ) . '</a>',
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
	 * Empty-state message
	 *
	 * @since 1.0.0
	 */
	public function no_items() {
		esc_html_e( 'No certificate templates yet. Click "Add New" to start from a template.', 'pressprimer-certificate' );
	}
}
