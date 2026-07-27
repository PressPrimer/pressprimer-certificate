/**
 * Verification page front end (Feature 006 FR-001/FR-003).
 *
 * Vanilla JS by design - no React on the front end in 1.0. Normalization
 * and the checksum mirror PressPrimer_Certificate_Credential_ID_Service
 * exactly; a checksum failure shows the typo help WITHOUT calling the
 * API (the server deliberately yields no malformed-vs-missing oracle).
 *
 * @package
 */
( function () {
	'use strict';

	const ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';
	const WEIGHTS = [ 1, 3, 5, 7, 9, 11, 13, 15, 17, 19, 21 ];

	/**
	 * Mirror of Credential_ID_Service::normalize().
	 *
	 * @param {string} input Raw user input.
	 * @return {string} Normalized candidate.
	 */
	function normalize( input ) {
		return String( input )
			.toUpperCase()
			.replace( /[\s\-_.]/g, '' )
			.replace( /I/g, '1' )
			.replace( /L/g, '1' )
			.replace( /O/g, '0' );
	}

	/**
	 * Mirror of Credential_ID_Service::is_well_formed().
	 *
	 * @param {string} candidate Normalized candidate.
	 * @return {boolean} Whether length, alphabet, and checksum pass.
	 */
	function isWellFormed( candidate ) {
		if ( candidate.length !== 12 ) {
			return false;
		}

		let sum = 0;

		for ( let i = 0; i < 12; i++ ) {
			const value = ALPHABET.indexOf( candidate[ i ] );

			if ( value === -1 ) {
				return false;
			}

			if ( i < 11 ) {
				sum += value * WEIGHTS[ i ];
			}
		}

		return ALPHABET[ sum % 32 ] === candidate[ 11 ];
	}

	/**
	 * Render a result state into the aria-live region.
	 *
	 * @param {Element} region Result region.
	 * @param {string}  state  State slug (valid|expired|revoked|not_found|typo|error).
	 * @param {Object}  data   Result data (locked-shape fields).
	 */
	function render( region, state, data ) {
		const i18n = window.ppcert_verify_data.i18n;

		region.className =
			'ppcert-verify__result ppcert-verify__result--' + state;

		while ( region.firstChild ) {
			region.removeChild( region.firstChild );
		}

		const heading = document.createElement( 'p' );
		heading.className = 'ppcert-verify__status';
		heading.textContent = i18n[ state ] || i18n.error;
		region.appendChild( heading );

		if ( ! data || ( state !== 'valid' && state !== 'expired' ) ) {
			return;
		}

		const rows = [
			[ i18n.recipient, data.recipient_name ],
			[ i18n.subject, data.subject ],
			[ i18n.issuer, data.issuer_name ],
			[ i18n.issued, formatDate( data.issued_at ) ],
		];

		if ( data.expires_at ) {
			// Past dates read "Expired", future dates "Expires".
			const isPast = new Date( data.expires_at ).getTime() < Date.now();
			rows.push( [
				isPast ? i18n.expires_past : i18n.expires,
				formatDate( data.expires_at ),
			] );
		}

		const list = document.createElement( 'dl' );
		list.className = 'ppcert-verify__details';

		rows.forEach( function ( row ) {
			if ( ! row[ 1 ] ) {
				return;
			}

			const term = document.createElement( 'dt' );
			term.textContent = row[ 0 ];
			const definition = document.createElement( 'dd' );
			definition.textContent = row[ 1 ];
			list.appendChild( term );
			list.appendChild( definition );
		} );

		region.appendChild( list );
	}

	/**
	 * Format an ISO 8601 UTC datetime in the viewer's locale.
	 *
	 * @param {string} iso ISO 8601 UTC string.
	 * @return {string} Localized date.
	 */
	function formatDate( iso ) {
		if ( ! iso ) {
			return '';
		}

		const date = new Date( iso );

		return isNaN( date.getTime() ) ? iso : date.toLocaleDateString();
	}

	/**
	 * Execute a lookup against the REST endpoint.
	 *
	 * @param {Element} region     Result region.
	 * @param {string}  credential Raw credential input.
	 */
	function verify( region, credential ) {
		const normalized = normalize( credential );

		if ( ! isWellFormed( normalized ) ) {
			render( region, 'typo', null );
			return;
		}

		render( region, 'checking', null );

		window
			.fetch(
				window.ppcert_verify_data.rest_url +
					encodeURIComponent( normalized ),
				{ headers: { Accept: 'application/json' } }
			)
			.then( function ( response ) {
				if ( response.status === 429 ) {
					render( region, 'rate_limited', null );
					return null;
				}
				return response.json();
			} )
			.then( function ( data ) {
				if ( ! data ) {
					return;
				}
				render( region, data.status, data );
			} )
			.catch( function () {
				render( region, 'error', null );
			} );
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		const form = document.querySelector( '.ppcert-verify__form' );
		const region = document.querySelector( '.ppcert-verify__result' );

		if ( ! form || ! region || ! window.ppcert_verify_data ) {
			return;
		}

		form.addEventListener( 'submit', function ( event ) {
			event.preventDefault();
			const input = form.querySelector( '.ppcert-verify__input' );
			verify( region, input ? input.value : '' );
		} );
	} );
} )();
