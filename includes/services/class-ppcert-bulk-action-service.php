<?php
/**
 * Bulk action service
 *
 * Revoke, reinstate, resend, or delete many certificates as one job
 * (Feature 2.1-002 TR-003). The set is fixed when the user confirms:
 * the job stores the certificate ids, the action, and the creator's
 * bulk scope, and the open Certificates page then processes it one
 * REST request at a time (the Quiz Reset Progress pattern), so the
 * acting user, their scope, and the audit actor are always the real
 * person and nothing depends on WP-Cron. Every row is re-checked when
 * processed and skipped with a reason when it no longer exists, has
 * left the job's scope, may no longer be managed by the creator, or
 * is already in the target state.
 *
 * @package PressPrimer_Certificate
 * @subpackage Services
 * @since 2.1.0
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Bulk action service class
 *
 * @since 2.1.0
 */
class PressPrimer_Certificate_Bulk_Action_Service {

	/**
	 * Bulk actions
	 *
	 * @since 2.1.0
	 * @var string[]
	 */
	const ACTIONS = [ 'revoke', 'reinstate', 'resend', 'delete' ];

	/**
	 * Site lock transient (one bulk job at a time per site)
	 *
	 * @since 2.1.0
	 * @var string
	 */
	const LOCK_KEY = 'ppcert_bulk_lock';

	/**
	 * Lock lifetime in seconds, refreshed by every run
	 *
	 * @since 2.1.0
	 * @var int
	 */
	const LOCK_TTL = 300;

	/**
	 * Job option prefix (one option per job, autoload off)
	 *
	 * @since 2.1.0
	 * @var string
	 */
	const JOB_PREFIX = 'ppcert_bulk_job_';

	/**
	 * Run-in-progress transient prefix (one run per job at a time)
	 *
	 * @since 2.1.0
	 * @var string
	 */
	const RUN_PREFIX = 'ppcert_bulk_run_';

	/**
	 * Job index option: job id => { created_by, status, updated_at }
	 *
	 * @since 2.1.0
	 * @var string
	 */
	const INDEX_OPTION = 'ppcert_bulk_jobs';

	/**
	 * Default most certificates per job (filter ppcert_bulk_max_items)
	 *
	 * @since 2.1.0
	 * @var int
	 */
	const MAX_ITEMS = 50000;

	/**
	 * Skipped and failed rows kept for the summary
	 *
	 * @since 2.1.0
	 * @var int
	 */
	const PROBLEM_CAP = 50;

	/**
	 * Days a finished or discarded job is kept
	 *
	 * @since 2.1.0
	 * @var int
	 */
	const RETENTION_DAYS = 7;

	/**
	 * Days an unfinished job may sit untouched before it is removed
	 *
	 * @since 2.1.0
	 * @var int
	 */
	const STALE_DAYS = 30;

	/**
	 * The job whose rows are being processed (current_job())
	 *
	 * @since 2.1.0
	 * @var array|null
	 */
	private static $current_job = null;

	/**
	 * Hook the job pruning into the daily event pruner
	 *
	 * @since 2.1.0
	 */
	public static function init() {
		add_action( PressPrimer_Certificate_Event_Pruner::CRON_HOOK, [ __CLASS__, 'prune' ] );
	}

