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

export const SNAP_TOLERANCE_PT = 4;
export const SAFE_MARGIN_PT = 24;
export const MAX_ELEMENTS = 100;

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

/**
 * Generate an element id: el_ + 8 lowercase alphanumerics (schema rule).
 *
 * @param {string[]} existing Ids already in the layout (collision guard).
 * @return {string} New id.
 */
export function generateElementId( existing = [] ) {
	const alphabet = 'abcdefghijklmnopqrstuvwxyz0123456789';
	let id = '';

	do {
		const bytes = new Uint8Array( 8 );
		window.crypto.getRandomValues( bytes );

		id = 'el_';
		bytes.forEach( ( byte ) => {
			id += alphabet[ byte % alphabet.length ];
		} );
	} while ( existing.includes( id ) );

	return id;
}

/**
 * Update one element's box fields (x/y/w/h), clamped. QR stays square:
 * a change to w or h applies to both.
 *
 * @param {Object} layout Layout document.
 * @param {string} id     Element id.
 * @param {Object} patch  Partial { x, y, w, h }.
 * @return {Object} New layout.
 */
export function updateElementBox( layout, id, patch ) {
	return {
		...layout,
		elements: layout.elements.map( ( element ) => {
			if ( element.id !== id ) {
				return element;
			}

			const next = { ...element, ...patch };

			if ( 'qr' === element.type ) {
				if ( undefined !== patch.w ) {
					next.h = patch.w;
				} else if ( undefined !== patch.h ) {
					next.w = patch.h;
				}
			}

			return { ...next, ...clampBox( next, layout.page ) };
		} ),
	};
}

/**
 * Patch one element's props. Returns a new layout.
 *
 * @param {Object} layout Layout document.
 * @param {string} id     Element id.
 * @param {Object} patch  Partial props.
 * @return {Object} New layout.
 */
export function updateElementProps( layout, id, patch ) {
	return {
		...layout,
		elements: layout.elements.map( ( element ) =>
			element.id === id
				? { ...element, props: { ...element.props, ...patch } }
				: element
		),
	};
}

/**
 * Append a new element on top (z = n+1), clamped to the page.
 *
 * @param {Object} layout  Layout document.
 * @param {Object} element Element without z.
 * @return {Object} New layout.
 */
export function addElement( layout, element ) {
	const box = clampBox( element, layout.page );

	return {
		...layout,
		elements: [
			...layout.elements,
			{ ...element, ...box, z: layout.elements.length + 1 },
		],
	};
}

/**
 * Patch the document root background.
 *
 * @param {Object} layout Layout document.
 * @param {Object} patch  Partial { color, attachment_id }.
 * @return {Object} New layout.
 */
export function updateBackground( layout, patch ) {
	return {
		...layout,
		background: { ...( layout.background || {} ), ...patch },
	};
}

/**
 * Remove elements and renumber z 1..n (FR-008: removal is undoable).
 *
 * @param {Object}   layout Layout document.
 * @param {string[]} ids    Element ids to remove.
 * @return {Object} New layout.
 */
export function removeElements( layout, ids ) {
	return {
		...layout,
		elements: layout.elements
			.filter( ( element ) => ! ids.includes( element.id ) )
			.map( ( element, index ) => ( { ...element, z: index + 1 } ) ),
	};
}

/**
 * Snap candidates for one axis of a box: leading edge, center, trailing
 * edge.
 *
 * @param {number} start Leading edge position.
 * @param {number} size  Box size on the axis.
 * @return {number[]} [ leading, center, trailing ].
 */
function axisPoints( start, size ) {
	return [ start, start + size / 2, start + size ];
}

/**
 * Mirrors only fire for partners actually hugging an edge: a margin
 * wider than this fraction of the page dimension is layout, not
 * margin styling, and offering it as a snap would blanket the canvas
 * in tolerance bands (Ryan's snapping-frequency concern, 2026-08-14 -
 * without the cap only ~10% of a starter's vertical drag range stays
 * snap-free; with it, ~24%).
 */
export const MIRROR_MAX_MARGIN_RATIO = 0.25;

