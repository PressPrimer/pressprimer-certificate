/**
 * Tour session state
 *
 * The template published and the certificate issued during the guided
 * build, remembered across the page loads the tour choreographs.
 * Cleared whenever a tour (re)starts so a relaunched tour never
 * targets a previous run's records.
 */

const TEMPLATE_ID_KEY = 'ppcertSetupTemplateId';
const TEMPLATE_STATUS_KEY = 'ppcertSetupTemplateStatus';
const CREDENTIAL_KEY = 'ppcertSetupCredential';
const ADVANCE_PENDING_KEY = 'ppcertSetupAdvancePending';

// Consumed at most once per page load; the memo keeps the answer
// stable for later callers after the marker is removed.
let advancePendingMemo = null;

/**
 * Read the saved template from session storage
 *
 * @return {{id: number|null, status: string|null}} Saved template info.
 */
export const readSavedTemplate = () => {
	try {
		const id = parseInt(
			window.sessionStorage.getItem( TEMPLATE_ID_KEY ),
			10
		);
		const status = window.sessionStorage.getItem( TEMPLATE_STATUS_KEY );

		return {
			id: id > 0 ? id : null,
			status: status || null,
		};
	} catch ( e ) {
		return { id: null, status: null };
	}
};

/**
 * Remember the template created/saved during the tour
 *
 * @param {number} id     Template ID.
 * @param {string} status Saved status ('published' or 'draft').
 */
export const writeSavedTemplate = ( id, status ) => {
	try {
		window.sessionStorage.setItem( TEMPLATE_ID_KEY, String( id ) );
		if ( status ) {
			window.sessionStorage.setItem( TEMPLATE_STATUS_KEY, status );
		}
	} catch ( e ) {
		// Session storage unavailable — in-memory state still works.
	}
};

/**
 * Read the credential issued during the tour
 *
 * @return {string|null} Credential ID or null.
 */
export const readIssuedCredential = () => {
	try {
		return window.sessionStorage.getItem( CREDENTIAL_KEY ) || null;
	} catch ( e ) {
		return null;
	}
};

/**
 * Remember the credential issued during the tour
 *
 * @param {string} credentialId Credential ID.
 */
export const writeIssuedCredential = ( credentialId ) => {
	try {
		window.sessionStorage.setItem( CREDENTIAL_KEY, credentialId );
	} catch ( e ) {
		// Session storage unavailable — the modal falls back gracefully.
	}
};

/**
 * Mark the issue-stop advance as pending
 *
 * Written when the issue bridge advances the tour. The Certificates
 * screen reloads itself on a timer, so the keepalive persistence can
 * lose the race with the reload's server render; the marker lets the
 * next page load recognize the stale step and reconcile forward.
 */
export const writeAdvancePending = () => {
	try {
		window.sessionStorage.setItem( ADVANCE_PENDING_KEY, '1' );
	} catch ( e ) {
		// Session storage unavailable — reconciliation just won't run.
	}
};

/**
 * Consume the pending-advance marker (one-shot)
 *
 * The marker is cleared on first read so it only ever influences the
 * single page load that follows the issue — an intentional Back to
 * the issue stop is never overridden on later loads.
 *
 * @return {boolean} Whether an advance was pending.
 */
export const consumeAdvancePending = () => {
	if ( null === advancePendingMemo ) {
		try {
			advancePendingMemo =
				'1' === window.sessionStorage.getItem( ADVANCE_PENDING_KEY );
			window.sessionStorage.removeItem( ADVANCE_PENDING_KEY );
		} catch ( e ) {
			advancePendingMemo = false;
		}
	}

	return advancePendingMemo;
};

/**
 * Forget the saved records (called when a tour starts fresh)
 */
export const clearSetupSession = () => {
	try {
		window.sessionStorage.removeItem( TEMPLATE_ID_KEY );
		window.sessionStorage.removeItem( TEMPLATE_STATUS_KEY );
		window.sessionStorage.removeItem( CREDENTIAL_KEY );
		window.sessionStorage.removeItem( ADVANCE_PENDING_KEY );
	} catch ( e ) {
		// Nothing to clear.
	}
	advancePendingMemo = false;
};