	/**
	 * Create a job for a fixed set of certificates
	 *
	 * The caller has already resolved the set (the checked rows, or the
	 * filters run with the user's bulk scope); this stores it with the
	 * scope that applies to it, takes the site lock, and fires
	 * `ppcert_certificates_bulk_started`.
	 *
	 * @since 2.1.0
	 *
	 * @param int    $user_id The creator (the user confirming).
	 * @param string $action  revoke | reinstate | resend | delete.
	 * @param int[]  $ids     Certificate ids.
	 * @param array  $args {
	 *     Optional.
	 *
	 *     @type string $reason   Revocation reason (revoke only).
	 *     @type array  $criteria What was selected, for display and audit:
	 *                            { type: 'selected' } or { type: 'filters',
	 *                            filters: {...} }.
	 * }
	 * @return array|WP_Error The job (public view), or an error.
	 */
	public static function create( $user_id, $action, array $ids, array $args = [] ) {
		$user_id = absint( $user_id );
		$action  = (string) $action;

		if ( ! in_array( $action, self::ACTIONS, true ) ) {
			return new WP_Error( 'ppcert_bulk_invalid_action', __( 'Choose a bulk action.', 'pressprimer-certificate' ), [ 'status' => 400 ] );
		}

		if ( ! PressPrimer_Certificate_Certificate::user_can_bulk_manage( $user_id, $action ) ) {
			return new WP_Error( 'ppcert_bulk_forbidden', __( 'You are not allowed to run this bulk action.', 'pressprimer-certificate' ), [ 'status' => 403 ] );
		}

		// Positive integers only (absint() would turn -4 into 4).
		$ids = array_values(
			array_unique(
				array_filter(
					array_map( 'intval', $ids ),
					static function ( $id ) {
						return $id > 0;
					}
				)
			)
		);

		if ( empty( $ids ) ) {
			return new WP_Error( 'ppcert_bulk_empty', __( 'Select at least one certificate.', 'pressprimer-certificate' ), [ 'status' => 400 ] );
		}

		if ( count( $ids ) > self::max_items() ) {
			return new WP_Error(
				'ppcert_bulk_too_many',
				sprintf(
					/* translators: %s: the most certificates one bulk action may change. */
					__( 'Narrow your filters to %s certificates or fewer.', 'pressprimer-certificate' ),
					number_format_i18n( self::max_items() )
				),
				[ 'status' => 400 ]
			);
		}

		$job_id = wp_generate_uuid4();
		$locked = self::acquire_lock( $job_id, $user_id, $action );

		if ( is_wp_error( $locked ) ) {
			return $locked;
		}

		$now      = current_time( 'mysql', true );
		$criteria = isset( $args['criteria'] ) && is_array( $args['criteria'] ) ? $args['criteria'] : [ 'type' => 'selected' ];

		$job = [
			'id'         => $job_id,
			'action'     => $action,
			'reason'     => 'revoke' === $action && isset( $args['reason'] ) ? substr( sanitize_text_field( (string) $args['reason'] ), 0, 191 ) : '',
			'created_by' => $user_id,
			'created_at' => $now,
			'updated_at' => $now,
			'criteria'   => $criteria,
			// Fixed at confirmation (FR-004): a membership change mid-run
			// can narrow what the creator may do, never widen it.
			'scope'      => PressPrimer_Certificate_Certificate::bulk_scope( $user_id ),
			'ids'        => $ids,
			'total'      => count( $ids ),
			'cursor'     => 0,
			'counts'     => [
				'done'    => 0,
				'skipped' => 0,
				'failed'  => 0,
			],
			'problems'   => [],
			'status'     => 'ready',
			'message'    => '',
		];

		self::save( $job );

		/**
		 * Fires when a bulk certificate job is confirmed.
		 *
		 * @since 2.1.0
		 *
		 * @param array $job The job (public view): id, action, created_by,
		 *                   created_at, criteria, scope, total, counts,
		 *                   status.
		 */
		do_action( 'ppcert_certificates_bulk_started', self::public_view( $job ) );

		return self::public_view( $job );
	}

