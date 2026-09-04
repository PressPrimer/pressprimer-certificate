/**
 * Settings Page - Main Component
 *
 * Vertical tabs layout matching the PressPrimer Quiz/Assignment
 * settings pages: core tabs plus addon tabs registered through the
 * ppcert_settings_tabs PHP filter, batch save to /ppcert/v1/settings.
 *
 * @package
 * @since 1.0.0
 */

import { useState, useCallback, useEffect, useMemo } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';
import { Button, message, Spin } from 'antd';
import {
	SettingOutlined,
	MailOutlined,
	ToolOutlined,
	SaveOutlined,
	BgColorsOutlined,
	InfoCircleOutlined,
	SkinOutlined,
	AuditOutlined,
	ClearOutlined,
	FormatPainterOutlined,
	KeyOutlined,
} from '@ant-design/icons';

import GeneralTab from './GeneralTab';
import AppearanceTab from './AppearanceTab';
import EmailTab from './EmailTab';
import StatusTab from './StatusTab';
import AdvancedTab from './AdvancedTab';

/**
 * Icon map for addon tabs (same feature => same icon across the
 * PressPrimer suites; every settings page gets a UNIQUE icon - see
 * CLAUDE.md "Admin UI Development"). Suite alignment: 'license' is
 * KeyOutlined in Quiz and Assignment.
 */
const ADDON_ICONS = {
	'white-label': <SkinOutlined />,
	'audit-log': <AuditOutlined />,
	'data-cleanup': <ClearOutlined />,
	branding: <FormatPainterOutlined />,
	license: <KeyOutlined />,
	default: <SettingOutlined />,
};

/**
 * Core tab configuration (built into the free plugin). Order values
 * match the server-side ppcert_settings_tabs filter.
 */
const CORE_TABS = [
	{
		id: 'general',
		label: __( 'General', 'pressprimer-certificate' ),
		icon: <SettingOutlined />,
		component: GeneralTab,
		order: 10,
	},
	{
		id: 'appearance',
		label: __( 'Appearance', 'pressprimer-certificate' ),
		icon: <BgColorsOutlined />,
		component: AppearanceTab,
		order: 20,
	},
	{
		id: 'email',
		label: __( 'Email', 'pressprimer-certificate' ),
		icon: <MailOutlined />,
		component: EmailTab,
		order: 30,
	},
	{
		id: 'status',
		label: __( 'Status', 'pressprimer-certificate' ),
		icon: <InfoCircleOutlined />,
		component: StatusTab,
		order: 100,
	},
	{
		id: 'advanced',
		label: __( 'Advanced', 'pressprimer-certificate' ),
		icon: <ToolOutlined />,
		component: AdvancedTab,
		order: 110,
	},
];

/**
 * Read-only tabs never show the Save button.
 */
const READ_ONLY_TABS = [ 'status' ];

/**
 * Settings Page Component
 *
 * @param {Object} props              Component props.
 * @param {Object} props.settingsData Initial settings data from PHP.
 */
