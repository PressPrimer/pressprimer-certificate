/**
 * Text / merge_field canvas element (layout-schema `text`)
 *
 * Renders exactly per schema props. font-synthesis is disabled: bold
 * and italic only ever come from the real bundled variant files, the
 * same files the PDF renderer subsets (parity rule FR-005).
 *
 * Merge fields render their raw token until the registry routes land
 * (Prompt 3.4 replaces this with registry sample values).
 */

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

	return (
		<div
			className={
				'merge_field' === element.type
					? 'ppcert-designer__el-merge'
					: undefined
			}
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
			{ 'merge_field' === element.type ? p.token : p.content }
		</div>
	);
}