	/**
	 * Process the next rows of a job, within the time budget
	 *
	 * Only the job's creator may run it, and only while they still pass
	 * the bulk permission check; a failed check stops the job with that
	 * message. Rows are processed in chunks (25, or 5 for resend) until
	 * the budget is spent or the set is done; the job is saved after
	 * each chunk.
	 *
	 * @since 2.1.0
	 *
	 * @param string $job_id  Job id.
	 * @param int    $user_id The requesting user.
	 * @return array|WP_Error The job (public view) after this run.
	 */
	public static function run( $job_id, $user_id ) {
		$user_id = absint( $user_id );
		$job     = self::owned_job( $job_id, $user_id );

		if ( is_wp_error( $job ) ) {
			return $job;
		}

		if ( ! self::is_unfinished( $job ) ) {
			return self::public_view( $job );
		}

		if ( ! PressPrimer_Certificate_Certificate::user_can_bulk_manage( $user_id, $job['action'] ) ) {
			$job['status']  = 'stopped';
			$job['message'] = __( 'You are no longer allowed to run this bulk action, so it stopped. Certificates already processed stay processed.', 'pressprimer-certificate' );
			self::save( $job );
			self::release_lock( $job['id'] );

			return new WP_Error( 'ppcert_bulk_forbidden', $job['message'], [ 'status' => 403 ] );
		}

		$locked = self::acquire_lock( $job['id'], $user_id, $job['action'] );

		if ( is_wp_error( $locked ) ) {
			return $locked;
		}

		$run_key = self::RUN_PREFIX . $job['id'];

		if ( get_transient( $run_key ) ) {
			return new WP_Error( 'ppcert_bulk_busy', __( 'This bulk action is already running in another window.', 'pressprimer-certificate' ), [ 'status' => 409 ] );
		}

		$budget = self::time_budget();
		set_transient( $run_key, 1, $budget + 60 );

		$started = microtime( true );
		$size    = self::chunk_size( $job['action'] );

		$job['status']  = 'running';
		$job['message'] = '';

		self::$current_job = self::public_view( $job );

		try {
			while ( $job['cursor'] < $job['total'] ) {
				$chunk = array_slice( $job['ids'], $job['cursor'], $size );

				foreach ( $chunk as $id ) {
					$outcome = self::process_row( $job, (int) $id );

					++$job['counts'][ $outcome['result'] ];

					if ( 'done' !== $outcome['result'] && count( $job['problems'] ) < self::PROBLEM_CAP ) {
						$job['problems'][] = [
							'id'            => (int) $id,
							'credential_id' => $outcome['credential_id'],
							'result'        => $outcome['result'],
							'reason'        => $outcome['reason'],
						];
					}

					++$job['cursor'];
				}

				$job['updated_at'] = current_time( 'mysql', true );
				self::save( $job );
				self::acquire_lock( $job['id'], $user_id, $job['action'] );

				if ( microtime( true ) - $started >= $budget ) {
					break;
				}
			}
		} finally {
			self::$current_job = null;
			delete_transient( $run_key );
		}

		if ( $job['cursor'] >= $job['total'] ) {
			$job['status']     = 'done';
			$job['updated_at'] = current_time( 'mysql', true );
			self::save( $job );
			self::release_lock( $job['id'] );

			/**
			 * Fires when a bulk certificate job has processed every row.
			 *
			 * @since 2.1.0
			 *
			 * @param array $job The job (public view) with its final counts.
			 */
			do_action( 'ppcert_certificates_bulk_completed', self::public_view( $job ) );
		}

		return self::public_view( $job );
	}

	/**
	 * A job's status for its creator
	 *
	 * @since 2.1.0
	 *
	 * @param string $job_id  Job id.
	 * @param int    $user_id The requesting user.
	 * @return array|WP_Error
	 */
	public static function status( $job_id, $user_id ) {
		$job = self::owned_job( $job_id, $user_id );

		return is_wp_error( $job ) ? $job : self::public_view( $job );
	}

	/**
	 * Cancel or discard a job
	 *
	 * Rows already processed stay processed; the rest are left alone.
	 * Releases the site lock and fires `ppcert_certificates_bulk_cancelled`.
	 *
	 * @since 2.1.0
	 *
	 * @param string $job_id  Job id.
	 * @param int    $user_id The requesting user.
	 * @return array|WP_Error The job (public view).
	 */
	public static function cancel( $job_id, $user_id ) {
		$job = self::owned_job( $job_id, $user_id );

		if ( is_wp_error( $job ) ) {
			return $job;
		}

		if ( ! self::is_unfinished( $job ) ) {
			return self::public_view( $job );
		}

		$job['status']     = 'discarded';
		$job['updated_at'] = current_time( 'mysql', true );
		self::save( $job );
		self::release_lock( $job['id'] );

		/**
		 * Fires when a bulk certificate job ends before processing every
		 * row: cancelled, discarded, or removed after sitting unfinished.
		 *
		 * @since 2.1.0
		 *
		 * @param array $job The job (public view) with the counts reached.
		 */
		do_action( 'ppcert_certificates_bulk_cancelled', self::public_view( $job ) );

		return self::public_view( $job );
	}

	/**
	 * A user's unfinished jobs, newest first (the resume notice)
	 *
	 * @since 2.1.0
	 *
	 * @param int $user_id User id.
	 * @return array[] Public views.
	 */
	public static function unfinished_for( $user_id ) {
		$user_id = absint( $user_id );
		$jobs    = [];

		foreach ( self::index() as $job_id => $entry ) {
			if ( (int) $entry['created_by'] !== $user_id || ! in_array( $entry['status'], [ 'ready', 'running', 'stopped' ], true ) ) {
				continue;
			}

			$job = self::get( (string) $job_id );

			if ( null !== $job && self::is_unfinished( $job ) ) {
				$jobs[] = self::public_view( $job );
			}
		}

		usort(
			$jobs,
			static function ( $a, $b ) {
				return strcmp( (string) $b['updated_at'], (string) $a['updated_at'] );
			}
		);

		return $jobs;
	}

