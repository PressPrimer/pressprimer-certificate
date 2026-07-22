/**
 * Canvas geometry (Feature 001 FR-002)
 *
 * Pure functions for the canvas interactions: pointer-to-point math,
 * element move/resize with the validator's clamps mirrored client-side,
 * z-order operations, and marquee hit testing. No DOM, no store - the
 * Playwright harness and Canvas share these.
 *
 * Mirrors includes/services/class-ppcert-layout-validator.php: positions
 * clamp to [-page dimension, 2x page dimension], sizes to
 * [MIN_DIMENSION, 2x page dimension], QR stays square.
 */

export const MIN_DIMENSION = 0.1;

export const NUDGE_PT = 1;
export const NUDGE_SHIFT_PT = 10;

/**
 * Round to the designer's 0.1pt storage precision.
 *
 * @param {number} value Value in points.
 * @return {number} Rounded value.
 */
export function roundPt( value ) {
	return Math.round( value * 10 ) / 10;
}

/**
 * Convert a screen-pixel delta to points at the current zoom scale.
 *
 * @param {number} px    Screen pixels.
 * @param {number} scale Zoom scale (1 = 100%).
 * @return {number} Points.
 */
export function pxToPt( px, scale ) {
	return px / scale;
}

/**
 * Clamp a number to a range.
 *
 * @param {number} value Value.
 * @param {number} min   Minimum.
 * @param {number} max   Maximum.
 * @return {number} Clamped value.
 */
function clamp( value, min, max ) {
	return Math.min( max, Math.max( min, value ) );
}

/**
 * Clamp an element box to the validator's ranges for the page.
 *
 * @param {Object} box  { x, y, w, h }.
 * @param {Object} page { width, height }.
 * @return {Object} Clamped box.
 */
export function clampBox( box, page ) {
	return {
		x: roundPt( clamp( box.x, -page.width, 2 * page.width ) ),
		y: roundPt( clamp( box.y, -page.height, 2 * page.height ) ),
		w: roundPt( clamp( box.w, MIN_DIMENSION, 2 * page.width ) ),
		h: roundPt( clamp( box.h, MIN_DIMENSION, 2 * page.height ) ),
	};
}

/**
 * Move a set of elements by a point delta. Returns a new layout.
 *
 * @param {Object}   layout Layout document.
 * @param {string[]} ids    Element ids to move.
 * @param {number}   dx     Delta x in points.
 * @param {number}   dy     Delta y in points.
 * @return {Object} New layout.
 */
export function moveElements( layout, ids, dx, dy ) {
	return {
		...layout,
		elements: layout.elements.map( ( element ) => {
			if ( ! ids.includes( element.id ) ) {
				return element;
			}

			const box = clampBox(
				{
					x: element.x + dx,
					y: element.y + dy,
					w: element.w,
					h: element.h,
				},
				layout.page
			);

			return { ...element, ...box };
		} ),
	};
}

/**
 * The eight resize handles.
 *
 * @type {string[]}
 */
export const HANDLES = [ 'nw', 'n', 'ne', 'e', 'se', 's', 'sw', 'w' ];

/**
 * Resize an element box by a point delta on one handle.
 *
 * North/west handles move the origin as they shrink the box; the box
 * never inverts (the dragged edge stops at MIN_DIMENSION). QR elements
 * stay square (schema rule: the validator snaps w/h to min), driven by
 * whichever axis the handle moves - corner handles use the larger delta.
 *
 * @param {Object}  box    { x, y, w, h }.
 * @param {string}  handle One of HANDLES.
 * @param {number}  dx     Delta x in points.
 * @param {number}  dy     Delta y in points.
 * @param {Object}  page   { width, height }.
 * @param {boolean} square Lock the box square (QR).
 * @return {Object} New box.
 */
