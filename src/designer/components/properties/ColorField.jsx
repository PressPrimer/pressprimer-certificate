/**
 * Color input (Feature 001 FR-005)
 *
 * Preset palettes + hex field via the Ant Design ColorPicker; alpha is
 * disabled so every stored value is a plain hex color the validator's
 * sanitize_hex_color() accepts. Clearable fields store '' (no color).
 */

import { __ } from '@wordpress/i18n';
import { ColorPicker } from 'antd';

const PRESETS = [
	{
		label: __( 'Certificate palette', 'pressprimer-certificate' ),
		colors: [
			'#1f2937',
			'#1f2a44',
			'#b8860b',
			'#7f1d1d',
			'#14532d',
			'#0f4c81',
			'#6b7280',
			'#000000',
			'#ffffff',
		],
	},
];

/**
 * The field.
 *
 * @param {Object}   props           Props.
 * @param {string}   props.value     Hex color or ''.
 * @param {Function} props.onChange  Receives hex string (or '').
 * @param {boolean}  props.clearable Allow '' (no color).
 * @return {JSX.Element} Picker.
 */
export default function ColorField( { value, onChange, clearable = false } ) {
	return (
		<ColorPicker
			size="small"
			disabledAlpha
			showText
			value={ value || null }
			presets={ PRESETS }
			allowClear={ clearable }
			onClear={ clearable ? () => onChange( '' ) : undefined }
			onChangeComplete={ ( color ) => onChange( color.toHexString() ) }
		/>
	);
}
