/**
 * Tooltip component
 *
 * Positioned tooltip for spotlight explanations.
 * Follows the Quiz/Assignment Tooltip pattern.
 */

import { useState, useEffect, useRef, useCallback } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { Button, Tooltip as AntTooltip } from 'antd';
import { LeftOutlined, RightOutlined, CloseOutlined } from '@ant-design/icons';
import ProgressDots from './ProgressDots';

/**
 * Calculate optimal position for the tooltip.
 *
 * @param {Object} targetRect        Target element bounding rect.
 * @param {Object} tooltipRect       Tooltip element bounding rect.
 * @param {string} preferredPosition Preferred position (top, bottom, left, right).
 * @param {number} offset            Distance from target element.
 * @return {Object} Position object with top, left, and actual position.
 */
const calculatePosition = (
	targetRect,
	tooltipRect,
	preferredPosition = 'bottom',
	offset = 16
) => {
	const viewport = {
		width: window.innerWidth,
		height: window.innerHeight,
	};

	const positions = {
		top: {
			top: targetRect.top - tooltipRect.height - offset,
			left:
				targetRect.left + ( targetRect.width - tooltipRect.width ) / 2,
		},
		bottom: {
			top: targetRect.top + targetRect.height + offset,
			left:
				targetRect.left + ( targetRect.width - tooltipRect.width ) / 2,
		},
		left: {
			top:
				targetRect.top + ( targetRect.height - tooltipRect.height ) / 2,
			left: targetRect.left - tooltipRect.width - offset,
		},
		right: {
			top:
				targetRect.top + ( targetRect.height - tooltipRect.height ) / 2,
			left: targetRect.left + targetRect.width + offset,
		},
	};

	const fitsInViewport = ( pos ) => {
		return (
			pos.top >= 10 &&
			pos.left >= 10 &&
			pos.top + tooltipRect.height <= viewport.height - 10 &&
			pos.left + tooltipRect.width <= viewport.width - 10
		);
	};

	if ( fitsInViewport( positions[ preferredPosition ] ) ) {
		return {
			...positions[ preferredPosition ],
			position: preferredPosition,
		};
	}

	const fallbackOrder = [ 'bottom', 'top', 'right', 'left' ].filter(
		( p ) => p !== preferredPosition
	);

	for ( const pos of fallbackOrder ) {
		if ( fitsInViewport( positions[ pos ] ) ) {
			return {
				...positions[ pos ],
				position: pos,
			};
		}
	}

	// Nothing fits — use bottom and constrain to the viewport.
	const constrained = { ...positions.bottom };
	constrained.top = Math.max(
		10,
		Math.min( constrained.top, viewport.height - tooltipRect.height - 10 )
	);
	constrained.left = Math.max(
		10,
		Math.min( constrained.left, viewport.width - tooltipRect.width - 10 )
	);
	constrained.position = 'bottom';

	return constrained;
};

/**
 * Tooltip component.
 *
 * @param {Object}         props                    Component props.
 * @param {Object}         props.targetRect         Target rect from Spotlight.
 * @param {string}         props.title              Tooltip title.
 * @param {string|Element} props.content            Tooltip content.
 * @param {string}         props.position           Preferred position.
 * @param {number}         props.currentStep        Current step number.
 * @param {number}         props.totalSteps         Total number of steps.
 * @param {Function}       props.onPrev             Previous step handler.
 * @param {Function}       props.onNext             Next step handler.
 * @param {Function}       props.onSkip             Skip handler.
 * @param {Function}       props.onClose            Close handler.
 * @param {boolean}        props.nextDisabled       Whether Next is disabled.
 * @param {string|null}    props.nextDisabledReason Tooltip shown on the disabled Next.
 */
