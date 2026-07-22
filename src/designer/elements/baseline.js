/**
 * Baseline parity compensation (Feature 007 FR-005)
 *
 * TCPDF's MultiCell places the first baseline at y + FontAscent (the
 * converted font's descriptor ascent). Chrome places it at
 * half-leading + its own ascent. The canvas text shifts by the exact
 * difference so glyphs land where the PDF puts them - computed from
 * the manifest metrics (the same descriptor TCPDF reads) against
 * Chrome's measured line metrics, per family/variant/size.
 */

import { getBoot } from '../boot';

let ctx = null;
const cache = new Map();

/**
 * Manifest ascent (per-1000-em) for a family + style.
 *
 * @param {string}  family Font family slug.
 * @param {boolean} bold   Bold flag.
 * @param {boolean} italic Italic flag.
 * @return {number|null} Ascent per 1000 em, or null when unknown.
 */
function manifestAscent( family, bold, italic ) {
	const entry = getBoot().fonts[ family ];

	if ( ! entry || ! entry.variants ) {
		return null;
	}

	let variant = 'regular';
	if ( bold && italic ) {
		variant = 'bold_italic';
	} else if ( bold ) {
		variant = 'bold';
	} else if ( italic ) {
		variant = 'italic';
	}

	const chosen = entry.variants[ variant ] || entry.variants.regular;

	return chosen && chosen.metrics ? chosen.metrics.ascent : null;
}

/**
 * Vertical shift (px == pt at 100%) aligning Chrome's first baseline
 * with TCPDF's.
 *
 * @param {Object} props Text props (font_family, bold, italic,
 *                       line_height).
 * @param {number} size  Rendered font size (post-fitting).
 * @return {number} Translate-Y in points (0 when unknowable).
 */
export function baselineCompensation( props, size ) {
	const key = `${ props.font_family }|${ props.bold ? 1 : 0 }|${
		props.italic ? 1 : 0
	}|${ size }|${ props.line_height }`;

	if ( cache.has( key ) ) {
		return cache.get( key );
	}

	const ascent = manifestAscent(
		props.font_family,
		!! props.bold,
		!! props.italic
	);

	let dy = 0;

	if ( null !== ascent && 'undefined' !== typeof document ) {
		if ( ! ctx ) {
			ctx = document.createElement( 'canvas' ).getContext( '2d' );
		}

		// Measured at 1000px: the browser quantizes fontBoundingBox to
		// whole pixels, so per-em values need a large measuring size.
		ctx.font = `${ props.italic ? 'italic ' : '' }${
			props.bold ? 700 : 400
		} 1000px "${ props.font_family }"`;

		const metrics = ctx.measureText( 'Hg' );

		if (
			'undefined' !== typeof metrics.fontBoundingBoxAscent &&
			'undefined' !== typeof metrics.fontBoundingBoxDescent
		) {
			const ascentEm = metrics.fontBoundingBoxAscent / 1000;
			const descentEm = metrics.fontBoundingBoxDescent / 1000;
			const chromeBaselineEm =
				( props.line_height - ( ascentEm + descentEm ) ) / 2 + ascentEm;

			dy = size * ( ascent / 1000 - chromeBaselineEm );
		}
	}

	cache.set( key, dy );

	return dy;
}
