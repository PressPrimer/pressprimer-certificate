/**
 * Merge field canvas element (Feature 001 FR-004)
 *
 * Renders the registry's sample value styled per the element's text
 * props with the dashed-underline affordance; the toolbar token toggle
 * flips every merge element to raw token display. Tokens without a
 * registry sample (meta tokens, orphaned adapter tokens) always show
 * the raw token - template syntax must be visible, never guessed at.
 */

import { useDesignerView } from '../view-context';

/**
 * The element.
 *
 * @param {Object} props         Props.
 * @param {Object} props.element Clean element.
 * @param {Object} props.box     Visual box.
 * @return {JSX.Element} Rendered merge field.
 */
export default function MergeFieldElement( { element, box } ) {
	const { tokenView, samples } = useDesignerView();
	const p = element.props;

	const token = p.token || '';
	const key = token.replace( /^\{\{|\}\}$/g, '' );
	const sample = samples[ key ];

	const display = tokenView || undefined === sample ? token : sample;

	return (
		<div
			className="ppcert-designer__el-merge"
			data-ppcert-merge-display={ tokenView ? 'token' : 'sample' }
			style={ {
				width: box.w,
				height: box.h,
				color: p.color,
				fontFamily: `"${ p.font_family }"`,
				fontSize: p.font_size,
				lineHeight: p.line_height,
				fontWeight: p.bold ? 700 : 400,
				fontStyle: p.italic ? 'italic' : 'normal',
				fontSynthesis: 'none',
				textAlign: p.align,
				overflow: 'hidden',
				whiteSpace: 'pre-wrap',
			} }
		>
			{ display }
		</div>
	);
}
