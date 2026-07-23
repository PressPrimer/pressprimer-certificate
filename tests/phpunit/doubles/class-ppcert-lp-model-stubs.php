<?php // phpcs:ignore Squiz.Commenting.FileComment.Missing -- Namespaced stub file; documented per-namespace below.

/**
 * LearnPress model stubs
 *
 * Namespaced doubles for the LP user-item models the LearnPress
 * adapters read: graduation lookups and quiz results come from
 * fixture globals.
 *
 * @package PressPrimer_Certificate
 * @subpackage Tests
 * @since 1.0.0
 */

namespace LearnPress\Models\UserItems {
	if ( ! class_exists( __NAMESPACE__ . '\\UserCourseModel' ) ) {
		/**
		 * Stub: user-course item; find() reads
		 * $GLOBALS['ppcert_test_lp_graduations'][user][course].
		 */
		class UserCourseModel {

			/**
			 * Graduation value ('passed' | 'failed' | '').
			 *
			 * @var string
			 */
			public $graduation = '';

			/**
			 * Fixture-backed lookup (mirrors the LP model contract).
			 *
			 * @param int  $user_id     User id.
			 * @param int  $course_id   Course id.
			 * @param bool $check_cache Unused.
			 * @return self|false
			 */
			public static function find( int $user_id, int $course_id, bool $check_cache = false ) {
				if ( ! isset( $GLOBALS['ppcert_test_lp_graduations'][ $user_id ][ $course_id ] ) ) {
					return false;
				}

				$item             = new self();
				$item->graduation = (string) $GLOBALS['ppcert_test_lp_graduations'][ $user_id ][ $course_id ];

				return $item;
			}
		}
	}
}

namespace {
	if ( ! class_exists( 'PPCert_Test_LP_User_Quiz' ) ) {
		/**
		 * Stub: LP UserQuizModel double carrying a fixed result array.
		 */
		class PPCert_Test_LP_User_Quiz {

			/**
			 * The stored quiz result.
			 *
			 * @var array
			 */
			private $result;

			/**
			 * Constructor.
			 *
			 * @param array $result Result array (keys: pass, result).
			 */
			public function __construct( array $result ) {
				$this->result = $result;
			}

			/**
			 * Result getter (mirrors UserQuizModel::get_result()).
			 *
			 * @return array
			 */
			public function get_result(): array {
				return $this->result;
			}
		}
	}
}
