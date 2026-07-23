/**
 * Color input (Feature 001 FR-005)
 *
 * Preset palettes + hex field via the Ant Design ColorPicker; alpha is
 * disabled so every stored value is a plain hex color the validator's
 * sanitize_hex_color() accepts. Clearable fields store '' (no color).
 */

import { __ } from '@wordpress/i18n';
import { ColorPicker } from 'antd';
import { getBoot } from '../../boot';

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
 * Preset groups with the site's Appearance brand colors first.
 *
 * @return {Array} Preset groups.
 */
function presetGroups() {
	const appearance = getBoot().appearance || {};
	const brand = [ appearance.primary_color, appearance.accent_color ].filter(
		Boolean
	);

	if ( ! brand.length ) {
		return PRESETS;
	}

	return [
		{
			label: __( 'Brand colors', 'pressprimer-certificate' ),
			colors: brand,
		},
		...PRESETS,
	];
}

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
			presets={ presetGroups() }
			allowClear={ clearable }
			onClear={ clearable ? () => onChange( '' ) : undefined }
			onChangeComplete={ ( color ) => onChange( color.toHexString() ) }
		/>
	);
}
