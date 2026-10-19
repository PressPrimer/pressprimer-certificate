/**
 * Certificates screen harness (2.1, Feature 2.1-002).
 *
 * Stands in for WordPress around the real assets/js/ppcert-admin.js:
 * the list table markup the PHP list table renders (filtered to one
 * template, 20 rows a page), the resume notices and the bulk context
 * element render_page() prints, core common.js's list-table behavior
 * (check-all without change events, the two bulk menus kept in sync,
 * the bottom Apply forwarded to the top one), wp.i18n, and wp.apiFetch
 * backed by an in-memory bulk REST server with the routes' semantics.
 * State lives in localStorage so window.location.reload() (the summary's
 * OK) shows the result, as the real screen does. `?reset=1` starts over.
 *
 * window.__ppcertHarness exposes the state and knobs: chunk (rows per
 * run), delay (ms per run), failRunAfter (reject runs after this many
 * succeeded, simulating a closed page), and the last create payload.
 *
 * @param {Object} $ jQuery.
 */
( function ( $ ) {
	'use strict';

	const KEY = 'ppcertBulkHarness';
	const PER_PAGE = 20;
	const TEMPLATE = 1;

	/**
	 * A fresh site: 30 certificates from template 1 (the filter), 15
	 * from template 2.
	 *
	 * @return {Object} State.
	 */
	function freshState() {
		const certificates = [];

		for ( let id = 1001; id <= 1045; id++ ) {
			certificates.push( {
				id,
				credential: 'CRED-' + id,
				template: id <= 1030 ? TEMPLATE : 2,
				status: 'issued',
				reason: '',
			} );
		}

		return {
			certificates,
			jobs: {},
			lock: '',
			chunk: 7,
			delay: 120,
			failRunAfter: 0,
			runs: 0,
			lastCreate: null,
		};
	}

	// A reset starts over once; the summary's reload must not repeat it.
	if ( /[?&]reset=1/.test( window.location.search ) ) {
		window.localStorage.removeItem( KEY );
		window.history.replaceState( null, '', window.location.pathname );
	}

	let state = JSON.parse( window.localStorage.getItem( KEY ) || 'null' );

	if ( ! state ) {
		state = freshState();
	}

	const save = () =>
		window.localStorage.setItem( KEY, JSON.stringify( state ) );
	save();

	window.__ppcertHarness = {
		state: () => JSON.parse( JSON.stringify( state ) ),
		set( values ) {
			Object.assign( state, values );
			save();
		},
	};

	/* ------------------------------------------------------------------
	 * wp.i18n (identity translations) and wp.apiFetch.
	 * ------------------------------------------------------------------ */

	const sprintf = ( format, ...args ) => {
		let next = 0;

		return String( format ).replace( /%(\d+\$)?s/g, ( match, position ) =>
			String(
				position ? args[ parseInt( position, 10 ) - 1 ] : args[ next++ ]
			)
		);
	};

	const error = ( code, message, status ) => ( {
		code,
		message,
		data: { status },
	} );

	const view = ( job ) => {
		const copy = Object.assign( {}, job );
		delete copy.ids;
		return copy;
	};

	const matches = ( filters ) =>
		state.certificates
			.filter(
				( cert ) =>
					( ! filters.template_id ||
						cert.template === Number( filters.template_id ) ) &&
					( ! filters.status || cert.status === filters.status )
			)
			.map( ( cert ) => cert.id );

	/**
	 * Apply one action to one row, as the bulk service does.
	 *
	 * @param {Object} job Job.
	 * @param {number} id  Certificate id.
	 * @return {Array} [ result, reason ].
	 */
	function processRow( job, id ) {
		const cert = state.certificates.find( ( row ) => row.id === id );

		if ( ! cert ) {
			return [ 'skipped', 'It no longer exists.' ];
		}

		if ( 'revoke' === job.action ) {
			if ( 'revoked' === cert.status ) {
				return [ 'skipped', 'It is already revoked.' ];
			}
			cert.status = 'revoked';
			cert.reason = job.reason || '';
		} else if ( 'reinstate' === job.action ) {
			if ( 'revoked' !== cert.status ) {
				return [ 'skipped', 'It is not revoked.' ];
			}
			cert.status = 'issued';
			cert.reason = '';
		} else if ( 'delete' === job.action ) {
			state.certificates = state.certificates.filter(
				( row ) => row.id !== id
			);
		}

		return [ 'done', '' ];
	}

	const routes = {
		create( data ) {
			if ( data.ids && data.filters ) {
				throw error( 'ppcert_bulk_ambiguous', 'Send either.', 400 );
			}

			let ids = data.ids || [];

			if ( data.filters ) {
				if ( 'delete' === data.action && 'DELETE' !== data.confirm ) {
					throw error(
						'ppcert_bulk_confirm_required',
						'Type DELETE to confirm.',
						400
					);
				}
				ids = matches( data.filters );
			}

			if ( state.lock ) {
				throw error(
					'ppcert_bulk_locked',
					'Another bulk action is running. Try again when it finishes.',
					409
				);
			}

			const id =
				'aaaaaaaa-bbbb-4ccc-8ddd-' +
				String( Date.now() ).slice( -12 ).padStart( 12, '0' );
			const job = {
				id,
				action: data.action,
				reason: data.reason || '',
				criteria: data.filters
					? { type: 'filters', filters: data.filters }
					: { type: 'selected' },
				ids,
				total: ids.length,
				processed: 0,
				counts: { done: 0, skipped: 0, failed: 0 },
				problems: [],
				status: 'ready',
				message: '',
			};

			state.jobs[ id ] = job;
			state.lock = id;
			state.lastCreate = data;
			save();

			return view( job );
		},

		run( id ) {
			const job = state.jobs[ id ];

			if ( ! job ) {
				throw error( 'ppcert_bulk_not_found', 'Not found.', 404 );
			}

			if ( state.failRunAfter && state.runs >= state.failRunAfter ) {
				throw {
					code: 'fetch_error',
					message: 'You are probably offline.',
				};
			}

			state.runs++;
			job.status = 'running';

			job.ids
				.slice( job.processed, job.processed + state.chunk )
				.forEach( ( rowId ) => {
					const [ result, reason ] = processRow( job, rowId );
					job.counts[ result ]++;
					if ( 'done' !== result ) {
						job.problems.push( {
							id: rowId,
							credential_id: 'CRED-' + rowId,
							result,
							reason,
						} );
					}
					job.processed++;
				} );

			if ( job.processed >= job.total ) {
				job.status = 'done';
				state.lock = '';
			}

			save();
			return view( job );
		},

		get( id ) {
			if ( ! state.jobs[ id ] ) {
				throw error( 'ppcert_bulk_not_found', 'Not found.', 404 );
			}
			return view( state.jobs[ id ] );
		},

		cancel( id ) {
			const job = state.jobs[ id ];
			job.status = 'discarded';
			state.lock = '';
			save();
			return view( job );
		},
	};

	window.wp = {
		i18n: {
			__: ( text ) => text,
			_n: ( single, plural, n ) => ( 1 === n ? single : plural ),
			sprintf,
		},
		apiFetch( { path, method = 'GET', data } ) {
			return new Promise( ( resolve, reject ) => {
				setTimeout( () => {
					try {
						const match =
							/^\/ppcert\/v1\/certificates\/bulk(?:\/([^/]+))?(\/run)?$/.exec(
								path
							);

						if ( ! match ) {
							throw error( 'rest_no_route', 'No route.', 404 );
						}

						const [ , id, run ] = match;

						if ( ! id && 'POST' === method ) {
							resolve( routes.create( data ) );
						} else if ( run ) {
							resolve( routes.run( id ) );
						} else if ( 'DELETE' === method ) {
							resolve( routes.cancel( id ) );
						} else {
							resolve( routes.get( id ) );
						}
					} catch ( thrown ) {
						reject( thrown );
					}
				}, state.delay );
			} );
		},
	};

	window.ppcert_bulk_data = { notes: {} };

	/* ------------------------------------------------------------------
	 * The screen render_page() prints: notices, context, table.
	 * ------------------------------------------------------------------ */

	const escape = ( text ) => $( '<div>' ).text( text ).html();

	Object.values( state.jobs )
		.filter(
			( job ) =>
				[ 'ready', 'running', 'stopped' ].includes( job.status ) &&
				job.processed < job.total
		)
		.forEach( ( job ) => {
			$( '#ppcert-harness-notices' ).append(
				'<div class="notice notice-warning ppcert-bulk-resume-notice"><p>' +
					escape(
						sprintf(
							'A bulk %1$s stopped at %2$s of %3$s certificates.',
							job.action,
							job.processed,
							job.total
						)
					) +
					' <button type="button" class="button button-primary ppcert-bulk-resume" data-ppcert-job="' +
					job.id +
					'">Resume</button> <button type="button" class="button ppcert-bulk-discard" data-ppcert-job="' +
					job.id +
					'">Discard</button></p></div>'
			);
		} );

	const filtered = state.certificates
		.filter( ( cert ) => cert.template === TEMPLATE )
		.sort( ( a, b ) => b.id - a.id );

	$( '#ppcert-harness-context' ).replaceWith(
		$( '<div id="ppcert-bulk-context" hidden></div>' ).attr(
			'data-ppcert-context',
			JSON.stringify( {
				total: filtered.length,
				bulkTotal: filtered.length,
				filters: {
					template_id: TEMPLATE,
					status: '',
					source_type: '',
					search: '',
					issued_after: '',
					issued_before: '',
				},
				criteria: [ 'Template: Safety Basics' ],
				note: '',
			} )
		)
	);

	const options =
		'<option value="-1">Bulk actions</option>' +
		'<option value="ppcert-bulk-revoke">Revoke</option>' +
		'<option value="ppcert-bulk-reinstate">Reinstate</option>' +
		'<option value="ppcert-bulk-resend">Resend email</option>' +
		'<option value="ppcert-bulk-delete">Delete</option>';
	$( '#bulk-action-selector-top, #bulk-action-selector-bottom' ).html(
		options
	);

	filtered.slice( 0, PER_PAGE ).forEach( ( cert ) => {
		$( '#the-list' ).append(
			'<tr data-id="' +
				cert.id +
				'"><th scope="row" class="check-column"><input type="checkbox" name="certificate[]" value="' +
				cert.id +
				'" /></th><td class="column-credential_id">' +
				escape( cert.credential ) +
				'</td><td class="column-status">' +
				escape(
					'revoked' === cert.status
						? 'Revoked ' + cert.reason
						: 'Issued'
				) +
				'</td></tr>'
		);
	} );

	/* ------------------------------------------------------------------
	 * Core common.js list-table behavior.
	 * ------------------------------------------------------------------ */

	const $rows = () => $( 'input[name="certificate[]"]' );

	$( '#cb-select-all-1, #cb-select-all-2' ).on( 'click', function () {
		const checked = $( this ).prop( 'checked' );
		$rows().prop( 'checked', checked );
		$( '#cb-select-all-1, #cb-select-all-2' ).prop( 'checked', checked );
	} );

	$( document ).on( 'click', 'input[name="certificate[]"]', function () {
		const all = $rows().length === $rows().filter( ':checked' ).length;
		$( '#cb-select-all-1, #cb-select-all-2' ).prop( 'checked', all );
	} );

	$( '.bulkactions select' ).on( 'change', function () {
		$( '.bulkactions select' ).val( $( this ).val() );
	} );

	$( '#doaction2' ).on( 'click', function ( event ) {
		event.preventDefault();
		event.stopPropagation();
		$( '#doaction' ).trigger( 'click' );
	} );

	$( '#ppcert-harness-form' ).on( 'submit', ( event ) => {
		event.preventDefault();
		window.__ppcertHarness.submitted = true;
	} );
} )( window.jQuery );
