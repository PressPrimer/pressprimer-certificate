/**
 * UpsellPrompt - inline premium touchpoint
 *
 * Renders the one-sentence prompt plus link for a touchpoint payload
 * produced by the server-side registry (PressPrimer_Certificate_Touchpoints,
 * the Assignment 2.2 pattern). The server localizes only the touchpoints
 * the current user may see (providing tier inactive AND manage_options),
 * so this component never decides visibility: an absent payload renders
 * nothing. Copy and link text arrive translated from PHP.
 *
 * Contextual, not interruptive: one sentence, one link, no dismissal
 * state, never blocking (Feature 2.0-005 FR-003).
 *
 * @since 2.0.0
 */

import { Typography } from 'antd';
import { LockOutlined } from '@ant-design/icons';

import './UpsellPrompt.css';

const { Text } = Typography;

/**
 * UpsellPrompt component.
 *
 * @param {Object}      props            Component props.
 * @param {Object|null} props.touchpoint Touchpoint payload ({ key, copy, linkText, url }).
 * @param {boolean}     props.compact    Tighter padding for toolbar-like slots.
 * @param {Object|null} props.style      Optional inline style for the root element.
 * @return {JSX.Element|null} Rendered prompt or null.
 */
const UpsellPrompt = ( { touchpoint, compact = false, style = null } ) => {
	if ( ! touchpoint || ! touchpoint.copy || ! touchpoint.url ) {
		return null;
	}

	return (
		<div
			className={ `ppcert-upsell-prompt${
				compact ? ' ppcert-upsell-prompt--compact' : ''
			}` }
			style={ style || undefined }
			data-touchpoint={ touchpoint.key }
		>
			<LockOutlined
				className="ppcert-upsell-prompt__icon"
				aria-hidden="true"
			/>
			<Text className="ppcert-upsell-prompt__copy">
				{ touchpoint.copy }
			</Text>
			<a
				className="ppcert-upsell-prompt__link"
				href={ touchpoint.url }
				target="_blank"
				rel="noopener noreferrer"
			>
				{ touchpoint.linkText }
				<span aria-hidden="true"> →</span>
			</a>
		</div>
	);
};

export default UpsellPrompt;
