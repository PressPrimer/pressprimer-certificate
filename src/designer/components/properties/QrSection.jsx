/**
 * QR section - colors only. The QR always encodes the credential's
 * verification URL (layout-schema rule: not configurable in v1).
 */

import { __ } from '@wordpress/i18n';
import { Alert, Typography } from 'antd';
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
export default function QrSection( { element } ) {
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
				{ __( 'QR Code', 'pressprimer-certificate' ) }
			</Text>

			<PropRow label={ __( 'Dark', 'pressprimer-certificate' ) }>
				<ColorField
					value={ p.dark_color }
					onChange={ ( color ) => patch( { dark_color: color } ) }
				/>
			</PropRow>

			<PropRow label={ __( 'Light', 'pressprimer-certificate' ) }>
				<ColorField
					clearable
					value={ p.light_color }
					onChange={ ( color ) => patch( { light_color: color } ) }
				/>
			</PropRow>

			<Alert
				type="info"
				showIcon
				message={ __(
					'The QR code always links to the certificate verification page.',
					'pressprimer-certificate'
				) }
			/>
		</div>
	);
}
