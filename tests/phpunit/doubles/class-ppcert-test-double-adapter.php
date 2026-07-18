<?php
/**
 * Test double adapter
 *
 * A minimal concrete implementation of the locked adapter interface,
 * used by the Prompt 1.5 unit tests (and by the trigger panel during
 * Phase 3 development, before real adapters exist in Phase 4).
 *
 * @package PressPrimer_Certificate
 * @subpackage Tests
 * @since 1.0.0
 */

/**
 * Double adapter
 *
 * Availability and listener bookkeeping are publicly togglable so tests
 * can exercise the registration gating.
 *
 * @since 1.0.0
 */
class PPCert_Test_Double_Adapter extends PressPrimer_Certificate_LMS_Adapter {

	/**
	 * Whether the (fictional) source plugin is available.
	 *
	 * @var bool
	 */
	public $available = true;

	/**
	 * How many times register_listeners() ran.
	 *
	 * @var int
	 */
	public $listeners_registered = 0;

	/**
	 * Unique adapter id.
	 *
	 * @return string
	 */
	public function get_id(): string {
		return 'double_lms';
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
	 * Listener registration bookkeeping.
	 *
	 * @return void
	 */
	public function register_listeners(): void {
		$this->listeners_registered++;
	}

	/**
	 * Selectable sources.
	 *
	 * @param string $search Search term.
	 * @return array
	 */
	public function get_sources( string $search = '' ): array {
		$sources = [
			[
				'id'    => 101,
				'title' => 'Sample Course',
			],
			[
				'id'    => 102,
				'title' => 'Advanced Botany',
			],
		];

		if ( '' === $search ) {
			return $sources;
		}

		return array_values(
			array_filter(
				$sources,
				static function ( $source ) use ( $search ) {
					return false !== stripos( $source['title'], $search );
				}
			)
		);
	}

	/**
	 * Contributed merge fields.
	 *
	 * @return array
	 */
	public function get_merge_fields(): array {
		return [
			'source' => [
				'course_title' => [
					'key'      => 'source.course_title',
					'label'    => 'Course Title',
					'sample'   => 'Introduction to Botany',
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
			'source.course_title' => 'Introduction to Botany',
		];
	}

	/**
	 * Conditions schema exercising all four field types.
	 *
	 * @return array
	 */
	public function get_conditions_schema(): array {
		return [
			'min_score' => [
				'type'    => 'number',
				'label'   => 'Minimum score (%)',
				'min'     => 0,
				'max'     => 100,
				'default' => null,
			],
			'notify'    => [
				'type'    => 'toggle',
				'label'   => 'Notify instructor',
				'default' => false,
			],
			'mode'      => [
				'type'    => 'select',
				'label'   => 'Completion mode',
				'options' => [ 'full', 'lessons_only' ],
				'default' => 'full',
			],
			'note'      => [
				'type'    => 'text',
				'label'   => 'Internal note',
				'default' => '',
			],
		];
	}

	/**
	 * Translated-label override.
	 *
	 * @return string
	 */
	public function get_label(): string {
		return 'Double LMS';
	}
}
