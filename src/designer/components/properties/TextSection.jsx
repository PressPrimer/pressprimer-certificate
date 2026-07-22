/**
 * Text section (FR-005) - typography for text and merge_field elements.
 *
 * The font picker lists the registered set (ppcert_designer_fonts via
 * boot data). Bold/italic enable only when the family bundles that
 * variant - synthetic styling never renders (parity rule). Switching
 * to a family that lacks the active variant resets the flag.
 */

import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import {
	Button,
	Input,
	InputNumber,
	Segmented,
	Select,
	Typography,
} from 'antd';
import {
	AlignLeftOutlined,
	AlignCenterOutlined,
	AlignRightOutlined,
	BoldOutlined,
	ItalicOutlined,
} from '@ant-design/icons';
import { useDesignerStore } from '../../hooks/useDesignerStore';
import { updateElementProps } from '../../schema/geometry';
import { getBoot, getFontVariants } from '../../boot';
import ColorField from './ColorField';
import PropRow from './PropRow';

const { Text } = Typography;

/**
 * Whether a bold/italic combination has a bundled variant.
 *
 * @param {Object}  variants Family variants map.
 * @param {boolean} bold     Bold flag.
 * @param {boolean} italic   Italic flag.
 * @return {boolean} Variant availability.
 */
function hasVariant( variants, bold, italic ) {
	if ( bold && italic ) {
		return !! variants.bold_italic;
	}
	if ( bold ) {
		return !! variants.bold;
	}
	if ( italic ) {
		return !! variants.italic;
	}
	return !! variants.regular;
}

/**
 * The section.
 *
 * @param {Object} props         Props.
 * @param {Object} props.element Selected element.
 * @return {JSX.Element} Section.
 */
export default function TextSection( { element } ) {
	const { state, dispatch } = useDesignerStore();
	const p = element.props;
	const fonts = getBoot().fonts;
	const variants = getFontVariants( p.font_family );

	// Content commits on blur so typing is not one history step per key.
	const [ draft, setDraft ] = useState( p.content || '' );

	useEffect( () => {
		setDraft( p.content || '' );
	}, [ element.id, p.content ] );

	const patch = ( propsPatch ) => {
		dispatch( {
			type: 'APPLY_LAYOUT',
			layout: updateElementProps( state.layout, element.id, propsPatch ),
		} );
	};

	const onFamilyChange = ( family ) => {
		const next = getFontVariants( family );
		const reset = {};

		// Gating on switch: drop flags the new family cannot render.
		if ( p.bold && ! hasVariant( next, true, false ) ) {
			reset.bold = false;
		}
		if ( p.italic && ! hasVariant( next, false, true ) ) {
			reset.italic = false;
		}
		if ( p.bold && p.italic && ! hasVariant( next, true, true ) ) {
			reset.bold = false;
			reset.italic = false;
		}

		patch( { font_family: family, ...reset } );
	};

	const boldAvailable = hasVariant( variants, true, p.italic );
	const italicAvailable = hasVariant( variants, p.bold, true );

	return (
		<div className="ppcert-designer__prop-section">
			<Text type="secondary" className="ppcert-designer__panel-heading">
				{ __( 'Text', 'pressprimer-certificate' ) }
			</Text>

			{ 'text' === element.type && (
				<Input.TextArea
					size="small"
					autoSize={ { minRows: 2, maxRows: 5 } }
					maxLength={ 2000 }
					value={ draft }
					data-ppcert-prop="content"
					onChange={ ( event ) => setDraft( event.target.value ) }
					onBlur={ () => {
						if ( draft !== p.content ) {
							patch( { content: draft } );
						}
					} }
				/>
			) }

			<PropRow label={ __( 'Font', 'pressprimer-certificate' ) }>
				<Select
					size="small"
					value={ p.font_family }
					onChange={ onFamilyChange }
					popupMatchSelectWidth={ false }
					data-ppcert-prop="font_family"
					options={ Object.keys( fonts ).map( ( slug ) => ( {
						value: slug,
						label: fonts[ slug ].label || slug,
					} ) ) }
					className="ppcert-designer__prop-wide"
				/>
			</PropRow>

			<PropRow label={ __( 'Size', 'pressprimer-certificate' ) }>
				<InputNumber
					size="small"
					min={ 6 }
					max={ 200 }
					step={ 1 }
					value={ p.font_size }
					data-ppcert-prop="font_size"
					onChange={ ( value ) =>
						'number' === typeof value &&
						patch( { font_size: value } )
					}
				/>
			</PropRow>

			<PropRow label={ __( 'Line height', 'pressprimer-certificate' ) }>
				<InputNumber
					size="small"
					min={ 0.8 }
					max={ 3 }
					step={ 0.1 }
					value={ p.line_height }
					data-ppcert-prop="line_height"
					onChange={ ( value ) =>
						'number' === typeof value &&
						patch( { line_height: value } )
					}
				/>
			</PropRow>

			<PropRow label={ __( 'Style', 'pressprimer-certificate' ) }>
				<Button
					size="small"
					type={ p.bold ? 'primary' : 'default' }
					icon={ <BoldOutlined /> }
					disabled={ ! boldAvailable }
					data-ppcert-prop="bold"
					title={
						boldAvailable
							? undefined
							: __(
									'This font does not bundle a bold variant.',
									'pressprimer-certificate'
							  )
					}
					onClick={ () => patch( { bold: ! p.bold } ) }
				/>
				<Button
					size="small"
					type={ p.italic ? 'primary' : 'default' }
					icon={ <ItalicOutlined /> }
					disabled={ ! italicAvailable }
					data-ppcert-prop="italic"
					title={
						italicAvailable
							? undefined
							: __(
									'This font does not bundle an italic variant.',
									'pressprimer-certificate'
							  )
					}
					onClick={ () => patch( { italic: ! p.italic } ) }
				/>
			</PropRow>

			<PropRow label={ __( 'Align', 'pressprimer-certificate' ) }>
				<Segmented
					size="small"
					value={ p.align }
					data-ppcert-prop="align"
					onChange={ ( align ) => patch( { align } ) }
					options={ [
						{
							value: 'left',
							icon: <AlignLeftOutlined />,
						},
						{
							value: 'center',
							icon: <AlignCenterOutlined />,
						},
						{
							value: 'right',
							icon: <AlignRightOutlined />,
						},
					] }
				/>
			</PropRow>

			<PropRow label={ __( 'Color', 'pressprimer-certificate' ) }>
				<ColorField
					value={ p.color }
					onChange={ ( color ) => patch( { color } ) }
				/>
			</PropRow>
		</div>
	);
}
