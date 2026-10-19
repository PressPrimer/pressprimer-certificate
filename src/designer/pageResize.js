/**
 * Page resizing with the scale-to-fit message (2.1, Feature 2.1-004
 * FR-002)
 *
 * One path for the Page panel's Size and Layout controls and for
 * window.ppcert_designer_api.resizePage(): the change is one undo step,
 * and rescaling is never silent.
 */

import { __ } from '@wordpress/i18n';
import { message } from 'antd';
import { resizeLayoutPage } from './schema/geometry';
import { getBoot } from './boot';

/**
 * Resize the working document's page and dispatch it.
 *
 * @param {Object}   layout   Current layout document.
 * @param {Object}   target   { size, orientation, width, height }.
 * @param {Function} dispatch Store dispatch.
 * @return {boolean} Whether the page changed.
 */
export function applyPageResize( layout, target, dispatch ) {
	if ( ! layout ) {
		return false;
	}

	const next = resizeLayoutPage(
		layout,
		target,
		getBoot().page_presets || {}
	);

	const before = layout.page;
	const after = next.page;

	if (
		next === layout ||
		( before.size === after.size &&
			before.orientation === after.orientation &&
			before.width === after.width &&
			before.height === after.height )
	) {
		return false;
	}

	dispatch( { type: 'APPLY_LAYOUT', layout: next } );

	// Rescaling must never be silent: name what just happened and that
	// it is one undo step.
	if (
		next.elements.length > 0 &&
		( after.width !== before.width || after.height !== before.height )
	) {
		message.info(
			__(
				'Design scaled to fit the new page. Undo restores it.',
				'pressprimer-certificate'
			)
		);
	}

	return true;
}
