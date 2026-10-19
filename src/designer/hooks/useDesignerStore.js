/**
 * Designer store (Feature 001 TR-002)
 *
 * Single reducer store via useReducer + context:
 * { template, layout, selection, history, dirty, triggers }.
 *
 * Layout mutations go through action creators that also push history -
 * no component mutates layout objects directly. Mutation actions grow in
 * Prompts 3.2+ (canvas interactions); 3.1 ships the load flow and the
 * history plumbing they build on.
 *
 * @package
 */

import { createContext, useContext, useReducer } from '@wordpress/element';
import { migrateLayout } from '../schema/migrations';

const HISTORY_LIMIT = 50;

export const initialState = {
	template: null, // { id, title, status, page_size, orientation, updated_at }
	layout: null, // The validator-clean layout document.
	selection: [], // Selected element ids.
	// pastStates/futureStates run parallel to past/future: opaque addon
	// state attached to each undo step (2.1, attachHistoryState).
	history: { past: [], future: [], pastStates: [], futureStates: [] },
	// The last undo/redo, delivered to extension subscribers once:
	// { seq, direction: 'undo'|'redo', state }.
	historyEvent: null,
	dirty: false,
	triggers: [],
	// Trigger edits live outside the undo stack (FR-008) and save with
	// the toolbar Save alongside the layout (FR-007).
	triggersDirty: false,
};

/**
 * Push the current layout onto the undo stack (bounded, FR-008).
 *
 * @param {Object} history Current history.
 * @param {Object} layout  Layout being replaced.
 * @return {Object} Next history.
 */
function pushHistory( history, layout ) {
	const past = [ ...history.past, layout ].slice( -HISTORY_LIMIT );
	const pastStates = [ ...( history.pastStates || [] ), null ].slice(
		-HISTORY_LIMIT
	);
	return { past, future: [], pastStates, futureStates: [] };
}

/**
 * The next history event (undo/redo notification for extensions).
 *
 * @param {Object|null} previous  Previous event.
 * @param {string}      direction 'undo' | 'redo'.
 * @param {*}           stepState The step's attached addon state.
 * @return {Object} Event.
 */
function historyEvent( previous, direction, stepState ) {
	return {
		seq: ( previous ? previous.seq : 0 ) + 1,
		direction,
		state: undefined === stepState ? null : stepState,
	};
}

/**
 * The designer reducer.
 *
 * @param {Object} state  Current state.
 * @param {Object} action Dispatched action.
 * @return {Object} Next state.
 */
