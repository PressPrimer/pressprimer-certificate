/**
 * Appearance Tab Component
 *
 * Certificate design defaults (Prompt 5.2, Ryan's scope): default
 * font, default signature and logo images, and the primary + accent
 * brand colors - with a live sample-certificate preview so every
 * choice is visible before saving (fonts render in their real faces).
 * Blank certificates start from these defaults; starter templates get
 * the brand colors applied to their mapped colors when cloned.
 *
 * @package
 * @since 1.0.0
 */

import { useState, useEffect } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { Button, ColorPicker, Form, Select, Space, Typography } from 'antd';
import {
	DeleteOutlined,
	PictureOutlined,
	UndoOutlined,
} from '@ant-design/icons';

const { Title, Paragraph, Text } = Typography;

/**
 * Ant Design color object => hex string.
 *
 * @param {Object|string} color Color value.
 * @return {string} Hex.
 */
const colorToHex = ( color ) => {
	if ( ! color ) {
		return '';
	}
	if ( typeof color === 'string' ) {
		return color;
	}
	if ( color.toHexString ) {
		return color.toHexString();
	}
	return '';
};

/**
 * Resolve an attachment id to a preview URL through wp.media.
 *
 * @param {number}   attachmentId Attachment id.
 * @param {Function} setUrl       State setter for the URL.
 * @return {Function|undefined} Cleanup.
 */
const useAttachmentUrl = ( attachmentId, setUrl ) => {
	useEffect( () => {
		let cancelled = false;

		if ( ! attachmentId || ! window.wp || ! window.wp.media ) {
			setUrl( '' );
			return undefined;
		}

		const attachment = window.wp.media.attachment( attachmentId );

		attachment.fetch().then( () => {
			if ( cancelled ) {
				return;
			}

			const sizes = attachment.get( 'sizes' ) || {};
			setUrl(
				( sizes.medium && sizes.medium.url ) ||
					attachment.get( 'url' ) ||
					''
			);
		} );

		return () => {
			cancelled = true;
		};
	}, [ attachmentId, setUrl ] );
};

/**
 * Color setting with a clear affordance.
 *
 * @param {Object}   props          Component props.
 * @param {string}   props.label    Field label.
 * @param {string}   props.help     Help text.
 * @param {string}   props.value    Current hex ('' = not set).
 * @param {Function} props.onChange Change handler.
 */
const ColorSetting = ( { label, help, value, onChange } ) => {
	const hasValue = !! value;

	return (
		<div className="ppcert-settings-field">
			<Form.Item label={ label } help={ help }>
				<Space align="center" wrap>
					<ColorPicker
						value={ value || null }
						onChange={ ( color ) =>
							onChange( colorToHex( color ) )
						}
						disabledAlpha
						showText
					/>
					{ hasValue ? (
						<Button
							type="link"
							icon={ <UndoOutlined /> }
							onClick={ () => onChange( '' ) }
							size="small"
						>
							{ __( 'Clear', 'pressprimer-certificate' ) }
						</Button>
					) : (
						<Text type="secondary">
							({ __( 'Not set', 'pressprimer-certificate' ) })
						</Text>
					) }
				</Space>
			</Form.Item>
		</div>
	);
};

/**
 * Media-library image setting (attachment id).
 *
 * @param {Object}   props            Component props.
 * @param {string}   props.label      Field label.
 * @param {string}   props.help       Help text.
 * @param {number}   props.value      Attachment id (0 = none).
 * @param {string}   props.previewUrl Resolved thumbnail URL.
 * @param {Function} props.onChange   Change handler.
 */