/**
 * Mirror-margin snap candidates against one other element, capped to
 * edge-hugging partners by MIRROR_MAX_MARGIN_RATIO (1.1,
 * Feature 004 FR-001): the dragged box's page margin on one side
 * equals the partner's page margin on the OPPOSITE side. Point-locked:
 * a leading-margin mirror only ever matches the dragged LEADING edge
 * (point 0), a trailing mirror only the TRAILING edge (point 2) -
 * centers never mirror.
 *
 * @param {Object} el       Partner element box on this axis
 *                          { start, size, cross }.
 * @param {number} pageSize Page dimension on this axis.
 * @return {Array} Mirror candidates { at, point, margin, partnerCross }.
 */
function mirrorCandidates( el, pageSize ) {
	const candidates = [];
	const cap = pageSize * MIRROR_MAX_MARGIN_RATIO;
	const partnerTrailingMargin = pageSize - ( el.start + el.size );
	const partnerLeadingMargin = el.start;

	// Dragged leading margin == partner trailing margin.
	if ( partnerTrailingMargin > 0 && partnerTrailingMargin <= cap ) {
		candidates.push( {
			at: partnerTrailingMargin,
			point: 0,
			margin: partnerTrailingMargin,
			partnerCross: el.cross,
		} );
	}

	// Dragged trailing margin == partner leading margin.
	if ( partnerLeadingMargin > 0 && partnerLeadingMargin <= cap ) {
		candidates.push( {
			at: pageSize - partnerLeadingMargin,
			point: 2,
			margin: partnerLeadingMargin,
			partnerCross: el.cross,
		} );
	}

	return candidates;
}

/**
 * Snap a dragged box against the other elements and the page (FR-002).
 *
 * Alignment targets are the other elements' edges/centers and the page
 * center; within tolerance the delta adjusts to align exactly and a
 * guide line is reported. Mirror-margin targets (1.1, Feature 1.1-004)
 * additionally snap when the dragged box's page margin equals another
 * element's opposite page margin - reported as kind 'mirror' guides
 * carrying the margin measurement so the canvas can render them
 * distinctly. The canvas NEVER snaps silently: no guide shown means
 * the pointer delta applies untouched (UX decision, Ryan 2026-07-22 -
 * the invisible 4pt grid fallback read as unexplained stickiness).
 * Alt disables snapping entirely (handled by the caller not calling
 * this). Ties between an align and a mirror target at identical
 * distance keep the align guide (enumerated first).
 *
 * @param {Object} box    The dragged element's box at its ORIGINAL
 *                        position { x, y, w, h }.
 * @param {number} dx     Proposed delta x in points.
 * @param {number} dy     Proposed delta y in points.
 * @param {Array}  others Other elements (excluded from dragging).
 * @param {Object} page   { width, height }.
 * @return {Object} { dx, dy, guides }. Guides: { axis: 'v'|'h', at,
 *                  kind: 'align' } or { axis, at, kind: 'mirror',
 *                  margin, side: 'leading'|'trailing', partnerCross }.
 */
