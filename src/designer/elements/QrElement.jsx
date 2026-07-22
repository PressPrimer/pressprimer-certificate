/**
 * QR canvas element (layout-schema `qr`)
 *
 * Paints the real matrix from the PHP encoder (one encoder, ADR-004)
 * with geometry identical to the PDF renderer: module size = box /
 * total modules (quiet zones included), dark modules as squares, light
 * transparent unless a light color is set. Falls back to a deterministic
 * finder-pattern placeholder when no matrix is available (boot data
 * absent - never the case in wp-admin).
 */

import { getSampleQr } from '../qr';

/**
 * Placeholder finder-pattern visual (no matrix available).
 *
 * @param {Object} props       Props.
 * @param {Object} props.box   Visual box.
 * @param {string} props.dark  Dark color.
 * @param {string} props.light Light color ('' = transparent).
 * @return {JSX.Element} Placeholder.
 */
function Placeholder( { box, dark, light } ) {
	const module = box.w / 10;

	const finder = ( x, y, key ) => (
		<g key={ key }>
			<rect
				x={ x }
				y={ y }
				width={ module * 3 }
				height={ module * 3 }
				fill="none"
				stroke={ dark }
				strokeWidth={ module * 0.6 }
			/>
			<rect
				x={ x + module }
				y={ y + module }
				width={ module }
				height={ module }
				fill={ dark }
			/>
		</g>
	);

	return (
		<svg
			width={ box.w }
			height={ box.h }
			viewBox={ `0 0 ${ box.w } ${ box.h }` }
			style={ { display: 'block', background: light } }
		>
			{ finder( module * 0.5, module * 0.5, 'tl' ) }
			{ finder( box.w - module * 3.5, module * 0.5, 'tr' ) }
			{ finder( module * 0.5, box.h - module * 3.5, 'bl' ) }
			<rect
				x={ box.w / 2 - module }
				y={ box.h / 2 - module }
				width={ module * 2 }
				height={ module * 2 }
				fill={ dark }
			/>
		</svg>
	);
}

/**
 * The element.
 *
 * @param {Object} props         Props.
 * @param {Object} props.element Clean element.
 * @param {Object} props.box     Visual box.
 * @return {JSX.Element} Rendered QR.
 */
export default function QrElement( { element, box } ) {
	const p = element.props;
	const dark = p.dark_color || '#000000';
	const light = p.light_color || 'transparent';
	const qr = getSampleQr();

	if ( ! qr ) {
		return <Placeholder box={ box } dark={ dark } light={ light } />;
	}

	// Identical geometry to the PDF renderer's render_qr().
	const total = qr.total_modules;
	const moduleSize = box.w / total;
	const offset = qr.quiet_zone;

	const modules = [];

	qr.matrix.forEach( ( row, rowIndex ) => {
		row.forEach( ( value, colIndex ) => {
			if ( 1 !== value ) {
				return;
			}

			modules.push(
				<rect
					key={ `${ rowIndex }-${ colIndex }` }
					x={ ( offset + colIndex ) * moduleSize }
					y={ ( offset + rowIndex ) * moduleSize }
					width={ moduleSize }
					height={ moduleSize }
					fill={ dark }
				/>
			);
		} );
	} );

	return (
		<svg
			data-ppcert-qr-real
			width={ box.w }
			height={ box.h }
			viewBox={ `0 0 ${ box.w } ${ box.h }` }
			shapeRendering="crispEdges"
			style={ { display: 'block', background: light } }
		>
			{ modules }
		</svg>
	);
}
