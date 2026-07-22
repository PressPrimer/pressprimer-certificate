/**
 * Position & Size section - X/Y/W/H numeric control for any element.
 *
 * Exact coordinate entry for precise layouts (values in points, 0.1
 * precision). QR width and height stay locked square via
 * updateElementBox.
 */

import { __ } from '@wordpress/i18n';
import { InputNumber, Typography } from 'antd';
import { useDesignerStore } from '../../hooks/useDesignerStore';
import { updateElementBox } from '../../schema/geometry';
import PropRow from './PropRow';

const { Text } = Typography;

/**
 * The section.
 *
 * @param {Object} props         Props.
 * @param {Object} props.element Selected element.
 * @return {JSX.Element} Section.
 */
export default function PositionSection( { element } ) {
	const { state, dispatch } = useDesignerStore();

	const patch = ( key ) => ( value ) => {
		if ( 'number' !== typeof value ) {
			return;
		}

		dispatch( {
			type: 'APPLY_LAYOUT',
			layout: updateElementBox( state.layout, element.id, {
				[ key ]: value,
			} ),
		} );
	};

	const fields = [
		[ 'x', __( 'X', 'pressprimer-certificate' ) ],
		[ 'y', __( 'Y', 'pressprimer-certificate' ) ],
		[ 'w', __( 'Width', 'pressprimer-certificate' ) ],
		[ 'h', __( 'Height', 'pressprimer-certificate' ) ],
	];

	return (
		<div className="ppcert-designer__prop-section">
			<Text type="secondary" className="ppcert-designer__panel-heading">
				{ __( 'Position & Size (pt)', 'pressprimer-certificate' ) }
			</Text>
			<div className="ppcert-designer__prop-grid">
				{ fields.map( ( [ key, label ] ) => (
					<PropRow key={ key } label={ label }>
						<InputNumber
							size="small"
							step={ 1 }
							precision={ 1 }
							value={ element[ key ] }
							onChange={ patch( key ) }
							data-ppcert-prop={ `box-${ key }` }
						/>
					</PropRow>
				) ) }
			</div>
		</div>
	);
}
