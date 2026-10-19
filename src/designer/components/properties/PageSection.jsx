/**
 * Page section - shown with no selection; edits the page preset (size +
 * orientation) and the document root background (the Background palette
 * entry routes here per FR-003).
 *
 * 2.1 (Feature 2.1-004 FR-002): the Size menu's options pass through
 * `ppcert.designer.pageSizeOptions`; an option may carry `width` and
 * `height` in points (a custom size, such as Educator's wallet cards).
 * A custom page shows as "Custom size" with no fields of its own; the
 * slot under the menu (#ppcert-designer-page-size-extension) is where
 * an addon mounts them. Choosing a named size converts the page.
 */

import { __, sprintf } from '@wordpress/i18n';
import { applyFilters } from '@wordpress/hooks';
import { Button, Segmented, Select, Tooltip, Typography } from 'antd';
import { PictureOutlined, DeleteOutlined } from '@ant-design/icons';
import { useDesignerStore } from '../../hooks/useDesignerStore';
import { updateBackground } from '../../schema/geometry';
import { applyPageResize } from '../../pageResize';
import { useAttachmentUrl } from '../../hooks/useAttachment';
import { isMediaAvailable, openImagePicker } from '../../media';
import { getBoot } from '../../boot';
import ColorField from './ColorField';
import PropRow from './PropRow';
import ImageFormatWarning from './ImageFormatWarning';

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
	const { url, mime } = useAttachmentUrl( background.attachment_id );
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
	const isCustom = 'custom' === layout.page.size;

	const sizeOptions = pageSizeOptions( layout, presets );

	// A custom page shows the option whose dimensions it has (either
	// orientation), else "Custom size".
	const matched = isCustom
		? sizeOptions.find(
				( option ) =>
					option.width &&
					option.height &&
					( ( option.width === layout.page.width &&
						option.height === layout.page.height ) ||
						( option.width === layout.page.height &&
							option.height === layout.page.width ) )
		  )
		: null;

	if (
		isCustom &&
		! matched &&
		! sizeOptions.some( ( option ) => 'custom' === option.value )
	) {
		sizeOptions.push( {
			value: 'custom',
			label: __( 'Custom size', 'pressprimer-certificate' ),
		} );
	}

	const sizeValue = matched ? matched.value : layout.page.size;

	const setSize = ( value ) => {
		const option = sizeOptions.find( ( entry ) => entry.value === value );

		if ( presets[ value ] ) {
			applyPageResize(
				layout,
				{ size: value, orientation: layout.page.orientation },
				dispatch
			);
		} else if ( option && option.width && option.height ) {
			applyPageResize(
				layout,
				{ size: 'custom', width: option.width, height: option.height },
				dispatch
			);
		} else if ( 'custom' === value ) {
			// Custom at the current dimensions: the addon's fields take
			// it from here.
			applyPageResize(
				layout,
				{
					size: 'custom',
					width: layout.page.width,
					height: layout.page.height,
				},
				dispatch
			);
		}
	};

	const setOrientation = ( orientation ) => {
		if ( ! isCustom ) {
			applyPageResize(
				layout,
				{ size: layout.page.size, orientation },
				dispatch
			);
			return;
		}

		// Custom pages turn by swapping their sides.
		if ( orientation !== layout.page.orientation ) {
			applyPageResize(
				layout,
				{
					size: 'custom',
					width: layout.page.height,
					height: layout.page.width,
				},
				dispatch
			);
		}
	};

	return (
		<div className="ppcert-designer__prop-section">
			<Text type="secondary" className="ppcert-designer__panel-heading">
				{ __( 'Page', 'pressprimer-certificate' ) }
			</Text>

			<PropRow label={ __( 'Size', 'pressprimer-certificate' ) }>
				<Select
					size="small"
					value={ sizeValue }
					data-ppcert-prop="page-size"
					popupMatchSelectWidth={ false }
					onChange={ setSize }
					options={ sizeOptions.map( ( option ) => ( {
						value: option.value,
						label: option.label,
					} ) ) }
				/>
			</PropRow>

			{ /* Custom-size slot (2.1, Feature 2.1-004 FR-002): an addon's
			     width and height fields mount their own React root here
			     while the page is a custom size; free renders none. */ }
			{ isCustom ? (
				<div
					id="ppcert-designer-page-size-extension"
					className="ppcert-designer__page-size-extension"
				/>
			) : null }

			<PropRow label={ __( 'Layout', 'pressprimer-certificate' ) }>
				<Segmented
					size="small"
					value={ layout.page.orientation }
					data-ppcert-prop="page-orientation"
					onChange={ setOrientation }
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

			{ background.attachment_id ? (
				<ImageFormatWarning mime={ mime } />
			) : null }
		</div>
	);
}

/**
 * The Size menu's options: the registry sizes, then
 * `ppcert.designer.pageSizeOptions` (2.1). Entries without a string
 * value and a label are dropped.
 *
 * @param {Object} layout  Layout document.
 * @param {Object} presets Boot page_presets map.
 * @return {Array} Options { value, label, width?, height? }.
 */
function pageSizeOptions( layout, presets ) {
	const base = Object.keys( presets ).map( ( size ) => ( {
		value: size,
		// Labels come from the page-size registry (2.1).
		label: presets[ size ].label || size.toUpperCase(),
	} ) );

	const filtered = applyFilters( 'ppcert.designer.pageSizeOptions', base, {
		layout,
		presets,
	} );

	return ( Array.isArray( filtered ) ? filtered : base ).filter(
		( option ) =>
			option &&
			'string' === typeof option.value &&
			'' !== option.value &&
			option.label
	);
}
