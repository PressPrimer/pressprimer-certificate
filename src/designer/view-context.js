/**
 * Designer view context (Feature 001 FR-004)
 *
 * View-only state the canvas elements read: the raw-token toggle and
 * the registry sample map. Kept out of the reducer store deliberately -
 * TR-002 scopes the store to document state; token view is a lens, not
 * a document property (it never dirties or saves).
 */

import { createContext, useContext } from '@wordpress/element';

export const DesignerViewContext = createContext( {
	tokenView: false,
	samples: {},
} );

/**
 * View state hook.
 *
 * @return {Object} { tokenView, samples }.
 */
export function useDesignerView() {
	return useContext( DesignerViewContext );
}
