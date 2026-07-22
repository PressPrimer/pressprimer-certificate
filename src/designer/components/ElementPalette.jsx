/**
 * Element palette (Feature 001 FR-003)
 *
 * Lists the seven 1.0 element types. Adding elements to the canvas
 * activates in Prompt 3.3 alongside the element components.
 *
 * @package
 */

import { __ } from '@wordpress/i18n';
import { List, Tooltip, Typography } from 'antd';
import {
	FontSizeOutlined,
	TagOutlined,
	PictureOutlined,
	EditOutlined,
	BorderOutlined,
	QrcodeOutlined,
	BgColorsOutlined,
} from '@ant-design/icons';

const { Text } = Typography;

const TYPES = [
	{ key: 'text', icon: <FontSizeOutlined />, labelKey: 'Text' },
	{ key: 'merge_field', icon: <TagOutlined />, labelKey: 'Merge Field' },
	{ key: 'image', icon: <PictureOutlined />, labelKey: 'Image / Logo' },
	{ key: 'signature', icon: <EditOutlined />, labelKey: 'Signature' },
	{ key: 'shape', icon: <BorderOutlined />, labelKey: 'Line / Shape' },
	{ key: 'qr', icon: <QrcodeOutlined />, labelKey: 'QR Code' },
	{ key: 'background', icon: <BgColorsOutlined />, labelKey: 'Background' },
];

/**
 * The palette.
 *
 * @return {JSX.Element} Palette list.
 */
export default function ElementPalette() {
	return (
		<div className="ppcert-designer__palette-inner">
			<Text type="secondary" className="ppcert-designer__panel-heading">
				{ __( 'Elements', 'pressprimer-certificate' ) }
			</Text>
			<List
				size="small"
				dataSource={ TYPES }
				renderItem={ ( type ) => (
					<Tooltip
						title={ __(
							'Adding elements activates in an upcoming step.',
							'pressprimer-certificate'
						) }
						placement="right"
					>
						<List.Item className="ppcert-designer__palette-item ppcert-designer__palette-item--inert">
							{ type.icon }
							<span>{ type.labelKey }</span>
						</List.Item>
					</Tooltip>
				) }
			/>
		</div>
	);
}