export function designerReducer( state, action ) {
	switch ( action.type ) {
		case 'LOAD_TEMPLATE':
			return {
				...initialState,
				template: action.template,
				// Older-version documents migrate on load; the in-memory
				// document is always current-version and saves persist it.
				layout: migrateLayout( action.layout ),
				triggers: action.triggers || [],
			};

		case 'APPLY_LAYOUT':
			// The single entry point for layout mutations (action creators
			// in 3.2+ funnel through this): history push + dirty flag.
			return {
				...state,
				layout: action.layout,
				history: pushHistory( state.history, state.layout ),
				dirty: true,
			};

		case 'REPLACE_LAYOUT':
			// Extension page switching (Feature 2.0-006 / Educator E-002):
			// swaps the working document WITHOUT a history push - a page
			// switch is navigation, not an edit, so undo stays scoped to
			// the current page. Selection clears (it referenced the old
			// page's elements); dirty is the caller's call (page
			// structure changes are edits, plain switches are not).
			return {
				...state,
				layout: action.layout,
				selection: [],
				history: {
					past: [],
					future: [],
					pastStates: [],
					futureStates: [],
				},
				dirty:
					'boolean' === typeof action.dirty
						? action.dirty
						: state.dirty,
			};

		case 'SET_SELECTION':
			return { ...state, selection: action.ids };

		case 'RENAME_TEMPLATE':
			// Title persists with the next save (not part of layout
			// history - renames are not undoable canvas operations).
			return {
				...state,
				template: { ...state.template, title: action.title },
				dirty: true,
			};

		case 'EDIT_SETTINGS':
			// Template settings (validity) persist with the next save;
			// like renames, they are not undoable canvas operations.
			return {
				...state,
				template: { ...state.template, settings: action.settings },
				dirty: true,
			};

		case 'EDIT_ISSUER':
			// Issuer assignment (2.0, School contract): a top-level
			// template column, persisted with the next save like renames.
			return {
				...state,
				template: {
					...state.template,
					issuer_id: parseInt( action.issuerId, 10 ) || 0,
				},
				dirty: true,
			};

		case 'SET_TRIGGERS':
			// Server truth (load or post-save adoption): not dirty.
			return {
				...state,
				triggers: action.triggers,
				triggersDirty: false,
			};

		case 'EDIT_TRIGGERS':
			// Panel edits: pending until the next save.
			return {
				...state,
				triggers: action.triggers,
				triggersDirty: true,
			};

		case 'ATTACH_HISTORY_STATE': {
			// Opaque addon state on the most recent undo step (2.1,
			// Feature 2.1-004 FR-006): delivered back to subscribers when
			// that step is undone or redone.
			const count = state.history.past.length;

			if ( 0 === count ) {
				return state;
			}

			const pastStates = [ ...( state.history.pastStates || [] ) ];

			while ( pastStates.length < count ) {
				pastStates.unshift( null );
			}

			pastStates[ count - 1 ] = action.state;

			return {
				...state,
				history: { ...state.history, pastStates },
			};
		}

		case 'UNDO': {
			if ( state.history.past.length === 0 ) {
				return state;
			}
			const past = state.history.past.slice( 0, -1 );
			const restored =
				state.history.past[ state.history.past.length - 1 ];
			const states = state.history.pastStates || [];
			const stepState =
				states.length === state.history.past.length
					? states[ states.length - 1 ]
					: null;
			return {
				...state,
				layout: restored,
				history: {
					past,
					future: [ state.layout, ...state.history.future ].slice(
						0,
						HISTORY_LIMIT
					),
					pastStates: states.slice( 0, past.length ),
					futureStates: [
						stepState,
						...( state.history.futureStates || [] ),
					].slice( 0, HISTORY_LIMIT ),
				},
				historyEvent: historyEvent(
					state.historyEvent,
					'undo',
					stepState
				),
				dirty: true,
			};
		}

		case 'REDO': {
			if ( state.history.future.length === 0 ) {
				return state;
			}
			const [ next, ...future ] = state.history.future;
			const [ stepState = null, ...futureStates ] =
				state.history.futureStates || [];
			return {
				...state,
				layout: next,
				history: {
					past: [ ...state.history.past, state.layout ].slice(
						-HISTORY_LIMIT
					),
					future,
					pastStates: [
						...( state.history.pastStates || [] ),
						stepState,
					].slice( -HISTORY_LIMIT ),
					futureStates,
				},
				historyEvent: historyEvent(
					state.historyEvent,
					'redo',
					stepState
				),
				dirty: true,
			};
		}

		case 'MARK_SAVED':
			return {
				...state,
				dirty: false,
				template: action.template || state.template,
			};

		case 'ADOPT_SAVED':
			// A successful save: the client adopts the validator's REBUILT
			// document verbatim (FR-007). No history push - the visual
			// state is (near-)identical and undo should step editing
			// history, not saves.
			return {
				...state,
				layout: action.layout,
				template: action.template || state.template,
				dirty: false,
			};

		default:
			return state;
	}
}

const DesignerContext = createContext( null );

/**
 * Store provider.
 *
 * @param {Object} props          Props.
 * @param {*}      props.children Children.
 * @return {JSX.Element} Provider.
 */
export function DesignerProvider( { children } ) {
	const [ state, dispatch ] = useReducer( designerReducer, initialState );

	return (
		<DesignerContext.Provider value={ { state, dispatch } }>
			{ children }
		</DesignerContext.Provider>
	);
}

/**
 * Store hook.
 *
 * @return {Object} { state, dispatch }.
 */
export function useDesignerStore() {
	const store = useContext( DesignerContext );

	if ( ! store ) {
		throw new Error(
			'useDesignerStore must be used inside DesignerProvider'
		);
	}

	return store;
}
