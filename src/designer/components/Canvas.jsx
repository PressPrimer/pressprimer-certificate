/**
 * Designer canvas - true-size page render + interactions (FR-002)
 *
 * Absolutely positioned DOM over the page (never <canvas> - text metrics
 * must match the browser for parity screenshots). Zoom is a CSS scale on
 * the true-size page; all stored coordinates are points, so pointer
 * deltas divide by the scale (zoom-independent storage).
 *
 * Prompt 3.2 scope: zoom (50-200%, fit-width default), selection
 * (click / shift-click / marquee), drag, 8-handle resize, arrow nudge
 * (1pt / Shift 10pt), z-order context menu. Snapping and alignment
 * guides arrive in 3.5; per-type element components in 3.3.
 */

import {
	useEffect,
	useLayoutEffect,
	useRef,
	useState,
} from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { Dropdown } from 'antd';
import { useDesignerStore } from '../hooks/useDesignerStore';
import { getElementComponent } from '../elements';
import { useAttachmentUrl } from '../hooks/useAttachment';
import {
	HANDLES,
	NUDGE_PT,
	NUDGE_SHIFT_PT,
	cornersToRect,
	marqueeHits,
	moveElements,
	pxToPt,
	removeElements,
	resizeBox,
	resizeElement,
	reorderElement,
	SAFE_MARGIN_PT,
	snapDrag,
} from '../schema/geometry';
import Ruler, { RULER_PX } from './Ruler';

const DRAG_THRESHOLD_PX = 3;
const FIT_MIN = 0.5;
const FIT_MAX = 2;

/**
 * The canvas.
 *
 * @param {Object}          props        Props.
 * @param {Object}          props.layout Layout document.
 * @param {string | number} props.zoom   'fit' or a scale (0.5-2).
 * @param {boolean}         props.rulers Show the point rulers.
 * @return {JSX.Element} Canvas surface.
 */