const SettingsPage = ( { settingsData = {} } ) => {
	const getInitialTab = () => {
		const params = new URLSearchParams( window.location.search );
		return params.get( 'tab' ) || 'general';
	};

	const [ activeTab, setActiveTab ] = useState( getInitialTab );
	const [ settings, setSettings ] = useState( settingsData.settings || {} );
	const [ saving, setSaving ] = useState( false );
	const [ hasChanges, setHasChanges ] = useState( false );

	/**
	 * Core tabs + addon tabs from the PHP filter.
	 */
	const allTabs = useMemo( () => {
		const serverTabs = settingsData.settingsTabs || {};
		const combined = CORE_TABS.map( ( coreTab ) => ( {
			...coreTab,
			isAddon: false,
		} ) );

		const coreIds = CORE_TABS.map( ( t ) => t.id );

		Object.entries( serverTabs ).forEach( ( [ id, tabConfig ] ) => {
			if ( coreIds.includes( id ) ) {
				return;
			}

			if ( tabConfig.isAddon === true ) {
				combined.push( {
					id,
					label: tabConfig.label || id,
					icon: ADDON_ICONS[ id ] || ADDON_ICONS.default,
					component: null,
					order: tabConfig.order ?? 50,
					isAddon: true,
				} );
			}
		} );

		combined.sort( ( a, b ) => a.order - b.order );

		return combined;
	}, [ settingsData.settingsTabs ] );

	const activeTabConfig = allTabs.find( ( tab ) => tab.id === activeTab );
	const isAddonTab = activeTabConfig?.isAddon ?? false;
	const isReadOnly = READ_ONLY_TABS.includes( activeTab );

	/**
	 * Addon scripts listen for this to mount their components.
	 */
	useEffect( () => {
		if ( isAddonTab ) {
			window.dispatchEvent(
				new CustomEvent( 'ppcert-settings-addon-tab-active', {
					detail: { tab: activeTab },
				} )
			);
		}
	}, [ activeTab, isAddonTab ] );

	const updateSetting = useCallback( ( key, value ) => {
		setSettings( ( prev ) => ( {
			...prev,
			[ key ]: value,
		} ) );
		setHasChanges( true );
	}, [] );

	// Addon sections mounted into core-tab slots (2.0, Feature 2.0-006
	// applied to settings) participate in the page's ONE Save button:
	// they announce edits with the dirty event (enabling Save) and
	// perform their own persistence when the save event fires.
	useEffect( () => {
		const markDirty = () => setHasChanges( true );

		window.addEventListener( 'ppcert-settings-addon-dirty', markDirty );

		return () =>
			window.removeEventListener(
				'ppcert-settings-addon-dirty',
				markDirty
			);
	}, [] );

	const handleSave = async () => {
		try {
			setSaving( true );

			const response = await apiFetch( {
				path: '/ppcert/v1/settings',
				method: 'POST',
				data: settings,
			} );

			if ( response.success ) {
				message.success(
					__( 'Settings saved.', 'pressprimer-certificate' )
				);
				setSettings( response.settings || settings );
				setHasChanges( false );

				// Addon sections persist their own stores now; failures
				// surface through their own error toasts.
				window.dispatchEvent(
					new CustomEvent( 'ppcert-settings-save' )
				);
			} else {
				message.error(
					__(
						'The settings could not be saved.',
						'pressprimer-certificate'
					)
				);
			}
		} catch ( error ) {
			message.error(
				error.message ||
					__(
						'The settings could not be saved.',
						'pressprimer-certificate'
					)
			);
		} finally {
			setSaving( false );
		}
	};

	const ActiveTabComponent = activeTabConfig?.component || null;

	const pluginUrl = settingsData.pluginUrl || '';
	const settingsMascot =
		settingsData.settingsMascot ||
		`${ pluginUrl }assets/images/construction-mascot.png`;

	return (
		<div className="ppcert-settings-container">
			<div className="ppcert-settings-header">
				<div className="ppcert-settings-header-content">
					<h1>
						{ __(
							'Certificate Settings',
							'pressprimer-certificate'
						) }
					</h1>
					<p>
						{ __(
							'Configure certificate defaults, appearance, email notifications, and data handling.',
							'pressprimer-certificate'
						) }
					</p>
				</div>
				{ settingsMascot && (
					<img
						src={ settingsMascot }
						alt=""
						className="ppcert-settings-header-mascot"
					/>
				) }
			</div>

			<div className="ppcert-settings-layout">
				<nav className="ppcert-settings-tabs">
					{ allTabs.map( ( tab ) => (
						<button
							key={ tab.id }
							type="button"
							className={ `ppcert-settings-tab ${
								activeTab === tab.id
									? 'ppcert-settings-tab--active'
									: ''
							}` }
							onClick={ () => setActiveTab( tab.id ) }
						>
							{ tab.icon }
							<span>{ tab.label }</span>
						</button>
					) ) }
				</nav>

				<div className="ppcert-settings-content">
					{ ! isAddonTab && isReadOnly && ActiveTabComponent && (
						<ActiveTabComponent
							settings={ settings }
							updateSetting={ updateSetting }
							settingsData={ settingsData }
						/>
					) }

					{ ! isAddonTab && ! isReadOnly && ActiveTabComponent && (
						<Spin
							spinning={ saving }
							tip={ __( 'Saving…', 'pressprimer-certificate' ) }
						>
							<ActiveTabComponent
								settings={ settings }
								updateSetting={ updateSetting }
								settingsData={ settingsData }
							/>

							<div className="ppcert-settings-footer">
								<Button
									type="primary"
									size="large"
									icon={ <SaveOutlined /> }
									onClick={ handleSave }
									loading={ saving }
									disabled={ ! hasChanges }
								>
									{ __(
										'Save Settings',
										'pressprimer-certificate'
									) }
								</Button>
							</div>
						</Spin>
					) }

					{ allTabs
						.filter( ( t ) => t.isAddon )
						.map( ( tab ) => (
							<div
								key={ tab.id }
								id={ `ppcert-settings-addon-${ tab.id }` }
								className="ppcert-settings-addon-content"
								style={ {
									display:
										activeTab === tab.id ? 'block' : 'none',
								} }
							/>
						) ) }
				</div>
			</div>
		</div>
	);
};

export default SettingsPage;
