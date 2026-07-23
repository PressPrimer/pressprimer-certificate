/**
 * Status Tab Component
 *
 * System information, diagnostics, and database status - the house
 * Status page from PressPrimer Quiz/Assignment, adapted for the
 * certificate stack (PDF rendering capabilities, six integrations).
 *
 * @package
 * @since 1.0.0
 */

import { useState, useCallback, useMemo } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { Typography, Tag, Space, Button, Alert, message } from 'antd';
import {
	CheckCircleOutlined,
	CloseCircleOutlined,
	ExclamationCircleOutlined,
	ToolOutlined,
	CopyOutlined,
} from '@ant-design/icons';

const { Title, Paragraph } = Typography;

/**
 * Simple version comparison.
 *
 * @param {string} a First version.
 * @param {string} b Second version.
 * @return {number} -1/0/1.
 */
const compareVersions = ( a, b ) => {
	if ( ! a || ! b ) {
		return 0;
	}
	const aParts = String( a ).split( '.' ).map( Number );
	const bParts = String( b ).split( '.' ).map( Number );

	for ( let i = 0; i < Math.max( aParts.length, bParts.length ); i++ ) {
		const aPart = aParts[ i ] || 0;
		const bPart = bParts[ i ] || 0;
		if ( aPart > bPart ) {
			return 1;
		}
		if ( aPart < bPart ) {
			return -1;
		}
	}
	return 0;
};

/**
 * Status Tab
 *
 * @param {Object} props              Component props.
 * @param {Object} props.settingsData Full localized data.
 */
