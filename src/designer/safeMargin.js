/**
 * The safe print margin (2.1)
 *
 * The dashed line the canvas draws, and a snapping target: 24 pt from
 * every edge unless `ppcert.designer.safeMargin` says otherwise for the
 * page on screen (Educator uses 9 pt, the 1/8 in safe zone print shops
 * use, on wallet cards). A guide only: nothing outside it is clipped.
 */

import { applyFilters } from '@wordpress/hooks';
import { SAFE_MARGIN_PT } from './schema/geometry';

/**
 * The safe print margin for a page, in points.
 *
 * Kept between 0 and a quarter of the page's shorter side, so the safe
 * area never collapses on a small page.
 *
 * @param {Object} page Page { width, height, size, orientation }.
 * @return {number} Margin in points.
 */
export function safeMargin( page ) {
	const cap =
		page && page.width && page.height
			? Math.min( page.width, page.height ) / 4
			: SAFE_MARGIN_PT;
	const value = Number(
		applyFilters( 'ppcert.designer.safeMargin', SAFE_MARGIN_PT, page )
	);

	if ( ! Number.isFinite( value ) || value < 0 ) {
		return Math.min( SAFE_MARGIN_PT, cap );
	}

	return Math.min( value, cap );
}
