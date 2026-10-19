/**
 * PressPrimer Certificate admin utilities
 *
 * The house confirmation modal for link-driven list-table actions,
 * ported from the Quiz admin.js pattern: links flagged with
 * .ppcert-confirm-link open a styled modal instead of navigating;
 * confirming follows the link's href. An optional text input (the
 * revocation reason) is declared per link via data attributes and
 * appended to the href as a query argument.
 *
 * Link data attributes:
 *   data-ppcert-title        Modal title.
 *   data-ppcert-message      Modal body text.
 *   data-ppcert-confirm      Confirm button label.
 *   data-ppcert-cancel      Cancel button label.
 *   data-ppcert-input-label  Optional input label (renders the input).
 *   data-ppcert-input-name   Query argument name for the input value.
 *
 * 2.1 (Feature 2.1-002): the Certificates list's Bulk actions. The
 * Apply buttons are intercepted for the ppcert-bulk-* actions (core
 * forwards the bottom Apply to the top one and keeps the two menus in
 * sync); a banner offers every certificate matching the filters once
 * the whole page is checked; the
 * house modal confirms, then the open page drives the REST job one
 * request at a time with a progress bar, a Cancel button, and a
 * summary. The resume notice's Resume and Discard buttons continue or
 * end a job a closed page left behind.
 *
 * @since 1.0.0
 */

/**
 * @param {Object} $  jQuery.
 * @param {Object} wp WordPress globals (i18n, apiFetch).
 */