const Tooltip = ( {
	targetRect,
	title,
	content,
	position = 'bottom',
	currentStep,
	totalSteps,
	nextDisabled = false,
	nextDisabledReason = null,
	onPrev,
	onNext,
	onSkip,
	onClose,
} ) => {
	const tooltipRef = useRef( null );
	const [ tooltipStyle, setTooltipStyle ] = useState( { opacity: 0 } );
	const [ arrowPosition, setArrowPosition ] = useState( position );

	const updatePosition = useCallback( () => {
		if ( ! tooltipRef.current || ! targetRect ) {
			return;
		}

		const tooltipRect = tooltipRef.current.getBoundingClientRect();
		const calculated = calculatePosition(
			targetRect,
			tooltipRect,
			position
		);

		setTooltipStyle( {
			position: 'fixed',
			top: calculated.top,
			left: calculated.left,
			opacity: 1,
			zIndex: 100001,
		} );
		setArrowPosition( calculated.position );
	}, [ targetRect, position ] );

	useEffect( () => {
		const timer = setTimeout( updatePosition, 50 );

		window.addEventListener( 'resize', updatePosition );

		return () => {
			clearTimeout( timer );
			window.removeEventListener( 'resize', updatePosition );
		};
	}, [ updatePosition ] );

	if ( ! targetRect ) {
		return null;
	}

	const data = window.ppcert_onboarding_data || {};
	const pluginName =
		data.i18n?.pluginName ||
		__( 'PressPrimer Certificate', 'pressprimer-certificate' );

	return (
		<div
			ref={ tooltipRef }
			className={ `ppcert-tooltip ppcert-tooltip--${ arrowPosition }` }
			style={ tooltipStyle }
		>
			<div
				className={ `ppcert-tooltip__arrow ppcert-tooltip__arrow--${ arrowPosition }` }
			/>

			{ onClose && (
				<button
					type="button"
					className="ppcert-tooltip__close"
					onClick={ onClose }
					aria-label={ __( 'Close', 'pressprimer-certificate' ) }
				>
					<CloseOutlined />
				</button>
			) }

			<div className="ppcert-tooltip__brand">{ pluginName }</div>

			<div className="ppcert-tooltip__content">
				{ title && (
					<h4 className="ppcert-tooltip__title">{ title }</h4>
				) }
				{ content && (
					<div className="ppcert-tooltip__body">{ content }</div>
				) }
			</div>

			<div className="ppcert-tooltip__navigation">
				<div className="ppcert-tooltip__nav-left">
					{ currentStep > 1 && onPrev && (
						<Button
							type="text"
							icon={ <LeftOutlined /> }
							onClick={ onPrev }
							size="small"
						>
							{ __( 'Back', 'pressprimer-certificate' ) }
						</Button>
					) }
				</div>

				<div className="ppcert-tooltip__nav-center">
					<ProgressDots
						currentStep={ currentStep }
						totalSteps={ totalSteps }
					/>
				</div>

				<div className="ppcert-tooltip__nav-right">
					{ onSkip && currentStep < totalSteps && (
						<Button type="text" onClick={ onSkip } size="small">
							{ __( 'Skip', 'pressprimer-certificate' ) }
						</Button>
					) }
					{ onNext && (
						<AntTooltip
							title={ nextDisabled ? nextDisabledReason : null }
							overlayStyle={ { zIndex: 100002 } }
						>
							{ /* Disabled buttons swallow mouse events;
							     the span keeps the tooltip working. */ }
							<span className="ppcert-tooltip__next-wrap">
								<Button
									type="primary"
									onClick={ onNext }
									size="small"
									disabled={ nextDisabled }
								>
									{ currentStep === totalSteps
										? __(
												'Finish',
												'pressprimer-certificate'
										  )
										: __(
												'Next',
												'pressprimer-certificate'
										  ) }
									{ currentStep < totalSteps && (
										<RightOutlined />
									) }
								</Button>
							</span>
						</AntTooltip>
					) }
				</div>
			</div>
		</div>
	);
};

export default Tooltip;
