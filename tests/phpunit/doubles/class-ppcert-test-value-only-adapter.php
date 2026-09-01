<?php
/**
 * Value-only test double adapter
 *
 * A concrete adapter with NO selectable sources (2.0, Feature 2.0-007
 * FR-002 / TR-002): completion is defined entirely by a numeric
 * condition ("credits earned >= N"), so its triggers store a NULL
 * source_ref and its registry entry carries has_sources = false.
 * Drives the FR-002 acceptance flow end to end: save -> NULL ref ->
 * find_active(null) -> issue -> suppress -> reissue toggle. Also loadable
 * on a dev site through the ppcert_adapter_classes filter for the
 * Award-tab manual pass.
 *
 * @package PressPrimer_Certificate
 * @subpackage Tests
 * @since 2.0.0
 */

/**
 * Value-only double adapter
 *
 * @since 2.0.0
 */
class PPCert_Test_Value_Only_Adapter extends PressPrimer_Certificate_LMS_Adapter {

	/**
	 * Whether the (fictional) source plugin is available.
	 *
	 * @var bool
	 */
	public $available = true;

	/**
	 * Unique adapter id.
	 *
	 * @return string
	 */
	public function get_id(): string {
		return 'test_credits';
	}

	/**
	 * Availability gate.
	 *
	 * @return bool
	 */
	public function is_available(): bool {
		return $this->available;
	}

	/**
	 * No listeners: tests fire issuance directly.
	 *
	 * @return void
	 */
	public function register_listeners(): void {
	}

	/**
	 * Value-only: no source picker anywhere (FR-002).
	 *
	 * @return bool
	 */
	public function has_sources(): bool {
		return false;
	}

	/**
	 * Never called for a value-only type; returns empty by contract.
	 *
	 * @param string $search Search term.
	 * @return array
	 */
	public function get_sources( string $search = '' ): array {
		return [];
	}

	/**
	 * One contributed merge field (TR-002).
	 *
	 * @return array
	 */
	public function get_merge_fields(): array {
		return [
			'source' => [
				'credit_total' => [
					'key'      => 'source.credit_total',
					'label'    => 'Credit Total',
					'sample'   => '12',
					'resolver' => [ $this, 'resolve_merge_data' ],
				],
			],
		];
	}

	/**
	 * Merge data resolution.
	 *
	 * @param array $context Issuance context.
	 * @return array
	 */
	public function resolve_merge_data( array $context ): array {
		return [
			'source.credit_total' => isset( $context['credit_total'] )
				? (string) $context['credit_total']
				: '12',
		];
	}

	/**
	 * One number condition (TR-002).
	 *
	 * @return array
	 */
	public function get_conditions_schema(): array {
		return [
			'min_credits' => [
				'type'    => 'number',
				'label'   => 'Minimum credits',
				'help'    => 'Award once the member reaches this credit total.',
				'min'     => 1,
				'max'     => 1000,
				'default' => 10,
			],
		];
	}

	/**
	 * Translated-label override.
	 *
	 * @return string
	 */
	public function get_label(): string {
		return 'Credit Milestone';
	}

	/**
	 * Integration label override.
	 *
	 * @return string
	 */
	public function get_integration_label(): string {
		return 'Test Credits';
	}
}