( function ( $, wp ) {
	'use strict';

	const { __, _n, sprintf } = wp.i18n;

	/**
	 * Format a count for display.
	 *
	 * @param {number} n Count.
	 * @return {string} Localized number.
	 */
	const fmt = ( n ) => Number( n || 0 ).toLocaleString();

	/**
	 * Per-action copy for the bulk flow (Feature 2.1-002 FR-003, FR-006,
	 * FR-007).
	 */
	const BULK = {
		revoke: {
			title: __( 'Revoke certificates', 'pressprimer-certificate' ),
			message: ( n ) =>
				sprintf(
					/* translators: %s: number of certificates. */
					_n(
						'Revoke %s certificate?',
						'Revoke %s certificates?',
						n,
						'pressprimer-certificate'
					),
					fmt( n )
				),
			confirm: __( 'Revoke', 'pressprimer-certificate' ),
			input: __(
				'Reason (optional, shown to staff only):',
				'pressprimer-certificate'
			),
			progress: ( done, total ) =>
				sprintf(
					/* translators: 1: certificates processed, 2: certificates in the job. */
					__( 'Revoking %1$s of %2$s…', 'pressprimer-certificate' ),
					fmt( done ),
					fmt( total )
				),
			done: ( n ) =>
				sprintf(
					/* translators: %s: number of certificates. */
					_n(
						'%s certificate revoked.',
						'%s certificates revoked.',
						n,
						'pressprimer-certificate'
					),
					fmt( n )
				),
			mixed: ( c ) =>
				sprintf(
					/* translators: 1: revoked, 2: skipped, 3: failed. */
					__(
						'%1$s revoked, %2$s skipped, %3$s failed.',
						'pressprimer-certificate'
					),
					fmt( c.done ),
					fmt( c.skipped ),
					fmt( c.failed )
				),
		},
		reinstate: {
			title: __( 'Reinstate certificates', 'pressprimer-certificate' ),
			message: ( n ) =>
				sprintf(
					/* translators: %s: number of certificates. */
					_n(
						'Reinstate %s certificate?',
						'Reinstate %s certificates?',
						n,
						'pressprimer-certificate'
					),
					fmt( n )
				),
			confirm: __( 'Reinstate', 'pressprimer-certificate' ),
			progress: ( done, total ) =>
				sprintf(
					/* translators: 1: certificates processed, 2: certificates in the job. */
					__(
						'Reinstating %1$s of %2$s…',
						'pressprimer-certificate'
					),
					fmt( done ),
					fmt( total )
				),
			done: ( n ) =>
				sprintf(
					/* translators: %s: number of certificates. */
					_n(
						'%s certificate reinstated.',
						'%s certificates reinstated.',
						n,
						'pressprimer-certificate'
					),
					fmt( n )
				),
			mixed: ( c ) =>
				sprintf(
					/* translators: 1: reinstated, 2: skipped, 3: failed. */
					__(
						'%1$s reinstated, %2$s skipped, %3$s failed.',
						'pressprimer-certificate'
					),
					fmt( c.done ),
					fmt( c.skipped ),
					fmt( c.failed )
				),
		},
		resend: {
			title: __( 'Resend emails', 'pressprimer-certificate' ),
			message: ( n ) =>
				sprintf(
					/* translators: %s: number of certificates. */
					_n(
						'Send the award email again for %s certificate? Each email includes the certificate PDF.',
						'Send the award email again for %s certificates? Each email includes the certificate PDF.',
						n,
						'pressprimer-certificate'
					),
					fmt( n )
				),
			confirm: __( 'Send emails', 'pressprimer-certificate' ),
			progress: ( done, total ) =>
				sprintf(
					/* translators: 1: emails processed, 2: emails in the job. */
					__( 'Sending %1$s of %2$s…', 'pressprimer-certificate' ),
					fmt( done ),
					fmt( total )
				),
			done: ( n ) =>
				sprintf(
					/* translators: %s: number of emails. */
					_n(
						'%s email sent.',
						'%s emails sent.',
						n,
						'pressprimer-certificate'
					),
					fmt( n )
				),
			mixed: ( c ) =>
				sprintf(
					/* translators: 1: sent, 2: skipped, 3: failed. */
					__(
						'%1$s sent, %2$s skipped, %3$s failed.',
						'pressprimer-certificate'
					),
					fmt( c.done ),
					fmt( c.skipped ),
					fmt( c.failed )
				),
		},
		delete: {
			title: __( 'Delete certificates', 'pressprimer-certificate' ),
			message: ( n ) =>
				sprintf(
					/* translators: %s: number of certificates. */
					_n(
						"Permanently delete %s certificate? Its verification link will stop working. This can't be undone.",
						"Permanently delete %s certificates? Their verification links will stop working. This can't be undone.",
						n,
						'pressprimer-certificate'
					),
					fmt( n )
				),
			confirm: __( 'Delete permanently', 'pressprimer-certificate' ),
			progress: ( done, total ) =>
				sprintf(
					/* translators: 1: certificates processed, 2: certificates in the job. */
					__( 'Deleting %1$s of %2$s…', 'pressprimer-certificate' ),
					fmt( done ),
					fmt( total )
				),
			done: ( n ) =>
				sprintf(
					/* translators: %s: number of certificates. */
					_n(
						'%s certificate deleted.',
						'%s certificates deleted.',
						n,
						'pressprimer-certificate'
					),
					fmt( n )
				),
			mixed: ( c ) =>
				sprintf(
					/* translators: 1: deleted, 2: skipped, 3: failed. */
					__(
						'%1$s deleted, %2$s skipped, %3$s failed.',
						'pressprimer-certificate'
					),
					fmt( c.done ),
					fmt( c.skipped ),
					fmt( c.failed )
				),
		},
	};

	const PPCert = {
		/**
		 * Bind the confirm-link interceptor and the bulk actions.
		 */
		init() {
			$( document ).on(
				'click',
				'.ppcert-confirm-link',
				this.onConfirmLink
			);

			$( document ).on(
				'click',
				'#doaction, #doaction2',
				this.onBulkAction
			);
			$( document ).on( 'click', '.ppcert-bulk-resume', this.onResume );
			$( document ).on( 'click', '.ppcert-bulk-discard', this.onDiscard );

			// "Select all matching" (FR-002): the banner follows the
			// checkboxes; core's select-all sets them without change events.
			this.context = this.readContext();
			this.allMatching = false;

			if ( this.context ) {
				$( document ).on(
					'click change',
					'.wp-list-table .check-column input[type="checkbox"]',
					function () {
						setTimeout( PPCert.updateBanner, 0 );
					}
				);
				$( document ).on(
					'click',
					'.ppcert-bulk-select-all',
					this.onSelectAll
				);
				$( document ).on(
					'click',
					'.ppcert-bulk-clear',
					this.onClearSelection
				);
			}
		},

		/**
		 * The list's bulk context (totals, filters, criteria), or null.
		 *
		 * @return {Object|null} Context.
		 */
		readContext() {
			const raw = $( '#ppcert-bulk-context' ).attr(
				'data-ppcert-context'
			);

			if ( ! raw ) {
				return null;
			}

			try {
				return JSON.parse( raw );
			} catch ( error ) {
				return null;
			}
		},

		/**
		 * Show, update, or remove the select-all banner.
		 */
		updateBanner() {
			const ctx = PPCert.context;
			const $rows = $( 'input[name="certificate[]"]' );
			const allChecked =
				$rows.length > 0 &&
				$rows.filter( ':checked' ).length === $rows.length;

			if ( ! allChecked ) {
				PPCert.allMatching = false;
			}

			$( '.ppcert-bulk-banner' ).remove();

			if (
				! allChecked ||
				ctx.total <= $rows.length ||
				ctx.bulkTotal < 1
			) {
				return;
			}

			let html;

			if ( PPCert.allMatching ) {
				html =
					PPCert.escapeHtml(
						sprintf(
							/* translators: %s: number of certificates. */
							_n(
								'All %s certificate that matches these filters is selected.',
								'All %s certificates that match these filters are selected.',
								ctx.bulkTotal,
								'pressprimer-certificate'
							),
							fmt( ctx.bulkTotal )
						)
					) +
					' <button type="button" class="button-link ppcert-bulk-clear">' +
					PPCert.escapeHtml(
						__( 'Clear selection.', 'pressprimer-certificate' )
					) +
					'</button>';
			} else {
				const link =
					ctx.bulkTotal === ctx.total
						? sprintf(
								/* translators: %s: number of certificates. */
								_n(
									'Select all %s certificate that matches these filters.',
									'Select all %s certificates that match these filters.',
									ctx.bulkTotal,
									'pressprimer-certificate'
								),
								fmt( ctx.bulkTotal )
						  )
						: sprintf(
								/* translators: %s: number of certificates. */
								_n(
									'Select all %s certificate you can manage that matches these filters.',
									'Select all %s certificates you can manage that match these filters.',
									ctx.bulkTotal,
									'pressprimer-certificate'
								),
								fmt( ctx.bulkTotal )
						  );

				html =
					PPCert.escapeHtml(
						sprintf(
							/* translators: %s: number of certificates. */
							_n(
								'All %s certificate on this page is selected.',
								'All %s certificates on this page are selected.',
								$rows.length,
								'pressprimer-certificate'
							),
							fmt( $rows.length )
						)
					) +
					' <button type="button" class="button-link ppcert-bulk-select-all">' +
					PPCert.escapeHtml( link ) +
					'</button>';
			}

			$(
				'<div class="notice notice-info inline ppcert-bulk-banner" role="status"><p>' +
					html +
					'</p></div>'
			)
				.attr( 'title', PPCert.criteriaText() )
				.insertBefore( 'table.wp-list-table' );
		},

		/**
		 * The active criteria in words, one per line.
		 *
		 * @return {string} Criteria.
		 */
		criteriaText() {
			const ctx = PPCert.context || {};
			const lines = ( ctx.criteria || [] ).slice();

			if ( ctx.note ) {
				lines.push( ctx.note );
			}

			return lines.join( '\n' );
		},

		/**
		 * Switch the selection to every matching certificate.
		 */
		onSelectAll() {
			PPCert.allMatching = true;
			PPCert.updateBanner();
		},

		/**
		 * Clear the selection entirely.
		 */
		onClearSelection() {
			PPCert.allMatching = false;
			$( '.wp-list-table .check-column input[type="checkbox"]' ).prop(
				'checked',
				false
			);
			$( '.ppcert-bulk-banner' ).remove();
		},

		/**
		 * Bulk actions Apply: confirm, then create and run the job.
		 *
		 * @param {Event} e Click event.
		 */
		onBulkAction( e ) {
			const $form = $( this ).closest( 'form' );
			const select =
				'doaction' === this.id
					? 'select[name="action"]'
					: 'select[name="action2"]';
			const value = String( $form.find( select ).val() || '' );

			if ( 0 !== value.indexOf( 'ppcert-bulk-' ) ) {
				return;
			}

			e.preventDefault();

			const action = value.substring( 'ppcert-bulk-'.length );
			const copy = BULK[ action ];

			if ( ! copy ) {
				return;
			}

			const ids = $form
				.find( 'input[name="certificate[]"]:checked' )
				.map( function () {
					return parseInt( this.value, 10 );
				} )
				.get();
			const matching = PPCert.allMatching && PPCert.context;

			if ( ! ids.length && ! matching ) {
				PPCert.confirm( {
					title: copy.title,
					message: __(
						'Select at least one certificate.',
						'pressprimer-certificate'
					),
					confirmText: __( 'OK', 'pressprimer-certificate' ),
					hideCancel: true,
				} );
				return;
			}

			const notes = ( window.ppcert_bulk_data || {} ).notes || {};
			const count = matching ? PPCert.context.bulkTotal : ids.length;
			let message =
				copy.message( count ) +
				( notes[ action ] ? ' ' + notes[ action ] : '' );

			if ( matching ) {
				message +=
					'\n\n' +
					__( 'Matching:', 'pressprimer-certificate' ) +
					'\n' +
					PPCert.criteriaText();
			}

			// Deleting everything that matches is typed (FR-003).
			const typed = matching && 'delete' === action;

			PPCert.confirm( {
				title: copy.title,
				message,
				confirmText: copy.confirm,
				cancelText: __( 'Cancel', 'pressprimer-certificate' ),
				inputLabel: copy.input || '',
				typedConfirm: typed ? 'DELETE' : '',
				typedLabel: __(
					'Type DELETE to confirm.',
					'pressprimer-certificate'
				),
				onConfirm( reason, confirmText ) {
					const progress = PPCert.progress( action, count );
					const data = matching
						? {
								action,
								filters: PPCert.context.filters,
								reason,
								confirm: confirmText,
						  }
						: { action, ids, reason };

					wp.apiFetch( {
						path: '/ppcert/v1/certificates/bulk',
						method: 'POST',
						data,
					} )
						.then( ( job ) => PPCert.runJob( job, progress ) )
						.catch( ( error ) => progress.fail( error ) );
				},
			} );
		},

		/**
		 * Resume a job a closed page left behind.
		 */
		onResume() {
			const jobId = String( $( this ).data( 'ppcert-job' ) || '' );

			wp.apiFetch( { path: '/ppcert/v1/certificates/bulk/' + jobId } )
				.then( ( job ) => {
					const progress = PPCert.progress( job.action, job.total );
					PPCert.runJob( job, progress );
				} )
				.catch( ( error ) =>
					PPCert.progress( 'revoke', 0 ).fail( error )
				);
		},

		/**
		 * Discard a job a closed page left behind.
		 */
		onDiscard() {
			const $notice = $( this ).closest( '.notice' );
			const jobId = String( $( this ).data( 'ppcert-job' ) || '' );

			PPCert.confirm( {
				title: __( 'Discard bulk action', 'pressprimer-certificate' ),
				message: __(
					'Discard this bulk action? Certificates already processed stay processed; the rest are left alone.',
					'pressprimer-certificate'
				),
				confirmText: __( 'Discard', 'pressprimer-certificate' ),
				cancelText: __( 'Cancel', 'pressprimer-certificate' ),
				onConfirm() {
					wp.apiFetch( {
						path: '/ppcert/v1/certificates/bulk/' + jobId,
						method: 'DELETE',
					} )
						.then( () => $notice.remove() )
						.catch( ( error ) =>
							PPCert.progress( 'revoke', 0 ).fail( error )
						);
				},
			} );
		},

		/**
		 * Drive a job to the end, one run request at a time.
		 *
		 * @param {Object} job      Job (REST view).
		 * @param {Object} progress Progress modal controller.
		 */
		runJob( job, progress ) {
			progress.update( job );

			if ( 'done' === job.status || 'discarded' === job.status ) {
				progress.finish( job );
				return;
			}

			if ( progress.isCancelled() ) {
				wp.apiFetch( {
					path: '/ppcert/v1/certificates/bulk/' + job.id,
					method: 'DELETE',
				} )
					.then( ( ended ) => progress.finish( ended ) )
					.catch( ( error ) => progress.fail( error ) );
				return;
			}

			wp.apiFetch( {
				path: '/ppcert/v1/certificates/bulk/' + job.id + '/run',
				method: 'POST',
			} )
				.then( ( next ) => PPCert.runJob( next, progress ) )
				.catch( ( error ) => progress.fail( error ) );
		},

		/**
		 * Open the progress modal (the house modal's progress state).
		 *
		 * @param {string} action Bulk action.
		 * @param {number} total  Certificates in the job.
		 * @return {Object} Controller: update, finish, fail, isCancelled.
		 */
		progress( action, total ) {
			const self = this;
			const copy = BULK[ action ] || BULK.revoke;
			let cancelled = false;

			$( '.ppcert-modal-overlay' ).remove();

			const $modal = $(
				'<div class="ppcert-modal-overlay" role="dialog" aria-modal="true">' +
					'<div class="ppcert-modal">' +
					'<div class="ppcert-modal-header">' +
					'<h3 class="ppcert-modal-title">' +
					self.escapeHtml( copy.title ) +
					'</h3>' +
					'</div>' +
					'<div class="ppcert-modal-body">' +
					'<div class="ppcert-modal-message ppcert-modal-progress-label" aria-live="polite"></div>' +
					'<div class="ppcert-modal-progress" role="progressbar" aria-valuemin="0">' +
					'<div class="ppcert-modal-progress-bar"></div>' +
					'</div>' +
					'<p class="ppcert-modal-hint">' +
					self.escapeHtml(
						__(
							'Keep this page open until it finishes.',
							'pressprimer-certificate'
						)
					) +
					'</p>' +
					'</div>' +
					'<div class="ppcert-modal-footer">' +
					'<button type="button" class="button ppcert-modal-cancel">' +
					self.escapeHtml(
						__( 'Cancel', 'pressprimer-certificate' )
					) +
					'</button>' +
					'</div>' +
					'</div>' +
					'</div>'
			);

			$( 'body' ).append( $modal );
			setTimeout( function () {
				$modal.addClass( 'ppcert-modal-overlay--visible' );
			}, 10 );

			const $label = $modal.find( '.ppcert-modal-progress-label' );
			const $bar = $modal.find( '.ppcert-modal-progress' );
			const $cancel = $modal.find( '.ppcert-modal-cancel' );

			$cancel.trigger( 'focus' ).on( 'click', function () {
				cancelled = true;
				$cancel
					.prop( 'disabled', true )
					.text(
						__(
							'Stopping after this step…',
							'pressprimer-certificate'
						)
					);
			} );

			const set = ( done, all ) => {
				const percent =
					all > 0 ? Math.round( ( done / all ) * 100 ) : 0;

				$label.text( copy.progress( done, all ) );
				$bar.attr( {
					'aria-valuemax': all,
					'aria-valuenow': done,
				} );
				$bar.find( '.ppcert-modal-progress-bar' ).css(
					'width',
					percent + '%'
				);
			};

			set( 0, total );

			/**
			 * Replace the body with a final message and an OK that reloads
			 * the list with the current filters.
			 *
			 * @param {string} message Plain-text message.
			 * @param {string} extra   Pre-escaped extra markup.
			 * @param {string} icon    success | warning.
			 */
			const end = ( message, extra, icon ) => {
				$modal
					.find( '.ppcert-modal-body' )
					.html(
						'<div class="ppcert-modal-icon ppcert-modal-icon--' +
							icon +
							'"><span class="dashicons dashicons-' +
							( 'success' === icon ? 'yes-alt' : 'warning' ) +
							'"></span></div>' +
							'<div class="ppcert-modal-message" role="status">' +
							self.escapeHtml( message ) +
							'</div>' +
							extra
					);
				$modal
					.find( '.ppcert-modal-footer' )
					.html(
						'<button type="button" class="button button-primary ppcert-modal-confirm">' +
							self.escapeHtml(
								__( 'OK', 'pressprimer-certificate' )
							) +
							'</button>'
					);
				$modal
					.find( '.ppcert-modal-confirm' )
					.trigger( 'focus' )
					.on( 'click', function () {
						window.location.reload();
					} );
			};

			return {
				isCancelled: () => cancelled,

				update( job ) {
					set( job.processed, job.total );
				},

				finish( job ) {
					const counts = job.counts || {};
					const clean = ! counts.skipped && ! counts.failed;
					let message = clean
						? copy.done( counts.done )
						: copy.mixed( counts );

					if ( 'discarded' === job.status ) {
						message =
							sprintf(
								/* translators: 1: certificates processed, 2: certificates in the job. */
								__(
									'Stopped after %1$s of %2$s certificates.',
									'pressprimer-certificate'
								),
								fmt( job.processed ),
								fmt( job.total )
							) +
							' ' +
							message;
					}

					let extra = '';

					if ( job.problems && job.problems.length ) {
						extra =
							'<details class="ppcert-modal-details"><summary>' +
							self.escapeHtml(
								__(
									'Show skipped and failed',
									'pressprimer-certificate'
								)
							) +
							'</summary><ul>' +
							job.problems
								.map(
									( problem ) =>
										'<li><strong>' +
										self.escapeHtml(
											problem.credential_id ||
												'#' + problem.id
										) +
										'</strong> ' +
										self.escapeHtml( problem.reason ) +
										'</li>'
								)
								.join( '' ) +
							'</ul></details>';
					}

					end(
						message,
						extra,
						clean && 'done' === job.status ? 'success' : 'warning'
					);
				},

				fail( error ) {
					end(
						( error && error.message ) ||
							__(
								'The bulk action could not continue.',
								'pressprimer-certificate'
							),
						'',
						'warning'
					);
				},
			};
		},

		/**
		 * Intercept a flagged link and confirm through the modal.
		 *
		 * @param {Event} e Click event.
		 */
		onConfirmLink( e ) {
			e.preventDefault();

			const $link = $( this );
			const href = $link.attr( 'href' );
			const inputLabel = $link.data( 'ppcert-input-label' ) || '';
			const inputName = $link.data( 'ppcert-input-name' ) || '';

			PPCert.confirm( {
				title: $link.data( 'ppcert-title' ) || '',
				message: $link.data( 'ppcert-message' ) || '',
				confirmText: $link.data( 'ppcert-confirm' ) || 'OK',
				cancelText: $link.data( 'ppcert-cancel' ) || 'Cancel',
				inputLabel,
				onConfirm( inputValue ) {
					let target = href;

					if ( inputName && inputValue ) {
						target +=
							( -1 === target.indexOf( '?' ) ? '?' : '&' ) +
							encodeURIComponent( inputName ) +
							'=' +
							encodeURIComponent( inputValue );
					}

					window.location.href = target;
				},
			} );
		},

		/**
		 * Show the confirmation modal.
		 *
		 * @param {Object} options Title, message, labels, callbacks;
		 *                         hideCancel for a single OK button;
		 *                         typedConfirm (+ typedLabel) for a word
		 *                         the user must type to enable confirm.
		 * @return {Object} The modal element (jQuery wrapped).
		 */
		confirm( options ) {
			const self = this;

			// One modal at a time.
			$( '.ppcert-modal-overlay' ).remove();

			const modalHtml =
				'<div class="ppcert-modal-overlay" role="dialog" aria-modal="true">' +
				'<div class="ppcert-modal">' +
				'<div class="ppcert-modal-header">' +
				( options.title
					? '<h3 class="ppcert-modal-title">' +
					  self.escapeHtml( options.title ) +
					  '</h3>'
					: '' ) +
				'<button type="button" class="ppcert-modal-close" aria-label="Close">' +
				'<span class="dashicons dashicons-no-alt"></span>' +
				'</button>' +
				'</div>' +
				'<div class="ppcert-modal-body">' +
				'<div class="ppcert-modal-icon ppcert-modal-icon--warning">' +
				'<span class="dashicons dashicons-warning"></span>' +
				'</div>' +
				'<div class="ppcert-modal-message">' +
				self.escapeHtml( options.message ) +
				'</div>' +
				( options.inputLabel
					? '<label class="ppcert-modal-input-label" for="ppcert-modal-input">' +
					  self.escapeHtml( options.inputLabel ) +
					  '</label>' +
					  '<input type="text" id="ppcert-modal-input" class="ppcert-modal-input" maxlength="191" />'
					: '' ) +
				( options.typedConfirm
					? '<label class="ppcert-modal-input-label" for="ppcert-modal-typed">' +
					  self.escapeHtml( options.typedLabel ) +
					  '</label>' +
					  '<input type="text" id="ppcert-modal-typed" class="ppcert-modal-input ppcert-modal-typed" autocomplete="off" spellcheck="false" />'
					: '' ) +
				'</div>' +
				'<div class="ppcert-modal-footer">' +
				( options.hideCancel
					? ''
					: '<button type="button" class="button ppcert-modal-cancel">' +
					  self.escapeHtml( options.cancelText ) +
					  '</button>' ) +
				'<button type="button" class="button button-primary ppcert-modal-confirm">' +
				self.escapeHtml( options.confirmText ) +
				'</button>' +
				'</div>' +
				'</div>' +
				'</div>';

			const $modal = $( modalHtml );
			$( 'body' ).append( $modal );

			// Animate in.
			setTimeout( function () {
				$modal.addClass( 'ppcert-modal-overlay--visible' );
			}, 10 );

			const $confirm = $modal.find( '.ppcert-modal-confirm' );
			const $typed = $modal.find( '.ppcert-modal-typed' );

			// A typed confirmation keeps the button disabled until the
			// field matches exactly.
			if ( options.typedConfirm ) {
				$confirm.prop( 'disabled', true );
				$typed.on( 'input', function () {
					$confirm.prop(
						'disabled',
						String( $typed.val() || '' ).trim() !==
							options.typedConfirm
					);
				} );
			}

			// Focus the first input when present, else the confirm button.
			if ( options.inputLabel || options.typedConfirm ) {
				$modal.find( '.ppcert-modal-input' ).first().trigger( 'focus' );
			} else {
				$confirm.trigger( 'focus' );
			}

			const close = function () {
				$modal.removeClass( 'ppcert-modal-overlay--visible' );
				$( document ).off( 'keydown.ppcertModal' );
				setTimeout( function () {
					$modal.remove();
				}, 200 );
			};

			$modal.on( 'click', '.ppcert-modal-confirm', function () {
				if ( $( this ).prop( 'disabled' ) ) {
					return;
				}

				const value = $modal.find( '#ppcert-modal-input' ).val() || '';
				const typedValue = String( $typed.val() || '' ).trim();
				close();
				if ( typeof options.onConfirm === 'function' ) {
					options.onConfirm( value, typedValue );
				}
			} );

			$modal.on(
				'click',
				'.ppcert-modal-cancel, .ppcert-modal-close',
				close
			);

			$modal.on( 'click', function ( e ) {
				if ( $( e.target ).hasClass( 'ppcert-modal-overlay' ) ) {
					close();
				}
			} );

			// Enter inside the input confirms; Escape cancels anywhere.
			$modal.on( 'keydown', '.ppcert-modal-input', function ( e ) {
				if ( 'Enter' === e.key ) {
					e.preventDefault();
					$modal.find( '.ppcert-modal-confirm' ).trigger( 'click' );
				}
			} );

			$( document ).on( 'keydown.ppcertModal', function ( e ) {
				if ( 'Escape' === e.key ) {
					close();
				}
			} );

			return $modal;
		},

		/**
		 * Escape HTML entities.
		 *
		 * @param {string} str Raw string.
		 * @return {string} Escaped string.
		 */
		escapeHtml( str ) {
			if ( ! str ) {
				return '';
			}
			const div = document.createElement( 'div' );
			div.textContent = str;
			return div.innerHTML;
		},
	};

	$( function () {
		PPCert.init();
	} );
} )( window.jQuery, window.wp );
