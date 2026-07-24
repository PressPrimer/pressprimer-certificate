/**
 * Spotlight component
 *
 * Renders an SVG mask overlay with a transparent cutout around the
 * target element. Uses a render prop to pass the target bounding
 * rect to child components (e.g., Tooltip).
 *
 * Follows the Quiz/Assignment Spotlight pattern.
 */

import {
	useState,
	useEffect,
	useCallback,
	createPortal,
} from '@wordpress/element';

/**
 * The visible-content rect of an element
 *
 * The union of the element's children's boxes rather than its own
 * border box: containers like the starter gallery carry large
 * paddings, and framing the padded box leaves the highlight floating
 * far from what the user actually sees.
 *
 * @param {Element} element Target element.
 * @return {DOMRect} Union rect (the element's own when it has no children).
 */
const contentRect = ( element ) => {
	const children = [ ...element.children ].filter(
		( child ) => child.getClientRects().length > 0
	);

	if ( ! children.length ) {
		return element.getBoundingClientRect();
	}

	let top = Infinity;
	let left = Infinity;
	let bottom = -Infinity;
	let right = -Infinity;

	for ( const child of children ) {
		const rect = child.getBoundingClientRect();
		top = Math.min( top, rect.top );
		left = Math.min( left, rect.left );
		bottom = Math.max( bottom, rect.bottom );
		right = Math.max( right, rect.right );
	}

	return {
		top,
		left,
		bottom,
		right,
		width: right - left,
		height: bottom - top,
	};
};

/**
 * Spotlight component.
 *
 * @param {Object}   props            Component props.
 * @param {string}   props.selector   CSS selector for the target element.
 * @param {number}   props.padding    Extra padding around the cutout.
 * @param {boolean}  props.fitContent Frame the children's union instead of the padded box.
 * @param {Function} props.children   Render prop receiving { targetRect }.
 */
const Spotlight = ( {
	selector,
	padding = 8,
	fitContent = false,
	children,
} ) => {
	const [ targetRect, setTargetRect ] = useState( null );

	/**
	 * Calculate the target element position
	 *
	 * Keeps the previous rect object when nothing moved so interval
	 * polling doesn't cause render churn.
	 */
	const updatePosition = useCallback( () => {
		if ( ! selector ) {
			return;
		}

		const element = document.querySelector( selector );
		if ( ! element ) {
			setTargetRect( null );
			return;
		}

		const rect = fitContent
			? contentRect( element )
			: element.getBoundingClientRect();

		setTargetRect( ( prev ) => {
			if (
				prev &&
				prev.top === rect.top &&
				prev.left === rect.left &&
				prev.width === rect.width &&
				prev.height === rect.height
			) {
				return prev;
			}

			return {
				top: rect.top,
				left: rect.left,
				width: rect.width,
				height: rect.height,
				bottom: rect.bottom,
				right: rect.right,
			};
		} );
	}, [ selector, fitContent ] );

	/**
	 * Set up position tracking
	 */
	useEffect( () => {
		// Bring the target itself into view — targets may sit below the
		// fold.
		const element = selector ? document.querySelector( selector ) : null;
		if ( element ) {
			element.scrollIntoView( { block: 'start', behavior: 'smooth' } );
		}

		const timer = setTimeout( updatePosition, 300 );

		// Poll while the step is open: late-loading content shifts
		// targets without resizing them, which the scroll/resize
		// listeners and ResizeObserver below cannot see.
		const interval = setInterval( updatePosition, 300 );

		window.addEventListener( 'resize', updatePosition );
		window.addEventListener( 'scroll', updatePosition );

		let observer;
		if ( element && typeof window.ResizeObserver !== 'undefined' ) {
			observer = new window.ResizeObserver( updatePosition );
			observer.observe( element );
		}

		return () => {
			clearTimeout( timer );
			clearInterval( interval );
			window.removeEventListener( 'resize', updatePosition );
			window.removeEventListener( 'scroll', updatePosition );
			if ( observer ) {
				observer.disconnect();
			}
		};
	}, [ selector, updatePosition ] );

	if ( ! targetRect ) {
		// No target found — just render children without spotlight.
		return typeof children === 'function'
			? children( { targetRect: null } )
			: null;
	}

	const cutout = {
		x: targetRect.left - padding,
		y: targetRect.top - padding,
		width: targetRect.width + padding * 2,
		height: targetRect.height + padding * 2,
		rx: 8,
	};

	// The frame stays inside the admin content area: never up into the
	// fixed admin bar, never left into the admin menu.
	const adminBar = document.getElementById( 'wpadminbar' );
	const adminMenu = document.getElementById( 'adminmenuwrap' );
	const minTop = adminBar ? adminBar.getBoundingClientRect().bottom + 4 : 4;
	const minLeft = adminMenu ? adminMenu.getBoundingClientRect().right + 4 : 4;

	if ( cutout.y < minTop ) {
		cutout.height -= minTop - cutout.y;
		cutout.y = minTop;
	}

	if ( cutout.x < minLeft ) {
		cutout.width -= minLeft - cutout.x;
		cutout.x = minLeft;
	}

	const overlay = createPortal(
		<>
			{ /* SVG mask overlay */ }
			<svg
				className="ppcert-spotlight__overlay"
				style={ {
					position: 'fixed',
					inset: 0,
					width: '100%',
					height: '100%',
					zIndex: 99998,
					pointerEvents: 'none',
				} }
			>
				<defs>
					<mask id="ppcert-spotlight-mask">
						<rect
							x="0"
							y="0"
							width="100%"
							height="100%"
							fill="white"
						/>
						<rect
							x={ cutout.x }
							y={ cutout.y }
							width={ cutout.width }
							height={ cutout.height }
							rx={ cutout.rx }
							fill="black"
						/>
					</mask>
				</defs>
				{ /* The dim layer is visual-only: the guided build needs the
				     user working in the real UI, and SVG masks cut a visual
				     hole but NOT a hit-testing hole — with pointer events
				     on, the whole page (cutout included) is unclickable. */ }
				<rect
					x="0"
					y="0"
					width="100%"
					height="100%"
					fill="rgba(0, 0, 0, 0.5)"
					mask="url(#ppcert-spotlight-mask)"
					style={ { pointerEvents: 'none' } }
				/>
			</svg>

			{ /* Highlight border around target */ }
			<div
				className="ppcert-spotlight__highlight ppcert-spotlight__highlight--pulse"
				style={ {
					position: 'fixed',
					top: cutout.y,
					left: cutout.x,
					width: cutout.width,
					height: cutout.height,
					borderRadius: cutout.rx,
					zIndex: 99999,
					pointerEvents: 'none',
				} }
			/>
		</>,
		document.body
	);

	return (
		<>
			{ overlay }
			{ typeof children === 'function'
				? children( { targetRect } )
				: null }
		</>
	);
};

export default Spotlight;