	/**
	 * The job whose rows are being processed right now, or null
	 *
	 * Per-certificate listeners (Enterprise's audit log) use it to tell
	 * that a revoke or delete belongs to a bulk job.
	 *
	 * @since 2.1.0
	 *
	 * @return array|null Public view.
	 */
	public static function current_job() {
		return self::$current_job;
	}

	/**
	 * Who holds the site lock (for the "another bulk action" message)
	 *
	 * @since 2.1.0
	 *
	 * @return array|null { job, user, action }.
	 */
	public static function lock_holder() {
		$lock = get_transient( self::LOCK_KEY );

		return is_array( $lock ) && ! empty( $lock['job'] ) ? $lock : null;
	}

	/**
	 * Remove old jobs (daily, with the event pruner)
	 *
	 * Finished and discarded jobs go after 7 days; unfinished ones
	 * untouched for 30 days are removed as cancelled.
	 *
	 * @since 2.1.0
	 *
	 * @return int Jobs removed.
	 */
	public static function prune() {
		$removed  = 0;
		$finished = gmdate( 'Y-m-d H:i:s', time() - ( self::RETENTION_DAYS * DAY_IN_SECONDS ) );
		$stale    = gmdate( 'Y-m-d H:i:s', time() - ( self::STALE_DAYS * DAY_IN_SECONDS ) );

		foreach ( self::index() as $job_id => $entry ) {
			$updated = (string) $entry['updated_at'];
			$done    = in_array( $entry['status'], [ 'done', 'discarded' ], true );

			if ( ( $done && $updated >= $finished ) || ( ! $done && $updated >= $stale ) ) {
				continue;
			}

			$job = self::get( (string) $job_id );

			if ( ! $done && null !== $job ) {
				$job['status'] = 'discarded';

				/** This action is documented in includes/services/class-ppcert-bulk-action-service.php */
				do_action( 'ppcert_certificates_bulk_cancelled', self::public_view( $job ) );
			}

			self::remove( (string) $job_id );
			++$removed;
		}

		return $removed;
	}

	/**
	 * The most certificates one job may hold
	 *
	 * @since 2.1.0
	 *
	 * @return int
	 */
	public static function max_items() {
		/**
		 * Filters the most certificates one bulk action may change.
		 *
		 * @since 2.1.0
		 *
		 * @param int $max Default 50,000.
		 */
		return max( 1, absint( apply_filters( 'ppcert_bulk_max_items', self::MAX_ITEMS ) ) );
	}

	/**
	 * Rows per chunk for an action
	 *
	 * @since 2.1.0
	 *
	 * @param string $action Bulk action.
	 * @return int
	 */
	public static function chunk_size( $action ) {
		/**
		 * Filters how many certificates a bulk action processes per chunk.
		 *
		 * @since 2.1.0
		 *
		 * @param int    $size   Default 25; 5 for resend (each send renders a PDF).
		 * @param string $action revoke | reinstate | resend | delete.
		 */
		return max( 1, absint( apply_filters( 'ppcert_bulk_chunk_size', 'resend' === $action ? 5 : 25, $action ) ) );
	}

	/**
	 * Seconds one run request may spend before reporting progress
	 *
	 * @since 2.1.0
	 *
	 * @return int
	 */
	public static function time_budget() {
		/**
		 * Filters the seconds one bulk run request may spend.
		 *
		 * @since 2.1.0
		 *
		 * @param int $seconds Default 10.
		 */
		return max( 1, absint( apply_filters( 'ppcert_bulk_time_budget', 10 ) ) );
	}