export function snapDrag( box, dx, dy, others, page ) {
	const guides = [];

	// Page structure targets (the Canva set): edges, the drawn
	// safe-print margin, and the center lines.
	const targetsX = [
		0,
		SAFE_MARGIN_PT,
		page.width / 2,
		page.width - SAFE_MARGIN_PT,
		page.width,
	];
	const targetsY = [
		0,
		SAFE_MARGIN_PT,
		page.height / 2,
		page.height - SAFE_MARGIN_PT,
		page.height,
	];

	const mirrorsX = [];
	const mirrorsY = [];

	others.forEach( ( el ) => {
		targetsX.push( ...axisPoints( el.x, el.w ) );
		targetsY.push( ...axisPoints( el.y, el.h ) );

		mirrorsX.push(
			...mirrorCandidates(
				{ start: el.x, size: el.w, cross: el.y + el.h / 2 },
				page.width
			)
		);
		mirrorsY.push(
			...mirrorCandidates(
				{ start: el.y, size: el.h, cross: el.x + el.w / 2 },
				page.height
			)
		);
	} );

	const snapAxis = ( start, size, delta, targets, mirrors ) => {
		const moved = axisPoints( start + delta, size );
		let best = null;

		moved.forEach( ( point, index ) => {
			targets.forEach( ( target ) => {
				const distance = Math.abs( point - target );

				if (
					distance <= SNAP_TOLERANCE_PT &&
					( ! best || distance < best.distance )
				) {
					best = { distance, target, index, kind: 'align' };
				}
			} );
		} );

		mirrors.forEach( ( mirror ) => {
			const distance = Math.abs( moved[ mirror.point ] - mirror.at );

			if (
				distance <= SNAP_TOLERANCE_PT &&
				( ! best || distance < best.distance )
			) {
				best = {
					distance,
					target: mirror.at,
					index: mirror.point,
					kind: 'mirror',
					mirror,
				};
			}
		} );

		if ( best ) {
			// Align the matched point (edge or center) exactly.
			const offsets = [ 0, size / 2, size ];
			return {
				delta: best.target - offsets[ best.index ] - start,
				guide: best.target,
				kind: best.kind,
				mirror: best.mirror || null,
			};
		}

		// No alignment match: the pointer delta applies untouched.
		return { delta, guide: null, kind: null, mirror: null };
	};

	const x = snapAxis( box.x, box.w, dx, targetsX, mirrorsX );
	const y = snapAxis( box.y, box.h, dy, targetsY, mirrorsY );

	const pushGuide = ( axis, result ) => {
		if ( null === result.guide ) {
			return;
		}

		if ( 'mirror' === result.kind ) {
			guides.push( {
				axis,
				at: result.guide,
				kind: 'mirror',
				margin: result.mirror.margin,
				side: 0 === result.mirror.point ? 'leading' : 'trailing',
				partnerCross: result.mirror.partnerCross,
			} );
			return;
		}

		guides.push( { axis, at: result.guide, kind: 'align' } );
	};

	pushGuide( 'v', x );
	pushGuide( 'h', y );

	return { dx: x.delta, dy: y.delta, guides };
}

/**
 * Align selected elements against their common bounding box (1.1,
 * Feature 004 FR-003).
 *
 * One call is one APPLY_LAYOUT dispatch, so every align is a single
 * undo step. Fewer than two ids returns the layout untouched.
 *
 * @param {Object}   layout Layout document.
 * @param {string[]} ids    Selected element ids.
 * @param {string}   edge   left|center|right|top|middle|bottom.
 * @return {Object} New layout.
 */
export function alignSelection( layout, ids, edge ) {
	const selected = layout.elements.filter( ( el ) => ids.includes( el.id ) );

	if ( selected.length < 2 ) {
		return layout;
	}

	const minX = Math.min( ...selected.map( ( el ) => el.x ) );
	const maxX = Math.max( ...selected.map( ( el ) => el.x + el.w ) );
	const minY = Math.min( ...selected.map( ( el ) => el.y ) );
	const maxY = Math.max( ...selected.map( ( el ) => el.y + el.h ) );

	const target = ( el ) => {
		switch ( edge ) {
			case 'left':
				return { x: minX };
			case 'center':
				return { x: ( minX + maxX ) / 2 - el.w / 2 };
			case 'right':
				return { x: maxX - el.w };
			case 'top':
				return { y: minY };
			case 'middle':
				return { y: ( minY + maxY ) / 2 - el.h / 2 };
			case 'bottom':
				return { y: maxY - el.h };
			default:
				return {};
		}
	};

	return {
		...layout,
		elements: layout.elements.map( ( el ) => {
			if ( ! ids.includes( el.id ) ) {
				return el;
			}

			const box = clampBox( { ...el, ...target( el ) }, layout.page );

			return { ...el, ...box };
		} ),
	};
}

/**
 * Distribute selected elements evenly along one axis (1.1, Feature 004
 * FR-004).
 *
 * The first and last elements (by position) hold; the gaps between
 * neighbors equalize. Fewer than three ids returns the layout
 * untouched. Overlapping elements distribute with negative gaps - the
 * arrangement, not the overlap, is this tool's job.
 *
 * @param {Object}   layout Layout document.
 * @param {string[]} ids    Selected element ids.
 * @param {string}   axis   'x' or 'y'.
 * @return {Object} New layout.
 */
