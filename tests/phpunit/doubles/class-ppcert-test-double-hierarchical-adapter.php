<?php
/**
 * Hierarchical test double adapter
 *
 * Extends the flat double with parent-scope condition keys, standing in
 * for the leaf-only "Any" contract of the hierarchical LMS types
 * (LD lesson/topic/quiz, LifterLMS/Tutor/LearnPress quiz) - Feature
 * 1.1-002.
 *
 * @package PressPrimer_Certificate
 * @subpackage Tests
 * @since 1.1.0
 */

require_once __DIR__ . '/class-ppcert-test-double-adapter.php';

/**
 * Hierarchical double adapter class
 *
 * @since 1.1.0
 */
class PPCert_Test_Double_Hierarchical_Adapter extends PPCert_Test_Double_Adapter {

	/**
	 * Distinct type id so both doubles can register side by side.
	 *
	 * @return string
	 */
	public function get_id(): string {
		return 'double_lms_lesson';
	}

	/**
	 * An 'any' trigger of this type is scoped to its course.
	 *
	 * @return string[]
	 */
	public function get_scope_condition_keys(): array {
		return [ 'course_id' ];
	}
}
