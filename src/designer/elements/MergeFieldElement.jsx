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
import { useTextFit } from './useTextFit';
import { baselineCompensation } from './baseline';

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

	// The fitting rule applies to the displayed value exactly as the PDF
	// applies it to the resolved value (Feature 007 FR-004).
	const fitted = useTextFit( display, box, p );
	const dy = baselineCompensation( p, fitted.size );

	return (
		<div
			className="ppcert-designer__el-merge"
			data-ppcert-merge-display={ tokenView ? 'token' : 'sample' }
			data-ppcert-fitted={ fitted.truncated ? 'truncated' : undefined }
			style={ {
				width: box.w,
				height: box.h,
				color: p.color,
				fontFamily: `"${ p.font_family }"`,
				fontSize: fitted.size,
				lineHeight: p.line_height,
				fontWeight: p.bold ? 700 : 400,
				fontStyle: p.italic ? 'italic' : 'normal',
				fontSynthesis: 'none',
				// TCPDF lays glyphs by plain advance widths (parity).
				fontKerning: 'none',
				fontVariantLigatures: 'none',
				textRendering: 'optimizeSpeed',
				fontOpticalSizing: 'none',
				textAlign: p.align,
				overflow: 'hidden',
				whiteSpace: 'pre-wrap',
			} }
		>
			<div
				style={ {
					transform: dy ? `translateY(${ dy }px)` : undefined,
				} }
			>
				{ fitted.text }
			</div>
		</div>
	);
}
