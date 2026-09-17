/**
 * Certificate Link block
 *
 * The [ppcert_certificate_link] equivalent (shortcode/block parity
 * rule, Feature 2.0-008): a dynamic block whose server render wraps the
 * shortcode handler. Every inspector control here mirrors a shortcode
 * attribute; the editor preview is static because the real output
 * depends on the viewing learner (hidden until they earn the
 * certificate).
 *
 * @package
 * @since 2.0.0
 */

import { registerBlockType } from '@wordpress/blocks';
import { useBlockProps, InspectorControls } from '@wordpress/block-editor';
import {
	PanelBody,
	RadioControl,
	SelectControl,
	TextControl,
	TextareaControl,
	ToggleControl,
	Notice,
} from '@wordpress/components';
import { __ } from '@wordpress/i18n';

/**
 * Award-with-link icon
 */
const linkIcon = (
	<svg
		xmlns="http://www.w3.org/2000/svg"
		viewBox="0 0 24 24"
		width="24"
		height="24"
	>
		<path
			fill="currentColor"
			d="M9 4a5 5 0 0 0-2.83 9.12V20l2.83-1.5L11.83 20v-6.88A5 5 0 0 0 9 4zm0 2a3 3 0 1 1 0 6 3 3 0 0 1 0-6zm7.5 3a1 1 0 0 0 0 2H18a2 2 0 0 1 0 4h-1.5a1 1 0 1 0 0 2H18a4 4 0 0 0 0-8h-1.5zm-3 2a1 1 0 0 0 0 2h4a1 1 0 1 0 0-2h-4z"
		/>
	</svg>
);

/**
 * Default label per action (mirrors the PHP renderer).
 *
 * @param {string} action view | download | verify.
 * @return {string} Label.
 */
function defaultLabel( action ) {
	if ( action === 'download' ) {
		return __( 'Download your certificate', 'pressprimer-certificate' );
	}
	if ( action === 'verify' ) {
		return __( 'Verify your certificate', 'pressprimer-certificate' );
	}
	return __( 'View your certificate', 'pressprimer-certificate' );
}

/**
 * Published templates localized at registration.
 *
 * @return {Array} Select options.
 */
function templateOptions() {
	const data = window.ppcert_certificate_link_block_data || {};
	const templates = Array.isArray( data.templates ) ? data.templates : [];

	return [
		{
			value: 0,
			label: __( 'Any template', 'pressprimer-certificate' ),
		},
		...templates.map( ( template ) => ( {
			value: template.id,
			label: template.title,
		} ) ),
	];
}

/**
 * Registered trigger types localized when the editor loads, for the
 * Kind of ID select. '' means "detect from the ID" (post-type
 * inference plus embedded detection).
 *
 * @return {Array} Select options.
 */
function sourceTypeOptions() {
	const data = window.ppcert_certificate_link_block_data || {};
	const types = Array.isArray( data.triggerTypes ) ? data.triggerTypes : [];
	return [
		{
			value: '',
			label: __( 'Detect from the ID', 'pressprimer-certificate' ),
		},
		...types.map( ( type ) => ( {
			value: type.id,
			label: type.label,
		} ) ),
	];
}

/**
 * Edit component: inspector controls plus a static preview.
 *
 * @param {Object}   props               Block props.
 * @param {Object}   props.attributes    Attributes.
 * @param {Function} props.setAttributes Setter.
 * @return {JSX.Element} Block edit component.
 */
