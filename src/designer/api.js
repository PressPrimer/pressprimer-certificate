/**
 * Designer REST calls (Feature 001 TR-003)
 *
 * Thin wrappers over apiFetch so components share one contract with
 * the templates controller.
 */

import apiFetch from '@wordpress/api-fetch';

/**
 * Save a template (layout and/or title/status).
 *
 * @param {number} id      Template id.
 * @param {Object} payload { layout?, title?, status?, expected_updated_at?, force? }.
 * @return {Promise<Object>} The full template with the REBUILT layout.
 */
export function saveTemplate( id, payload ) {
	return apiFetch( {
		path: `/ppcert/v1/templates/${ id }`,
		method: 'PUT',
		data: payload,
	} );
}

/**
 * Render a sample-data preview PDF of a layout.
 *
 * @param {number} id     Template id.
 * @param {Object} layout Current (possibly unsaved) layout.
 * @return {Promise<Object>} { url }.
 */
export function previewTemplate( id, layout ) {
	return apiFetch( {
		path: `/ppcert/v1/templates/${ id }/preview`,
		method: 'POST',
		data: { layout },
	} );
}

/**
 * Move a template to the trash.
 *
 * @param {number} id Template id.
 * @return {Promise<Object>} { trashed }.
 */
export function trashTemplate( id ) {
	return apiFetch( {
		path: `/ppcert/v1/templates/${ id }`,
		method: 'DELETE',
	} );
}
