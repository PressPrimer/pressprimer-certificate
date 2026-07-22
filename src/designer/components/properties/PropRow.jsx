/**
 * Labeled property row - shared layout for the panel sections.
 */

/**
 * The row.
 *
 * @param {Object} props          Props.
 * @param {string} props.label    Row label.
 * @param {*}      props.children Control(s).
 * @return {JSX.Element} Row.
 */
export default function PropRow( { label, children } ) {
	return (
		<div className="ppcert-designer__prop-row">
			<span className="ppcert-designer__prop-label">{ label }</span>
			<span className="ppcert-designer__prop-control">{ children }</span>
		</div>
	);
}
