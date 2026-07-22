/**
 * Properties panel (Feature 001 FR-003/FR-005)
 *
 * Per-type property sections arrive in Prompt 3.3; this shell shows the
 * selection-aware placeholder.
 *
 * @package
 */

import { __ } from '@wordpress/i18n';
import { Empty } from 'antd';

/**
 * The panel.
 *
 * @return {JSX.Element} Panel.
 */
export default function PropertiesPanel() {
	return (
		<Empty
			image={ Empty.PRESENTED_IMAGE_SIMPLE }
			description={ __(
				'Select an element to edit its properties (editing activates in an upcoming step).',
				'pressprimer-certificate'
			) }
		/>
	);
}
