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
let registryScope = null;
let registryPromise = null;
let seeded = false;

const metaKeyFixtures = { user: null, post: null };

/**
 * Scope cache key for a trigger-type list.
 *
 * @param {Array|null} triggerTypes Type ids, or null for unscoped.
 * @return {string|null} Cache key.
 */
function scopeKey( triggerTypes ) {
	return Array.isArray( triggerTypes )
		? [ ...triggerTypes ].sort().join( ',' )
		: null;
}

/**
 * Load the registry (cached per trigger scope).
 *
 * Passing an array scopes adapter-contributed source fields to those
 * trigger types (an empty array = core fields only); null loads the
 * unscoped registry. Changing scope refetches.
 *
 * @param {Array|null} triggerTypes The template's trigger type ids.
 * @return {Promise<Object>} { groups, fields }.
 */
export function loadMergeFields( triggerTypes = null ) {
	if ( seeded ) {
		return Promise.resolve( registry );
	}

	const key = scopeKey( triggerTypes );

	// One promise per scope, shared by concurrent callers. The old
	// guard returned Promise.resolve( registry ) - the PREVIOUS
	// scope's data - to any same-scope caller arriving while the
	// fetch was in flight, so the canvas could snapshot stale samples
	// forever while the palette showed fresh ones (Ryan, 2026-07-30).
	if ( registryPromise && registryScope === key ) {
		return registryPromise;
	}

	registryScope = key;
	registryPromise = apiFetch( {
		path:
			'/ppcert/v1/merge-fields' +
			( null === key
				? ''
				: `?trigger_types=${ encodeURIComponent( key ) }` ),
	} )
		.then( ( data ) => {
			// A newer scope request may have superseded this one; only
			// the current scope's response becomes the shared registry.
			if ( registryScope === key ) {
				registry = data;
			}
			return data;
		} )
		.catch( () => {
			if ( registryScope === key ) {
				registryPromise = null;
			}
			return { groups: {}, fields: [] };
		} );

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
 * Seed the registry (harness / tests). A seeded registry answers every
 * scope - specs that exercise scoping mock the REST route instead.
 *
 * @param {Object} data { groups, fields }.
 */
export function seedMergeFields( data ) {
	registry = data;
	registryPromise = Promise.resolve( data );
	seeded = true;
}

/**
 * Sample map: token key => sample string.
 *
 * @param {Object|null} data Registry to read; defaults to the shared
 *                           module registry. Callers holding a resolved
 *                           loadMergeFields() result should pass it so
 *                           the map always matches their scope.
 * @return {Object} Map (empty before load).
 */
export function getSampleMap( data = null ) {
	const source = data || registry;
	const map = {};

	( source ? source.fields : [] ).forEach( ( field ) => {
		map[ field.key ] = field.sample;
	} );

	return map;
}

/**
 * Search meta keys for the picker.
 *
 * @param {string} scope       'user' or 'post'.
 * @param {string} search      Search term.
 * @param {number} postId      Source post id (post scope only).
 * @param {string} triggerType Trigger type id - previews against the
 *                             most recent source of the type when no
 *                             post id is bound ('any' triggers, 1.1).
 * @return {Promise<Array>} [ { key, sample } ].
 */
export function searchMetaKeys(
	scope,
	search = '',
	postId = 0,
	triggerType = ''
) {
	const fixtures = metaKeyFixtures[ scope ];

	if ( fixtures ) {
		const term = search.toLowerCase();
		return Promise.resolve(
			fixtures.filter( ( item ) => item.key.includes( term ) )
		);
	}

	const source =
		postId > 0
			? `post_id=${ postId }`
			: `trigger_type=${ encodeURIComponent( triggerType ) }`;

	const path =
		'post' === scope
			? `/ppcert/v1/merge-fields/post-meta-keys?${ source }&search=${ encodeURIComponent(
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
