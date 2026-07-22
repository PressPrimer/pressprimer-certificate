/**
 * Trigger panel - "Award this certificate when..." (Feature 001 FR-006)
 *
 * The full panel (trigger list, add flow, conditions forms) arrives in
 * Prompt 3.7 against the trigger registry.
 *
 * @package
 */

import { __ } from '@wordpress/i18n';
import { Empty, Typography } from 'antd';

const { Paragraph } = Typography;

/**
 * The panel.
 *
 * @return {JSX.Element} Panel.
 */
export default function TriggerPanel() {
	return (
		<div>
			<Empty
				image={ Empty.PRESENTED_IMAGE_SIMPLE }
				description={ __(
					'Award triggers activate in an upcoming step.',
					'pressprimer-certificate'
				) }
			/>
			<Paragraph type="secondary">
				{ __(
					'Certificates can always be issued manually from the Certificates screen.',
					'pressprimer-certificate'
				) }
			</Paragraph>
		</div>
	);
}
