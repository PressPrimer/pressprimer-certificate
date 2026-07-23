/**
 * General Tab Component
 *
 * The verification page mapping (Feature 008 FR-004): which page hosts
 * [ppcert_verify] / the verification block - QR codes and email links
 * resolve against it.
 *
 * @package
 * @since 1.0.0
 */

import { __ } from '@wordpress/i18n';
import { Form, Select, Typography, Alert } from 'antd';

const { Title, Paragraph, Text } = Typography;

/**
 * General Tab
 *
 * @param {Object}   props               Component props.
 * @param {Object}   props.settings      Current settings.
 * @param {Function} props.updateSetting Update a setting.
 * @param {Object}   props.settingsData  Full localized data.
 */
const GeneralTab = ( { settings, updateSetting, settingsData } ) => {
	const pages = settingsData.pages || [];
	const pageId = Number( settings.verification_page_id ) || 0;
	const pageExists =
		0 === pageId || pages.some( ( page ) => page.id === pageId );

	return (
		<div>
			<div className="ppcert-settings-section">
				<Title level={ 4 } className="ppcert-settings-section-title">
					{ __( 'Verification', 'pressprimer-certificate' ) }
				</Title>
				<Paragraph className="ppcert-settings-section-description">
					{ __(
						'The public page where visitors verify certificates. QR codes on rendered certificates and links in issuance emails point here.',
						'pressprimer-certificate'
					) }
				</Paragraph>

				{ ! pageExists && (
					<Alert
						type="warning"
						showIcon
						style={ { marginBottom: 16 } }
						message={ __(
							'The configured verification page no longer exists.',
							'pressprimer-certificate'
						) }
						description={ __(
							'Certificate QR codes and links will not resolve until you assign a new page. The page needs the Certificate Verification block or the [ppcert_verify] shortcode.',
							'pressprimer-certificate'
						) }
					/>
				) }

				<div className="ppcert-settings-field">
					<Form.Item
						label={ __(
							'Verification page',
							'pressprimer-certificate'
						) }
					>
						<Select
							style={ { maxWidth: 420, width: '100%' } }
							popupMatchSelectWidth={ false }
							showSearch
							optionFilterProp="label"
							value={ pageId || undefined }
							placeholder={ __(
								'Choose a page…',
								'pressprimer-certificate'
							) }
							onChange={ ( value ) =>
								updateSetting(
									'verification_page_id',
									value || 0
								)
							}
							options={ pages.map( ( page ) => ( {
								value: page.id,
								label: page.title,
							} ) ) }
						/>
					</Form.Item>
					<Text type="secondary">
						{ __(
							'The page should contain the Certificate Verification block (or the [ppcert_verify] shortcode).',
							'pressprimer-certificate'
						) }
					</Text>
				</div>
			</div>
		</div>
	);
};

export default GeneralTab;
