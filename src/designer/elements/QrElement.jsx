/**
 * QR canvas element (layout-schema `qr`)
 *
 * Placeholder pattern render: a deterministic finder-pattern visual in
 * the element's colors. The real matrix render (shared with the PDF
 * encoder for parity) lands with the canvas-vs-PDF parity work; the QR
 * content itself is never configurable (always the verification URL).
 */

/**
 * The element.
 *
 * @param {Object} props         Props.
 * @param {Object} props.element Clean element.
 * @param {Object} props.box     Visual box.
 * @return {JSX.Element} Rendered QR placeholder.
 */
export default function QrElement( { element, box } ) {
	const p = element.props;
	const dark = p.dark_color || '#000000';
	const light = p.light_color || 'transparent';
	const module = box.w / 10;

	const finder = ( x, y ) => (
		<g>
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
			{ finder( module * 0.5, module * 0.5 ) }
			{ finder( box.w - module * 3.5, module * 0.5 ) }
			{ finder( module * 0.5, box.h - module * 3.5 ) }
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
