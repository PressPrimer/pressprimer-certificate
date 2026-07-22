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

let triggerTypesPromise = null;

/**
 * Registered trigger types (available adapters only).
 *
 * Cached for the session: the palette and the Award tab both consume
 * this, and the registered set only changes with plugin (de)activation.
 *
 * @return {Promise<Array>} [ { id, label, source_label, has_sources,
 *                          source_post_types, conditions_schema } ].
 */
export function getTriggerTypes() {
	if ( ! triggerTypesPromise ) {
		triggerTypesPromise = apiFetch( {
			path: '/ppcert/v1/trigger-types',
		} ).catch( () => {
			triggerTypesPromise = null;
			return [];
		} );
	}

	return triggerTypesPromise;
}

/**
 * Search a trigger type's sources.
 *
 * @param {string} type   Trigger type id.
 * @param {string} search Search term.
 * @return {Promise<Array>} [ { id, title } ].
 */
export function getTriggerSources( type, search = '' ) {
	return apiFetch( {
		path: `/ppcert/v1/trigger-types?type=${ encodeURIComponent(
			type
		) }&search=${ encodeURIComponent( search ) }`,
	} );
}

/**
 * Load a template's triggers (enriched rows).
 *
 * @param {number} id Template id.
 * @return {Promise<Array>} Trigger rows.
 */
export function getTriggers( id ) {
	return apiFetch( { path: `/ppcert/v1/templates/${ id }/triggers` } );
}

/**
 * Replace a template's trigger set.
 *
 * @param {number} id       Template id.
 * @param {Array}  triggers Trigger payloads.
 * @return {Promise<Array>} The saved, enriched rows.
 */
export function saveTriggers( id, triggers ) {
	return apiFetch( {
		path: `/ppcert/v1/templates/${ id }/triggers`,
		method: 'PUT',
		data: { triggers },
	} );
}
