/**
 * Merge field registry access (Feature 002)
 *
 * Loads GET /ppcert/v1/merge-fields once (groups + fields + samples)
 * and answers meta-key picker searches. The designer never hard-codes
 * samples (FR-004) - everything display-worthy comes from here. The
 * Playwright harness seeds both caches instead of hitting REST.
 */

import apiFetch from '@wordpress/api-fetch';

let registry = null;
let registryPromise = null;

const metaKeyFixtures = { user: null, post: null };

/**
 * Load the registry (cached).
 *
 * @return {Promise<Object>} { groups, fields }.
 */
export function loadMergeFields() {
	if ( registry ) {
		return Promise.resolve( registry );
	}

	if ( ! registryPromise ) {
		registryPromise = apiFetch( { path: '/ppcert/v1/merge-fields' } )
			.then( ( data ) => {
				registry = data;
				return registry;
			} )
			.catch( () => {
				registryPromise = null;
				return { groups: {}, fields: [] };
			} );
	}

	return registryPromise;
}

/**
 * The loaded registry, or null before loadMergeFields resolves.
 *
 * @return {Object|null} { groups, fields } or null.
 */
export function getMergeFieldsSync() {
	return registry;
}

/**
 * Seed the registry (harness / tests).
 *
 * @param {Object} data { groups, fields }.
 */
export function seedMergeFields( data ) {
	registry = data;
	registryPromise = Promise.resolve( data );
}

/**
 * Sample map: token key => sample string.
 *
 * @return {Object} Map (empty before load).
 */
export function getSampleMap() {
	const map = {};

	( registry ? registry.fields : [] ).forEach( ( field ) => {
		map[ field.key ] = field.sample;
	} );

	return map;
}

/**
 * Search meta keys for the picker.
 *
 * @param {string} scope  'user' or 'post'.
 * @param {string} search Search term.
 * @param {number} postId Source post id (post scope only).
 * @return {Promise<Array>} [ { key, sample } ].
 */
export function searchMetaKeys( scope, search = '', postId = 0 ) {
	const fixtures = metaKeyFixtures[ scope ];

	if ( fixtures ) {
		const term = search.toLowerCase();
		return Promise.resolve(
			fixtures.filter( ( item ) => item.key.includes( term ) )
		);
	}

	const path =
		'post' === scope
			? `/ppcert/v1/merge-fields/post-meta-keys?post_id=${ postId }&search=${ encodeURIComponent(
					search
			  ) }`
			: `/ppcert/v1/merge-fields/user-meta-keys?search=${ encodeURIComponent(
					search
			  ) }`;

	return apiFetch( { path } ).catch( () => [] );
}

/**
 * Seed meta-key fixtures (harness / tests).
 *
 * @param {string} scope 'user' or 'post'.
 * @param {Array}  items [ { key, sample } ].
 */
export function seedMetaKeys( scope, items ) {
	metaKeyFixtures[ scope ] = items;
}
