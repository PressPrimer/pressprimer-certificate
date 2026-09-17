/**
 * Image format warning (Feature 2.0-010 FR-004)
 *
 * Shown under an image, signature, or background picker when the chosen
 * attachment is a format this server cannot place in PDFs. The list of
 * renderable types comes from the server (designer boot `image_mimes`,
 * the renderer's own allowlist); when the boot carries no list the
 * component stays silent rather than guessing. Warn only: saving and
 * publishing are never blocked, and the picker keeps offering every
 * image.
 *
 * @since 2.0.0
 */

import { __, sprintf } from '@wordpress/i18n';
import { Alert } from 'antd';
import { getBoot } from '../../boot';

/**
 * Human labels for the formats WordPress accepts that a server may not
 * render. Anything else falls back to the upper-cased subtype.
 */
const FORMAT_LABELS = {
	'image/webp': 'WebP',
	'image/avif': 'AVIF',
	'image/heic': 'HEIC',
	'image/heif': 'HEIF',
	'image/bmp': 'BMP',
	'image/tiff': 'TIFF',
	'image/svg+xml': 'SVG',
	'image/x-icon': 'ICO',
};

/**
 * Label a MIME type for the warning sentence.
 *
 * @param {string} mime MIME type.
 * @return {string} Format label.
 */
export function formatLabel( mime ) {
	if ( FORMAT_LABELS[ mime ] ) {
		return FORMAT_LABELS[ mime ];
	}

	const subtype = String( mime ).split( '/' )[ 1 ] || '';

	return subtype.replace( /^x-/, '' ).toUpperCase();
}

/**
 * Whether a MIME type is an image this server cannot render.
 *
 * @param {string}     mime  MIME type ('' while loading).
 * @param {Array|null} mimes Renderable list from boot (null = unknown).
 * @return {boolean} True when a warning is due.
 */
export function isUnsupportedImage( mime, mimes ) {
	return (
		Array.isArray( mimes ) &&
		'string' === typeof mime &&
		mime.startsWith( 'image/' ) &&
		! mimes.includes( mime )
	);
}

/**
 * The warning.
 *
 * @param {Object} props      Props.
 * @param {string} props.mime The attachment's MIME type.
 * @return {JSX.Element|null} Alert, or null when the image renders here.
 */
export default function ImageFormatWarning( { mime } ) {
	if ( ! isUnsupportedImage( mime, getBoot().image_mimes ) ) {
		return null;
	}

	return (
		<Alert
			type="warning"
			showIcon
			className="ppcert-designer__format-warning"
			data-ppcert-prop="format-warning"
			message={ sprintf(
				/* translators: %s: image format name, e.g. WebP */
				__(
					'This image is %s, which this server cannot place in PDFs. Use PNG or JPG.',
					'pressprimer-certificate'
				),
				formatLabel( mime )
			) }
		/>
	);
}
