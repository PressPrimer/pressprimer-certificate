<?php
/**
 * LMS adapter interface
 *
 * THE adapter contract all six 1.0 adapters implement (PPQ, PPA,
 * LearnDash, LifterLMS, Tutor LMS, LearnPress).
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
 * Abstract LMS adapter
 *
 * LOCKED at the Prompt 1.5 checkpoint (Feature 004 FR-001). The seven
 * abstract methods below are the contract from CODE-STRUCTURE.md "The LMS
 * Adapter Interface"; changing any signature after the lock requires
 * explicit approval and touches all adapters.
 *
 * The concrete methods are shared registration glue: adapters instantiate
 * on `ppcert_loaded` and call register(), which no-ops unless
 * is_available() - so an adapter whose source plugin is missing registers
 * nothing and its triggers go inert (Feature 004 FR-002, Edge US-5).
 * Registration happens only through the two public filters; adapters are
 * architecturally identical to third-party integrations and core has no
 * adapter-specific branches.
 *
 * @since 1.0.0
 */
abstract class PressPrimer_Certificate_LMS_Adapter {
	/*
	 * ------------------------------------------------------------------
	 * THE LOCKED CONTRACT (CODE-STRUCTURE.md). Do not change signatures.
	 * ------------------------------------------------------------------
	 */

	/**
	 * Unique adapter id, e.g. 'lms_learndash'. Doubles as trigger_type.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	abstract public function get_id(): string;

	/**
	 * Whether the source plugin is active on this site.
	 *
	 * Must be a cheap constant/class/function check (Feature 004 FR-002).
	 *
	 * @since 1.0.0
	 *
	 * @return bool
	 */
	abstract public function is_available(): bool;

	/**
	 * Register runtime listeners for the source plugin's completion events.
	 *
	 * Listeners must be idempotent and run after the source plugin's own
	 * completion state is final (Feature 004 TR-003); each adapter's build
	 * prompt documents the chosen hook and priority with a source citation.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	abstract public function register_listeners(): void;

	/**
	 * Selectable sources for the designer's trigger panel (courses, quizzes...).
	 *
	 * @since 1.0.0
	 *
	 * @param string $search Optional search term.
	 * @return array List of sources, each [ 'id' => scalar, 'title' => string ].
	 */
	abstract public function get_sources( string $search = '' ): array;

	/**
	 * Merge field definitions this adapter contributes (ppcert_register_merge_fields).
	 *
	 * Shape: [ group => [ field_key => definition ] ] where each definition
	 * carries key/label/sample/resolver per HOOKS.md. Adapter course/quiz
	 * fields conventionally live in the 'source' group.
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	abstract public function get_merge_fields(): array;

	/**
	 * Resolve merge field values for an issuance context.
	 *
	 * The context is built server-side inside the listener - never from
	 * request data (Feature 004 Security Requirements).
	 *
	 * @since 1.0.0
	 *
	 * @param array $context Issuance context.
	 * @return array Map of token key => resolved value.
	 */
	abstract public function resolve_merge_data( array $context ): array;

	/**
	 * Validation schema for this adapter's trigger conditions_json.
	 *
	 * Declarative format per Feature 004 TR-002; the trigger panel renders
	 * it and the trigger registry sanitizes conditions against it.
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	abstract public function get_conditions_schema(): array;

	/*
	 * ------------------------------------------------------------------
	 * Shared registration glue (concrete; not part of the abstract
	 * contract - see CODE-STRUCTURE.md "The LMS Adapter Interface").
	 * ------------------------------------------------------------------
	 */

