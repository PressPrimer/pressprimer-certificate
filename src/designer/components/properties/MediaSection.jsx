/**
 * Image / signature section - media selection via the WP media modal.
 *
 * Attachment IDs only (schema + security rule); the modal seeds the
 * URL cache so the canvas renders immediately. When wp.media is not
 * present (test harness) the picker disables with an explanation.
 */

import { __ } from '@wordpress/i18n';
import { Button, Select, Slider, Tooltip, Typography } from 'antd';
import { PictureOutlined, DeleteOutlined } from '@ant-design/icons';
import { useDesignerStore } from '../../hooks/useDesignerStore';
import { updateElementProps } from '../../schema/geometry';
import { useAttachmentUrl } from '../../hooks/useAttachment';
import { isMediaAvailable, openImagePicker } from '../../media';
import PropRow from './PropRow';

const { Text } = Typography;

/**
 * The section.
 *
 * @param {Object} props         Props.
 * @param {Object} props.element Selected element.
 * @return {JSX.Element} Section.
 */
export default function MediaSection( { element } ) {
	const { state, dispatch } = useDesignerStore();
	const p = element.props;
	const { url } = useAttachmentUrl( p.attachment_id );
	const available = isMediaAvailable();

	const patch = ( propsPatch ) => {
		dispatch( {
			type: 'APPLY_LAYOUT',
			layout: updateElementProps( state.layout, element.id, propsPatch ),
		} );
	};

	const pick = () =>
		openImagePicker( {
			title:
				'signature' === element.type
					? __( 'Choose signature image', 'pressprimer-certificate' )
					: __( 'Choose image', 'pressprimer-certificate' ),
			onSelect: ( { id } ) => patch( { attachment_id: id } ),
		} );

	const pickButton = (
		<Button
			size="small"
			icon={ <PictureOutlined /> }
			disabled={ ! available }
			onClick={ pick }
			data-ppcert-prop="attachment-pick"
		>
			{ p.attachment_id
				? __( 'Replace', 'pressprimer-certificate' )
				: __( 'Choose image', 'pressprimer-certificate' ) }
		</Button>
	);

	return (
		<div className="ppcert-designer__prop-section">
			<Text type="secondary" className="ppcert-designer__panel-heading">
				{ 'signature' === element.type
					? __( 'Signature', 'pressprimer-certificate' )
					: __( 'Image', 'pressprimer-certificate' ) }
			</Text>

			{ url ? (
				<div className="ppcert-designer__media-preview">
					<img src={ url } alt="" />
				</div>
			) : null }

			<PropRow label={ __( 'Media', 'pressprimer-certificate' ) }>
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
				{ p.attachment_id ? (
					<Button
						size="small"
						icon={ <DeleteOutlined /> }
						data-ppcert-prop="attachment-clear"
						onClick={ () => patch( { attachment_id: 0 } ) }
					/>
				) : null }
			</PropRow>

			<PropRow label={ __( 'Fit', 'pressprimer-certificate' ) }>
				<Select
					size="small"
					value={ p.fit }
					data-ppcert-prop="fit"
					onChange={ ( fit ) => patch( { fit } ) }
					options={ [
						{
							value: 'contain',
							label: __( 'Contain', 'pressprimer-certificate' ),
						},
						{
							value: 'cover',
							label: __( 'Cover', 'pressprimer-certificate' ),
						},
						{
							value: 'stretch',
							label: __( 'Stretch', 'pressprimer-certificate' ),
						},
					] }
				/>
			</PropRow>

			<PropRow label={ __( 'Opacity', 'pressprimer-certificate' ) }>
				<Slider
					min={ 0 }
					max={ 1 }
					step={ 0.05 }
					value={ undefined === p.opacity ? 1 : p.opacity }
					onChangeComplete={ ( opacity ) => patch( { opacity } ) }
					className="ppcert-designer__prop-wide"
				/>
			</PropRow>
		</div>
	);
}