const ImageSetting = ( { label, help, value, previewUrl, onChange } ) => {
	const attachmentId = Number( value ) || 0;

	const openPicker = () => {
		if ( ! window.wp || ! window.wp.media ) {
			return;
		}

		const frame = window.wp.media( {
			title: label,
			multiple: false,
			library: { type: 'image' },
		} );

		frame.on( 'select', () => {
			const attachment = frame
				.state()
				.get( 'selection' )
				.first()
				.toJSON();

			onChange( attachment.id );
		} );

		frame.open();
	};

	return (
		<div className="ppcert-settings-field">
			<Form.Item label={ label } help={ help }>
				<Space align="center" wrap>
					{ previewUrl && (
						<img
							src={ previewUrl }
							alt=""
							className="ppcert-settings-image-preview"
						/>
					) }
					<Button icon={ <PictureOutlined /> } onClick={ openPicker }>
						{ attachmentId
							? __( 'Change image', 'pressprimer-certificate' )
							: __( 'Choose image', 'pressprimer-certificate' ) }
					</Button>
					{ attachmentId > 0 && (
						<Button
							type="link"
							icon={ <DeleteOutlined /> }
							size="small"
							onClick={ () => onChange( 0 ) }
						>
							{ __( 'Remove', 'pressprimer-certificate' ) }
						</Button>
					) }
				</Space>
			</Form.Item>
		</div>
	);
};

/**
 * Live sample-certificate preview of the current (unsaved) choices.
 *
 * A lightweight HTML mock in the certificate's proportions - not the
 * real renderer, but every choice on this tab is visible: the font in
 * its actual face, both brand colors in their roles, and the default
 * logo and signature in place.
 *
 * @param {Object} props              Component props.
 * @param {string} props.font         Selected font slug ('' = default).
 * @param {string} props.primary      Primary hex ('' = default).
 * @param {string} props.accent       Accent hex ('' = default).
 * @param {string} props.logoUrl      Logo image URL.
 * @param {string} props.signatureUrl Signature image URL.
 */
const CertificatePreview = ( {
	font,
	primary,
	accent,
	logoUrl,
	signatureUrl,
} ) => {
	const fontFamily = `"${ font || 'source-sans-3' }"`;
	const primaryColor = primary || '#1f2a44';
	const accentColor = accent || '#b8860b';

	return (
		<div className="ppcert-appearance-preview">
			<div
				className="ppcert-appearance-preview__page"
				style={ { borderColor: accentColor } }
			>
				<div
					className="ppcert-appearance-preview__inner"
					style={ { borderColor: accentColor } }
				>
					{ logoUrl && (
						<img
							className="ppcert-appearance-preview__logo"
							src={ logoUrl }
							alt=""
						/>
					) }
					<div
						className="ppcert-appearance-preview__title"
						style={ { fontFamily, color: primaryColor } }
					>
						{ __(
							'Certificate of Completion',
							'pressprimer-certificate'
						) }
					</div>
					<div
						className="ppcert-appearance-preview__kicker"
						style={ { fontFamily } }
					>
						{ __(
							'This certificate is proudly presented to',
							'pressprimer-certificate'
						) }
					</div>
					<div
						className="ppcert-appearance-preview__name"
						style={ { fontFamily, color: primaryColor } }
					>
						Jordan Rivera
					</div>
					<div
						className="ppcert-appearance-preview__rule"
						style={ { background: accentColor } }
					/>
					<div
						className="ppcert-appearance-preview__body"
						style={ { fontFamily } }
					>
						{ __(
							'for successfully completing',
							'pressprimer-certificate'
						) }
					</div>
					<div className="ppcert-appearance-preview__signature">
						{ signatureUrl && (
							<img
								className="ppcert-appearance-preview__signature-image"
								src={ signatureUrl }
								alt=""
							/>
						) }
						<div className="ppcert-appearance-preview__signature-line" />
						<div
							className="ppcert-appearance-preview__signature-caption"
							style={ { fontFamily } }
						>
							{ __(
								'Authorized Signature',
								'pressprimer-certificate'
							) }
						</div>
					</div>
				</div>
			</div>
			<Text type="secondary">
				{ __(
					'Live preview of your defaults - a simplified sample, not a real template.',
					'pressprimer-certificate'
				) }
			</Text>
		</div>
	);
};

/**
 * Appearance Tab
 *
 * @param {Object}   props               Component props.
 * @param {Object}   props.settings      Current settings.
 * @param {Function} props.updateSetting Update a setting.
 * @param {Object}   props.settingsData  Full localized data.
 */
