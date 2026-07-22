/**
 * Page section - shown with no selection; edits the document root
 * background (the Background palette entry routes here per FR-003).
 */

import { __, sprintf } from '@wordpress/i18n';
import { Button, Tooltip, Typography } from 'antd';
import { PictureOutlined, DeleteOutlined } from '@ant-design/icons';
import { useDesignerStore } from '../../hooks/useDesignerStore';
import { updateBackground } from '../../schema/geometry';
import { useAttachmentUrl } from '../../hooks/useAttachment';
import { isMediaAvailable, openImagePicker } from '../../media';
import ColorField from './ColorField';
import PropRow from './PropRow';

const { Text } = Typography;

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

	return (
		<div className="ppcert-designer__prop-section">
			<Text type="secondary" className="ppcert-designer__panel-heading">
				{ __( 'Page', 'pressprimer-certificate' ) }
			</Text>

			<Text type="secondary" className="ppcert-designer__page-info">
				{ sprintf(
					/* translators: 1: page size, 2: orientation, 3: width, 4: height */
					__(
						'%1$s %2$s — %3$s × %4$s pt',
						'pressprimer-certificate'
					),
					( layout.page.size || '' ).toUpperCase(),
					layout.page.orientation,
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
