/**
 * Shape section - line / rect / ellipse styling per layout-schema.
 */

import { __ } from '@wordpress/i18n';
import { InputNumber, Select, Typography } from 'antd';
import { useDesignerStore } from '../../hooks/useDesignerStore';
import { updateElementProps } from '../../schema/geometry';
import ColorField from './ColorField';
import PropRow from './PropRow';

const { Text } = Typography;

/**
 * The section.
 *
 * @param {Object} props         Props.
 * @param {Object} props.element Selected element.
 * @return {JSX.Element} Section.
 */
export default function ShapeSection( { element } ) {
	const { state, dispatch } = useDesignerStore();
	const p = element.props;

	const patch = ( propsPatch ) => {
		dispatch( {
			type: 'APPLY_LAYOUT',
			layout: updateElementProps( state.layout, element.id, propsPatch ),
		} );
	};

	return (
		<div className="ppcert-designer__prop-section">
			<Text type="secondary" className="ppcert-designer__panel-heading">
				{ __( 'Shape', 'pressprimer-certificate' ) }
			</Text>

			<PropRow label={ __( 'Type', 'pressprimer-certificate' ) }>
				<Select
					size="small"
					value={ p.shape }
					data-ppcert-prop="shape"
					popupMatchSelectWidth={ false }
					onChange={ ( shape ) => patch( { shape } ) }
					options={ [
						{
							value: 'line',
							label: __( 'Line', 'pressprimer-certificate' ),
						},
						{
							value: 'rect',
							label: __( 'Rectangle', 'pressprimer-certificate' ),
						},
						{
							value: 'ellipse',
							label: __( 'Ellipse', 'pressprimer-certificate' ),
						},
					] }
				/>
			</PropRow>

			<PropRow label={ __( 'Stroke', 'pressprimer-certificate' ) }>
				<ColorField
					value={ p.stroke_color }
					onChange={ ( color ) => patch( { stroke_color: color } ) }
				/>
				<InputNumber
					size="small"
					min={ 0 }
					max={ 20 }
					step={ 0.5 }
					value={ p.stroke_width }
					data-ppcert-prop="stroke_width"
					onChange={ ( value ) =>
						'number' === typeof value &&
						patch( { stroke_width: value } )
					}
				/>
			</PropRow>

			{ 'line' !== p.shape && (
				<PropRow label={ __( 'Fill', 'pressprimer-certificate' ) }>
					<ColorField
						clearable
						value={ p.fill_color }
						onChange={ ( color ) => patch( { fill_color: color } ) }
					/>
				</PropRow>
			) }

			{ 'rect' === p.shape && (
				<PropRow label={ __( 'Radius', 'pressprimer-certificate' ) }>
					<InputNumber
						size="small"
						min={ 0 }
						max={ 100 }
						step={ 1 }
						value={ p.radius }
						data-ppcert-prop="radius"
						onChange={ ( value ) =>
							'number' === typeof value &&
							patch( { radius: value } )
						}
					/>
				</PropRow>
			) }
		</div>
	);
}
