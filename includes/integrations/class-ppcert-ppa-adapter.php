<?php
/**
 * PressPrimer Assignment adapter
 *
 * Second adapter (Feature 004 FR-004 ppa_assignment row): "award this
 * certificate when an assignment is passed." Follows the PPQ reference
 * pattern established in Prompt 4.1.
 *
 * @package PressPrimer_Certificate
 * @subpackage Integrations
 * @since 1.0.0
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * PressPrimer Assignment adapter class
 *
 * Direction note (as with the PPQ adapter): this adapter only LISTENS -
 * PPA grades and persists the submission, then we issue. The listener
 * performs no PPA writes, so it is idempotent by construction; the
 * issuance engine's duplicate suppression (Feature 003) is the backstop
 * for resubmission regrades.
 *
 * PPA assignments live in the custom wp_ppa_assignments table, not a
 * post type, so this adapter declares no source_post_types (the
 * designer's post-meta picker stays unavailable for assignment sources)
 * and {{source.meta.*}} tokens resolve empty.
 *
 * Grading in PPA is points-based (score against the assignment's
 * max_points), so the grade condition and merge field normalize to a
 * percentage: score / max_points_at_grading x 100.
 *
 * @since 1.0.0
 */
class PressPrimer_Certificate_PPA_Adapter extends PressPrimer_Certificate_LMS_Adapter {

	/**
	 * Trigger type id (FR-004 contract)
	 *
	 * @since 1.0.0
	 * @var string
	 */
	const TRIGGER_TYPE = 'ppa_assignment';

	/**
	 * Adapter id
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public function get_id(): string {
		return self::TRIGGER_TYPE;
	}

	/**
	 * Trigger type label for the designer's Award tab
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public function get_label(): string {
		return __( 'Assignment passed (PressPrimer Assignment)', 'pressprimer-certificate' );
	}

	/**
	 * Source noun for the Award tab and merge-field palette
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public function get_source_group_label(): string {
		return __( 'Assignment', 'pressprimer-certificate' );
	}

	/**
	 * "Any" option label (leaf-only Any, Feature 1.1-002 FR-003).
	 *
	 * @since 1.1.0
	 *
	 * @return string
	 */
	public function get_any_source_label(): string {
		return __( 'Any assignment', 'pressprimer-certificate' );
	}

	/**
	 * Integration name for the two-step trigger picker
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public function get_integration_label(): string {
		return __( 'PressPrimer Assignment', 'pressprimer-certificate' );
	}

	/**
	 * Short trigger label (integration already chosen)
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public function get_short_label(): string {
		return __( 'Assignment passed', 'pressprimer-certificate' );
	}

	/**
	 * Availability: cheap constant/class checks (FR-002)
	 *
	 * PRESSPRIMER_ASSIGNMENT_VERSION is defined unconditionally in
	 * pressprimer-assignment.php (verified against PPA 2.2.0).
	 *
	 * @since 1.0.0
	 *
	 * @return bool
	 */
	public function is_available(): bool {
		return defined( 'PRESSPRIMER_ASSIGNMENT_VERSION' )
			&& class_exists( 'PressPrimer_Assignment_Submission' );
	}

