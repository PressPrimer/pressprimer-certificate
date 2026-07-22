/**
 * Text canvas element (layout-schema `text`)
 *
 * Renders exactly per schema props with the shared fitting rule
 * (Feature 007 FR-004): overflowing text shrinks in manifest steps to
 * the scale floor, then truncates with an ellipsis - identical math to
 * the PDF renderer. font-synthesis is disabled: bold and italic only
 * ever come from the real bundled variant files (parity rule FR-005),
 * kerning/ligatures/optical sizing stay off (TCPDF lays glyphs by plain
 * advance widths), and the text block shifts by the exact TCPDF-vs-
 * Chrome first-baseline difference.
 */

import { useTextFit } from './useTextFit';
import { baselineCompensation } from './baseline';

/**
 * The element.
 *
 * @param {Object} props         Props.
 * @param {Object} props.element Clean element.
 * @param {Object} props.box     Visual box.
 * @return {JSX.Element} Rendered text.
 */
export default function TextElement( { element, box } ) {
	const p = element.props;
	const fitted = useTextFit( p.content, box, p );
	const dy = baselineCompensation( p, fitted.size );

	return (
		<div
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