	/**
	 * Register this adapter with core
	 *
	 * Called on `ppcert_loaded`. No-ops when the source plugin is absent:
	 * no trigger type, no merge fields, no listeners.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function register(): void {
		if ( ! $this->is_available() ) {
			return;
		}

		add_filter( 'ppcert_register_trigger_types', [ $this, 'register_trigger_type' ] );
		add_filter( 'ppcert_register_merge_fields', [ $this, 'register_merge_fields' ], 10, 2 );

		$this->register_listeners();
	}

	/**
	 * Human-readable trigger type label for the designer's trigger panel
	 *
	 * Adapters override this with a translated label; the default derives
	 * a readable form from the id.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public function get_label(): string {
		return ucwords( str_replace( [ '_', '-' ], ' ', $this->get_id() ) );
	}

	/**
	 * Noun for this adapter's sources ("Quiz", "Course", "Assignment")
	 *
	 * Labels the Award tab's source line and the merge-field palette's
	 * source group. Adapters override with a translated noun.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public function get_source_group_label(): string {
		return __( 'Source', 'pressprimer-certificate' );
	}

	/**
	 * Integration name for the two-step trigger picker ("LearnDash",
	 * "PressPrimer Quiz")
	 *
	 * The designer's Add-trigger modal leads with the integration, then
	 * lists its triggers (Ryan's Award-tab review, 2026-07-23).
	 * Adapters override with the translated plugin name.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public function get_integration_label(): string {
		return $this->get_label();
	}

	/**
	 * Short trigger label for the second picker step ("Course
	 * completed") - the integration is already chosen, so the full
	 * label's bracket suffix would be noise.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public function get_short_label(): string {
		return $this->get_label();
	}

	/**
	 * Parent levels the source picker cascades through before the
	 * source itself
	 *
	 * Empty = flat picker (the default). A hierarchical adapter returns
	 * ordered levels, e.g. [ [ 'key' => 'course', 'label' => 'Course' ],
	 * [ 'key' => 'lesson', 'label' => 'Lesson' ] ] - the designer
	 * renders one select per level and only then the source select,
	 * scoped by get_sources_for_parents() (Ryan's Award-tab review,
	 * 2026-07-23: same-named lessons/topics/quizzes need context).
	 *
	 * @since 1.0.0
	 *
	 * @return array<int,array{key:string,label:string}>
	 */
	public function get_source_levels(): array {
		return [];
	}

	/**
	 * Options for one parent level of the cascade
	 *
	 * @since 1.0.0
	 *
	 * @param string $level   Level key from get_source_levels().
	 * @param array  $parents Earlier level selections (key => id).
	 * @param string $search  Search term.
	 * @return array<int,array{id:string,title:string}>
	 */
	public function get_level_options( string $level, array $parents, string $search = '' ): array {
		return [];
	}

	/**
	 * Sources scoped to the chosen parent levels
	 *
	 * Flat adapters fall through to get_sources(); hierarchical
	 * adapters override to scope by parents.
	 *
	 * @since 1.0.0
	 *
	 * @param array  $parents Level selections (key => id).
	 * @param string $search  Search term.
	 * @return array<int,array{id:string,title:string}>
	 */
	public function get_sources_for_parents( array $parents, string $search = '' ): array {
		return $this->get_sources( $search );
	}

	/**
	 * Post types this adapter's sources live in
	 *
	 * Empty when sources are not posts (PPQ quizzes live in a custom
	 * table). Non-empty unlocks the designer's source-meta picker and
	 * gates the post-meta REST route (Feature 002 TR-002).
	 *
	 * @since 1.0.0
	 *
	 * @return string[]
	 */
	public function get_source_post_types(): array {
		return [];
	}

	/**
	 * Label for the "Any" source option ("Any quiz", "Any lesson in
	 * this course")
	 *
	 * Empty (the default) means the Award tab offers no "Any" option
	 * for this type - explicit opt-in, so third-party adapters keep
	 * their exact-source behavior until they choose otherwise. Bundled
	 * adapters override with a translated label; hierarchical ones
	 * phrase the parent scope ("in this course") since their cascade
	 * stays specific (leaf-only "Any", Feature 1.1-002 FR-002).
	 *
	 * @since 1.1.0
	 *
	 * @return string
	 */
	public function get_any_source_label(): string {
		return '';
	}

	/**
	 * Conditions keys that scope an 'any' trigger to its parent object
	 *
	 * Empty = flat type: "Any" matches every source unconditionally
	 * (the default). Hierarchical types (leaf-only "Any", Feature
	 * 1.1-002 FR-002) return their parent key(s) - e.g. [ 'course_id' ]
	 * - and an 'any' trigger of the type MUST carry each key in its
	 * conditions (registry-enforced at save) and match the fired event
	 * (trigger_scope_matches() at fire time). Values are carried in
	 * conditions but are NOT part of get_conditions_schema() - the
	 * Award tab's cascade selects write them, they never render as
	 * condition controls.
	 *
	 * @since 1.1.0
	 *
	 * @return string[]
	 */
	public function get_scope_condition_keys(): array {
		return [];
	}

