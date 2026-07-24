/**
 * Dashboard - main component
 *
 * One boot fetch (GET /ppcert/v1/dashboard) feeds the stats cards,
 * recent certificates, and top templates; the chart fetches its own
 * range. Branding values arrive localized (white-label filters).
 */

import { useState, useEffect } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';
import { Spin, Alert } from 'antd';

import StatsCards from './StatsCards';
import ActivityChart from './ActivityChart';
import QuickActions from './QuickActions';
import RecentCertificates from './RecentCertificates';
import TopTemplates from './TopTemplates';
import EmailCourseCard from './EmailCourseCard';

/**
 * Dashboard component.
 *
 * @param {Object} props          Component props.
 * @param {Object} props.bootData Localized boot data from PHP.
 * @return {JSX.Element} Rendered component.
 */
const Dashboard = ( { bootData = {} } ) => {
	const [ stats, setStats ] = useState( null );
	const [ recent, setRecent ] = useState( [] );
	const [ topTemplates, setTopTemplates ] = useState( [] );
	const [ loading, setLoading ] = useState( true );
	const [ error, setError ] = useState( null );

	useEffect( () => {
		apiFetch( { path: '/ppcert/v1/dashboard' } )
			.then( ( response ) => {
				if ( response.success ) {
					setStats( response.stats || null );
					setRecent( response.recent || [] );
					setTopTemplates( response.top_templates || [] );
				}
				setError( null );
			} )
			.catch( ( err ) => {
				setError(
					err.message ||
						__(
							'Failed to load dashboard data.',
							'pressprimer-certificate'
						)
				);
			} )
			.finally( () => setLoading( false ) );
	}, [] );

	const pluginName = bootData.pluginName || 'PressPrimer Certificate';
	const welcomeText =
		bootData.welcomeText ||
		__(
			"Welcome to PressPrimer Certificate! Here's an overview of your certificates.",
			'pressprimer-certificate'
		);

	return (
		<div className="ppcert-dashboard-container">
			<div className="ppcert-dashboard-header">
				<div className="ppcert-dashboard-header-content">
					{ bootData.dashboardLogo ? (
						<img
							src={ bootData.dashboardLogo }
							alt={ pluginName }
							className="ppcert-dashboard-logo"
						/>
					) : (
						<h1>{ pluginName }</h1>
					) }
					<p>{ welcomeText }</p>
				</div>
			</div>

			{ error && (
				<Alert
					message={ __( 'Error', 'pressprimer-certificate' ) }
					description={ error }
					type="error"
					showIcon
					style={ { marginBottom: 24 } }
				/>
			) }

			<Spin spinning={ loading }>
				<div className="ppcert-dashboard-content">
					<div className="ppcert-dashboard-top-row">
						<StatsCards stats={ stats } loading={ loading } />
						<QuickActions
							urls={ bootData.urls || {} }
							caps={ bootData.caps || {} }
						/>
					</div>

					<ActivityChart parentLoading={ loading } />

					<div className="ppcert-dashboard-grid">
						<div className="ppcert-dashboard-main">
							<RecentCertificates
								certificates={ recent }
								loading={ loading }
								certificatesUrl={
									( bootData.urls || {} ).certificates || ''
								}
							/>
						</div>
						<div className="ppcert-dashboard-sidebar">
							<TopTemplates
								templates={ topTemplates }
								loading={ loading }
								templatesUrl={
									( bootData.urls || {} ).templates || ''
								}
							/>
							<EmailCourseCard
								optin={ bootData.emailOptin || {} }
							/>
						</div>
					</div>
				</div>
			</Spin>
		</div>
	);
};

export default Dashboard;
