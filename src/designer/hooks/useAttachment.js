/**
 * Attachment URL resolution (Feature 001 FR-003)
 *
 * Layouts store attachment IDs only (schema rule); the canvas resolves
 * display URLs through the core media REST route with a module-level
 * cache. The media modal seeds the cache on pick so the canvas renders
 * without a round-trip; the Playwright harness seeds it directly.
 *
 * Since 2.0 the cache also carries each attachment's MIME type (from
 * the same media request) for the image-format warning (Feature
 * 2.0-010 FR-004).
 */

import { useEffect, useState } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';

const cache = new Map();
const pending = new Map();

/**
 * Seed the attachment cache.
 *
 * @param {number} id   Attachment id.
 * @param {string} url  Display URL.
 * @param {string} mime MIME type (optional; '' = unknown, never warns).
 */
export function seedAttachmentUrl( id, url, mime = '' ) {
	cache.set( Number( id ), { url: url || '', mime: mime || '' } );
}

/**
 * Resolve an attachment id to a display URL and MIME type.
 *
 * @param {number} id Attachment id (0 = none).
 * @return {Object} { url, missing, mime } - url and mime '' while loading or when none.
 */
export function useAttachmentUrl( id ) {
	const attachmentId = Number( id ) || 0;

	const [ , setTick ] = useState( 0 );
	const [ missing, setMissing ] = useState( false );

	useEffect( () => {
		setMissing( false );

		if ( ! attachmentId || cache.has( attachmentId ) ) {
			return;
		}

		if ( ! pending.has( attachmentId ) ) {
			pending.set(
				attachmentId,
				apiFetch( {
					path: `/wp/v2/media/${ attachmentId }?_fields=source_url,mime_type`,
				} )
					.then( ( media ) => {
						cache.set( attachmentId, {
							url: media.source_url || '',
							mime: media.mime_type || '',
						} );
					} )
					.catch( () => {
						cache.set( attachmentId, { url: '', mime: '' } );
					} )
					.finally( () => pending.delete( attachmentId ) )
			);
		}

		let active = true;

		pending.get( attachmentId ).then( () => {
			if ( active ) {
				setMissing( '' === ( cache.get( attachmentId ) || {} ).url );
				setTick( ( t ) => t + 1 );
			}
		} );

		return () => {
			active = false;
		};
	}, [ attachmentId ] );

	if ( ! attachmentId ) {
		return { url: '', missing: false, mime: '' };
	}

	const cached = cache.get( attachmentId );

	return {
		url: cached ? cached.url : '',
		missing: missing || ( !! cached && '' === cached.url ),
		mime: cached ? cached.mime : '',
	};
}
