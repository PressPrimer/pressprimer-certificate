/**
 * Dashboard - React entry point (Phase 5B item 1)
 *
 * Mounts the admin dashboard on the top-level Certificates menu slug:
 * welcome header, stats cards, awarded-over-time chart, quick actions,
 * recent certificates, and top templates.
 */

import { render } from '@wordpress/element';
import { message } from 'antd';
import Dashboard from './components/Dashboard';
import './style.css';

// Ecosystem convention: toasts clear the WP admin bar.
message.config( { top: 50, duration: 5, maxCount: 3 } );

document.addEventListener( 'DOMContentLoaded', () => {
	const root = document.getElementById( 'ppcert-dashboard-root' );

	if ( root ) {
		render(
			<Dashboard bootData={ window.ppcert_dashboard_data || {} } />,
			root
		);
	}
} );
