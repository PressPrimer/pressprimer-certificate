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
 * Migrate a loaded layout document to the current schema version
 *
 * v1 -> v2 is a version-stamp-only migration: v2 allows merge tokens
 * in text content (interpolated at render time), and the v1 validator
 * rejected token-bearing text, so no stored v1 document can change
 * appearance by being stamped.
 *
 * Unknown or newer versions pass through untouched - the server-side
 * validator is the authority on rejecting them.
 *
 * @param {Object} layout Layout document from the REST API.
 * @return {Object} The document at the current schema version.
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
