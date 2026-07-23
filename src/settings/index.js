/**
 * Settings entry point
 *
 * Mounts the React settings page (Feature 008 FR-004) into
 * #ppcert-settings-root with bootstrap data from ppcert_settings_data,
 * following the Quiz/Assignment settings-panel pattern.
 *
 * @package
 * @since 1.0.0
 */

import { render } from '@wordpress/element';
import { message } from 'antd';
import SettingsPage from './components/SettingsPage';
import './style.css';

// Ecosystem convention: toasts clear the WP admin bar.
message.config( { top: 50, duration: 5, maxCount: 3 } );

const root = document.getElementById( 'ppcert-settings-root' );

if ( root ) {
	render(
		<SettingsPage settingsData={ window.ppcert_settings_data || {} } />,
		root
	);
}
