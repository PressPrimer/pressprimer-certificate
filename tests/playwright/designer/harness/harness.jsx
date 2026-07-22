/**
 * Designer canvas test harness (Feature 001 FR-002/FR-003)
 *
 * Mounts the real palette + Canvas + properties panel + store against a
 * bundled starter layout with no WordPress involved - the
 * designer-canvas Playwright project drives this page. Everything
 * (React, antd, the components) is bundled by webpack.harness.config.js
 * so the page runs from file://.
 *
 * Boot data is a fixture: the real font manifest families plus a
 * test-only family lacking italic/bold variants (variant-gating specs),
 * and an element_types mirror of the PHP registry defaults.
 *
 * The window.__ppcertHarness bridge exposes state reads, dispatch,
 * zoom, and attachment-cache seeding for the specs.
 */

import { render, useEffect, useState } from '@wordpress/element';
import manifest from '../../../../fonts/manifest.json';
import starter from '../../../../templates/starter-formal-landscape.json';
import '../../../../src/designer/style.css';
import {
	DesignerProvider,
	useDesignerStore,
} from '../../../../src/designer/hooks/useDesignerStore';
import Canvas from '../../../../src/designer/components/Canvas';
import ElementPalette from '../../../../src/designer/components/ElementPalette';
import PropertiesPanel from '../../../../src/designer/components/PropertiesPanel';
import { seedAttachmentUrl } from '../../../../src/designer/hooks/useAttachment';
import {
	seedMergeFields,
	seedMetaKeys,
	getSampleMap,
} from '../../../../src/designer/mergeFields';
import { DesignerViewContext } from '../../../../src/designer/view-context';

// Boot fixture: installed before render - designer modules read boot
// lazily via getBoot(), never at import time.
window.ppcert_designer_data = {
	template_id: 0,
	list_url: '',
	fonts: {
		...manifest.families,
		'test-noitalic': {
			label: 'Test NoItalic',
			variants: {
				regular: { ttf: '' },
				bold: { ttf: '' },
			},
		},
	},
	element_types: {
		text: {
			key: 'text',
			label: 'Text',
			icon: 'text',
			default_box: { w: 220, h: 28 },
			default_props: {
				content: 'Your text',
				font_family: 'source-sans-3',
				font_size: 16,
				color: '#1f2937',
				align: 'left',
				line_height: 1.2,
				bold: false,
				italic: false,
			},
		},
		merge_field: {
			key: 'merge_field',
			label: 'Merge Field',
			icon: 'merge_field',
			default_box: { w: 260, h: 28 },
			default_props: {},
		},
		image: {
			key: 'image',
			label: 'Image / Logo',
			icon: 'image',
			default_box: { w: 140, h: 140 },
			default_props: { attachment_id: 0, fit: 'contain', opacity: 1 },
		},
		signature: {
			key: 'signature',
			label: 'Signature',
			icon: 'signature',
			default_box: { w: 180, h: 60 },
			default_props: { attachment_id: 0, fit: 'contain', opacity: 1 },
		},
		shape: {
			key: 'shape',
			label: 'Line / Shape',
			icon: 'shape',
			default_box: { w: 200, h: 100 },
			default_props: {
				shape: 'rect',
				stroke_color: '#1f2937',
				stroke_width: 1,
				fill_color: '',
				radius: 0,
			},
		},
		qr: {
			key: 'qr',
			label: 'QR Code',
			icon: 'qr',
			default_box: { w: 60, h: 60 },
			default_props: { dark_color: '#000000', light_color: '' },
		},
		background: {
			key: 'background',
			label: 'Background',
			icon: 'background',
			default_box: null,
			default_props: {},
		},
	},
	starters: [],
	page_presets: {},
};

// Merge-field registry fixture (no REST in the harness) - a subset of
// the core registry plus meta-key picker fixtures.
seedMergeFields( {
	groups: {
		recipient: 'Recipient',
		certificate: 'Certificate',
		site: 'Site',
	},
	fields: [
		{
			key: 'recipient.display_name',
			group: 'recipient',
			label: 'Recipient Name',
			sample: 'Jordan Rivera',
		},
		{
			key: 'recipient.full_name',
			group: 'recipient',
			label: 'Recipient Full Name',
			sample: 'Jordan Rivera',
		},
		{
			key: 'recipient.email',
			group: 'recipient',
			label: 'Recipient Email',
			sample: 'jordan@example.com',
		},
		{
			key: 'certificate.credential_id',
			group: 'certificate',
			label: 'Credential ID',
			sample: '7Q4M-K9P2-XT3A',
		},
		{
			key: 'certificate.issue_date',
			group: 'certificate',
			label: 'Issue Date',
			sample: 'July 22, 2026',
		},
		{
			key: 'site.name',
			group: 'site',
			label: 'Site Name',
			sample: 'Acme Academy',
		},
	],
} );

seedMetaKeys( 'user', [
	{ key: 'license_no', sample: 'LIC-2201' },
	{ key: 'membership_tier', sample: 'Gold' },
	{ key: 'graduation_year', sample: '2026' },
] );

/**
 * Harness app: load the starter, expose the bridge, render the editor.
 *
 * @return {JSX.Element|null} Editor.
 */
function Harness() {
	const { state, dispatch } = useDesignerStore();
	const [ zoom, setZoom ] = useState( 1 );
	const [ tokenView, setTokenView ] = useState( false );

	useEffect( () => {
		if ( state.layout ) {
			return;
		}

		const layout = { ...starter };
		delete layout._meta;

		dispatch( {
			type: 'LOAD_TEMPLATE',
			template: { id: 0, title: 'Harness', status: 'draft' },
			layout,
		} );
	}, [ state.layout, dispatch ] );

	// Reassigned every render so getState always returns current state.
	window.__ppcertHarness = {
		ready: true,
		getState: () => state,
		dispatch,
		setZoom,
		setTokenView,
		seedAttachment: seedAttachmentUrl,
	};

	if ( ! state.layout ) {
		return null;
	}

	return (
		<DesignerViewContext.Provider
			value={ { tokenView, samples: getSampleMap() } }
		>
			<div className="ppcert-harness__editor">
				<div className="ppcert-harness__palette">
					<ElementPalette />
				</div>
				<div className="ppcert-harness__canvas">
					<Canvas layout={ state.layout } zoom={ zoom } />
				</div>
				<div className="ppcert-harness__panel">
					<PropertiesPanel />
				</div>
			</div>
		</DesignerViewContext.Provider>
	);
}

render(
	<DesignerProvider>
		<Harness />
	</DesignerProvider>,
	document.getElementById( 'root' )
);