const AppearanceTab = ( { settings, updateSetting, settingsData } ) => {
	const fonts = settingsData.fonts || [];
	const [ signatureUrl, setSignatureUrl ] = useState( '' );
	const [ logoUrl, setLogoUrl ] = useState( '' );

	useAttachmentUrl(
		Number( settings.appearance_signature_id ) || 0,
		setSignatureUrl
	);
	useAttachmentUrl( Number( settings.appearance_logo_id ) || 0, setLogoUrl );

	return (
		<div>
			<div className="ppcert-settings-section">
				<Title level={ 4 } className="ppcert-settings-section-title">
					{ __( 'Design Defaults', 'pressprimer-certificate' ) }
				</Title>
				<Paragraph className="ppcert-settings-section-description">
					{ __(
						'New blank certificates start from these defaults. Certificates created from a starter template keep their design but take on your brand colors. The preview below shows every choice as you make it.',
						'pressprimer-certificate'
					) }
				</Paragraph>

				<div className="ppcert-appearance-columns">
					<div className="ppcert-appearance-controls">
						<div className="ppcert-settings-field">
							<Form.Item
								label={ __(
									'Default font',
									'pressprimer-certificate'
								) }
								help={ __(
									'Used for new text and merge-field elements.',
									'pressprimer-certificate'
								) }
							>
								<Select
									style={ { maxWidth: 320, width: '100%' } }
									popupMatchSelectWidth={ false }
									allowClear
									value={
										settings.appearance_default_font ||
										undefined
									}
									placeholder={ __(
										'Plugin default (Source Sans 3)',
										'pressprimer-certificate'
									) }
									onChange={ ( value ) =>
										updateSetting(
											'appearance_default_font',
											value || ''
										)
									}
									options={ fonts.map( ( font ) => ( {
										value: font.slug,
										label: (
											<span
												style={ {
													fontFamily: `"${ font.slug }"`,
													fontSize: 16,
												} }
											>
												{ font.label }
											</span>
										),
									} ) ) }
								/>
							</Form.Item>
						</div>

						<ImageSetting
							label={ __(
								'Default signature',
								'pressprimer-certificate'
							) }
							help={ __(
								'Pre-fills new Signature elements in the designer.',
								'pressprimer-certificate'
							) }
							value={ settings.appearance_signature_id }
							previewUrl={ signatureUrl }
							onChange={ ( value ) =>
								updateSetting(
									'appearance_signature_id',
									value
								)
							}
						/>

						<ImageSetting
							label={ __(
								'Default logo',
								'pressprimer-certificate'
							) }
							help={ __(
								'Pre-fills new Image / Logo elements in the designer.',
								'pressprimer-certificate'
							) }
							value={ settings.appearance_logo_id }
							previewUrl={ logoUrl }
							onChange={ ( value ) =>
								updateSetting( 'appearance_logo_id', value )
							}
						/>

						<ColorSetting
							label={ __(
								'Primary color',
								'pressprimer-certificate'
							) }
							help={ __(
								'Titles, names, and body text.',
								'pressprimer-certificate'
							) }
							value={ settings.appearance_primary_color || '' }
							onChange={ ( value ) =>
								updateSetting(
									'appearance_primary_color',
									value
								)
							}
						/>

						<ColorSetting
							label={ __(
								'Accent color',
								'pressprimer-certificate'
							) }
							help={ __(
								'Borders, rules, and decorative shapes.',
								'pressprimer-certificate'
							) }
							value={ settings.appearance_accent_color || '' }
							onChange={ ( value ) =>
								updateSetting(
									'appearance_accent_color',
									value
								)
							}
						/>
					</div>

					<CertificatePreview
						font={ settings.appearance_default_font || '' }
						primary={ settings.appearance_primary_color || '' }
						accent={ settings.appearance_accent_color || '' }
						logoUrl={ logoUrl }
						signatureUrl={ signatureUrl }
					/>
				</div>
			</div>
		</div>
	);
};

export default AppearanceTab;
