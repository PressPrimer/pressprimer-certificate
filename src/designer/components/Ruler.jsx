/**
 * Canvas ruler - point scale along the page edges.
 *
 * Rendered unscaled (labels stay readable at any zoom); tick positions
 * multiply by the current scale so they track the page exactly. Minor
 * ticks every 10pt, medium at 50pt, labeled major ticks at 100pt.
 */

export const RULER_PX = 24;

const MINOR_STEP_PT = 10;

/**
 * The ruler.
 *
 * @param {Object} props        Props.
 * @param {string} props.axis   'h' (top) or 'v' (left).
 * @param {number} props.length Page dimension in points.
 * @param {number} props.scale  Current zoom scale.
 * @return {JSX.Element} Ruler.
 */
export default function Ruler( { axis, length, scale } ) {
	const horizontal = 'h' === axis;
	const lengthPx = length * scale;

	const ticks = [];

	for ( let pt = 0; pt <= length; pt += MINOR_STEP_PT ) {
		const major = 0 === pt % 100;
		const medium = ! major && 0 === pt % 50;
		const size = major ? 14 : medium ? 10 : 6; // eslint-disable-line no-nested-ternary
		const at = pt * scale;

		ticks.push(
			horizontal ? (
				<line
					key={ pt }
					x1={ at }
					y1={ RULER_PX }
					x2={ at }
					y2={ RULER_PX - size }
					stroke="#9ca3af"
					strokeWidth="1"
				/>
			) : (
				<line
					key={ pt }
					x1={ RULER_PX }
					y1={ at }
					x2={ RULER_PX - size }
					y2={ at }
					stroke="#9ca3af"
					strokeWidth="1"
				/>
			)
		);

		if ( major && pt > 0 ) {
			ticks.push(
				horizontal ? (
					<text
						key={ `label-${ pt }` }
						x={ at + 3 }
						y={ 12 }
						fontSize="9"
						fill="#6b7280"
					>
						{ pt }
					</text>
				) : (
					<text
						key={ `label-${ pt }` }
						x={ 12 }
						y={ at + 3 }
						fontSize="9"
						fill="#6b7280"
						transform={ `rotate(-90 12 ${ at + 3 })` }
					>
						{ pt }
					</text>
				)
			);
		}
	}

	return (
		<svg
			data-ppcert-ruler={ axis }
			className={ `ppcert-designer__ruler ppcert-designer__ruler--${ axis }` }
			width={ horizontal ? lengthPx : RULER_PX }
			height={ horizontal ? RULER_PX : lengthPx }
		>
			{ ticks }
		</svg>
	);
}
