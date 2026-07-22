/**
 * Text fitting rule (Feature 007 FR-004)
 *
 * IDENTICAL math to PressPrimer_Certificate_PDF_Renderer::fit_text():
 * shrink in shrink_step_pt steps down to min_scale of the specified
 * size, then truncate with an ellipsis. Thresholds come from the font
 * manifest via boot data - fitting is part of the parity contract, not
 * a renderer-only fallback. Any change here changes the PHP twin too.
 */

import { getBoot } from '../boot';

const ELLIPSIS = '…';

/**
 * The manifest fitting thresholds.
 *
 * @return {Object} { shrink_step_pt, min_scale }.
 */
export function fittingThresholds() {
	const fitting = getBoot().fitting || {};

	return {
		shrink_step_pt: fitting.shrink_step_pt || 0.5,
		min_scale: fitting.min_scale || 0.6,
	};
}

/**
 * Fit text into a box (the PHP fit_text() twin).
 *
 * @param {string}   text       Text to fit.
 * @param {number}   boxH       Box height (pt).
 * @param {number}   fontSize   Specified font size (pt).
 * @param {number}   lineHeight Line height multiplier.
 * @param {Function} measure    fn( text, size ): line count.
 * @param {Object}   thresholds { shrink_step_pt, min_scale }.
 * @return {Object} { size, text, truncated }.
 */
export function fitText(
	text,
	boxH,
	fontSize,
	lineHeight,
	measure,
	thresholds
) {
	// Non-finite inputs (a malformed element) must never wedge the UI:
	// NaN comparisons would make both loop exits unreachable.
	if (
		! isFinite( boxH ) ||
		! isFinite( fontSize ) ||
		! isFinite( lineHeight ) ||
		fontSize <= 0 ||
		lineHeight <= 0
	) {
		return { size: fontSize || 16, text, truncated: false };
	}

	const step = thresholds.shrink_step_pt;
	const floor = fontSize * thresholds.min_scale;
	let size = fontSize;

	for (;;) {
		const lines = Math.max( 1, measure( text, size ) );
		const height = lines * size * lineHeight;

		if ( height <= boxH ) {
			return { size, text, truncated: false };
		}

		if ( size - step < floor ) {
			break;
		}

		size -= step;
	}

	// At the floor: truncate with ellipsis until it fits.
	const maxLines = Math.max( 1, Math.floor( boxH / ( size * lineHeight ) ) );
	let kept = text;

	while ( '' !== kept ) {
		const lines = Math.max( 1, measure( kept + ELLIPSIS, size ) );

		if ( lines <= maxLines ) {
			break;
		}

		kept = kept.slice( 0, -1 );
	}

	return { size, text: kept + ELLIPSIS, truncated: true };
}

let measurerNode = null;

/**
 * The shared off-screen measurer element.
 *
 * @return {HTMLElement} Measurer.
 */
function measurer() {
	if ( ! measurerNode ) {
		measurerNode = document.createElement( 'div' );
		measurerNode.setAttribute( 'aria-hidden', 'true' );
		measurerNode.style.position = 'absolute';
		measurerNode.style.visibility = 'hidden';
		measurerNode.style.left = '-99999px';
		measurerNode.style.top = '0';
		measurerNode.style.whiteSpace = 'pre-wrap';
		measurerNode.style.wordBreak = 'normal';
		measurerNode.style.overflowWrap = 'break-word';
		measurerNode.style.fontSynthesis = 'none';
		// Match the canvas elements' parity text rendering.
		measurerNode.style.fontKerning = 'none';
		measurerNode.style.fontVariantLigatures = 'none';
		measurerNode.style.textRendering = 'optimizeSpeed';
		measurerNode.style.fontOpticalSizing = 'none';
		document.body.appendChild( measurerNode );
	}

	return measurerNode;
}

/**
 * Fit a text element's content with browser measurement.
 *
 * The measure counts wrapped lines exactly as the canvas renders them
 * (same font stack, width, and line height as the element box).
 *
 * @param {string} text  Display text.
 * @param {Object} box   { w, h } in points (canvas px at 100%).
 * @param {Object} props Text props (font_family, font_size, line_height,
 *                       bold, italic).
 * @return {Object} { size, text, truncated }.
 */
export function fitTextInBox( text, box, props ) {
	const node = measurer();

	node.style.width = `${ box.w }px`;
	node.style.fontFamily = `"${ props.font_family }"`;
	node.style.fontWeight = props.bold ? '700' : '400';
	node.style.fontStyle = props.italic ? 'italic' : 'normal';
	node.style.lineHeight = String( props.line_height );

	const measure = ( candidate, size ) => {
		node.style.fontSize = `${ size }px`;
		node.textContent = candidate;

		const height = node.getBoundingClientRect().height;

		return Math.max(
			1,
			Math.round( height / ( size * props.line_height ) )
		);
	};

	return fitText(
		text,
		box.h,
		props.font_size,
		props.line_height,
		measure,
		fittingThresholds()
	);
}
