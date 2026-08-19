<?php
/**
 * PressPrimer Quiz adapter
 *
 * The reference adapter implementation (Feature 004): "award this
 * certificate when a quiz is passed." The other five adapters follow
 * this class's pattern.
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
 * PressPrimer Quiz adapter class
 *
 * Direction note: unlike PressPrimer Quiz's own LMS integrations (which
 * MARK lessons/courses complete when a quiz passes), this adapter only
 * LISTENS - PPQ grades and persists the attempt, then we issue. The
 * listener performs no PPQ writes, so it is idempotent by construction;
 * the issuance engine's duplicate suppression (Feature 003) is the
 * backstop for retakes.
 *
 * PPQ quizzes live in the custom wp_ppq_quizzes table, not a post type,
 * so this adapter declares no source_post_types (the designer's
 * post-meta picker stays unavailable for quiz sources) and
 * {{source.meta.*}} tokens resolve empty.
 *
 * @since 1.0.0
 */
class PressPrimer_Certificate_PPQ_Adapter extends PressPrimer_Certificate_LMS_Adapter {

	/**
	 * Trigger type id (FR-004 contract)
	 *
	 * @since 1.0.0
	 * @var string
	 */
	const TRIGGER_TYPE = 'ppq_quiz';

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
		return __( 'Quiz passed (PressPrimer Quiz)', 'pressprimer-certificate' );
	}

	/**
	 * Source noun for the Award tab and merge-field palette
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public function get_source_group_label(): string {
		return __( 'Quiz', 'pressprimer-certificate' );
	}

	/**
	 * "Any" option label (leaf-only Any, Feature 1.1-002 FR-003).
	 *
	 * @since 1.1.0
	 *
	 * @return string
	 */
	public function get_any_source_label(): string {
		return __( 'Any quiz', 'pressprimer-certificate' );
	}

	/**
	 * Integration name for the two-step trigger picker
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public function get_integration_label(): string {
		return __( 'PressPrimer Quiz', 'pressprimer-certificate' );
	}

	/**
	 * Short trigger label (integration already chosen)
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public function get_short_label(): string {
		return __( 'Quiz passed', 'pressprimer-certificate' );
	}

	/**
	 * Availability: cheap constant/class checks (FR-002)
	 *
	 * PRESSPRIMER_QUIZ_VERSION is defined unconditionally in
	 * pressprimer-quiz.php (verified against PPQ 3.0.3).
	 *
	 * @since 1.0.0
	 *
	 * @return bool
	 */
	public function is_available(): bool {
		return defined( 'PRESSPRIMER_QUIZ_VERSION' )
			&& class_exists( 'PressPrimer_Quiz_Attempt' );
	}

	/**
	 * Listen for passed attempts
	 *
	 * Hook citation (PPQ 3.0.3): `pressprimer_quiz_quiz_passed` fires in
	 * PressPrimer_Quiz_Attempt::submit() (class-ppq-attempt.php:1045)
	 * AFTER the attempt is scored, the row saved with its final
	 * score_percent, and the pass decided against the quiz's own
	 * pass_percent - grading state is final when we run (TR-003).
	 * Signature: ( PressPrimer_Quiz_Attempt $attempt,
	 * PressPrimer_Quiz_Quiz $quiz ). Priority 20 keeps us after PPQ's
	 * own default-priority listeners (e.g. its LMS completion marking).
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function register_listeners(): void {
		add_action( 'pressprimer_quiz_quiz_passed', [ $this, 'handle_quiz_passed' ], 20, 2 );
	}

	/**
	 * Selectable sources: published PPQ quizzes
	 *
	 * Read-only lookup against PPQ's quiz table (quizzes are not posts,
	 * so there is no WP_Query path). Capability gating happens at the
	 * REST route (ppcert_manage_templates).
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
				$wpdb->prefix . 'ppq_quizzes',
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
	 * A null min_score means "use the quiz's own pass threshold" - the
	 * pass event only fires for passing attempts, so a null condition is
	 * satisfied by definition. If the quiz's threshold changes later,
	 * the trigger follows it (documented Edge Case).
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	public function get_conditions_schema(): array {
		return [
			'min_score' => [
				'type'    => 'number',
				'label'   => __( 'Minimum score (%)', 'pressprimer-certificate' ),
				'help'    => __( 'Leave blank to award on any passing score. Enter a percentage to require more than the quiz\'s own pass mark.', 'pressprimer-certificate' ),
				'min'     => 0,
				'max'     => 100,
				'default' => null,
			],
		];
	}

	/**
	 * Contributed merge fields (FR-004 minimum set)
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	public function get_merge_fields(): array {
		return [
			'source' => [
				'quiz_title' => [
					'key'      => 'source.quiz_title',
					'label'    => __( 'Quiz Title', 'pressprimer-certificate' ),
					'sample'   => __( 'Advanced Botany Quiz', 'pressprimer-certificate' ),
					'resolver' => [ $this, 'resolve_source_quiz_title' ],
				],
				'score'      => [
					'key'      => 'source.score',
					'label'    => __( 'Quiz Score', 'pressprimer-certificate' ),
					'sample'   => '92%',
					'resolver' => [ $this, 'resolve_source_score' ],
				],
				'grade'      => [
					'key'      => 'source.grade',
					// Neutral label: source.grade is a SHARED polymorphic
					// key (quiz adapters + Assignment); the registry's
					// union is last-writer-wins on the definition, so
					// every registrant must use the same label or the
					// palette shows another integration's wording.
					'label'    => __( 'Result', 'pressprimer-certificate' ),
					'sample'   => __( 'Passed', 'pressprimer-certificate' ),
					'resolver' => [ $this, 'resolve_source_grade' ],
				],
				'pass_date'  => [
					'key'      => 'source.pass_date',
					'label'    => __( 'Pass Date', 'pressprimer-certificate' ),
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
			'source.quiz_title' => $this->resolve_source_quiz_title( $context ),
			'source.score'      => $this->resolve_source_score( $context ),
			'source.grade'      => $this->resolve_source_grade( $context ),
			'source.pass_date'  => $this->resolve_source_completed_date( $context ),
		];
	}

	/**
	 * The passed-quiz listener (FR-003)
	 *
	 * Builds the issuance context server-side from PPQ's own objects -
	 * never from request data (Security Requirements) - and calls the
	 * engine once per matching active trigger.
	 *
	 * @since 1.0.0
	 *
	 * @param object $attempt PressPrimer_Quiz_Attempt (score_percent,
	 *                        user_id, finished_at are final).
	 * @param object $quiz    PressPrimer_Quiz_Quiz (id, title).
	 * @return void
	 */
	public function handle_quiz_passed( $attempt, $quiz ) {
		if ( ! is_object( $attempt ) || ! is_object( $quiz ) || empty( $quiz->id ) || empty( $attempt->user_id ) ) {
			return;
		}

		$triggers = PressPrimer_Certificate_Trigger::find_active( self::TRIGGER_TYPE, (string) $quiz->id );

		if ( empty( $triggers ) ) {
			return;
		}

		$score = isset( $attempt->score_percent ) ? (float) $attempt->score_percent : 0.0;

		// PPQ stores finished_at in WordPress LOCAL time
		// (current_time('mysql') in PressPrimer_Quiz_Attempt::submit());
		// the shared contract wants UTC, so convert here.
		$finished_local = isset( $attempt->finished_at ) ? (string) $attempt->finished_at : '';

		// Shared source-context contract (abstract adapter): display
		// strings precomputed, shared resolvers stay passthroughs.
		$context = [
			// Quizzes are not posts: no source_post_id, so source.meta.*
			// resolves empty (class docblock).
			'source_post_id'    => 0,
			'src_quiz_title'    => (string) $quiz->title,
			'src_score_display' => $this->format_percent_display( $score ),
			'src_grade_display' => __( 'Passed', 'pressprimer-certificate' ),
			'src_completed_at'  => '' !== $finished_local ? (string) get_gmt_from_date( $finished_local ) : '',
		];

		foreach ( $triggers as $trigger ) {
			// Condition: numeric min_score raises the bar above the
			// quiz's own threshold; null follows the quiz (schema doc).
			$min_score = isset( $trigger->conditions['min_score'] ) ? $trigger->conditions['min_score'] : null;

			if ( null !== $min_score && is_numeric( $min_score ) && $score < (float) $min_score ) {
				continue;
			}

			// Duplicate suppression is the engine's job (Feature 003) -
			// a retake pass lands here again and is suppressed there.
			PressPrimer_Certificate_Issuance_Service::issue(
				[
					'template_id'  => (int) $trigger->template_id,
					'recipient_id' => (int) $attempt->user_id,
					'source_type'  => self::TRIGGER_TYPE,
					'source_ref'   => (string) $quiz->id,
					'issued_by'    => 0,
					'context'      => $context,
				]
			);
		}
	}
}
