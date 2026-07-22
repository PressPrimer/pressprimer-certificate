/**
 * Canvas element component registry (Feature 001 FR-003)
 *
 * Maps layout element types to their canvas components. Types without
 * a component (third-party types from ppcert_designer_element_types)
 * render as a labeled generic box so they stay selectable.
 */

import TextElement from './TextElement';
import ShapeElement from './ShapeElement';
import ImageElement from './ImageElement';
import QrElement from './QrElement';

const COMPONENTS = {
	text: TextElement,
	merge_field: TextElement,
	shape: ShapeElement,
	image: ImageElement,
	signature: ImageElement,
	qr: QrElement,
};

/**
 * Generic fallback for unknown element types.
 *
 * @param {Object} props         Props.
 * @param {Object} props.element Clean element.
 * @param {Object} props.box     Visual box.
 * @return {JSX.Element} Labeled box.
 */
function GenericElement( { element, box } ) {
	return (
		<div
			className="ppcert-designer__el-media"
			style={ { width: box.w, height: box.h } }
		>
			{ element.type }
		</div>
	);
}

/**
 * Component for an element type.
 *
 * @param {string} type Element type key.
 * @return {Function} React component.
 */
export function getElementComponent( type ) {
	return COMPONENTS[ type ] || GenericElement;
}