	/**
	 * Process one row
	 *
	 * @since 2.1.0
	 *
	 * @param array $job Job.
	 * @param int   $id  Certificate id.
	 * @return array { result: done|skipped|failed, reason, credential_id }.
	 */
	private static function process_row( array $job, $id ) {
		$user_id     = (int) $job['created_by'];
		$action      = (string) $job['action'];
		$certificate = PressPrimer_Certificate_Certificate::get( $id );

		if ( ! $certificate ) {
			return self::outcome( 'skipped', __( 'It no longer exists.', 'pressprimer-certificate' ), '' );
		}

		$credential = PressPrimer_Certificate_Certificate::display_credential_id( $certificate );

		if ( ! PressPrimer_Certificate_Certificate::in_scope( $certificate, $job['scope'] )
			|| ! PressPrimer_Certificate_Certificate::user_can_manage( $certificate, $user_id, $action ) ) {
			return self::outcome( 'skipped', __( 'You are not allowed to change this certificate.', 'pressprimer-certificate' ), $credential );
		}

		$revoked = 'revoked' === (string) $certificate->status;

		if ( 'revoke' === $action && $revoked ) {
			return self::outcome( 'skipped', __( 'It is already revoked.', 'pressprimer-certificate' ), $credential );
		}

		if ( 'reinstate' === $action && ! $revoked ) {
			return self::outcome( 'skipped', __( 'It is not revoked.', 'pressprimer-certificate' ), $credential );
		}

		if ( 'resend' === $action && $revoked ) {
			return self::outcome( 'skipped', __( 'Revoked certificates are not emailed.', 'pressprimer-certificate' ), $credential );
		}

		switch ( $action ) {
			case 'revoke':
				$result = PressPrimer_Certificate_Certificate::revoke( $id, (string) $job['reason'], $user_id );
				break;

			case 'reinstate':
				$result = PressPrimer_Certificate_Certificate::reinstate( $id, $user_id );
				break;

			case 'delete':
				$result = PressPrimer_Certificate_Certificate::delete( $id, $user_id, 'bulk' );
				break;

			default:
				return self::resend_row( $id, $user_id, $credential );
		}

		return is_wp_error( $result )
			? self::outcome( 'failed', $result->get_error_message(), $credential )
			: self::outcome( 'done', '', $credential );
	}

	/**
	 * Resend one certificate's award email
	 *
	 * A mailer error is a failure with the mailer's message; a send
	 * that did not happen for any other reason (no address, or the
	 * email turned off by `ppcert_email_enabled`) is skipped.
	 *
	 * @since 2.1.0
	 *
	 * @param int    $id         Certificate id.
	 * @param int    $user_id    Actor.
	 * @param string $credential Display credential ID.
	 * @return array Outcome.
	 */
	private static function resend_row( $id, $user_id, $credential ) {
		$mailer_error = '';
		$listener     = static function ( $error ) use ( &$mailer_error ) {
			$mailer_error = is_wp_error( $error ) ? $error->get_error_message() : __( 'The email could not be sent.', 'pressprimer-certificate' );
		};

		add_action( 'wp_mail_failed', $listener );

		try {
			$sent = PressPrimer_Certificate_Email_Service::resend( $id, $user_id );
		} finally {
			remove_action( 'wp_mail_failed', $listener );
		}

		if ( $sent ) {
			return self::outcome( 'done', '', $credential );
		}

		if ( '' !== $mailer_error ) {
			return self::outcome( 'failed', $mailer_error, $credential );
		}

		return self::outcome( 'skipped', __( 'No email was sent: there is no address, or the email is turned off.', 'pressprimer-certificate' ), $credential );
	}

	/**
	 * Build a row outcome
	 *
	 * @since 2.1.0
	 *
	 * @param string $result     done | skipped | failed.
	 * @param string $reason     Why (skipped and failed).
	 * @param string $credential Display credential ID.
	 * @return array
	 */
	private static function outcome( $result, $reason, $credential ) {
		return [
			'result'        => $result,
			'reason'        => (string) $reason,
			'credential_id' => (string) $credential,
		];
	}

	/**
	 * A job the user created, or an error
	 *
	 * Another user's job answers exactly like a missing one.
	 *
	 * @since 2.1.0
	 *
	 * @param string $job_id  Job id.
	 * @param int    $user_id User id.
	 * @return array|WP_Error
	 */
	private static function owned_job( $job_id, $user_id ) {
		$job = self::get( (string) $job_id );

		if ( null === $job || (int) $job['created_by'] !== absint( $user_id ) || absint( $user_id ) < 1 ) {
			return new WP_Error( 'ppcert_bulk_not_found', __( 'That bulk action was not found.', 'pressprimer-certificate' ), [ 'status' => 404 ] );
		}

		return $job;
	}

	/**
	 * Load a stored job
	 *
	 * @since 2.1.0
	 *
	 * @param string $job_id Job id.
	 * @return array|null
	 */
	public static function get( $job_id ) {
		if ( ! self::is_job_id( $job_id ) ) {
			return null;
		}

		$job = get_option( self::JOB_PREFIX . $job_id, null );

		return is_array( $job ) && isset( $job['id'], $job['ids'] ) ? $job : null;
	}

