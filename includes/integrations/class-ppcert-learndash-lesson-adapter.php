<?php
/**
 * LearnDash lesson adapter
 *
 * Sub-course trigger (1.0 scope addition, Ryan 2026-07-23): "award this
 * certificate when a LearnDash lesson is completed." Extends the course
 * adapter for availability and the shared course helpers.
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
 * LearnDash lesson adapter class
 *
 * @since 1.0.0
 */
class PressPrimer_Certificate_LearnDash_Lesson_Adapter extends PressPrimer_Certificate_LearnDash_Adapter {

	/**
	 * Trigger type id
	 *
	 * @since 1.0.0
	 * @var string
	 */
	const TRIGGER_TYPE = 'lms_learndash_lesson';

	/**
	 * LearnDash lesson post type
	 *
	 * @since 1.0.0
	 * @var string
	 */
	const LESSON_POST_TYPE = 'sfwd-lessons';

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
		return __( 'Lesson completed (LearnDash)', 'pressprimer-certificate' );
	}

	/**
	 * Source noun for the Award tab and merge-field palette
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public function get_source_group_label(): string {
		return __( 'Lesson', 'pressprimer-certificate' );
	}

	/**
	 * Short trigger label (integration already chosen)
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public function get_short_label(): string {
		return __( 'Lesson completed', 'pressprimer-certificate' );
	}

	/**
	 * Lessons are posts: unlocks the source-meta picker (Feature 002).
	 *
	 * @since 1.0.0
	 *
	 * @return string[]
	 */
	public function get_source_post_types(): array {
		return [ self::LESSON_POST_TYPE ];
	}

	/**
	 * Cascade: pick the course first, then a lesson from that course
	 * (Ryan's Award-tab review, 2026-07-23: same-named lessons need
	 * context).
	 *
	 * @since 1.0.0
	 *
	 * @return array<int,array{key:string,label:string}>
	 */
	public function get_source_levels(): array {
		return [
			[
				'key'   => 'course',
				'label' => __( 'Course', 'pressprimer-certificate' ),
			],
		];
	}

	/**
	 * Level options: published LearnDash courses
	 *
	 * @since 1.0.0
	 *
	 * @param string $level   Level key.
	 * @param array  $parents Earlier level selections.
	 * @param string $search  Search term.
	 * @return array<int,array{id:string,title:string}>
	 */
	public function get_level_options( string $level, array $parents, string $search = '' ): array {
		if ( 'course' === $level ) {
			return $this->get_post_sources( PressPrimer_Certificate_LearnDash_Adapter::COURSE_POST_TYPE, $search );
		}

		return [];
	}

	/**
	 * Sources scoped to the chosen course: its lesson steps
	 *
	 * @since 1.0.0
	 *
	 * @param array  $parents Level selections (course => id).
	 * @param string $search  Search term.
	 * @return array<int,array{id:string,title:string}>
	 */
	public function get_sources_for_parents( array $parents, string $search = '' ): array {
		$course_id = isset( $parents['course'] ) ? absint( $parents['course'] ) : 0;

		if ( $course_id < 1 ) {
			return $this->get_sources( $search );
		}

		return $this->ld_step_sources( $course_id, self::LESSON_POST_TYPE, $search );
	}

	/**
	 * An 'any' lesson trigger is scoped to its course (leaf-only "Any")
	 *
	 * @since 1.1.0
	 *
	 * @return string[]
	 */
	public function get_scope_condition_keys(): array {
		return [ 'course_id' ];
	}

	/**
	 * Listen for completed lessons
	 *
	 * Hook citation (LearnDash 4.23.0): `learndash_lesson_completed`
	 * fires in learndash_process_mark_complete()
	 * (includes/course/ld-course-progress.php:933) with ONE array arg:
	 * [ 'user' => WP_User, 'course' => WP_Post, 'lesson' => WP_Post,
	 * 'progress' => array ] - after the user activity/progress rows are
	 * written, so completion state is final (TR-003). Priority 20 keeps
	 * us after LearnDash's own default-priority listeners.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function register_listeners(): void {
		add_action( 'learndash_lesson_completed', [ $this, 'handle_lesson_completed' ], 20, 1 );
	}

	/**
	 * Selectable sources: published LearnDash lessons
	 *
	 * @since 1.0.0
	 *
	 * @param string $search Search term.
	 * @return array<int,array{id:string,title:string}>
	 */
	public function get_sources( string $search = '' ): array {
		return $this->get_post_sources( self::LESSON_POST_TYPE, $search );
	}

	/**
	 * Contributed merge fields: lesson title plus the shared course set
	 * (the shared keys carry every contributing trigger type's tag)
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	public function get_merge_fields(): array {
		$fields = $this->course_merge_fields();

		$fields['source'] = array_merge(
			[
				'lesson_title' => [
					'key'      => 'source.lesson_title',
					'label'    => __( 'Lesson Title', 'pressprimer-certificate' ),
					'sample'   => __( 'Leaf Structure and Function', 'pressprimer-certificate' ),
					'resolver' => [ $this, 'resolve_lesson_title' ],
				],
			],
			$fields['source']
		);

		return $fields;
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
		return array_merge(
			[ 'source.lesson_title' => $this->resolve_lesson_title( $context ) ],
			$this->resolve_course_merge_data( $context )
		);
	}

	/**
	 * The lesson-completed listener (FR-003)
	 *
	 * @since 1.0.0
	 *
	 * @param array $data LearnDash payload (user, course, lesson).
	 * @return void
	 */
	public function handle_lesson_completed( $data ) {
		if ( ! is_array( $data ) || empty( $data['lesson'] ) || ! is_object( $data['lesson'] ) ) {
			return;
		}

		$lesson  = $data['lesson'];
		$user_id = isset( $data['user']->ID ) ? (int) $data['user']->ID : 0;

		if ( $user_id < 1 || empty( $lesson->ID ) ) {
			return;
		}

		$triggers = PressPrimer_Certificate_Trigger::find_active( self::TRIGGER_TYPE, (string) $lesson->ID );

		if ( empty( $triggers ) ) {
			return;
		}

		$course = isset( $data['course'] ) && is_object( $data['course'] ) ? $data['course'] : null;

		// The lesson is the source (source_post_id feeds source.meta.*);
		// course title/instructor come from the parent course in the
		// payload. The event carries no completion moment, so the
		// listener stamps the event time in UTC.
		$context = [
			'source_post_id'   => (int) $lesson->ID,
			'lms_lesson_title' => (string) $lesson->post_title,
			'lms_course_title' => $course ? (string) $course->post_title : '',
			'src_completed_at' => gmdate( 'Y-m-d H:i:s' ),
			'lms_instructor'   => $this->author_display_name( $course ? $course : $lesson ),
		];

		$fired_scope = [ 'course_id' => $course && ! empty( $course->ID ) ? (string) $course->ID : '' ];

		foreach ( $triggers as $trigger ) {
			if ( ! $this->trigger_scope_matches( $trigger, $fired_scope ) ) {
				continue;
			}

			PressPrimer_Certificate_Issuance_Service::issue(
				[
					'template_id'  => (int) $trigger->template_id,
					'recipient_id' => $user_id,
					'source_type'  => self::TRIGGER_TYPE,
					'source_ref'   => (string) $lesson->ID,
					'issued_by'    => 0,
					'context'      => $context,
				]
			);
		}
	}

	/**
	 * Resolve the lesson title.
	 *
	 * @since 1.0.0
	 *
	 * @param array $context Issuance context.
	 * @return string
	 */
	public function resolve_lesson_title( array $context ) {
		return isset( $context['lms_lesson_title'] ) ? (string) $context['lms_lesson_title'] : '';
	}

	/**
	 * Author display name of a post ('' when unavailable).
	 *
	 * @since 1.0.0
	 *
	 * @param object|null $post Post object.
	 * @return string
	 */
	protected function author_display_name( $post ): string {
		if ( ! is_object( $post ) || empty( $post->post_author ) ) {
			return '';
		}

		$author = get_userdata( (int) $post->post_author );

		return $author ? (string) $author->display_name : '';
	}
}
