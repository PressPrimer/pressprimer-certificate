/**
 * Attachment preview URL hook (shared by the Appearance and Email tabs)
 *
 * @package
 * @since 2.1.0
 */

import { useEffect } from '@wordpress/element';

/**
 * Resolve an attachment id to a preview URL through wp.media.
 *
 * @param {number}   attachmentId Attachment id.
 * @param {Function} setUrl       State setter for the URL.
 */
export default function useAttachmentUrl( attachmentId, setUrl ) {
	useEffect( () => {
		let cancelled = false;

		if ( ! attachmentId || ! window.wp || ! window.wp.media ) {
			setUrl( '' );
			return undefined;
		}

		const attachment = window.wp.media.attachment( attachmentId );

		attachment.fetch().then( () => {
			if ( cancelled ) {
				return;
			}

			const sizes = attachment.get( 'sizes' ) || {};
			setUrl(
				( sizes.medium && sizes.medium.url ) ||
					attachment.get( 'url' ) ||
					''
			);
		} );

		return () => {
			cancelled = true;
		};
	}, [ attachmentId, setUrl ] );
}
