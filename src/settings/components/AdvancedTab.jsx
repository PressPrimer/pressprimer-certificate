/**
 * Advanced Tab Component
 *
 * Data retention (the ppcert_prune_events cleanup window) and the
 * danger zone, following the Quiz/Assignment Advanced-tab pattern.
 *
 * @package
 * @since 1.0.0
 */

import { __ } from '@wordpress/i18n';
import { Form, InputNumber, Switch, Typography, Alert } from 'antd';
import { WarningOutlined } from '@ant-design/icons';

const { Title, Paragraph, Text } = Typography;

/**
 * Advanced Tab
 *
 * @param {Object}   props               Component props.
 * @param {Object}   props.settings      Current settings.
 * @param {Function} props.updateSetting Update a setting.
 */
const AdvancedTab = ( { settings, updateSetting } ) => {
	// Only an explicitly truthy stored value enables removal - the
	// default is ALWAYS off (house rule from Quiz/Assignment).
	const isRemoveDataEnabled =
		settings.remove_data_on_uninstall === true ||
		settings.remove_data_on_uninstall === '1' ||
		settings.remove_data_on_uninstall === 1;

	return (
		<div>
			<div className="ppcert-settings-section">
				<Title level={ 4 } className="ppcert-settings-section-title">
					{ __( 'Data Retention', 'pressprimer-certificate' ) }
				</Title>
				<Paragraph className="ppcert-settings-section-description">
					{ __(
						'Anonymous verification and view events are pruned daily once they pass this age. Issuance and download events are lifecycle records and are always kept.',
						'pressprimer-certificate'
					) }
				</Paragraph>

				<div className="ppcert-settings-field">
					<Form.Item
						label={ __(
							'Keep verification events for (days)',
							'pressprimer-certificate'
						) }
					>
						<InputNumber
							min={ 7 }
							max={ 3650 }
							value={
								settings.events_retention_days === undefined
									? 90
									: Number( settings.events_retention_days )
							}
							onChange={ ( value ) =>
								updateSetting(
									'events_retention_days',
									value === null ? 90 : value
								)
							}
						/>
					</Form.Item>
				</div>
			</div>

			<div className="ppcert-settings-section ppcert-danger-zone">
				<Title level={ 4 } className="ppcert-settings-section-title">
					<WarningOutlined style={ { marginRight: 8 } } />
					{ __( 'Danger Zone', 'pressprimer-certificate' ) }
				</Title>
				<Paragraph className="ppcert-settings-section-description">
					{ __(
						'These settings can result in permanent data loss. Use with caution.',
						'pressprimer-certificate'
					) }
				</Paragraph>

				<div className="ppcert-settings-field">
					<Form.Item
						label={ __(
							'Remove Data on Uninstall',
							'pressprimer-certificate'
						) }
					>
						<Switch
							checked={ isRemoveDataEnabled }
							onChange={ ( checked ) =>
								updateSetting(
									'remove_data_on_uninstall',
									checked ? 1 : 0
								)
							}
						/>
						<Text type="secondary" style={ { marginLeft: 12 } }>
							{ __(
								'Remove all plugin data when uninstalling',
								'pressprimer-certificate'
							) }
						</Text>
					</Form.Item>

					<Alert
						message={ __( 'Warning', 'pressprimer-certificate' ) }
						description={
							<>
								<Paragraph style={ { marginBottom: 8 } }>
									{ __(
										'If enabled, uninstalling this plugin will permanently delete:',
										'pressprimer-certificate'
									) }
								</Paragraph>
								<ul
									style={ {
										marginBottom: 8,
										paddingLeft: 20,
									} }
								>
									<li>
										{ __(
											'All certificate templates',
											'pressprimer-certificate'
										) }
									</li>
									<li>
										{ __(
											'All issued certificates and their snapshots',
											'pressprimer-certificate'
										) }
									</li>
									<li>
										{ __(
											'All verification history and events',
											'pressprimer-certificate'
										) }
									</li>
									<li>
										{ __(
											'All cached preview images and generated files',
											'pressprimer-certificate'
										) }
									</li>
									<li>
										{ __(
											'All plugin settings',
											'pressprimer-certificate'
										) }
									</li>
								</ul>
								<Paragraph style={ { marginBottom: 0 } }>
									<strong>
										{ __(
											'Issued certificates can no longer be verified after removal. This action cannot be undone!',
											'pressprimer-certificate'
										) }
									</strong>
								</Paragraph>
							</>
						}
						type="warning"
						showIcon
						icon={ <WarningOutlined /> }
					/>

					<Paragraph type="secondary" style={ { marginTop: 12 } }>
						{ __(
							'By default, data is preserved when you uninstall the plugin to prevent accidental data loss. Only enable this if you are certain you want to completely remove all data.',
							'pressprimer-certificate'
						) }
					</Paragraph>
				</div>
			</div>
		</div>
	);
};

export default AdvancedTab;