export default function Canvas( { layout, zoom, rulers = true } ) {
	const { state, dispatch } = useDesignerStore();
	const selection = state.selection;

	const surfaceRef = useRef( null );
	const pageRef = useRef( null );

	const [ fitScale, setFitScale ] = useState( 1 );
	const [ gesture, setGesture ] = useState( null );
	const [ menu, setMenu ] = useState( null );

	const gestureRef = useRef( null );
	gestureRef.current = gesture;

	const scale = 'fit' === zoom ? fitScale : zoom;

	const { url: backgroundUrl } = useAttachmentUrl(
		( layout && layout.background && layout.background.attachment_id ) || 0
	);

	// Fit-width: track the surface width and derive the scale.
	useEffect( () => {
		const node = surfaceRef.current;

		if ( ! node || ! layout ) {
			return undefined;
		}

		const measure = () => {
			const available = node.clientWidth - ( rulers ? RULER_PX : 0 );
			const next = Math.min(
				FIT_MAX,
				Math.max( FIT_MIN, available / layout.page.width )
			);
			setFitScale( next );
		};

		measure();

		const observer = new window.ResizeObserver( measure );
		observer.observe( node );

		return () => observer.disconnect();
	}, [ layout, rulers ] );

	// Active gesture: window-level move/up listeners. Layout effect, not
	// passive: the listeners must exist before the browser processes the
	// next input event, or a fast click's mouseup escapes the gesture.
	useLayoutEffect( () => {
		if ( ! gesture ) {
			return undefined;
		}

		const onMove = ( event ) => {
			setGesture( ( g ) => {
				if ( ! g ) {
					return g;
				}

				const dxPx = event.clientX - g.startX;
				const dyPx = event.clientY - g.startY;
				const moved =
					g.moved ||
					Math.abs( dxPx ) >= DRAG_THRESHOLD_PX ||
					Math.abs( dyPx ) >= DRAG_THRESHOLD_PX;

				let dx = pxToPt( dxPx, g.scale );
				let dy = pxToPt( dyPx, g.scale );
				let guides = [];

				// Snapping (FR-002): alignment guides + 4pt grid on drag,
				// grid on resize. Alt disables while held.
				if ( moved && ! event.altKey ) {
					if ( 'drag' === g.kind ) {
						const primary = layout.elements.find(
							( el ) => el.id === g.pressedId
						);

						if ( primary ) {
							const others = layout.elements.filter(
								( el ) => ! g.ids.includes( el.id )
							);
							const snapped = snapDrag(
								primary,
								dx,
								dy,
								others,
								layout.page
							);
							dx = snapped.dx;
							dy = snapped.dy;
							guides = snapped.guides;
						}
					}
				}

				return { ...g, moved, dx, dy, guides };
			} );
		};

		const onUp = () => {
			const g = gestureRef.current;
			setGesture( null );

			if ( ! g ) {
				return;
			}

			if ( 'drag' === g.kind && g.moved ) {
				dispatch( {
					type: 'APPLY_LAYOUT',
					layout: moveElements( layout, g.ids, g.dx, g.dy ),
				} );
				return;
			}

			if ( 'drag' === g.kind && ! g.moved && g.toggleOnTap ) {
				// Shift-click on an already-selected element deselects it.
				dispatch( {
					type: 'SET_SELECTION',
					ids: selection.filter( ( id ) => id !== g.pressedId ),
				} );
				return;
			}

			if ( 'resize' === g.kind && g.moved ) {
				dispatch( {
					type: 'APPLY_LAYOUT',
					layout: resizeElement( layout, g.id, g.handle, g.dx, g.dy ),
				} );
				return;
			}

			if ( 'marquee' === g.kind ) {
				if ( ! g.moved ) {
					dispatch( { type: 'SET_SELECTION', ids: [] } );
					return;
				}

				const rect = cornersToRect( g.originPt, {
					x: g.originPt.x + g.dx,
					y: g.originPt.y + g.dy,
				} );
				dispatch( {
					type: 'SET_SELECTION',
					ids: marqueeHits( layout.elements, rect ),
				} );
			}
		};

		window.addEventListener( 'mousemove', onMove );
		window.addEventListener( 'mouseup', onUp );

		return () => {
			window.removeEventListener( 'mousemove', onMove );
			window.removeEventListener( 'mouseup', onUp );
		};
	}, [ gesture, dispatch, layout, selection ] );

	if ( ! layout ) {
		return null;
	}

	const { width, height } = layout.page;

	/**
	 * Pointer position in page points.
	 *
	 * @param {Object} event Mouse event.
	 * @return {Object} { x, y } in points.
	 */
	const eventToPt = ( event ) => {
		const rect = pageRef.current.getBoundingClientRect();
		return {
			x: pxToPt( event.clientX - rect.left, scale ),
			y: pxToPt( event.clientY - rect.top, scale ),
		};
	};

	const onElementMouseDown = ( event, element ) => {
		if ( 0 !== event.button ) {
			return;
		}

		event.preventDefault();
		event.stopPropagation();
		surfaceRef.current.focus();
		setMenu( null );

		const isSelected = selection.includes( element.id );
		let ids = selection;

		if ( event.shiftKey ) {
			if ( ! isSelected ) {
				ids = [ ...selection, element.id ];
				dispatch( { type: 'SET_SELECTION', ids } );
			}
			// Deselection of an already-selected element happens on
			// mouseup (toggleOnTap) so shift-dragging a group still works.
		} else if ( ! isSelected ) {
			ids = [ element.id ];
			dispatch( { type: 'SET_SELECTION', ids } );
		}

		setGesture( {
			kind: 'drag',
			ids,
			pressedId: element.id,
			toggleOnTap: event.shiftKey && isSelected,
			startX: event.clientX,
			startY: event.clientY,
			dx: 0,
			dy: 0,
			moved: false,
			scale,
		} );
	};

	const onHandleMouseDown = ( event, element, handle ) => {
		if ( 0 !== event.button ) {
			return;
		}

		event.preventDefault();
		event.stopPropagation();
		surfaceRef.current.focus();
		setMenu( null );

		setGesture( {
			kind: 'resize',
			id: element.id,
			handle,
			startX: event.clientX,
			startY: event.clientY,
			dx: 0,
			dy: 0,
			moved: false,
			scale,
		} );
	};

	const onPageMouseDown = ( event ) => {
		if ( 0 !== event.button ) {
			return;
		}

		event.preventDefault();
		surfaceRef.current.focus();
		setMenu( null );

		setGesture( {
			kind: 'marquee',
			originPt: eventToPt( event ),
			startX: event.clientX,
			startY: event.clientY,
			dx: 0,
			dy: 0,
			moved: false,
			scale,
		} );
	};

	const onKeyDown = ( event ) => {
		if ( 0 === selection.length ) {
			return;
		}

		if ( 'Delete' === event.key || 'Backspace' === event.key ) {
			event.preventDefault();
			dispatch( {
				type: 'APPLY_LAYOUT',
				layout: removeElements( layout, selection ),
			} );
			dispatch( { type: 'SET_SELECTION', ids: [] } );
			return;
		}

		const deltas = {
			ArrowLeft: [ -1, 0 ],
			ArrowRight: [ 1, 0 ],
			ArrowUp: [ 0, -1 ],
			ArrowDown: [ 0, 1 ],
		};

		if ( ! deltas[ event.key ] ) {
			return;
		}

		event.preventDefault();

		const step = event.shiftKey ? NUDGE_SHIFT_PT : NUDGE_PT;
		const [ dx, dy ] = deltas[ event.key ];

		dispatch( {
			type: 'APPLY_LAYOUT',
			layout: moveElements( layout, selection, dx * step, dy * step ),
		} );
	};

	const onElementContextMenu = ( event, element ) => {
		event.preventDefault();
		event.stopPropagation();

		if ( ! selection.includes( element.id ) ) {
			dispatch( { type: 'SET_SELECTION', ids: [ element.id ] } );
		}

		const rect = surfaceRef.current.getBoundingClientRect();
		setMenu( {
			id: element.id,
			x: event.clientX - rect.left,
			y: event.clientY - rect.top,
		} );
	};

	const onMenuAction = ( op ) => {
		if ( 'delete' === op ) {
			const ids = selection.includes( menu.id ) ? selection : [ menu.id ];
			dispatch( {
				type: 'APPLY_LAYOUT',
				layout: removeElements( layout, ids ),
			} );
			dispatch( { type: 'SET_SELECTION', ids: [] } );
		} else {
			dispatch( {
				type: 'APPLY_LAYOUT',
				layout: reorderElement( layout, menu.id, op ),
			} );
		}

		setMenu( null );
	};

	/**
	 * Visual box for an element, including any in-flight gesture.
	 *
	 * @param {Object} element Element.
	 * @return {Object} { x, y, w, h }.
	 */
	const visualBox = ( element ) => {
		const box = {
			x: element.x,
			y: element.y,
			w: element.w,
			h: element.h,
		};

		if ( ! gesture || ! gesture.moved ) {
			return box;
		}

		if ( 'drag' === gesture.kind && gesture.ids.includes( element.id ) ) {
			return { ...box, x: box.x + gesture.dx, y: box.y + gesture.dy };
		}

		if ( 'resize' === gesture.kind && gesture.id === element.id ) {
			return resizeBox(
				box,
				gesture.handle,
				gesture.dx,
				gesture.dy,
				layout.page,
				'qr' === element.type
			);
		}

		return box;
	};

	const ordered = [ ...layout.elements ].sort(
		( a, b ) => ( a.z || 0 ) - ( b.z || 0 )
	);

	const singleSelected =
		1 === selection.length
			? layout.elements.find( ( el ) => el.id === selection[ 0 ] )
			: null;

	const marqueeRect =
		gesture && 'marquee' === gesture.kind && gesture.moved
			? cornersToRect( gesture.originPt, {
					x: gesture.originPt.x + gesture.dx,
					y: gesture.originPt.y + gesture.dy,
			  } )
			: null;

	const menuItems = [
		{
			key: 'forward',
			label: __( 'Bring forward', 'pressprimer-certificate' ),
		},
		{
			key: 'backward',
			label: __( 'Send backward', 'pressprimer-certificate' ),
		},
		{ type: 'divider' },
		{
			key: 'front',
			label: __( 'Bring to front', 'pressprimer-certificate' ),
		},
		{ key: 'back', label: __( 'Send to back', 'pressprimer-certificate' ) },
		{ type: 'divider' },
		{
			key: 'delete',
			danger: true,
			label: __( 'Delete', 'pressprimer-certificate' ),
		},
	];

	const activeGuides =
		gesture && gesture.moved && gesture.guides ? gesture.guides : [];

	return (
		// eslint-disable-next-line jsx-a11y/no-noninteractive-element-interactions -- role="application" is the correct role for a keyboard-operated design canvas; arrow-key nudging lives here.
		<div
			className={
				'ppcert-designer__surface' +
				( rulers ? ' ppcert-designer__surface--rulers' : '' )
			}
			ref={ surfaceRef }
			tabIndex={ 0 }
			role="application"
			aria-label={ __( 'Certificate canvas', 'pressprimer-certificate' ) }
			onKeyDown={ onKeyDown }
		>
			{ rulers && (
				<>
					<div
						className="ppcert-designer__ruler-corner"
						style={ { width: RULER_PX, height: RULER_PX } }
					/>
					<Ruler axis="h" length={ width } scale={ scale } />
					<Ruler axis="v" length={ height } scale={ scale } />
				</>
			) }
			<div
				className="ppcert-designer__scaler"
				style={ { width: width * scale, height: height * scale } }
			>
				{ /* eslint-disable-next-line jsx-a11y/no-noninteractive-element-interactions -- design surface; keyboard interaction lives on the focusable surface wrapper. */ }
				<div
					ref={ pageRef }
					className="ppcert-designer__page"
					data-ppcert-canvas-scale={ scale }
					style={ {
						width,
						height,
						// Longhands only: the `background` SHORTHAND
						// resets background-image, and React's style
						// diffing re-sets just the changed key - a
						// color edit was wiping the image live
						// (Ryan, 2026-07-30).
						backgroundColor: layout.background?.color || '#ffffff',
						backgroundImage: backgroundUrl
							? `url("${ backgroundUrl }")`
							: undefined,
						backgroundSize: 'cover',
						backgroundPosition: 'center',
						transform: `scale(${ scale })`,
						transformOrigin: '0 0',
					} }
					role="presentation"
					onMouseDown={ onPageMouseDown }
				>
					<div
						className="ppcert-designer__safe-margin"
						style={ { inset: SAFE_MARGIN_PT } }
					/>

					{ ordered.map( ( element ) => {
						const box = visualBox( element );
						const isSelected = selection.includes( element.id );
						const ElementContent = getElementComponent(
							element.type
						);

						return (
							/* eslint-disable-next-line jsx-a11y/no-static-element-interactions -- pointer-driven design surface; selection is keyboard-reachable via the surface wrapper. */
							<div
								key={ element.id }
								data-ppcert-el={ element.id }
								data-selected={
									isSelected ? 'true' : undefined
								}
								className={
									'ppcert-designer__el' +
									( isSelected
										? ' ppcert-designer__el--selected'
										: '' )
								}
								style={ {
									left: box.x,
									top: box.y,
									width: box.w,
									height: box.h,
								} }
								onMouseDown={ ( event ) =>
									onElementMouseDown( event, element )
								}
								onContextMenu={ ( event ) =>
									onElementContextMenu( event, element )
								}
							>
								<ElementContent
									element={ element }
									box={ box }
								/>
							</div>
						);
					} ) }

					{ singleSelected &&
						HANDLES.map( ( handle ) => {
							const box = visualBox( singleSelected );
							const cx = {
								n: box.x + box.w / 2,
								s: box.x + box.w / 2,
								nw: box.x,
								w: box.x,
								sw: box.x,
								ne: box.x + box.w,
								e: box.x + box.w,
								se: box.x + box.w,
							}[ handle ];
							const cy = {
								w: box.y + box.h / 2,
								e: box.y + box.h / 2,
								nw: box.y,
								n: box.y,
								ne: box.y,
								sw: box.y + box.h,
								s: box.y + box.h,
								se: box.y + box.h,
							}[ handle ];

							return (
								/* eslint-disable-next-line jsx-a11y/no-static-element-interactions -- pointer-only affordance; resizing is available via keyboard nudge + properties panel. */
								<div
									key={ handle }
									data-ppcert-handle={ handle }
									className={ `ppcert-designer__handle ppcert-designer__handle--${ handle }` }
									style={ {
										left: cx,
										top: cy,
										width: 8 / scale,
										height: 8 / scale,
										borderWidth: 1 / scale,
									} }
									onMouseDown={ ( event ) =>
										onHandleMouseDown(
											event,
											singleSelected,
											handle
										)
									}
								/>
							);
						} ) }

					{ marqueeRect && (
						<div
							className="ppcert-designer__marquee"
							style={ {
								left: marqueeRect.x,
								top: marqueeRect.y,
								width: marqueeRect.w,
								height: marqueeRect.h,
							} }
						/>
					) }

					{ activeGuides.map( ( guide ) => (
						<div
							key={ `${ guide.axis }-${ guide.at }` }
							data-ppcert-guide={ guide.axis }
							className={ `ppcert-designer__guide ppcert-designer__guide--${ guide.axis }` }
							style={
								'v' === guide.axis
									? {
											left: guide.at,
											width: 1 / scale,
									  }
									: {
											top: guide.at,
											height: 1 / scale,
									  }
							}
						/>
					) ) }
				</div>
			</div>

			{ menu && (
				<Dropdown
					open
					trigger={ [] }
					menu={ {
						items: menuItems,
						onClick: ( { key } ) => onMenuAction( key ),
					} }
					onOpenChange={ ( open ) => {
						if ( ! open ) {
							setMenu( null );
						}
					} }
				>
					<div
						className="ppcert-designer__menu-anchor"
						style={ { left: menu.x, top: menu.y } }
					/>
				</Dropdown>
			) }
		</div>
	);
}
