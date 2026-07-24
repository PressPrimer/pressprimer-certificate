/**
 * Quick actions - shortcuts to the key tasks
 *
 * Each action renders only when the viewer holds its capability (the
 * dashboard itself needs only ppcert_view_certificates).
 */

import { __ } from '@wordpress/i18n';
import { Button } from 'antd';
import {
	PlusOutlined,
	SendOutlined,
	UnorderedListOutlined,
	SettingOutlined,
} from '@ant-design/icons';

/**
 * Quick actions component.
 *
 * @param {Object} props      Component props.
 * @param {Object} props.urls URL mappings for actions.
 * @param {Object} props.caps Capability flags for the viewer.
 * @return {JSX.Element} Rendered component.
 */
const QuickActions = ( { urls = {}, caps = {} } ) => {
	const actions = [
		{
			key: 'create_template',
			label: __( 'Create a Template', 'pressprimer-certificate' ),
			icon: <PlusOutlined />,
			url: urls.create_template,
			type: 'primary',
			visible: !! caps.manage_templates,
		},
		{
			key: 'issue',
			label: __( 'Issue a Certificate', 'pressprimer-certificate' ),
			icon: <SendOutlined />,
			url: urls.issue,
			type: 'default',
			visible: !! caps.issue_certificates,
		},
		{
			key: 'certificates',
			label: __( 'View Certificates', 'pressprimer-certificate' ),
			icon: <UnorderedListOutlined />,
			url: urls.certificates,
			type: 'default',
			visible: true,
		},
		{
			key: 'settings',
			label: __( 'Open Settings', 'pressprimer-certificate' ),
			icon: <SettingOutlined />,
			url: urls.settings,
			type: 'default',
			visible: !! caps.manage_settings,
		},
	].filter( ( action ) => action.visible && action.url );

	return (
		<div className="ppcert-dashboard-card">
			<h3 className="ppcert-dashboard-card-title">
				{ __( 'Quick Actions', 'pressprimer-certificate' ) }
			</h3>
			<div className="ppcert-quick-actions">
				{ actions.map( ( action ) => (
					<Button
						key={ action.key }
						type={ action.type }
						icon={ action.icon }
						href={ action.url }
						block
						className="ppcert-quick-action-button"
					>
						{ action.label }
					</Button>
				) ) }
			</div>
		</div>
	);
};

export default QuickActions;
