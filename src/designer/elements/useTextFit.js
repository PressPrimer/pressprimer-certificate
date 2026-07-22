/**
 * Text-fit hook for canvas text elements (Feature 007 FR-004)
 *
 * Measures with the browser and applies the shared fitting rule.
 * Re-renders once webfonts finish loading so measurements are taken
 * against the real bundled variants, not fallback metrics.
 */

import { useEffect, useState } from '@wordpress/element';
import { fitTextInBox } from '../schema/fitting';

/**
 * Fit display text into an element box.
 *
 * @param {string} text  Display text.
 * @param {Object} box   { w, h }.
 * @param {Object} props Text props.
 * @return {Object} { size, text, truncated }.
 */
export function useTextFit( text, box, props ) {
	const [ , setFontTick ] = useState( 0 );

	useEffect( () => {
		let active = true;

		if ( document.fonts && document.fonts.ready ) {
			document.fonts.ready.then( () => {
				if ( active ) {
					setFontTick( ( tick ) => tick + 1 );
				}
			} );
		}

		return () => {
			active = false;
		};
	}, [] );

	return fitTextInBox( text || '', box, props );
}
