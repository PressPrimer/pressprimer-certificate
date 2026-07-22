/**
 * Page section - shown with no selection; edits the page preset (size +
 * orientation) and the document root background (the Background palette
 * entry routes here per FR-003).
 */

import { __, sprintf } from '@wordpress/i18n';
import { Button, Segmented, Select, Tooltip, Typography } from 'antd';
import { PictureOutlined, DeleteOutlined } from '@ant-design/icons';
import { useDesignerStore } from '../../hooks/useDesignerStore';
import { updateBackground, updatePagePreset } from '../../schema/geometry';
import { useAttachmentUrl } from '../../hooks/useAttachment';
import { isMediaAvailable, openImagePicker } from '../../media';
import { getBoot } from '../../boot';
import ColorField from './ColorField';
import PropRow from './PropRow';

const { Text } = Typography;

const SIZE_LABELS = {
	a4: 'A4',
	letter: __( 'Letter', 'pressprimer-certificate' ),
};

/**
 * The section.
 *
 * @return {JSX.Element} Section.
 */
export default function PageSection() {
	const { state, dispatch } = useDesignerStore();
	const layout = state.layout;
	const background = layout.background || {};
	const { url } = useAttachmentUrl( background.attachment_id );
	const available = isMediaAvailable();

	const patch = ( backgroundPatch ) => {
		dispatch( {
			type: 'APPLY_LAYOUT',
			layout: updateBackground( layout, backgroundPatch ),
		} );
	};

	const pick = () =>
		openImagePicker( {
			title: __( 'Choose background image', 'pressprimer-certificate' ),
			onSelect: ( { id } ) => patch( { attachment_id: id } ),
		} );

	const pickButton = (
		<Button
			size="small"
			icon={ <PictureOutlined /> }
			disabled={ ! available }
			onClick={ pick }
			data-ppcert-prop="background-pick"
		>
			{ background.attachment_id
				? __( 'Replace', 'pressprimer-certificate' )
				: __( 'Choose image', 'pressprimer-certificate' ) }
		</Button>
	);

	const presets = getBoot().page_presets;

	const setPreset = ( size, orientation ) => {
		dispatch( {
			type: 'APPLY_LAYOUT',
			layout: updatePagePreset( layout, size, orientation, presets ),
		} );
	};

	return (
		<div className="ppcert-designer__prop-section">
			<Text type="secondary" className="ppcert-designer__panel-heading">
				{ __( 'Page', 'pressprimer-certificate' ) }
			</Text>

			<PropRow label={ __( 'Size', 'pressprimer-certificate' ) }>
				<Select
					size="small"
					value={ layout.page.size }
					data-ppcert-prop="page-size"
					popupMatchSelectWidth={ false }
					onChange={ ( size ) =>
						setPreset( size, layout.page.orientation )
					}
					options={ Object.keys( presets ).map( ( size ) => ( {
						value: size,
						label: SIZE_LABELS[ size ] || size.toUpperCase(),
					} ) ) }
				/>
			</PropRow>

			<PropRow label={ __( 'Layout', 'pressprimer-certificate' ) }>
				<Segmented
					size="small"
					value={ layout.page.orientation }
					data-ppcert-prop="page-orientation"
					onChange={ ( orientation ) =>
						setPreset( layout.page.size, orientation )
					}
					options={ [
						{
							value: 'landscape',
							label: __( 'Landscape', 'pressprimer-certificate' ),
						},
						{
							value: 'portrait',
							label: __( 'Portrait', 'pressprimer-certificate' ),
						},
					] }
				/>
			</PropRow>

			<Text type="secondary" className="ppcert-designer__page-info">
				{ sprintf(
					/* translators: 1: width, 2: height */
					__( '%1$s × %2$s pt', 'pressprimer-certificate' ),
					layout.page.width,
					layout.page.height
				) }
			</Text>

			<PropRow label={ __( 'Background', 'pressprimer-certificate' ) }>
				<ColorField
					value={ background.color || '' }
					clearable
					onChange={ ( color ) => patch( { color } ) }
				/>
			</PropRow>

			{ url ? (
				<div className="ppcert-designer__media-preview">
					<img src={ url } alt="" />
				</div>
			) : null }

			<PropRow label={ __( 'Image', 'pressprimer-certificate' ) }>
				{ available ? (
					pickButton
				) : (
					<Tooltip
						title={ __(
							'The WordPress media library is unavailable here.',
							'pressprimer-certificate'
						) }
					>
						{ pickButton }
					</Tooltip>
				) }
				{ background.attachment_id ? (
					<Button
						size="small"
						icon={ <DeleteOutlined /> }
						data-ppcert-prop="background-clear"
						onClick={ () => patch( { attachment_id: 0 } ) }
					/>
				) : null }
			</PropRow>
		</div>
	);
}
