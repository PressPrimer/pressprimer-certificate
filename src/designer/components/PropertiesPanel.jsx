/**
 * Properties panel (Feature 001 FR-003/FR-005)
 *
 * No selection: the Page section (root background - the Background
 * palette entry routes here). Single selection: Position & Size plus
 * the per-type section. Multi-selection: count notice (alignment tools
 * arrive with the guardrails prompt).
 *
 * 2.1 (Feature 2.1-004 FR-006): with no selection, an addon slot sits
 * above the Page section (#ppcert-designer-page-panel-extension;
 * Educator's Brand). Free renders it empty.
 */

import { __, sprintf, _n } from '@wordpress/i18n';
import { Empty, Typography } from 'antd';
import { useDesignerStore } from '../hooks/useDesignerStore';
import PositionSection from './properties/PositionSection';
import TextSection from './properties/TextSection';
import ShapeSection from './properties/ShapeSection';
import MediaSection from './properties/MediaSection';
import QrSection from './properties/QrSection';
import PageSection from './properties/PageSection';

const { Text } = Typography;

/**
 * The no-selection panel: the addon slot, then the Page section.
 *
 * @return {JSX.Element} Panel.
 */
function NoSelectionPanel() {
	return (
		<>
			<div
				id="ppcert-designer-page-panel-extension"
				className="ppcert-designer__page-panel-extension"
			/>
			<PageSection />
		</>
	);
}

const TYPE_SECTIONS = {
	text: TextSection,
	merge_field: TextSection,
	shape: ShapeSection,
	image: MediaSection,
	signature: MediaSection,
	qr: QrSection,
};

/**
 * The panel.
 *
 * @return {JSX.Element} Panel.
 */
export default function PropertiesPanel() {
	const { state } = useDesignerStore();

	if ( ! state.layout ) {
		return (
			<Empty
				image={ Empty.PRESENTED_IMAGE_SIMPLE }
				description={ __(
					'Load a template to start designing.',
					'pressprimer-certificate'
				) }
			/>
		);
	}

	if ( 0 === state.selection.length ) {
		return <NoSelectionPanel />;
	}

	if ( state.selection.length > 1 ) {
		return (
			<div className="ppcert-designer__prop-section">
				<Text type="secondary">
					{ sprintf(
						/* translators: %d: number of selected elements */
						_n(
							'%d element selected.',
							'%d elements selected.',
							state.selection.length,
							'pressprimer-certificate'
						),
						state.selection.length
					) }
				</Text>
			</div>
		);
	}

	const element = state.layout.elements.find(
		( el ) => el.id === state.selection[ 0 ]
	);

	if ( ! element ) {
		return <NoSelectionPanel />;
	}

	const TypeSection = TYPE_SECTIONS[ element.type ];

	return (
		<div data-ppcert-panel={ element.type }>
			<PositionSection element={ element } />
			{ TypeSection ? <TypeSection element={ element } /> : null }
		</div>
	);
}
