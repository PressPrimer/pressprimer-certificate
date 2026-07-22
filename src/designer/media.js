/**
 * WP media modal helper (Feature 001 FR-003)
 *
 * Image, signature, and background pickers go through the WordPress
 * media library - attachment IDs only, never raw file handling
 * (CLAUDE.md file-upload rule). wp.media is enqueued by the admin
 * class; when it is unavailable (the Playwright harness) the picker
 * reports unsupported and the panel disables the button.
 */

import { seedAttachmentUrl } from './hooks/useAttachment';

/**
 * Whether the WP media modal is available.
 *
 * @return {boolean} Availability.
 */
export function isMediaAvailable() {
	return !! ( window.wp && window.wp.media );
}

/**
 * Open the media modal for a single image selection.
 *
 * @param {Object}   options          Options.
 * @param {string}   options.title    Modal title.
 * @param {Function} options.onSelect Called with { id, url }.
 */
export function openImagePicker( { title, onSelect } ) {
	if ( ! isMediaAvailable() ) {
		return;
	}

	const frame = window.wp.media( {
		title,
		multiple: false,
		library: { type: 'image' },
	} );

	frame.on( 'select', () => {
		const attachment = frame.state().get( 'selection' ).first().toJSON();

		const url =
			( attachment.sizes &&
				attachment.sizes.large &&
				attachment.sizes.large.url ) ||
			attachment.url ||
			'';

		seedAttachmentUrl( attachment.id, url );
		onSelect( { id: attachment.id, url } );
	} );

	frame.open();
}
