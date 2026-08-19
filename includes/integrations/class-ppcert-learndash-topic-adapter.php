<?php
/**
 * LearnDash topic adapter
 *
 * Sub-course trigger (1.0 scope addition, Ryan 2026-07-23): "award this
 * certificate when a LearnDash topic is completed." Extends the lesson
 * adapter - a topic completion carries the same shapes plus the parent
 * lesson.
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
 * LearnDash topic adapter class
 *
 * @since 1.0.0
 */
class PressPrimer_Certificate_LearnDash_Topic_Adapter extends PressPrimer_Certificate_LearnDash_Lesson_Adapter {

	/**
	 * Trigger type id
	 *
	 * @since 1.0.0
	 * @var string
	 */
	const TRIGGER_TYPE = 'lms_learndash_topic';

	/**
	 * LearnDash topic post type
	 *
	 * @since 1.0.0
	 * @var string
	 */
	const TOPIC_POST_TYPE = 'sfwd-topic';

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
		return __( 'Topic completed (LearnDash)', 'pressprimer-certificate' );
	}

	/**
	 * Source noun for the Award tab and merge-field palette
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public function get_source_group_label(): string {
		return __( 'Topic', 'pressprimer-certificate' );
	}

	/**
	 * Short trigger label (integration already chosen)
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public function get_short_label(): string {
		return __( 'Topic completed', 'pressprimer-certificate' );
	}

	/**
	 * Cascade: course, then lesson, then a topic from that lesson.
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
			[
				'key'   => 'lesson',
				'label' => __( 'Lesson', 'pressprimer-certificate' ),
			],
		];
	}

	/**
	 * Level options: courses, then the chosen course's lessons
	 *
	 * @since 1.0.0
	 *
	 * @param string $level   Level key.
	 * @param array  $parents Earlier level selections.
	 * @param string $search  Search term.
	 * @return array<int,array{id:string,title:string}>
	 */
	public function get_level_options( string $level, array $parents, string $search = '' ): array {
		if ( 'lesson' === $level ) {
			$course_id = isset( $parents['course'] ) ? absint( $parents['course'] ) : 0;

			return $course_id > 0
				? $this->ld_step_sources( $course_id, PressPrimer_Certificate_LearnDash_Lesson_Adapter::LESSON_POST_TYPE, $search )
				: [];
		}

		return parent::get_level_options( $level, $parents, $search );
	}

	/**
	 * Sources scoped to course + lesson: the lesson's topics
	 *
	 * @since 1.0.0
	 *
	 * @param array  $parents Level selections (course, lesson => ids).
	 * @param string $search  Search term.
	 * @return array<int,array{id:string,title:string}>
	 */
	public function get_sources_for_parents( array $parents, string $search = '' ): array {
		$course_id = isset( $parents['course'] ) ? absint( $parents['course'] ) : 0;
		$lesson_id = isset( $parents['lesson'] ) ? absint( $parents['lesson'] ) : 0;

		if ( $course_id < 1 || $lesson_id < 1 ) {
			return $this->get_sources( $search );
		}

		if ( ! function_exists( 'learndash_get_topic_list' ) ) {
			return [];
		}

		return $this->posts_to_options( (array) learndash_get_topic_list( $lesson_id, $course_id ), $search );
	}

	/**
	 * Topics are posts: unlocks the source-meta picker (Feature 002).
	 *
	 * @since 1.0.0
	 *
	 * @return string[]
	 */
	public function get_source_post_types(): array {
		return [ self::TOPIC_POST_TYPE ];
	}

	/**
	 * An 'any' topic trigger is scoped to its course AND lesson - the
	 * full parent cascade stays specific (leaf-only "Any")
	 *
	 * @since 1.1.0
	 *
	 * @return string[]
	 */
	public function get_scope_condition_keys(): array {
		return [ 'course_id', 'lesson_id' ];
	}

	/**
	 * "Any" option label (leaf-only Any, Feature 1.1-002 FR-003).
	 *
	 * @since 1.1.0
	 *
	 * @return string
	 */
	public function get_any_source_label(): string {
		return __( 'Any topic in this lesson', 'pressprimer-certificate' );
	}

	/**
	 * Listen for completed topics
	 *
	 * Hook citation (LearnDash 4.23.0): `learndash_topic_completed`
	 * fires in learndash_process_mark_complete()
	 * (includes/course/ld-course-progress.php:953) with ONE array arg:
	 * [ 'user' => WP_User, 'course' => WP_Post, 'lesson' => WP_Post,
	 * 'topic' => WP_Post, 'progress' => array ] - after the user
	 * activity/progress rows are written, so completion state is final
	 * (TR-003). Priority 20 keeps us after LearnDash's own
	 * default-priority listeners.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function register_listeners(): void {
		add_action( 'learndash_topic_completed', [ $this, 'handle_topic_completed' ], 20, 1 );
	}

	/**
	 * Selectable sources: published LearnDash topics
	 *
	 * @since 1.0.0
	 *
	 * @param string $search Search term.
	 * @return array<int,array{id:string,title:string}>
	 */
	public function get_sources( string $search = '' ): array {
		return $this->get_post_sources( self::TOPIC_POST_TYPE, $search );
	}

	/**
	 * Contributed merge fields: topic title plus the lesson adapter's
	 * set (lesson title + shared course set)
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	public function get_merge_fields(): array {
		$fields = parent::get_merge_fields();

		$fields['source'] = array_merge(
			[
				'topic_title' => [
					'key'      => 'source.topic_title',
					'label'    => __( 'Topic Title', 'pressprimer-certificate' ),
					'sample'   => __( 'Stomata Up Close', 'pressprimer-certificate' ),
					'resolver' => [ $this, 'resolve_topic_title' ],
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
			[ 'source.topic_title' => $this->resolve_topic_title( $context ) ],
			parent::resolve_merge_data( $context )
		);
	}

	/**
	 * The topic-completed listener (FR-003)
	 *
	 * @since 1.0.0
	 *
	 * @param array $data LearnDash payload (user, course, lesson, topic).
	 * @return void
	 */
	public function handle_topic_completed( $data ) {
		if ( ! is_array( $data ) || empty( $data['topic'] ) || ! is_object( $data['topic'] ) ) {
			return;
		}

		$topic   = $data['topic'];
		$user_id = isset( $data['user']->ID ) ? (int) $data['user']->ID : 0;

		if ( $user_id < 1 || empty( $topic->ID ) ) {
			return;
		}

		$triggers = PressPrimer_Certificate_Trigger::find_active( self::TRIGGER_TYPE, (string) $topic->ID );

		if ( empty( $triggers ) ) {
			return;
		}

		$course = isset( $data['course'] ) && is_object( $data['course'] ) ? $data['course'] : null;
		$lesson = isset( $data['lesson'] ) && is_object( $data['lesson'] ) ? $data['lesson'] : null;

		$context = [
			'source_post_id'   => (int) $topic->ID,
			'lms_topic_title'  => (string) $topic->post_title,
			'lms_lesson_title' => $lesson ? (string) $lesson->post_title : '',
			'lms_course_title' => $course ? (string) $course->post_title : '',
			'src_completed_at' => gmdate( 'Y-m-d H:i:s' ),
			'lms_instructor'   => $this->author_display_name( $course ? $course : $topic ),
		];

		$fired_scope = [
			'course_id' => $course && ! empty( $course->ID ) ? (string) $course->ID : '',
			'lesson_id' => $lesson && ! empty( $lesson->ID ) ? (string) $lesson->ID : '',
		];

		foreach ( $triggers as $trigger ) {
			if ( ! $this->trigger_scope_matches( $trigger, $fired_scope ) ) {
				continue;
			}

			PressPrimer_Certificate_Issuance_Service::issue(
				[
					'template_id'  => (int) $trigger->template_id,
					'recipient_id' => $user_id,
					'source_type'  => self::TRIGGER_TYPE,
					'source_ref'   => (string) $topic->ID,
					'issued_by'    => 0,
					'context'      => $context,
				]
			);
		}
	}

	/**
	 * Resolve the topic title.
	 *
	 * @since 1.0.0
	 *
	 * @param array $context Issuance context.
	 * @return string
	 */
	public function resolve_topic_title( array $context ) {
		return isset( $context['lms_topic_title'] ) ? (string) $context['lms_topic_title'] : '';
	}
}
