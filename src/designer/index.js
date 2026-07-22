/**
 * PressPrimer Certificate - Designer entry point (Feature 001 TR-004)
 *
 * Mounts into #ppcert-designer-root with bootstrap data from the
 * ppcert_designer_data localized object.
 *
 * @package
 */

import { render } from '@wordpress/element';
import { message } from 'antd';
import DesignerApp from './components/DesignerApp';
import { DesignerProvider } from './hooks/useDesignerStore';
import './style.css';

// Ecosystem convention: identical message positioning in every entry point.
message.config( { top: 50, duration: 5, maxCount: 3 } );

document.addEventListener( 'DOMContentLoaded', () => {
	const root = document.getElementById( 'ppcert-designer-root' );

	if ( ! root ) {
		return;
	}

	const boot = window.ppcert_designer_data || {
		template_id: 0,
		list_url: '',
		starters: [],
		font_manifest: {},
		page_presets: {},
	};

	render(
		<DesignerProvider>
			<DesignerApp boot={ boot } />
		</DesignerProvider>,
		root
	);
} );