const StatusTab = ( { settingsData } ) => {
	const systemInfo = useMemo(
		() => settingsData.systemInfo || {},
		[ settingsData.systemInfo ]
	);
	const integrations = useMemo(
		() => settingsData.integrations || [],
		[ settingsData.integrations ]
	);
	const nonces = settingsData.nonces || {};

	const [ databaseTables, setDatabaseTables ] = useState(
		settingsData.databaseTables || []
	);
	const [ isRepairing, setIsRepairing ] = useState( false );

	const hasMissingTables = databaseTables.some( ( table ) => ! table.exists );
	const caps = useMemo(
		() => systemInfo.renderCapabilities || {},
		[ systemInfo.renderCapabilities ]
	);
	const stats = useMemo(
		() => systemInfo.statistics || {},
		[ systemInfo.statistics ]
	);

	const handleRepairTables = async () => {
		setIsRepairing( true );

		try {
			const formData = new FormData();
			formData.append( 'action', 'ppcert_repair_database_tables' );
			formData.append( 'nonce', nonces.repairTables );

			const response = await fetch( window.ajaxurl, {
				method: 'POST',
				body: formData,
			} );

			const result = await response.json();

			if ( result.success ) {
				message.success( result.data.message );
				if ( result.data.tableStatus ) {
					setDatabaseTables( result.data.tableStatus );
				}
			} else {
				message.error(
					result.data?.message ||
						__(
							'The tables could not be repaired.',
							'pressprimer-certificate'
						)
				);
			}
		} catch ( error ) {
			message.error(
				__(
					'An error occurred. Please try again.',
					'pressprimer-certificate'
				)
			);
		} finally {
			setIsRepairing( false );
		}
	};

	const formatVersionWithCheck = ( current, required ) => {
		const meetsRequirement = compareVersions( current, required ) >= 0;
		return (
			<Space>
				<span>{ current }</span>
				{ meetsRequirement ? (
					<Tag icon={ <CheckCircleOutlined /> } color="success">
						{ __( 'OK', 'pressprimer-certificate' ) }
					</Tag>
				) : (
					<Tag icon={ <CloseCircleOutlined /> } color="error">
						{ sprintf(
							/* translators: %s: minimum required version number */
							__( 'Requires %s+', 'pressprimer-certificate' ),
							required
						) }
					</Tag>
				) }
			</Space>
		);
	};

	const renderCapabilityTag = ( available ) =>
		available ? (
			<Tag icon={ <CheckCircleOutlined /> } color="success">
				{ __( 'Available', 'pressprimer-certificate' ) }
			</Tag>
		) : (
			<Tag color="default">
				{ __( 'Not Available', 'pressprimer-certificate' ) }
			</Tag>
		);

	const buildDiagnosticText = useCallback( () => {
		const lines = [];

		lines.push( '### PressPrimer Certificate - System Status ###' );
		lines.push( '' );

		lines.push( '## Plugin' );
		lines.push(
			`Plugin Version: ${ systemInfo.pluginVersion || 'Unknown' }`
		);
		lines.push(
			`Database Version: ${ systemInfo.dbVersion || 'Not set' }`
		);
		Object.entries( systemInfo.addonVersions || {} ).forEach(
			( [ id, version ] ) => lines.push( `Addon ${ id }: ${ version }` )
		);
		lines.push( '' );

		lines.push( '## WordPress' );
		lines.push( `Site URL: ${ systemInfo.siteUrl || 'Unknown' }` );
		lines.push(
			`WordPress Version: ${ systemInfo.wpVersion || 'Unknown' }`
		);
		lines.push( `Multisite: ${ systemInfo.isMultisite ? 'Yes' : 'No' }` );
		lines.push( `Memory Limit: ${ systemInfo.memoryLimit || 'Unknown' }` );
		lines.push( `Active Theme: ${ systemInfo.activeTheme || 'Unknown' }` );
		lines.push( '' );

		lines.push( '## Server' );
		lines.push( `PHP Version: ${ systemInfo.phpVersion || 'Unknown' }` );
		lines.push( `Post Max Size: ${ systemInfo.postMaxSize || 'Unknown' }` );
		lines.push(
			`Upload Max Filesize: ${ systemInfo.uploadMaxSize || 'Unknown' }`
		);
		lines.push(
			`PHP Time Limit: ${
				systemInfo.maxExecutionTime || 'Unknown'
			} seconds`
		);
		lines.push(
			`MySQL Version: ${ systemInfo.mysqlVersion || 'Unknown' }`
		);
		lines.push( '' );

		lines.push( '## PDF Rendering' );
		lines.push( `GD: ${ caps.gd ? 'Available' : 'Not Available' }` );
		lines.push(
			`Imagick: ${ caps.imagick ? 'Available' : 'Not Available' }`
		);
		lines.push(
			`Imagick PDF Delegate: ${
				caps.imagick_pdf ? 'Available' : 'Not Available'
			}`
		);
		lines.push( `Bundled Fonts: ${ caps.fonts ?? 0 }` );
		lines.push( '' );

		const activeIntegrations = integrations.filter( ( row ) => row.active );
		if ( activeIntegrations.length > 0 ) {
			lines.push( '## Integrations' );
			activeIntegrations.forEach( ( row ) =>
				lines.push( `${ row.label } ${ row.version || '' }`.trim() )
			);
			lines.push( '' );
		}

		lines.push( '## Statistics' );
		lines.push( `Templates: ${ stats.templates ?? 0 }` );
		lines.push( `Certificates: ${ stats.certificates ?? 0 }` );
		lines.push( `Events: ${ stats.events ?? 0 }` );
		lines.push( '' );

		lines.push( '## Database Tables' );
		databaseTables.forEach( ( table ) => {
			const status = table.exists ? 'OK' : 'MISSING';
			const rows = table.exists ? ` (${ table.row_count } rows)` : '';
			lines.push( `${ table.name }: ${ status }${ rows }` );
		} );
		lines.push( '' );

		const plugins = systemInfo.activePlugins || [];
		if ( plugins.length > 0 ) {
			lines.push( '## Active Plugins' );
			plugins.forEach( ( plugin ) => lines.push( plugin ) );
			lines.push( '' );
		}

		lines.push( '---' );
		return lines.join( '\n' );
	}, [ systemInfo, integrations, databaseTables, caps, stats ] );

	const handleCopyDiagnostics = useCallback( async () => {
		try {
			await window.navigator.clipboard.writeText( buildDiagnosticText() );
			message.success(
				__(
					'System status copied to clipboard.',
					'pressprimer-certificate'
				)
			);
		} catch ( clipboardError ) {
			try {
				const textArea = document.createElement( 'textarea' );
				textArea.value = buildDiagnosticText();
				textArea.style.position = 'fixed';
				textArea.style.left = '-9999px';
				document.body.appendChild( textArea );
				textArea.select();
				document.execCommand( 'copy' );
				document.body.removeChild( textArea );
				message.success(
					__(
						'System status copied to clipboard.',
						'pressprimer-certificate'
					)
				);
			} catch ( fallbackError ) {
				message.error(
					__(
						'Failed to copy. Please try again.',
						'pressprimer-certificate'
					)
				);
			}
		}
	}, [ buildDiagnosticText ] );

	return (
		<div>
			<div className="ppcert-status-copy-bar">
				<span className="ppcert-status-copy-bar-text">
					{ __(
						'Copy all diagnostic information to share with support.',
						'pressprimer-certificate'
					) }
				</span>
				<Button
					icon={ <CopyOutlined /> }
					onClick={ handleCopyDiagnostics }
				>
					{ __( 'Copy System Status', 'pressprimer-certificate' ) }
				</Button>
			</div>

			<div className="ppcert-status-grid">
				<div className="ppcert-settings-section">
					<Title
						level={ 4 }
						className="ppcert-settings-section-title"
					>
						{ __( 'Plugin', 'pressprimer-certificate' ) }
					</Title>

					<table className="ppcert-system-info">
						<tbody>
							<tr>
								<th>
									{ __(
										'Version',
										'pressprimer-certificate'
									) }
								</th>
								<td>{ systemInfo.pluginVersion || '1.0.0' }</td>
							</tr>
							<tr>
								<th>
									{ __(
										'DB Version',
										'pressprimer-certificate'
									) }
								</th>
								<td>
									{ systemInfo.dbVersion ||
										__(
											'Not set',
											'pressprimer-certificate'
										) }
								</td>
							</tr>
							{ Object.entries(
								systemInfo.addonVersions || {}
							).map( ( [ id, version ] ) => (
								<tr key={ id }>
									<th>{ id }</th>
									<td>{ version }</td>
								</tr>
							) ) }
						</tbody>
					</table>
				</div>

				<div className="ppcert-settings-section">
					<Title
						level={ 4 }
						className="ppcert-settings-section-title"
					>
						{ __( 'WordPress', 'pressprimer-certificate' ) }
					</Title>

					<table className="ppcert-system-info">
						<tbody>
							<tr>
								<th>
									{ __(
										'Site URL',
										'pressprimer-certificate'
									) }
								</th>
								<td>
									<code>
										{ systemInfo.siteUrl || 'Unknown' }
									</code>
								</td>
							</tr>
							<tr>
								<th>
									{ __(
										'Version',
										'pressprimer-certificate'
									) }
								</th>
								<td>
									{ formatVersionWithCheck(
										systemInfo.wpVersion,
										'6.4'
									) }
								</td>
							</tr>
							<tr>
								<th>
									{ __(
										'Multisite',
										'pressprimer-certificate'
									) }
								</th>
								<td>
									{ systemInfo.isMultisite ? (
										<Tag color="blue">
											{ __(
												'Yes',
												'pressprimer-certificate'
											) }
										</Tag>
									) : (
										<Tag>
											{ __(
												'No',
												'pressprimer-certificate'
											) }
										</Tag>
									) }
								</td>
							</tr>
							<tr>
								<th>
									{ __(
										'Memory Limit',
										'pressprimer-certificate'
									) }
								</th>
								<td>{ systemInfo.memoryLimit || 'Unknown' }</td>
							</tr>
							<tr>
								<th>
									{ __( 'Theme', 'pressprimer-certificate' ) }
								</th>
								<td>{ systemInfo.activeTheme || 'Unknown' }</td>
							</tr>
						</tbody>
					</table>
				</div>

				<div className="ppcert-settings-section">
					<Title
						level={ 4 }
						className="ppcert-settings-section-title"
					>
						{ __( 'Server', 'pressprimer-certificate' ) }
					</Title>

					<table className="ppcert-system-info">
						<tbody>
							<tr>
								<th>
									{ __(
										'PHP Version',
										'pressprimer-certificate'
									) }
								</th>
								<td>
									{ formatVersionWithCheck(
										systemInfo.phpVersion,
										'7.4'
									) }
								</td>
							</tr>
							<tr>
								<th>
									{ __(
										'Post Max Size',
										'pressprimer-certificate'
									) }
								</th>
								<td>{ systemInfo.postMaxSize || 'Unknown' }</td>
							</tr>
							<tr>
								<th>
									{ __(
										'Upload Max Filesize',
										'pressprimer-certificate'
									) }
								</th>
								<td>
									{ systemInfo.uploadMaxSize || 'Unknown' }
								</td>
							</tr>
							<tr>
								<th>
									{ __(
										'Time Limit',
										'pressprimer-certificate'
									) }
								</th>
								<td>
									{ systemInfo.maxExecutionTime || 'Unknown' }{ ' ' }
									{ systemInfo.maxExecutionTime &&
										__(
											'seconds',
											'pressprimer-certificate'
										) }
								</td>
							</tr>
							<tr>
								<th>
									{ __(
										'MySQL Version',
										'pressprimer-certificate'
									) }
								</th>
								<td>
									{ systemInfo.mysqlVersion || 'Unknown' }
								</td>
							</tr>
						</tbody>
					</table>
				</div>

				<div className="ppcert-settings-section">
					<Title
						level={ 4 }
						className="ppcert-settings-section-title"
					>
						{ __( 'Statistics', 'pressprimer-certificate' ) }
					</Title>

					<table className="ppcert-system-info">
						<tbody>
							<tr>
								<th>
									{ __(
										'Templates',
										'pressprimer-certificate'
									) }
								</th>
								<td>
									{ (
										stats.templates ?? 0
									).toLocaleString() }
								</td>
							</tr>
							<tr>
								<th>
									{ __(
										'Certificates',
										'pressprimer-certificate'
									) }
								</th>
								<td>
									{ (
										stats.certificates ?? 0
									).toLocaleString() }
								</td>
							</tr>
							<tr>
								<th>
									{ __(
										'Events',
										'pressprimer-certificate'
									) }
								</th>
								<td>
									{ ( stats.events ?? 0 ).toLocaleString() }
								</td>
							</tr>
						</tbody>
					</table>
				</div>
			</div>

			{ integrations.length > 0 && (
				<div className="ppcert-settings-section">
					<Title
						level={ 4 }
						className="ppcert-settings-section-title"
					>
						{ __( 'Integrations', 'pressprimer-certificate' ) }
					</Title>

					<table className="ppcert-system-info">
						<tbody>
							{ integrations.map( ( row ) => (
								<tr key={ row.label }>
									<th>{ row.label }</th>
									<td>
										{ row.active ? (
											<Space>
												<Tag
													icon={
														<CheckCircleOutlined />
													}
													color="success"
												>
													{ __(
														'Active',
														'pressprimer-certificate'
													) }
												</Tag>
												<span>{ row.version }</span>
											</Space>
										) : (
											<Tag>
												{ __(
													'Not Detected',
													'pressprimer-certificate'
												) }
											</Tag>
										) }
									</td>
								</tr>
							) ) }
						</tbody>
					</table>
				</div>
			) }

			<div className="ppcert-settings-section">
				<Title level={ 4 } className="ppcert-settings-section-title">
					{ __( 'PDF Rendering', 'pressprimer-certificate' ) }
				</Title>
				<Paragraph className="ppcert-settings-section-description">
					{ __(
						'Server capabilities for rendering certificate PDFs and preview images. GD is the baseline; Imagick with a PDF delegate produces the highest-fidelity previews.',
						'pressprimer-certificate'
					) }
				</Paragraph>

				<table className="ppcert-system-info">
					<tbody>
						<tr>
							<th>
								{ __(
									'Image Processing (GD)',
									'pressprimer-certificate'
								) }
							</th>
							<td>{ renderCapabilityTag( caps.gd ) }</td>
						</tr>
						<tr>
							<th>
								{ __( 'Imagick', 'pressprimer-certificate' ) }
							</th>
							<td>{ renderCapabilityTag( caps.imagick ) }</td>
						</tr>
						<tr>
							<th>
								{ __(
									'Imagick PDF Delegate',
									'pressprimer-certificate'
								) }
							</th>
							<td>{ renderCapabilityTag( caps.imagick_pdf ) }</td>
						</tr>
						<tr>
							<th>
								{ __(
									'Bundled Fonts',
									'pressprimer-certificate'
								) }
							</th>
							<td>{ caps.fonts ?? 0 }</td>
						</tr>
					</tbody>
				</table>
			</div>

			<div className="ppcert-settings-section">
				<Title level={ 4 } className="ppcert-settings-section-title">
					{ __( 'Database Tables', 'pressprimer-certificate' ) }
				</Title>

				{ hasMissingTables && (
					<Alert
						message={ __(
							'Missing Tables Detected',
							'pressprimer-certificate'
						) }
						description={ __(
							'Some database tables are missing. Click the repair button below to recreate them.',
							'pressprimer-certificate'
						) }
						type="warning"
						showIcon
						icon={ <ExclamationCircleOutlined /> }
						style={ { marginBottom: 16 } }
					/>
				) }

				<table className="ppcert-system-info">
					<thead>
						<tr>
							<th>
								{ __( 'Table', 'pressprimer-certificate' ) }
							</th>
							<th>
								{ __( 'Status', 'pressprimer-certificate' ) }
							</th>
							<th>{ __( 'Rows', 'pressprimer-certificate' ) }</th>
						</tr>
					</thead>
					<tbody>
						{ databaseTables.map( ( table ) => (
							<tr key={ table.name }>
								<td>
									<code>{ table.name }</code>
								</td>
								<td>
									{ table.exists ? (
										<Tag
											icon={ <CheckCircleOutlined /> }
											color="success"
										>
											{ __(
												'OK',
												'pressprimer-certificate'
											) }
										</Tag>
									) : (
										<Tag
											icon={ <CloseCircleOutlined /> }
											color="error"
										>
											{ __(
												'Missing',
												'pressprimer-certificate'
											) }
										</Tag>
									) }
								</td>
								<td>
									{ table.exists
										? table.row_count.toLocaleString()
										: '—' }
								</td>
							</tr>
						) ) }
					</tbody>
				</table>

				{ hasMissingTables && (
					<div style={ { marginTop: 16 } }>
						<Button
							type="primary"
							danger
							icon={ <ToolOutlined /> }
							onClick={ handleRepairTables }
							loading={ isRepairing }
						>
							{ __(
								'Repair Database Tables',
								'pressprimer-certificate'
							) }
						</Button>
					</div>
				) }
			</div>

			{ systemInfo.activePlugins &&
				systemInfo.activePlugins.length > 0 && (
					<div className="ppcert-settings-section">
						<Title
							level={ 4 }
							className="ppcert-settings-section-title"
						>
							{ sprintf(
								/* translators: %d: number of active plugins */
								__(
									'Active Plugins (%d)',
									'pressprimer-certificate'
								),
								systemInfo.activePlugins.length
							) }
						</Title>

						<div className="ppcert-status-plugin-list">
							{ systemInfo.activePlugins.map( ( plugin ) => (
								<span
									key={ plugin }
									className="ppcert-status-plugin-item"
								>
									{ plugin }
								</span>
							) ) }
						</div>
					</div>
				) }
		</div>
	);
};

export default StatusTab;
