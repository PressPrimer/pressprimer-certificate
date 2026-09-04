/**
 * Designer extension bridge (2.0, Feature 2.0-006 addon contract)
 *
 * The JS surface addon designer bundles build on. Publishes
 * `window.ppcert_designer_api` (store access without store internals),
 * dispatches the `ppcert-designer-ready` CustomEvent once the API is
 * live, and notifies subscribers on every relevant state change.
 * Addon bundles load after the app (they depend on the
 * 'ppcert-designer' handle, enqueued on the ppcert_designer_enqueued
 * PHP action) and either find the API already present or wait for the
 * ready event.
 *
 * The API is deliberately narrow: layout in/out through the same
 * APPLY_LAYOUT path every free tool uses (so history, dirty state, and
 * validation semantics hold), selection, and read-only boot data.
 * Anything more is a contract change, not a workaround.
 */

import { useEffect, useRef } from '@wordpress/element';
import { useDesignerStore } from '../hooks/useDesignerStore';
import { getBoot } from '../boot';

/**
 * The bridge. Renders nothing; the extension rail slot lives in
 * DesignerApp so its placement is part of the app layout.
 *
 * @return {null} Nothing.
 */
export default function ExtensionBridge() {
	const { state, dispatch } = useDesignerStore();

	const stateRef = useRef( state );
	stateRef.current = state;

	const listenersRef = useRef( new Set() );

	useEffect( () => {
		window.ppcert_designer_api = {
			/**
			 * Read-only boot data (fonts, element types, presets…).
			 *
			 * @return {Object} Boot data.
			 */
			getBoot,

			/**
			 * The current validator-clean layout document (or null
			 * before load).
			 *
			 * @return {Object|null} Layout.
			 */
			getLayout: () => stateRef.current.layout,

			/**
			 * Selected element ids.
			 *
			 * @return {Array} Ids.
			 */
			getSelection: () => stateRef.current.selection,

			/**
			 * The loaded template summary (or null).
			 *
			 * @return {Object|null} Template.
			 */
			getTemplate: () => stateRef.current.template,

			/**
			 * Whether unsaved layout changes exist.
			 *
			 * @return {boolean} Dirty flag.
			 */
			isDirty: () => stateRef.current.dirty,

			/**
			 * Replace the layout document through the standard mutation
			 * path (undo history and dirty tracking included).
			 *
			 * @param {Object} layout Next layout document.
			 */
			applyLayout: ( layout ) =>
				dispatch( { type: 'APPLY_LAYOUT', layout } ),

			/**
			 * Replace the working document without a history push
			 * (extension page switching - undo stays scoped to the
			 * current page; selection clears).
			 *
			 * @param {Object}  layout Next working document.
			 * @param {boolean} dirty  Optional dirty override; omitted
			 *                         keeps the current flag.
			 */
			replaceLayout: ( layout, dirty ) =>
				dispatch( { type: 'REPLACE_LAYOUT', layout, dirty } ),

			/**
			 * Set the canvas selection.
			 *
			 * @param {Array} ids Element ids.
			 */
			setSelection: ( ids ) => dispatch( { type: 'SET_SELECTION', ids } ),

			/**
			 * Replace the template settings through the standard
			 * EDIT_SETTINGS path (persists with the next save; not an
			 * undoable canvas operation - E-005 addon sections edit
			 * their settings_json keys here).
			 *
			 * @param {Object} settings Next full settings object.
			 */
			editSettings: ( settings ) =>
				dispatch( { type: 'EDIT_SETTINGS', settings } ),

			/**
			 * Subscribe to state changes
			 * ({ layout, selection, dirty, template }).
			 *
			 * @param {Function} callback Change listener.
			 * @return {Function} Unsubscribe.
			 */
			subscribe: ( callback ) => {
				listenersRef.current.add( callback );
				return () => listenersRef.current.delete( callback );
			},
		};

		window.dispatchEvent( new CustomEvent( 'ppcert-designer-ready' ) );

		return () => {
			delete window.ppcert_designer_api;
		};
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [] );

	useEffect( () => {
		listenersRef.current.forEach( ( callback ) =>
			callback( {
				layout: state.layout,
				selection: state.selection,
				dirty: state.dirty,
				template: state.template,
			} )
		);
	}, [ state.layout, state.selection, state.dirty, state.template ] );

	return null;
}