export function resizeBox( box, handle, dx, dy, page, square = false ) {
	let { x, y, w, h } = box;

	const west = handle.includes( 'w' );
	const north = handle.includes( 'n' );
	const east = handle.includes( 'e' );
	const south = handle.includes( 's' );

	if ( east ) {
		w += dx;
	}
	if ( south ) {
		h += dy;
	}
	if ( west ) {
		w -= dx;
	}
	if ( north ) {
		h -= dy;
	}

	w = Math.max( MIN_DIMENSION, w );
	h = Math.max( MIN_DIMENSION, h );

	if ( square ) {
		const movesX = east || west;
		const movesY = north || south;
		let side;

		if ( movesX && movesY ) {
			side = Math.max( w, h );
		} else if ( movesX ) {
			side = w;
		} else {
			side = h;
		}

		w = side;
		h = side;
	}

	// Anchor the opposite edge: west/north handles shift the origin by
	// the size change.
	if ( west ) {
		x += box.w - w;
	}
	if ( north ) {
		y += box.h - h;
	}

	return clampBox( { x, y, w, h }, page );
}

/**
 * Apply a resize to one element in a layout. Returns a new layout.
 *
 * @param {Object} layout Layout document.
 * @param {string} id     Element id.
 * @param {string} handle One of HANDLES.
 * @param {number} dx     Delta x in points.
 * @param {number} dy     Delta y in points.
 * @return {Object} New layout.
 */
export function resizeElement( layout, id, handle, dx, dy ) {
	return {
		...layout,
		elements: layout.elements.map( ( element ) => {
			if ( element.id !== id ) {
				return element;
			}

			const box = resizeBox(
				{ x: element.x, y: element.y, w: element.w, h: element.h },
				handle,
				dx,
				dy,
				layout.page,
				element.type === 'qr'
			);

			return { ...element, ...box };
		} ),
	};
}

/**
 * Z-order operations. Elements are kept sorted by z ascending (the
 * validator's post-sort order) and z is renumbered 1..n, matching the
 * validator's unique-ify pass.
 *
 * @param {Object} layout Layout document.
 * @param {string} id     Element id to move.
 * @param {string} op     'forward' | 'backward' | 'front' | 'back'.
 * @return {Object} New layout.
 */
export function reorderElement( layout, id, op ) {
	const elements = [ ...layout.elements ];
	const from = elements.findIndex( ( element ) => element.id === id );

	if ( from === -1 ) {
		return layout;
	}

	let to = from;

	if ( 'forward' === op ) {
		to = Math.min( elements.length - 1, from + 1 );
	} else if ( 'backward' === op ) {
		to = Math.max( 0, from - 1 );
	} else if ( 'front' === op ) {
		to = elements.length - 1;
	} else if ( 'back' === op ) {
		to = 0;
	}

	if ( to === from ) {
		return layout;
	}

	const [ moved ] = elements.splice( from, 1 );
	elements.splice( to, 0, moved );

	return {
		...layout,
		elements: elements.map( ( element, index ) => ( {
			...element,
			z: index + 1,
		} ) ),
	};
}

/**
 * Ids of elements intersecting a marquee rectangle (point space).
 *
 * @param {Array}  elements Layout elements.
 * @param {Object} rect     { x, y, w, h } marquee in points.
 * @return {string[]} Intersecting element ids.
 */
export function marqueeHits( elements, rect ) {
	return elements
		.filter(
			( el ) =>
				el.x < rect.x + rect.w &&
				el.x + el.w > rect.x &&
				el.y < rect.y + rect.h &&
				el.y + el.h > rect.y
		)
		.map( ( el ) => el.id );
}

/**
 * Normalize two drag corners into a rect with positive size.
 *
 * @param {Object} a { x, y } first corner.
 * @param {Object} b { x, y } second corner.
 * @return {Object} { x, y, w, h }.
 */
export function cornersToRect( a, b ) {
	return {
		x: Math.min( a.x, b.x ),
		y: Math.min( a.y, b.y ),
		w: Math.abs( a.x - b.x ),
		h: Math.abs( a.y - b.y ),
	};
}
