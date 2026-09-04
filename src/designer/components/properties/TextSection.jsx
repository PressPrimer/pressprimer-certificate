/**
 * Text section (FR-005) - typography for text and merge_field elements.
 *
 * The font picker lists the registered set (ppcert_designer_fonts via
 * boot data). Bold/italic enable only when the family bundles that
 * variant - synthetic styling never renders (parity rule). Switching
 * to a family that lacks the active variant resets the flag.
 */

import { useEffect, useRef, useState } from '@wordpress/element';
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
import InsertFieldButton from '../InsertFieldButton';
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
 * Build the font Select options, grouped when custom fonts exist.
 *
 * Registry entries carrying group: 'custom' (Educator uploads, free
 * font-pipeline contract) render under "Your fonts" above the bundled
 * set (E-001). Free-only sites have no custom entries and keep the
 * flat, ungrouped list unchanged.
 *
 * @param {Object} fonts Registered font map from boot data.
 * @return {Array} Ant Design Select options (flat or grouped).
 */
export function buildFontOptions( fonts ) {
	const toOption = ( slug ) => ( {
		value: slug,
		label: fonts[ slug ].label || slug,
	} );

	const custom = Object.keys( fonts ).filter(
		( slug ) => 'custom' === fonts[ slug ].group
	);

	if ( ! custom.length ) {
		return Object.keys( fonts ).map( toOption );
	}

	const bundled = Object.keys( fonts ).filter(
		( slug ) => 'custom' !== fonts[ slug ].group
	);

	return [
		{
			label: __( 'Your fonts', 'pressprimer-certificate' ),
			options: custom.map( toOption ),
		},
		{
			label: __( 'Bundled', 'pressprimer-certificate' ),
			options: bundled.map( toOption ),
		},
	];
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
	const contentRef = useRef( null );

	// Last user-placed caret. Null until the user touches the content
	// box - an untouched textarea reports caret 0, and inserting a
	// field at the START of untouched text would surprise; with no
	// caret history the token appends instead.
	const caretRef = useRef( null );

	useEffect( () => {
		setDraft( p.content || '' );
	}, [ element.id, p.content ] );

	useEffect( () => {
		caretRef.current = null;
	}, [ element.id ] );

	const rememberCaret = () => {
		const node = contentRef.current?.resizableTextArea?.textArea;

		if ( node && 'number' === typeof node.selectionStart ) {
			caretRef.current = [ node.selectionStart, node.selectionEnd ];
		}
	};

	const patch = ( propsPatch ) => {
		dispatch( {
			type: 'APPLY_LAYOUT',
			layout: updateElementProps( state.layout, element.id, propsPatch ),
		} );
	};

	/**
	 * Insert a merge token at the content caret (Feature 1.1-001 FR-004).
	 *
	 * The textarea keeps its selection through the blur into the picker,
	 * so the token lands where the user last placed the caret; with no
	 * caret history it appends. One history step, canvas updates
	 * immediately, focus returns with the caret after the token.
	 *
	 * @param {string} key Field key (no braces).
	 */
	const insertField = ( key ) => {
		const token = `{{${ key }}}`;
		const [ start, end ] = caretRef.current || [
			draft.length,
			draft.length,
		];
		const next = draft.slice( 0, start ) + token + draft.slice( end );

		setDraft( next );
		patch( { content: next } );

		const caret = start + token.length;

		caretRef.current = [ caret, caret ];

		window.requestAnimationFrame( () => {
			const el = contentRef.current?.resizableTextArea?.textArea;

			if ( el ) {
				el.focus();
				el.setSelectionRange( caret, caret );
			}
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
				<>
					<Input.TextArea
						ref={ contentRef }
						size="small"
						autoSize={ { minRows: 2, maxRows: 5 } }
						maxLength={ 2000 }
						value={ draft }
						data-ppcert-prop="content"
						onChange={ ( event ) => setDraft( event.target.value ) }
						onSelect={ rememberCaret }
						onKeyUp={ rememberCaret }
						onClick={ rememberCaret }
						onBlur={ () => {
							rememberCaret();
							if ( draft !== p.content ) {
								patch( { content: draft } );
							}
						} }
					/>
					<InsertFieldButton onInsert={ insertField } />
					<Text
						type="secondary"
						className="ppcert-designer__prop-help"
					>
						{ __(
							'Fields drop in at the cursor in the text field above and print real values on each certificate. You can also type them, like {{recipient.display_name}}.',
							'pressprimer-certificate'
						) }
					</Text>
				</>
			) }

			<PropRow label={ __( 'Font', 'pressprimer-certificate' ) }>
				<Select
					size="small"
					value={ p.font_family }
					onChange={ onFamilyChange }
					popupMatchSelectWidth={ false }
					data-ppcert-prop="font_family"
					options={ buildFontOptions( fonts ) }
					// Menu entries render in their actual face (the
					// designer inlines every bundled @font-face), like
					// the Appearance tab's Default font field; the
					// closed control stays plain for compactness.
					optionRender={ ( option ) => (
						<span
							style={ {
								fontFamily: `"${ option.value }"`,
								fontSize: 16,
							} }
						>
							{ option.label }
						</span>
					) }
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
