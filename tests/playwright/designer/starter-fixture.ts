/**
 * Shared spec fixtures derived from the real starter document.
 *
 * The harness loads templates/starter-formal-landscape.json, so specs
 * must read their geometry from the same file - hardcoded copies
 * silently rot when the starters are redesigned (the Prompt 5.1
 * redesign broke eight specs exactly that way).
 */
import starter from '../../../templates/starter-formal-landscape.json';

export const STARTER = starter;

export const ELEMENT_COUNT = starter.elements.length;

/**
 * Pull one element's box by id.
 *
 * @param {string} id Element id.
 * @return {Object} id + box.
 */
function box( id: string ) {
	const element = starter.elements.find( ( el ) => id === el.id )!;

	return {
		id: element.id,
		x: element.x,
		y: element.y,
		w: element.w,
		h: element.h,
	};
}

export const TITLE = box( 'el_frmtitle' );
export const NAME = box( 'el_frmname1' );
export const QR = box( 'el_frmqr001' );
