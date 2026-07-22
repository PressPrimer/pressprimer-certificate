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
	// Manifest fitting thresholds (Feature 007 FR-004 parity contract).
	fitting: { shrink_step_pt: 0.5, min_scale: 0.6 },
	// Sample QR matrix from the PHP encoder (one encoder, ADR-004).
	sample_qr: null,
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
