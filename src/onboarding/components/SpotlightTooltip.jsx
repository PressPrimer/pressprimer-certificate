/**
 * SpotlightTooltip component
 *
 * Convenience wrapper combining Spotlight and Tooltip.
 * Follows the Quiz/Assignment SpotlightTooltip pattern.
 */

import Spotlight from './Spotlight';
import Tooltip from './Tooltip';

/**
 * SpotlightTooltip component.
 *
 * @param {Object}      props                    Component props.
 * @param {string}      props.selector           CSS selector for the target element.
 * @param {string}      props.title              Tooltip title.
 * @param {string}      props.content            Tooltip content.
 * @param {string}      props.position           Preferred tooltip position.
 * @param {number}      props.currentStep        Current step number.
 * @param {number}      props.totalSteps         Total step count.
 * @param {Function}    props.onPrev             Previous step handler.
 * @param {Function}    props.onNext             Next step handler.
 * @param {Function}    props.onSkip             Skip handler.
 * @param {Function}    props.onClose            Close handler.
 * @param {boolean}     props.nextDisabled       Whether Next is disabled.
 * @param {string|null} props.nextDisabledReason Tooltip shown on the disabled Next.
 * @param {boolean}     props.fitContent         Frame the target's content instead of its padded box.
 */
const SpotlightTooltip = ( {
	selector,
	title,
	content,
	position,
	fitContent,
	currentStep,
	totalSteps,
	nextDisabled,
	nextDisabledReason,
	onPrev,
	onNext,
	onSkip,
	onClose,
} ) => {
	return (
		<Spotlight selector={ selector } fitContent={ fitContent }>
			{ ( { targetRect } ) => (
				<Tooltip
					targetRect={ targetRect }
					title={ title }
					content={ content }
					position={ position }
					currentStep={ currentStep }
					totalSteps={ totalSteps }
					nextDisabled={ nextDisabled }
					nextDisabledReason={ nextDisabledReason }
					onPrev={ onPrev }
					onNext={ onNext }
					onSkip={ onSkip }
					onClose={ onClose }
				/>
			) }
		</Spotlight>
	);
};

export default SpotlightTooltip;