export function distributeSelection( layout, ids, axis ) {
	const size = 'x' === axis ? 'w' : 'h';
	const selected = layout.elements
		.filter( ( el ) => ids.includes( el.id ) )
		.slice()
		.sort( ( a, b ) => a[ axis ] - b[ axis ] );

	if ( selected.length < 3 ) {
		return layout;
	}

	const first = selected[ 0 ];
	const last = selected[ selected.length - 1 ];
	const span = last[ axis ] + last[ size ] - first[ axis ];
	const total = selected.reduce( ( sum, el ) => sum + el[ size ], 0 );
	const gap = ( span - total ) / ( selected.length - 1 );

	const positions = {};
	let cursor = first[ axis ];

	selected.forEach( ( el ) => {
		positions[ el.id ] = cursor;
		cursor += el[ size ] + gap;
	} );

	return {
		...layout,
		elements: layout.elements.map( ( el ) => {
			if ( ! ( el.id in positions ) ) {
				return el;
			}

			const box = clampBox(
				{ ...el, [ axis ]: positions[ el.id ] },
				layout.page
			);

			return { ...el, ...box };
		} ),
	};
}

/**
 * Change the page preset (size + orientation), keeping elements valid.
 *
 * Element boxes re-clamp against the new page dimensions (the same
 * ranges the validator enforces); elements keep their positions and may
 * hang past a smaller page until the user repositions them.
 *
 * @param {Object} layout      Layout document.
 * @param {string} size        'a4' | 'letter'.
 * @param {string} orientation 'landscape' | 'portrait'.
 * @param {Object} presets     Boot page_presets map.
 * @return {Object} New layout.
 */
export function updatePagePreset( layout, size, orientation, presets ) {
	const preset = presets[ size ] && presets[ size ][ orientation ];

	if ( ! preset ) {
		return layout;
	}

	const page = {
		...layout.page,
		size,
		orientation,
		width: preset[ 0 ],
		height: preset[ 1 ],
	};

	const oldWidth = layout.page.width;
	const oldHeight = layout.page.height;

	if (
		! oldWidth ||
		! oldHeight ||
		( oldWidth === page.width && oldHeight === page.height )
	) {
		return {
			...layout,
			page,
			elements: layout.elements.map( ( element ) => ( {
				...element,
				...clampBox( element, page ),
			} ) ),
		};
	}

	// Rescale the design to the new page (Ryan's Award-tab review,
	// 2026-07-22): uniform scale by the smaller axis ratio - never
	// distorting element aspect - then center the scaled design. A4 <->
	// Letter is near-lossless; an orientation flip shrinks the design
	// to fit with even margins on the long axis. Font sizes, stroke
	// widths, and corner radii scale with the boxes; the change is one
	// undo step like any layout mutation.
	const scale = Math.min( page.width / oldWidth, page.height / oldHeight );
	const offsetX = ( page.width - oldWidth * scale ) / 2;
	const offsetY = ( page.height - oldHeight * scale ) / 2;

	const scaleProp = ( value, min ) =>
		'number' === typeof value
			? Math.max( min, roundPt( value * scale ) )
			: value;

	const elements = layout.elements.map( ( element ) => {
		const raw = element.props || {};
		const props = {
			...raw,
			// Validator floor for font_size is 6pt.
			font_size: scaleProp( raw.font_size, 6 ),
			stroke_width: scaleProp( raw.stroke_width, 0 ),
			radius: scaleProp( raw.radius, 0 ),
		};

		// Only carry keys the element actually had.
		Object.keys( props ).forEach( ( key ) => {
			if ( undefined === props[ key ] ) {
				delete props[ key ];
			}
		} );

		const scaled = {
			...element,
			x: roundPt( element.x * scale + offsetX ),
			y: roundPt( element.y * scale + offsetY ),
			w: roundPt( element.w * scale ),
			h: roundPt( element.h * scale ),
			props,
		};

		return { ...scaled, ...clampBox( scaled, page ) };
	} );

	return { ...layout, page, elements };
}