	/**
	 * Listen for graded submissions
	 *
	 * Hook citation (PPA 2.2.0): `pressprimer_assignment_submission_graded`
	 * fires in PressPrimer_Assignment_Grading_Service::grade()
	 * (class-ppa-grading-service.php:174) AFTER the submission row is
	 * saved with its final score (late penalty applied), pass/fail
	 * decided against the assignment's own passing_score, and graded_at
	 * stamped - grading state is final when we run (TR-003). Signature:
	 * ( int $submission_id, float $score ). The listener reloads the
	 * submission model rather than trusting the loose score argument.
	 * Priority 20 keeps us after PPA's own default-priority listeners.
	 *
	 * The hook fires for FAILING grades too (unlike PPQ's pass-only
	 * event); the listener checks the submission's passed flag.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function register_listeners(): void {
		add_action( 'pressprimer_assignment_submission_graded', [ $this, 'handle_submission_graded' ], 20, 2 );
	}

	/**
	 * Selectable sources: published PPA assignments
	 *
	 * Read-only lookup against PPA's assignment table (assignments are
	 * not posts, so there is no WP_Query path). Capability gating
	 * happens at the REST route (ppcert_manage_templates).
	 *
	 * @since 1.0.0
	 *
	 * @param string $search Search term.
	 * @return array<int,array{id:string,title:string}>
	 */
	public function get_sources( string $search = '' ): array {
		global $wpdb;

		$like = '%' . $wpdb->esc_like( $search ) . '%';

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, title FROM %i WHERE status = 'published' AND title LIKE %s ORDER BY title ASC LIMIT 50",
				$wpdb->prefix . 'ppa_assignments',
				$like
			)
		);

		$sources = [];

		foreach ( (array) $rows as $row ) {
			$sources[] = [
				'id'    => (string) $row->id,
				'title' => (string) $row->title,
			];
		}

		return $sources;
	}

	/**
	 * Conditions schema (TR-002)
	 *
	 * A null min_grade means "use the assignment's own passing score" -
	 * the listener only issues for passing submissions, so a null
	 * condition is satisfied by definition. A numeric value raises the
	 * bar, as a percentage of the assignment's max points.
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	public function get_conditions_schema(): array {
		return [
			'min_grade' => [
				'type'    => 'number',
				'label'   => __( 'Minimum grade (%)', 'pressprimer-certificate' ),
				'help'    => __( 'Leave blank to award on any passing grade. Enter a percentage to require more than the assignment\'s own passing score.', 'pressprimer-certificate' ),
				'min'     => 0,
				'max'     => 100,
				'default' => null,
			],
		];
	}

	/**
	 * Contributed merge fields (FR-004 set)
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	public function get_merge_fields(): array {
		return [
			'source' => [
				'assignment_title' => [
					'key'      => 'source.assignment_title',
					'label'    => __( 'Assignment Title', 'pressprimer-certificate' ),
					'sample'   => __( 'Field Research Essay', 'pressprimer-certificate' ),
					'resolver' => [ $this, 'resolve_assignment_title' ],
				],
				'grade'            => [
					'key'      => 'source.grade',
					// Neutral label - shared polymorphic key, see the
					// PPQ adapter's note on source.grade.
					'label'    => __( 'Result', 'pressprimer-certificate' ),
					'sample'   => __( 'Passed', 'pressprimer-certificate' ),
					'resolver' => [ $this, 'resolve_source_grade' ],
				],
				'completion_date'  => [
					'key'      => 'source.completion_date',
					'label'    => __( 'Completion Date', 'pressprimer-certificate' ),
					'sample'   => __( 'June 12, 2026', 'pressprimer-certificate' ),
					'resolver' => [ $this, 'resolve_source_completed_date' ],
				],
			],
		];
	}

	/**
	 * Bulk merge resolution (abstract contract)
	 *
	 * @since 1.0.0
	 *
	 * @param array $context Issuance context.
	 * @return array<string,string>
	 */
	public function resolve_merge_data( array $context ): array {
		return [
			'source.assignment_title' => $this->resolve_assignment_title( $context ),
			'source.grade'            => $this->resolve_source_grade( $context ),
			'source.completion_date'  => $this->resolve_source_completed_date( $context ),
		];
	}

	/**
	 * The graded-submission listener (FR-003)
	 *
	 * Reloads the submission and assignment models - the context is
	 * built server-side from PPA's own persisted state, never from
	 * request data (Security Requirements) - and calls the engine once
	 * per matching active trigger. Failing grades never issue.
	 *
	 * @since 1.0.0
	 *
	 * @param int   $submission_id Graded submission id.
	 * @param float $score         Final score (unused; the model is authoritative).
	 * @return void
	 */
	public function handle_submission_graded( $submission_id, $score = 0 ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Hook signature.
		$submission = PressPrimer_Assignment_Submission::get( absint( $submission_id ) );

		if ( ! is_object( $submission ) || empty( $submission->assignment_id ) || empty( $submission->user_id ) ) {
			return;
		}

		// The graded event fires for failures too: only passing
		// submissions (the assignment's own bar, late penalty applied)
		// award certificates.
		if ( empty( $submission->passed ) ) {
			return;
		}

		$triggers = PressPrimer_Certificate_Trigger::find_active( self::TRIGGER_TYPE, (string) $submission->assignment_id );

		if ( empty( $triggers ) ) {
			return;
		}

		$assignment = PressPrimer_Assignment_Assignment::get( (int) $submission->assignment_id );
		$percent    = $this->grade_percent( $submission, $assignment );

		// Shared source-context contract (abstract adapter): display
		// strings precomputed, shared resolvers stay passthroughs. PPA's
		// grade renders as a percentage.
		$context = [
			// Assignments are not posts: no source_post_id, so
			// source.meta.* resolves empty (class docblock).
			'source_post_id'       => 0,
			'ppa_assignment_title' => is_object( $assignment ) ? (string) $assignment->title : '',
			'src_grade_display'    => $this->format_percent_display( $percent ),
			// PPA stores graded_at in UTC (current_time('mysql', true)
			// in PressPrimer_Assignment_Grading_Service::grade()) - the
			// shared contract's expectation, no conversion needed.
			'src_completed_at'     => isset( $submission->graded_at ) ? (string) $submission->graded_at : '',
		];

		foreach ( $triggers as $trigger ) {
			// Condition: numeric min_grade raises the bar above the
			// assignment's own passing score; null follows it (schema doc).
			$min_grade = isset( $trigger->conditions['min_grade'] ) ? $trigger->conditions['min_grade'] : null;

			if ( null !== $min_grade && is_numeric( $min_grade ) && $percent < (float) $min_grade ) {
				continue;
			}

			// Duplicate suppression is the engine's job (Feature 003) -
			// a resubmission regrade lands here again and is suppressed.
			PressPrimer_Certificate_Issuance_Service::issue(
				[
					'template_id'  => (int) $trigger->template_id,
					'recipient_id' => (int) $submission->user_id,
					'source_type'  => self::TRIGGER_TYPE,
					'source_ref'   => (string) $submission->assignment_id,
					'issued_by'    => 0,
					'context'      => $context,
				]
			);
		}
	}

	/**
	 * Normalize a graded submission to a percentage
	 *
	 * PPA scores are points against max_points_at_grading (snapshotted
	 * at grading; falls back to the assignment's current max_points for
	 * pre-1.10 rows, mirroring PPA's own statistics queries). A
	 * non-positive max yields 0.0 rather than dividing by zero.
	 *
	 * @since 1.0.0
	 *
	 * @param object      $submission Graded submission.
	 * @param object|null $assignment Its assignment (fallback max).
	 * @return float
	 */
	private function grade_percent( $submission, $assignment ) {
		$score = isset( $submission->score ) && is_numeric( $submission->score ) ? (float) $submission->score : 0.0;
		$max   = isset( $submission->max_points_at_grading ) && is_numeric( $submission->max_points_at_grading )
			? (float) $submission->max_points_at_grading
			: 0.0;

		if ( $max <= 0 && is_object( $assignment ) && isset( $assignment->max_points ) && is_numeric( $assignment->max_points ) ) {
			$max = (float) $assignment->max_points;
		}

		if ( $max <= 0 ) {
			return 0.0;
		}

		return round( ( $score / $max ) * 100, 2 );
	}

	/*
	 * ------------------------------------------------------------------
	 * Field resolvers (scalar returns per the registry contract).
	 * ------------------------------------------------------------------
	 */

	/**
	 * Resolve the assignment title.
	 *
	 * @since 1.0.0
	 *
	 * @param array $context Issuance context.
	 * @return string
	 */
	public function resolve_assignment_title( array $context ) {
		return isset( $context['ppa_assignment_title'] ) ? (string) $context['ppa_assignment_title'] : '';
	}
}