	/**
	 * Whether a string is a job id (a UUID v4)
	 *
	 * @since 2.1.0
	 *
	 * @param mixed $job_id Candidate.
	 * @return bool
	 */
	public static function is_job_id( $job_id ) {
		return is_string( $job_id ) && (bool) preg_match( '/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/', $job_id );
	}

	/**
	 * Whether a job still has rows to process
	 *
	 * @since 2.1.0
	 *
	 * @param array $job Job.
	 * @return bool
	 */
	private static function is_unfinished( array $job ) {
		return in_array( $job['status'], [ 'ready', 'running', 'stopped' ], true ) && $job['cursor'] < $job['total'];
	}

	/**
	 * Store a job and its index entry
	 *
	 * @since 2.1.0
	 *
	 * @param array $job Job.
	 * @return void
	 */
	private static function save( array $job ) {
		update_option( self::JOB_PREFIX . $job['id'], $job, false );

		$index               = self::index();
		$index[ $job['id'] ] = [
			'created_by' => (int) $job['created_by'],
			'status'     => (string) $job['status'],
			'updated_at' => (string) $job['updated_at'],
		];

		update_option( self::INDEX_OPTION, $index, false );
	}

	/**
	 * Delete a job and its index entry
	 *
	 * @since 2.1.0
	 *
	 * @param string $job_id Job id.
	 * @return void
	 */
	private static function remove( $job_id ) {
		delete_option( self::JOB_PREFIX . $job_id );

		$index = self::index();
		unset( $index[ $job_id ] );
		update_option( self::INDEX_OPTION, $index, false );
	}

	/**
	 * The job index
	 *
	 * @since 2.1.0
	 *
	 * @return array job id => { created_by, status, updated_at }.
	 */
	private static function index() {
		$index = get_option( self::INDEX_OPTION, [] );
		$clean = [];

		foreach ( is_array( $index ) ? $index : [] as $job_id => $entry ) {
			if ( self::is_job_id( (string) $job_id ) && is_array( $entry ) ) {
				$clean[ (string) $job_id ] = [
					'created_by' => isset( $entry['created_by'] ) ? (int) $entry['created_by'] : 0,
					'status'     => isset( $entry['status'] ) ? (string) $entry['status'] : '',
					'updated_at' => isset( $entry['updated_at'] ) ? (string) $entry['updated_at'] : '',
				];
			}
		}

		return $clean;
	}

	/**
	 * Take or refresh the site lock for a job
	 *
	 * @since 2.1.0
	 *
	 * @param string $job_id  Job id.
	 * @param int    $user_id Lock holder.
	 * @param string $action  Bulk action.
	 * @return true|WP_Error
	 */
	private static function acquire_lock( $job_id, $user_id, $action ) {
		$holder = self::lock_holder();

		if ( null !== $holder && (string) $holder['job'] !== (string) $job_id ) {
			$message = __( 'Another bulk action is running. Try again when it finishes.', 'pressprimer-certificate' );
			$starter = get_userdata( (int) $holder['user'] );

			if ( $starter && current_user_can( 'manage_options' ) ) {
				$message = sprintf(
					/* translators: %s: the name of the user running the other bulk action. */
					__( 'Another bulk action, started by %s, is running. Try again when it finishes.', 'pressprimer-certificate' ),
					$starter->display_name
				);
			}

			return new WP_Error( 'ppcert_bulk_locked', $message, [ 'status' => 409 ] );
		}

		set_transient(
			self::LOCK_KEY,
			[
				'job'    => (string) $job_id,
				'user'   => absint( $user_id ),
				'action' => (string) $action,
			],
			self::LOCK_TTL
		);

		return true;
	}

	/**
	 * Release the site lock if a job holds it
	 *
	 * @since 2.1.0
	 *
	 * @param string $job_id Job id.
	 * @return void
	 */
	private static function release_lock( $job_id ) {
		$holder = self::lock_holder();

		if ( null !== $holder && (string) $holder['job'] === (string) $job_id ) {
			delete_transient( self::LOCK_KEY );
		}
	}

	/**
	 * A job without its id list (REST responses, hooks, the notice)
	 *
	 * @since 2.1.0
	 *
	 * @param array $job Job.
	 * @return array
	 */
	public static function public_view( array $job ) {
		unset( $job['ids'] );

		$job['processed'] = (int) $job['cursor'];
		unset( $job['cursor'] );

		return $job;
	}
}