	/**
	 * Whether a matched trigger's scope covers the fired event
	 *
	 * Exact-ref triggers always match (find_active already compared the
	 * ref). For 'any' triggers, every declared scope key must be present
	 * on BOTH sides and equal - fail closed: a scoped 'any' trigger
	 * never fires when the event cannot prove its parent (e.g. an LMS
	 * payload without a course), and an unscoped hierarchical 'any' row
	 * (unreachable through the save path) never fires at all.
	 *
	 * Listeners call this per matched trigger before issuing, passing
	 * the fired event's scope (e.g. [ 'course_id' => '301' ]).
	 *
	 * @since 1.1.0
	 *
	 * @param object $trigger     Matched trigger row (hydrated).
	 * @param array  $fired_scope Fired event's scope values (key => id).
	 * @return bool
	 */
	protected function trigger_scope_matches( $trigger, array $fired_scope ): bool {
		if ( ! is_object( $trigger ) || PressPrimer_Certificate_Trigger::SOURCE_ANY !== (string) $trigger->source_ref ) {
			return true;
		}

		foreach ( $this->get_scope_condition_keys() as $key ) {
			$required = isset( $trigger->conditions[ $key ] ) ? (string) $trigger->conditions[ $key ] : '';
			$fired    = isset( $fired_scope[ $key ] ) ? (string) $fired_scope[ $key ] : '';

			if ( '' === $required || '' === $fired || $required !== $fired ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Filter callback: contribute this adapter's trigger type entry
	 *
	 * Entry shape per HOOKS.md ppcert_register_trigger_types.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $types Registered trigger types.
	 * @return array
	 */
	public function register_trigger_type( $types ): array {
		$types = is_array( $types ) ? $types : [];

		$types[ $this->get_id() ] = [
			'id'                => $this->get_id(),
			'label'             => $this->get_label(),
			'integration'       => $this->get_integration_label(),
			'short_label'       => $this->get_short_label(),
			'source_label'      => $this->get_source_group_label(),
			'source_picker'     => [ $this, 'get_sources' ],
			'source_levels'     => $this->get_source_levels(),
			'level_picker'      => [ $this, 'get_level_options' ],
			'scoped_picker'     => [ $this, 'get_sources_for_parents' ],
			'source_post_types' => $this->get_source_post_types(),
			'conditions_schema' => $this->get_conditions_schema(),
			'scope_keys'        => $this->get_scope_condition_keys(),
			'any_label'         => $this->get_any_source_label(),
		];

		return $types;
	}

	/*
	 * ------------------------------------------------------------------
	 * Shared course helpers (TR-001: course-field resolution lives in
	 * the abstract class, never in core services). The four LMS course
	 * adapters share sources, merge fields, and resolvers; each LMS
	 * listener builds the same context via build_course_context().
	 *
	 * SHARED SOURCE-CONTEXT CONTRACT: source.* token keys are
	 * polymorphic - "the thing that triggered this certificate" - and
	 * the registry keeps ONE resolver per token key. Any adapter that
	 * registers a token key another adapter may also register MUST use
	 * the shared resolver below and write its shared context key:
	 *
	 *   src_completed_at   (UTC datetime)     -> source.completion_date,
	 *                                            source.pass_date
	 *   src_quiz_title     (string)           -> source.quiz_title
	 *   src_score_display  ("86.5%", ready)   -> source.score
	 *   src_grade_display  ("Passed"/"92%")   -> source.grade
	 *   lms_course_title   (string)           -> source.course_title
	 *   lms_lesson_title   (string)           -> source.lesson_title
	 *   lms_instructor     (string)           -> source.instructor
	 *
	 * Listeners precompute display strings (score/grade) so the shared
	 * resolvers are passthroughs and never need per-LMS branches.
	 * ------------------------------------------------------------------
	 */

	/**
	 * Selectable sources from a post type (published, searchable)
	 *
	 * The course adapters' get_sources() implementation: LMS courses are
	 * posts, unlike PPQ/PPA sources.
	 *
	 * @since 1.0.0
	 *
	 * @param string $post_type Course post type slug.
	 * @param string $search    Search term.
	 * @return array<int,array{id:string,title:string}>
	 */
	protected function get_post_sources( string $post_type, string $search = '' ): array {
		$posts = get_posts(
			[
				'post_type'   => $post_type,
				'post_status' => 'publish',
				's'           => $search,
				'numberposts' => 50,
				'orderby'     => 'title',
				'order'       => 'ASC',
			]
		);

		$sources = [];

		foreach ( (array) $posts as $post ) {
			$sources[] = [
				'id'    => (string) $post->ID,
				'title' => (string) $post->post_title,
			];
		}

		return $sources;
	}

	/**
	 * Published posts to sorted id/title picker options
	 *
	 * Shared by every hierarchical picker (LD steps, LLMS lesson
	 * quizzes, Tutor topics, LP sections): filters to published, applies
	 * the search term, sorts by title, caps at 50.
	 *
	 * @since 1.0.0
	 *
	 * @param array  $posts  Post objects (nulls tolerated).
	 * @param string $search Search term.
	 * @return array<int,array{id:string,title:string}>
	 */
	protected function posts_to_options( array $posts, string $search = '' ): array {
		$options = [];

		foreach ( $posts as $post ) {
			if ( ! is_object( $post ) || 'publish' !== (string) $post->post_status ) {
				continue;
			}

			if ( '' !== $search && false === stripos( (string) $post->post_title, $search ) ) {
				continue;
			}

			$options[] = [
				'id'    => (string) $post->ID,
				'title' => (string) $post->post_title,
			];
		}

		usort(
			$options,
			static function ( $a, $b ) {
				return strcasecmp( $a['title'], $b['title'] );
			}
		);

		return array_slice( $options, 0, 50 );
	}

	/**
	 * The shared course merge-field set (FR-004: course_title,
	 * completion_date, instructor; source.meta.* resolves dynamically
	 * from source_post_id)
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	protected function course_merge_fields(): array {
		return [
			'source' => [
				'course_title'    => [
					'key'      => 'source.course_title',
					'label'    => __( 'Course Title', 'pressprimer-certificate' ),
					'sample'   => __( 'Introduction to Botany', 'pressprimer-certificate' ),
					'resolver' => [ $this, 'resolve_course_title' ],
				],
				'completion_date' => [
					'key'      => 'source.completion_date',
					'label'    => __( 'Completion Date', 'pressprimer-certificate' ),
					'sample'   => __( 'June 12, 2026', 'pressprimer-certificate' ),
					'resolver' => [ $this, 'resolve_source_completed_date' ],
				],
				'instructor'      => [
					'key'      => 'source.instructor',
					'label'    => __( 'Instructor', 'pressprimer-certificate' ),
					'sample'   => __( 'Prof. Marisol Vega', 'pressprimer-certificate' ),
					'resolver' => [ $this, 'resolve_course_instructor' ],
				],
			],
		];
	}

	/**
	 * The shared course resolve_merge_data() map
	 *
	 * @since 1.0.0
	 *
	 * @param array $context Issuance context.
	 * @return array<string,string>
	 */
	protected function resolve_course_merge_data( array $context ): array {
		return [
			'source.course_title'    => $this->resolve_course_title( $context ),
			'source.completion_date' => $this->resolve_source_completed_date( $context ),
			'source.instructor'      => $this->resolve_course_instructor( $context ),
		];
	}

	/**
	 * Build the shared issuance context from a course post
	 *
	 * The source_post_id feeds {{source.meta.*}}; the instructor is the
	 * course author's display name unless the LMS exposes a richer
	 * instructor concept (FR-004).
	 *
	 * @since 1.0.0
	 *
	 * @param WP_Post|object $course       Course post.
	 * @param string         $completed_at Completion datetime, UTC.
	 * @return array
	 */
	protected function build_course_context( $course, string $completed_at ): array {
		$instructor = '';

		if ( ! empty( $course->post_author ) ) {
			$author     = get_userdata( (int) $course->post_author );
			$instructor = $author ? (string) $author->display_name : '';
		}

		return [
			'source_post_id'   => (int) $course->ID,
			'lms_course_title' => (string) $course->post_title,
			// UTC per the ppcert datetime standard - each listener
			// converts its LMS's own value before it lands here.
			'src_completed_at' => $completed_at,
			'lms_instructor'   => $instructor,
		];
	}

	/**
	 * The shared quiz merge-field set: quiz fields plus parent-course
	 * context (used by every LMS quiz adapter)
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	protected function quiz_merge_fields(): array {
		$course = $this->course_merge_fields();

		return [
			'source' => [
				'quiz_title'   => [
					'key'      => 'source.quiz_title',
					'label'    => __( 'Quiz Title', 'pressprimer-certificate' ),
					'sample'   => __( 'Advanced Botany Quiz', 'pressprimer-certificate' ),
					'resolver' => [ $this, 'resolve_source_quiz_title' ],
				],
				'score'        => [
					'key'      => 'source.score',
					'label'    => __( 'Quiz Score', 'pressprimer-certificate' ),
					'sample'   => '92%',
					'resolver' => [ $this, 'resolve_source_score' ],
				],
				'grade'        => [
					'key'      => 'source.grade',
					// Neutral label - shared polymorphic key, see the
					// PPQ adapter's note on source.grade.
					'label'    => __( 'Result', 'pressprimer-certificate' ),
					'sample'   => __( 'Passed', 'pressprimer-certificate' ),
					'resolver' => [ $this, 'resolve_source_grade' ],
				],
				'pass_date'    => [
					'key'      => 'source.pass_date',
					'label'    => __( 'Pass Date', 'pressprimer-certificate' ),
					'sample'   => __( 'June 12, 2026', 'pressprimer-certificate' ),
					'resolver' => [ $this, 'resolve_source_completed_date' ],
				],
				'course_title' => $course['source']['course_title'],
				'instructor'   => $course['source']['instructor'],
			],
		];
	}

	/**
	 * The shared quiz resolve_merge_data() map
	 *
	 * @since 1.0.0
	 *
	 * @param array $context Issuance context.
	 * @return array<string,string>
	 */
	protected function resolve_quiz_merge_data( array $context ): array {
		return [
			'source.quiz_title'   => $this->resolve_source_quiz_title( $context ),
			'source.score'        => $this->resolve_source_score( $context ),
			'source.grade'        => $this->resolve_source_grade( $context ),
			'source.pass_date'    => $this->resolve_source_completed_date( $context ),
			'source.course_title' => $this->resolve_course_title( $context ),
			'source.instructor'   => $this->resolve_course_instructor( $context ),
		];
	}

	/**
	 * Build the shared quiz issuance context
	 *
	 * Display strings precomputed per the shared source-context
	 * contract; instructor resolves from the parent course's author,
	 * falling back to the quiz's own.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_Post|object $quiz         Quiz post.
	 * @param float          $percent      Score percent (already rounded).
	 * @param object|null    $course       Parent course post (optional).
	 * @param string         $completed_at Completion datetime, UTC.
	 * @return array
	 */
	protected function build_quiz_context( $quiz, float $percent, $course, string $completed_at ): array {
		$instructor = '';
		$author_src = is_object( $course ) && ! empty( $course->post_author ) ? $course : $quiz;

		if ( ! empty( $author_src->post_author ) ) {
			$author     = get_userdata( (int) $author_src->post_author );
			$instructor = $author ? (string) $author->display_name : '';
		}

		return [
			'source_post_id'    => (int) $quiz->ID,
			'src_quiz_title'    => (string) $quiz->post_title,
			'src_score_display' => $this->format_percent_display( $percent ),
			'src_grade_display' => __( 'Passed', 'pressprimer-certificate' ),
			'lms_course_title'  => is_object( $course ) ? (string) $course->post_title : '',
			'src_completed_at'  => $completed_at,
			'lms_instructor'    => $instructor,
		];
	}

	/**
	 * Resolve the course title.
	 *
	 * @since 1.0.0
	 *
	 * @param array $context Issuance context.
	 * @return string
	 */
	public function resolve_course_title( array $context ) {
		return isset( $context['lms_course_title'] ) ? (string) $context['lms_course_title'] : '';
	}

	/**
	 * Resolve the completion date in the site date format.
	 *
	 * Shared across every adapter registering source.completion_date or
	 * source.pass_date (shared source-context contract).
	 *
	 * @since 1.0.0
	 *
	 * @param array $context Issuance context.
	 * @return string
	 */
	public function resolve_source_completed_date( array $context ) {
		if ( empty( $context['src_completed_at'] ) ) {
			return '';
		}

		// src_completed_at is UTC (shared source-context contract).
		return (string) get_date_from_gmt( (string) $context['src_completed_at'], get_option( 'date_format' ) );
	}

	/**
	 * Resolve the instructor name.
	 *
	 * @since 1.0.0
	 *
	 * @param array $context Issuance context.
	 * @return string
	 */
	public function resolve_course_instructor( array $context ) {
		return isset( $context['lms_instructor'] ) ? (string) $context['lms_instructor'] : '';
	}

	/**
	 * Resolve the triggering quiz's title (shared contract).
	 *
	 * @since 1.0.0
	 *
	 * @param array $context Issuance context.
	 * @return string
	 */
	public function resolve_source_quiz_title( array $context ) {
		return isset( $context['src_quiz_title'] ) ? (string) $context['src_quiz_title'] : '';
	}

	/**
	 * Resolve the display-ready score (shared contract).
	 *
	 * @since 1.0.0
	 *
	 * @param array $context Issuance context.
	 * @return string
	 */
	public function resolve_source_score( array $context ) {
		return isset( $context['src_score_display'] ) ? (string) $context['src_score_display'] : '';
	}

	/**
	 * Resolve the display-ready grade/result (shared contract).
	 *
	 * @since 1.0.0
	 *
	 * @param array $context Issuance context.
	 * @return string
	 */
	public function resolve_source_grade( array $context ) {
		return isset( $context['src_grade_display'] ) ? (string) $context['src_grade_display'] : '';
	}

	/**
	 * Format a numeric percent for display (86.5% / 92%)
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $value Numeric percent.
	 * @return string Empty when non-numeric.
	 */
	protected function format_percent_display( $value ): string {
		if ( ! is_numeric( $value ) ) {
			return '';
		}

		return rtrim( rtrim( number_format( (float) $value, 2, '.', '' ), '0' ), '.' ) . '%';
	}

	/**
	 * Filter callback: contribute this adapter's merge fields
	 *
	 * Merges get_merge_fields() group-wise into the registry array.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed  $fields  Registered merge fields.
	 * @param string $context 'designer' (palette) or 'issue' (resolution).
	 * @return array
	 */
	public function register_merge_fields( $fields, $context = 'designer' ): array {
		$fields = is_array( $fields ) ? $fields : [];

		foreach ( $this->get_merge_fields() as $group => $group_fields ) {
			foreach ( (array) $group_fields as $field_key => $definition ) {
				if ( ! is_array( $definition ) ) {
					$fields[ $group ][ $field_key ] = $definition;
					continue;
				}

				// Tag with the contributing trigger type so the designer
				// palette can scope source fields to the template's
				// trigger (Feature 002 registry scoping). A key another
				// adapter already registered (source.course_title across
				// course adapters) keeps its earlier tags - the UNION
				// keeps the field in scope for every contributor.
				$tags = isset( $definition['trigger_types'] ) && is_array( $definition['trigger_types'] )
					? $definition['trigger_types']
					: [];

				if ( isset( $definition['trigger_type'] ) && is_string( $definition['trigger_type'] ) ) {
					$tags[] = $definition['trigger_type'];
					unset( $definition['trigger_type'] );
				}

				if ( isset( $fields[ $group ][ $field_key ]['trigger_types'] ) && is_array( $fields[ $group ][ $field_key ]['trigger_types'] ) ) {
					$tags = array_merge( $fields[ $group ][ $field_key ]['trigger_types'], $tags );
				} elseif ( isset( $fields[ $group ][ $field_key ]['trigger_type'] ) && is_string( $fields[ $group ][ $field_key ]['trigger_type'] ) ) {
					$tags[] = $fields[ $group ][ $field_key ]['trigger_type'];
				}

				$tags[] = $this->get_id();

				$definition['trigger_types'] = array_values( array_unique( array_filter( $tags ) ) );

				$fields[ $group ][ $field_key ] = $definition;
			}
		}

		return $fields;
	}
}
