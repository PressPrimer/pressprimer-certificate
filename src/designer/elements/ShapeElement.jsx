/**
 * Shape canvas element (layout-schema `shape`)
 *
 * rect / ellipse / line per schema props. For `line` the bounding box
 * defines the endpoints (top-left to bottom-right) - rendered as an
 * SVG line so diagonals match the PDF renderer.
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

	if ( 'line' === p.shape ) {
		return (
			<svg
				width={ box.w }
				height={ box.h }
				viewBox={ `0 0 ${ box.w } ${ box.h }` }
				style={ { display: 'block', overflow: 'visible' } }
			>
				<line
					x1="0"
					y1="0"
					x2={ box.w }
					y2={ Math.min( box.w, box.h ) <= 1 ? 0 : box.h }
					stroke={ p.stroke_color }
					strokeWidth={ Math.max( p.stroke_width, 0.5 ) }
				/>
			</svg>
		);
	}

	return (
		<div
			style={ {
				width: box.w,
				height: box.h,
				boxSizing: 'border-box',
				border:
					p.stroke_width > 0
						? `${ p.stroke_width }px solid ${ p.stroke_color }`
						: 'none',
				background: p.fill_color || 'transparent',
				borderRadius: 'ellipse' === p.shape ? '50%' : p.radius || 0,
			} }
		/>
	);
}
