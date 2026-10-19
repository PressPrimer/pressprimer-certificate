/**
 * Shape canvas element (layout-schema `shape`)
 *
 * SVG rendering with strokes CENTERED on the geometric path, exactly as
 * TCPDF strokes Rect/Ellipse/Line (a CSS border draws inside the box
 * and drifts by half the stroke - a parity failure). overflow stays
 * visible so the outer stroke half renders, as it does in the PDF.
 */

/**
 * The element.
 *
 * @param {Object} props         Props.
 * @param {Object} props.element Clean element.
 * @param {Object} props.box     Visual box.
 * @return {JSX.Element} Rendered shape.
 */
export default function ShapeElement( { element, box } ) {
	const p = element.props;
	const stroke = p.stroke_width > 0 ? p.stroke_color : 'none';
	const strokeWidth = p.stroke_width > 0 ? p.stroke_width : 0;
	const fill = p.fill_color || 'none';

	const svgProps = {
		width: box.w,
		height: box.h,
		viewBox: `0 0 ${ box.w } ${ box.h }`,
		style: { display: 'block', overflow: 'visible' },
		shapeRendering: 'crispEdges',
	};

	if ( 'line' === p.shape ) {
		// The bounding box defines the endpoints, top-left to bottom-right,
		// except that a box 1 pt or less across is a straight rule through
		// its center (2.1) - exactly the renderer's line_endpoints().
		let ends = [ 0, 0, box.w, box.h ];

		if ( Math.min( box.w, box.h ) <= 1 ) {
			ends =
				box.h <= box.w
					? [ 0, box.h / 2, box.w, box.h / 2 ]
					: [ box.w / 2, 0, box.w / 2, box.h ];
		}

		return (
			<svg { ...svgProps } shapeRendering="auto">
				<line
					x1={ ends[ 0 ] }
					y1={ ends[ 1 ] }
					x2={ ends[ 2 ] }
					y2={ ends[ 3 ] }
					stroke={ p.stroke_color }
					strokeWidth={ strokeWidth }
				/>
			</svg>
		);
	}

	if ( 'ellipse' === p.shape ) {
		return (
			<svg { ...svgProps } shapeRendering="auto">
				<ellipse
					cx={ box.w / 2 }
					cy={ box.h / 2 }
					rx={ box.w / 2 }
					ry={ box.h / 2 }
					fill={ fill }
					stroke={ stroke }
					strokeWidth={ strokeWidth }
				/>
			</svg>
		);
	}

	return (
		<svg
			{ ...svgProps }
			shapeRendering={ p.radius > 0 ? 'auto' : 'crispEdges' }
		>
			<rect
				x="0"
				y="0"
				width={ box.w }
				height={ box.h }
				rx={ p.radius || 0 }
				fill={ fill }
				stroke={ stroke }
				strokeWidth={ strokeWidth }
			/>
		</svg>
	);
}
