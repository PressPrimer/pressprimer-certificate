/**
 * Layout schema migrations (client side)
 *
 * Mirror of the PHP validator's migrate step (the PHP side is
 * authoritative; see docs/architecture/layout-schema.md, Versioning).
 * The designer migrates on load so the in-memory document is always
 * current-version and saves persist the current version. Stored
 * documents are never rewritten outside the normal save path.
 */

export const CURRENT_SCHEMA_VERSION = 2;

/**
 * The newest schema version the free plugin validates and renders.
 *
 * v3 (plugin 2.0.0) is the multi-page pages[] model - Educator-authored;
 * the free designer neither creates nor edits v3 documents and declines
 * to open them (DesignerApp guards on this constant), while the PHP
 * validator, renderer, and issuance pipeline handle v3 fully.
 */
export const MAX_SCHEMA_VERSION = 3;

/**
 * Migrate a loaded layout document to the free designer's version
 *
 * v1 -> v2 is a version-stamp-only migration: v2 allows merge tokens
 * in text content (interpolated at render time), and the v1 validator
 * rejected token-bearing text, so no stored v1 document can change
 * appearance by being stamped.
 *
 * v2 documents pass through UNCHANGED - the free save path deliberately
 * keeps producing v2 (the 2.0 schema bump cannot perturb free users);
 * migrateV2toV3 is the explicit conversion Educator's designer calls.
 * Unknown or newer versions also pass through untouched - the
 * server-side validator is the authority on rejecting them.
 *
 * @param {Object} layout Layout document from the REST API.
 * @return {Object} The document at the free designer's schema version.
 */
export function migrateLayout( layout ) {
	if ( ! layout || 'object' !== typeof layout ) {
		return layout;
	}

	if ( 1 === parseInt( layout.layout_schema_version, 10 ) ) {
		return { ...layout, layout_schema_version: CURRENT_SCHEMA_VERSION };
	}

	return layout;
}

/**
 * Convert a v2 single-page document to the v3 multi-page shape
 *
 * Wraps the root elements array as pages[0], removes the root key, and
 * stamps version 3. Lossless and shape-only. NOT called by the free
 * designer's load or save path - this is the explicit conversion
 * Educator's designer invokes when a user adds a second page. Mirrors
 * the PHP validator's migrate_v2_to_v3() (PHP is authoritative).
 *
 * @param {Object} layout Layout document at version 1 or 2.
 * @return {Object} The document at version 3.
 */
export function migrateV2toV3( layout ) {
	const migrated = migrateLayout( layout );

	if (
		! migrated ||
		'object' !== typeof migrated ||
		parseInt( migrated.layout_schema_version, 10 ) >= 3
	) {
		return migrated;
	}

	const { elements = [], ...rest } = migrated;

	return {
		...rest,
		layout_schema_version: 3,
		pages: [ { elements } ],
	};
}
