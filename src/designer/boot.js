/**
 * Designer boot data access
 *
 * ppcert_designer_data is localized by class-ppcert-admin.php. Read
 * lazily so the harness can install a fixture object before components
 * render. Components import getBoot() rather than threading the object
 * through props.
 */

const DEFAULTS = {
	template_id: 0,
	list_url: '',
	fonts: {},
	element_types: {},
	starters: [],
	page_presets: {},
};

/**
 * Get the boot data.
 *
 * @return {Object} Boot data with all keys present.
 */
export function getBoot() {
	return { ...DEFAULTS, ...( window.ppcert_designer_data || {} ) };
}

/**
 * Variant map for a font family from the boot fonts.
 *
 * @param {string} slug Font family slug.
 * @return {Object} Variants map (empty when unknown).
 */
export function getFontVariants( slug ) {
	const family = getBoot().fonts[ slug ];
	return family && family.variants ? family.variants : {};
}