function Edit( { attributes, setAttributes } ) {
	const blockProps = useBlockProps();
	const {
		source,
		sourceId,
		sourceType,
		template,
		action,
		text,
		message,
		style,
		newTab,
	} = attributes;

	const effectiveNewTab =
		newTab === null || newTab === undefined ? action === 'verify' : newTab;
	const label = text || defaultLabel( action );
	const typeOptions = sourceTypeOptions();
	let buttonClass = 'components-button is-primary';
	if ( style === 'link' ) {
		buttonClass = '';
	} else if ( action === 'verify' ) {
		buttonClass = 'components-button is-secondary';
	}

	return (
		<>
			<InspectorControls>
				<PanelBody
					title={ __(
						'Which certificate',
						'pressprimer-certificate'
					) }
				>
					<RadioControl
						label={ __( 'Source', 'pressprimer-certificate' ) }
						help={ __(
							'What the certificate was earned for. "This page" covers a course, lesson, topic, or quiz page, and any PressPrimer quiz or assignment placed on the page with its block or shortcode.',
							'pressprimer-certificate'
						) }
						selected={ source }
						options={ [
							{
								label: __(
									'This page',
									'pressprimer-certificate'
								),
								value: 'current',
							},
							{
								label: __(
									'A specific quiz, assignment, or post',
									'pressprimer-certificate'
								),
								value: 'specific',
							},
							{
								label: __(
									'Not scoped to a page (use the template)',
									'pressprimer-certificate'
								),
								value: 'none',
							},
						] }
						onChange={ ( value ) =>
							setAttributes( { source: value } )
						}
					/>
					{ source === 'specific' && (
						<TextControl
							label={ __(
								'Quiz, assignment, or post ID',
								'pressprimer-certificate'
							) }
							help={ __(
								'For a PressPrimer quiz or assignment, the ID shown in its list in the admin. For a course, lesson, topic, or LMS quiz, the post ID.',
								'pressprimer-certificate'
							) }
							type="number"
							min={ 1 }
							autoComplete="off"
							value={ sourceId || '' }
							onChange={ ( value ) =>
								setAttributes( {
									sourceId: parseInt( value, 10 ) || 0,
								} )
							}
						/>
					) }
					<SelectControl
						label={ __( 'Template', 'pressprimer-certificate' ) }
						help={
							source === 'none'
								? __(
										'Required when not scoped to a post.',
										'pressprimer-certificate'
								  )
								: __(
										'Optional. Narrows to certificates from one template.',
										'pressprimer-certificate'
								  )
						}
						value={ template }
						options={ templateOptions() }
						onChange={ ( value ) =>
							setAttributes( {
								template: parseInt( value, 10 ) || 0,
							} )
						}
					/>
					{ source === 'specific' && typeOptions.length > 1 && (
						<SelectControl
							label={ __(
								'Kind of ID',
								'pressprimer-certificate'
							) }
							help={ __(
								'A course, lesson, topic, or LMS quiz ID is a post ID and is detected on its own. A PressPrimer quiz or assignment ID is not a post, so choose its kind here.',
								'pressprimer-certificate'
							) }
							value={ sourceType }
							options={ typeOptions }
							onChange={ ( value ) =>
								setAttributes( { sourceType: value } )
							}
						/>
					) }
					<p className="components-base-control__help">
						{ __(
							'When the learner has earned this certificate more than once, the newest one is linked.',
							'pressprimer-certificate'
						) }
					</p>
				</PanelBody>
				<PanelBody title={ __( 'Link', 'pressprimer-certificate' ) }>
					<RadioControl
						label={ __( 'Action', 'pressprimer-certificate' ) }
						selected={ action }
						options={ [
							{
								label: __(
									'Open the certificate page',
									'pressprimer-certificate'
								),
								value: 'view',
							},
							{
								label: __(
									'Download the PDF',
									'pressprimer-certificate'
								),
								value: 'download',
							},
							{
								label: __(
									'Open the verification page',
									'pressprimer-certificate'
								),
								value: 'verify',
							},
						] }
						onChange={ ( value ) =>
							setAttributes( { action: value } )
						}
					/>
					<TextControl
						label={ __( 'Label', 'pressprimer-certificate' ) }
						placeholder={ defaultLabel( action ) }
						value={ text }
						onChange={ ( value ) =>
							setAttributes( { text: value } )
						}
					/>
					<TextareaControl
						label={ __(
							'Message shown with the button',
							'pressprimer-certificate'
						) }
						help={ __(
							'Optional. Appears in a panel above the button, and only when the button does, so it never shows to learners who have not earned the certificate yet. Example: Congratulations on completing the course! Download your certificate by clicking the button below.',
							'pressprimer-certificate'
						) }
						value={ message }
						rows={ 3 }
						onChange={ ( value ) =>
							setAttributes( { message: value } )
						}
					/>
					<ToggleControl
						label={ __(
							'Show as a button',
							'pressprimer-certificate'
						) }
						checked={ style !== 'link' }
						onChange={ ( checked ) =>
							setAttributes( {
								style: checked ? 'button' : 'link',
							} )
						}
					/>
					<ToggleControl
						label={ __(
							'Open in a new tab',
							'pressprimer-certificate'
						) }
						checked={ effectiveNewTab }
						onChange={ ( checked ) =>
							setAttributes( { newTab: checked } )
						}
					/>
				</PanelBody>
			</InspectorControls>
			<div { ...blockProps }>
				{ message ? (
					<p className="ppcert-certificate-link__message">
						{ message }
					</p>
				) : null }
				{ style === 'link' ? (
					<a
						href="#preview"
						onClick={ ( event ) => event.preventDefault() }
					>
						{ label }
					</a>
				) : (
					<button type="button" disabled className={ buttonClass }>
						{ label }
					</button>
				) }
				<Notice status="info" isDismissible={ false }>
					{ __(
						'Shows only to learners who have earned this certificate. Everyone else sees nothing.',
						'pressprimer-certificate'
					) }
				</Notice>
			</div>
		</>
	);
}

registerBlockType( 'pressprimer-certificate/certificate-link', {
	apiVersion: 3,
	title: __( 'Certificate Link', 'pressprimer-certificate' ),
	description: __(
		"A button to the logged-in learner's certificate for this course, lesson, quiz, or assignment. Shows only once it is earned.",
		'pressprimer-certificate'
	),
	category: 'pressprimer-certificate',
	icon: linkIcon,
	supports: {
		html: false,
		align: true,
	},
	attributes: {
		source: { type: 'string', default: 'current' },
		sourceId: { type: 'integer', default: 0 },
		sourceType: { type: 'string', default: '' },
		template: { type: 'integer', default: 0 },
		action: { type: 'string', default: 'view' },
		text: { type: 'string', default: '' },
		message: { type: 'string', default: '' },
		style: { type: 'string', default: 'button' },
		newTab: { type: [ 'boolean', 'null' ], default: null },
	},
	edit: Edit,
	// Dynamic block: output comes from the server render callback.
	save: () => null,
} );
