/**
 * Attachment URL resolution (Feature 001 FR-003)
 *
 * Layouts store attachment IDs only (schema rule); the canvas resolves
 * display URLs through the core media REST route with a module-level
 * cache. The media modal seeds the cache on pick so the canvas renders
 * without a round-trip; the Playwright harness seeds it directly.
 */

import { useEffect, useState } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';

const cache = new Map();
const pending = new Map();

/**
 * Seed the attachment URL cache.
 *
 * @param {number} id  Attachment id.
 * @param {string} url Display URL.
 */
export function seedAttachmentUrl( id, url ) {
	cache.set( Number( id ), url );
}

/**
 * Resolve an attachment id to a display URL.
 *
 * @param {number} id Attachment id (0 = none).
 * @return {Object} { url, missing } - url '' while loading or when none.
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
					path: `/wp/v2/media/${ attachmentId }?_fields=source_url`,
				} )
					.then( ( media ) => {
						cache.set( attachmentId, media.source_url || '' );
					} )
					.catch( () => {
						cache.set( attachmentId, '' );
					} )
					.finally( () => pending.delete( attachmentId ) )
			);
		}

		let active = true;

		pending.get( attachmentId ).then( () => {
			if ( active ) {
				setMissing( '' === cache.get( attachmentId ) );
				setTick( ( t ) => t + 1 );
			}
		} );

		return () => {
			active = false;
		};
	}, [ attachmentId ] );

	if ( ! attachmentId ) {
		return { url: '', missing: false };
	}

	const cached = cache.get( attachmentId );

	return { url: cached || '', missing: missing || '' === cached };
}
