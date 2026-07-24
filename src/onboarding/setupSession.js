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
 * Forget the saved records (called when a tour starts fresh)
 */
export const clearSetupSession = () => {
	try {
		window.sessionStorage.removeItem( TEMPLATE_ID_KEY );
		window.sessionStorage.removeItem( TEMPLATE_STATUS_KEY );
		window.sessionStorage.removeItem( CREDENTIAL_KEY );
	} catch ( e ) {
		// Nothing to clear.
	}
};
