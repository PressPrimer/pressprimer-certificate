/**
 * Designer app shell (Feature 001 TR-001)
 *
 * Toolbar / left palette / canvas region / right tabs. Routes between
 * the template gallery (no template) and the designer (template loaded).
 *
 * @package
 */

import { useEffect, useRef, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';
import {
	Layout,
	Segmented,
	Select,
	Tabs,
	Tag,
	Tooltip,
	Typography,
	Button,
	Input,
	Spin,
	message,
	notification,
	Alert,
} from 'antd';
import {
	ArrowLeftOutlined,
	BorderOuterOutlined,
	CheckOutlined,
	EditOutlined,
	EyeOutlined,
	RedoOutlined,
	SaveOutlined,
	UndoOutlined,
} from '@ant-design/icons';
import { useDesignerStore } from '../hooks/useDesignerStore';
import { DesignerViewContext } from '../view-context';
import { loadMergeFields, getSampleMap } from '../mergeFields';
import {
	getTriggers,
	previewTemplate,
	saveTemplate,
	saveTriggers,
} from '../api';
import TemplateGallery from './TemplateGallery';
import Canvas from './Canvas';
import AlignToolbar from './AlignToolbar';
import ElementPalette from './ElementPalette';
import PropertiesPanel from './PropertiesPanel';
import TriggerPanel from './TriggerPanel';

const { Header, Sider, Content } = Layout;
const { Text } = Typography;

const STATUS_COLORS = {
	draft: 'default',
	published: 'green',
	archived: 'orange',
};

const ZOOM_OPTIONS = [
	{ value: 'fit', label: __( 'Fit width', 'pressprimer-certificate' ) },
	{ value: 0.5, label: '50%' },
	{ value: 0.75, label: '75%' },
	{ value: 1, label: '100%' },
	{ value: 1.25, label: '125%' },
	{ value: 1.5, label: '150%' },
	{ value: 2, label: '200%' },
];

/**
 * The shell.
 *
 * @param {Object} props      Props.
 * @param {Object} props.boot Localized ppcert_designer_data.
 * @return {JSX.Element} App.
 */
export default function DesignerApp( { boot } ) {
	const { state, dispatch } = useDesignerStore();
	const [ loading, setLoading ] = useState( boot.template_id > 0 );
	const [ loadError, setLoadError ] = useState( '' );
	const [ zoom, setZoom ] = useState( 'fit' );
	const [ tokenView, setTokenView ] = useState( false );
	const [ samples, setSamples ] = useState( {} );
	const [ rulers, setRulers ] = useState( true );
	const [ saving, setSaving ] = useState( false );
	const [ renaming, setRenaming ] = useState( false );
	const [ renameValue, setRenameValue ] = useState( '' );

	// Latest save callback for the keyboard shortcut (stable effect).
	const doSaveRef = useRef( null );
	const [ previewing, setPreviewing ] = useState( false );

	// Idempotent: Enter, blur, and the check button can all fire for
	// one edit; only the first changed value dispatches.
	const commitRename = () => {
		setRenaming( false );

		const clean = renameValue.trim();

		if ( clean && state.template && clean !== state.template.title ) {
			dispatch( { type: 'RENAME_TEMPLATE', title: clean } );
		}
	};

	const doSave = ( extra = {} ) => {
		if ( ! state.template || saving ) {
			return;
		}

		setSaving( true );

		saveTemplate( state.template.id, {
			layout: state.layout,
			title: state.template.title,
			settings: state.template.settings || {},
			expected_updated_at: state.template.updated_at,
			...extra,
		} )
			.then( ( template ) => {
				// Adopt the validator's rebuilt document (FR-007).
				dispatch( {
					type: 'ADOPT_SAVED',
					template,
					layout: template.layout,
				} );

				// Triggers persist alongside the layout (FR-007): the
				// replace-set response is the enriched server truth.
				if ( state.triggersDirty ) {
					return saveTriggers( template.id, state.triggers ).then(
						( rows ) => {
							dispatch( {
								type: 'SET_TRIGGERS',
								triggers: rows,
							} );
							return template;
						}
					);
				}

				return template;
			} )
			.then( ( template ) => {
				message.success(
					'published' === extra.status
						? __( 'Template published.', 'pressprimer-certificate' )
						: __( 'Template saved.', 'pressprimer-certificate' )
				);

				// Bridge for other bundles (the setup tour's publish
				// stop advances on a published save).
				window.dispatchEvent(
					new CustomEvent( 'ppcert:template-saved', {
						detail: {
							id: template.id,
							status: template.status,
						},
					} )
				);
			} )
			.catch( ( error ) => {
				if ( 'ppcert_template_conflict' === error?.code ) {
					notification.warning( {
						message: __(
							'Changed elsewhere',
							'pressprimer-certificate'
						),
						description: __(
							'This template was changed in another window since you opened it. Saving again will overwrite those changes.',
							'pressprimer-certificate'
						),
						btn: (
							<Button
								size="small"
								danger
								onClick={ () => {
									notification.destroy();
									doSave( { ...extra, force: true } );
								} }
							>
								{ __(
									'Save anyway',
									'pressprimer-certificate'
								) }
							</Button>
						),
						duration: 0,
					} );
					return;
				}

				message.error(
					error?.message ||
						__(
							'The template could not be saved.',
							'pressprimer-certificate'
						)
				);
			} )
			.finally( () => setSaving( false ) );
	};

	doSaveRef.current = doSave;

	const doPreview = () => {
		if ( ! state.template || previewing ) {
			return;
		}

		// Open synchronously so popup blockers allow it, then point the
		// tab at the rendered PDF.
		const tab = window.open( 'about:blank', '_blank' );
		setPreviewing( true );

		previewTemplate( state.template.id, state.layout )
			.then( ( { url } ) => {
				if ( tab ) {
					tab.location = url;
				}
			} )
			.catch( ( error ) => {
				if ( tab ) {
					tab.close();
				}

				message.error(
					error?.message ||
						__(
							'The preview could not be rendered.',
							'pressprimer-certificate'
						)
				);
			} )
			.finally( () => setPreviewing( false ) );
	};

	// Registry samples for merge-field canvas rendering (FR-004), scoped
	// to the template's trigger like the palette: a token whose trigger
	// was removed loses its sample and renders as its raw token - visible
	// feedback that it will not resolve.
	const triggerScope = ( state.triggers || [] )
		.map( ( trigger ) => trigger.trigger_type )
		.sort()
		.join( ',' );

	useEffect( () => {
		loadMergeFields(
			'' === triggerScope ? [] : triggerScope.split( ',' )
		).then( ( data ) => setSamples( getSampleMap( data ) ) );
	}, [ triggerScope ] );

	// Undo/redo + save shortcuts (FR-008/FR-007): Cmd/Ctrl+Z,
	// Shift+Cmd/Ctrl+Z or Ctrl+Y, Cmd/Ctrl+S. Skipped while typing.
	useEffect( () => {
		const onKeyDown = ( event ) => {
			const target = event.target;
			const typing =
				target &&
				( 'INPUT' === target.tagName ||
					'TEXTAREA' === target.tagName ||
					'SELECT' === target.tagName ||
					target.isContentEditable );

			if ( ! ( event.metaKey || event.ctrlKey ) ) {
				return;
			}

			const key = event.key.toLowerCase();

			if ( 's' === key ) {
				// Save works even from a field: users expect Cmd+S
				// everywhere, and it never destroys typing state.
				event.preventDefault();

				if ( doSaveRef.current ) {
					doSaveRef.current();
				}
				return;
			}

			if ( typing ) {
				return;
			}

			if ( 'z' === key ) {
				event.preventDefault();
				dispatch( { type: event.shiftKey ? 'REDO' : 'UNDO' } );
			} else if ( 'y' === key && event.ctrlKey ) {
				event.preventDefault();
				dispatch( { type: 'REDO' } );
			}
		};

		document.addEventListener( 'keydown', onKeyDown );

		return () => document.removeEventListener( 'keydown', onKeyDown );
	}, [ dispatch ] );

	// Triggers load once the template is known (deep link or create).
	useEffect( () => {
		if ( state.template && state.template.id > 0 ) {
			getTriggers( state.template.id ).then( ( rows ) =>
				dispatch( { type: 'SET_TRIGGERS', triggers: rows } )
			);
		}
	}, [ state.template && state.template.id, dispatch ] ); // eslint-disable-line react-hooks/exhaustive-deps -- keyed by the id, not the object.

	// Unsaved-changes navigation guard (FR-007).
	useEffect( () => {
		if ( ! state.dirty && ! state.triggersDirty ) {
			return undefined;
		}

		const onBeforeUnload = ( event ) => {
			event.preventDefault();
			event.returnValue = '';
		};

		window.addEventListener( 'beforeunload', onBeforeUnload );

		return () =>
			window.removeEventListener( 'beforeunload', onBeforeUnload );
	}, [ state.dirty, state.triggersDirty ] );

	// Deep link: ?action=edit&template_id=N loads directly.
	useEffect( () => {
		if ( boot.template_id > 0 ) {
			apiFetch( { path: `/ppcert/v1/templates/${ boot.template_id }` } )
				.then( ( template ) => {
					dispatch( {
						type: 'LOAD_TEMPLATE',
						template,
						layout: template.layout,
					} );
				} )
				.catch( ( error ) => {
					setLoadError(
						error?.message ||
							__(
								'The template could not be loaded.',
								'pressprimer-certificate'
							)
					);
				} )
				.finally( () => setLoading( false ) );
		}
	}, [ boot.template_id, dispatch ] );

	const onCreate = ( template ) => {
		dispatch( {
			type: 'LOAD_TEMPLATE',
			template,
			layout: template.layout,
		} );

		// Reflect the new template in the URL without a reload.
		const url = new URL( window.location.href );
		url.searchParams.set( 'action', 'edit' );
		url.searchParams.set( 'template_id', String( template.id ) );
		window.history.replaceState( {}, '', url.toString() );

		message.success(
			__(
				'Template created - happy designing!',
				'pressprimer-certificate'
			)
		);

		// Bridge for other bundles (the setup tour's pick-design stop
		// advances when the draft exists).
		window.dispatchEvent(
			new CustomEvent( 'ppcert:template-created', {
				detail: {
					id: template.id,
					status: template.status || 'draft',
				},
			} )
		);
	};

	if ( loading ) {
		return (
			<div className="ppcert-designer__loading">
				<Spin size="large" />
			</div>
		);
	}

	if ( loadError ) {
		return (
			<Alert
				type="error"
				showIcon
				message={ __(
					'Cannot open the designer',
					'pressprimer-certificate'
				) }
				description={ loadError }
				action={
					<Button href={ boot.list_url }>
						{ __( 'Back to Templates', 'pressprimer-certificate' ) }
					</Button>
				}
			/>
		);
	}

	if ( ! state.template ) {
		return (
			<TemplateGallery starters={ boot.starters } onCreate={ onCreate } />
		);
	}

	return (
		<DesignerViewContext.Provider value={ { tokenView, samples } }>
			<Layout className="ppcert-designer">
				<Header className="ppcert-designer__toolbar">
					<Button
						type="text"
						icon={ <ArrowLeftOutlined /> }
						onClick={ () => {
							if (
								( state.dirty || state.triggersDirty ) &&
								// eslint-disable-next-line no-alert -- Intentional navigation guard (FR-007); matches core editor behavior.
								! window.confirm(
									__(
										'You have unsaved changes. Leave without saving?',
										'pressprimer-certificate'
									)
								)
							) {
								return;
							}

							window.location.href = boot.list_url;
						} }
					>
						{ __( 'Templates', 'pressprimer-certificate' ) }
					</Button>
					{ /* Rename commits on Enter, on blur (click anywhere),
					     or via the clickable check button (Ryan,
					     2026-07-26: keyboard-only commit was a dead end
					     for mouse users). Escape cancels. */ }
					{ renaming ? (
						<Input
							className="ppcert-designer__title-input"
							size="small"
							// eslint-disable-next-line jsx-a11y/no-autofocus -- Focus moves in direct response to the user's rename click (click-to-edit), never on page load.
							autoFocus
							value={ renameValue }
							maxLength={ 200 }
							aria-label={ __(
								'Template name',
								'pressprimer-certificate'
							) }
							onChange={ ( e ) =>
								setRenameValue( e.target.value )
							}
							onPressEnter={ () => commitRename() }
							onBlur={ () => commitRename() }
							onKeyDown={ ( e ) => {
								if ( 'Escape' === e.key ) {
									// Reset to the current title first so
									// a late blur commit is a no-op.
									setRenameValue( state.template.title );
									setRenaming( false );
								}
							} }
							suffix={
								<Button
									type="text"
									size="small"
									icon={ <CheckOutlined /> }
									aria-label={ __(
										'Save name',
										'pressprimer-certificate'
									) }
									onMouseDown={ ( e ) => {
										// Commit here; preventDefault
										// stops the input's blur from
										// firing first and unmounting
										// this button mid-click.
										e.preventDefault();
										commitRename();
									} }
								/>
							}
						/>
					) : (
						/* Placement bottom: the toolbar sits directly
						   under the WP admin bar, and top-placed floating
						   UI disappears behind it. Nothing may render
						   under the admin bar (CLAUDE.md). */
						<Tooltip
							title={ __(
								'Rename template',
								'pressprimer-certificate'
							) }
							placement="bottom"
						>
							<Button
								type="text"
								className="ppcert-designer__title"
								onClick={ () => {
									setRenameValue( state.template.title );
									setRenaming( true );
								} }
							>
								<Text strong>{ state.template.title }</Text>
								<EditOutlined aria-hidden="true" />
							</Button>
						</Tooltip>
					) }
					<Tag
						color={
							STATUS_COLORS[ state.template.status ] || 'default'
						}
					>
						{ state.template.status }
					</Tag>
					<Button
						type="text"
						icon={ <UndoOutlined /> }
						disabled={ 0 === state.history.past.length }
						title={ __( 'Undo', 'pressprimer-certificate' ) }
						aria-label={ __( 'Undo', 'pressprimer-certificate' ) }
						onClick={ () => dispatch( { type: 'UNDO' } ) }
					/>
					<Button
						type="text"
						icon={ <RedoOutlined /> }
						disabled={ 0 === state.history.future.length }
						title={ __( 'Redo', 'pressprimer-certificate' ) }
						aria-label={ __( 'Redo', 'pressprimer-certificate' ) }
						onClick={ () => dispatch( { type: 'REDO' } ) }
					/>
					<AlignToolbar />
					<span className="ppcert-designer__toolbar-spacer" />
					<Button
						type={ rulers ? 'primary' : 'text' }
						ghost={ rulers }
						icon={ <BorderOuterOutlined /> }
						title={ __(
							'Toggle rulers',
							'pressprimer-certificate'
						) }
						aria-label={ __(
							'Toggle rulers',
							'pressprimer-certificate'
						) }
						onClick={ () => setRulers( ( r ) => ! r ) }
					/>
					<Segmented
						size="small"
						value={ tokenView }
						onChange={ setTokenView }
						aria-label={ __(
							'Merge field display',
							'pressprimer-certificate'
						) }
						options={ [
							{
								value: false,
								label: __(
									'Samples',
									'pressprimer-certificate'
								),
							},
							{
								value: true,
								label: __(
									'Tokens',
									'pressprimer-certificate'
								),
							},
						] }
					/>
					<Select
						size="small"
						value={ zoom }
						onChange={ setZoom }
						options={ ZOOM_OPTIONS }
						popupMatchSelectWidth={ false }
						aria-label={ __( 'Zoom', 'pressprimer-certificate' ) }
						className="ppcert-designer__zoom"
					/>
					<Button
						icon={ <EyeOutlined /> }
						loading={ previewing }
						onClick={ doPreview }
						data-ppcert-action="preview"
					>
						{ __( 'Preview PDF', 'pressprimer-certificate' ) }
					</Button>
					{ 'published' === state.template.status ? (
						<Button
							loading={ saving }
							onClick={ () => doSave( { status: 'draft' } ) }
							data-ppcert-action="unpublish"
						>
							{ __(
								'Switch to draft',
								'pressprimer-certificate'
							) }
						</Button>
					) : (
						<Button
							loading={ saving }
							onClick={ () => doSave( { status: 'published' } ) }
							data-ppcert-action="publish"
						>
							{ __( 'Publish', 'pressprimer-certificate' ) }
						</Button>
					) }
					<Button
						type="primary"
						icon={ <SaveOutlined /> }
						loading={ saving }
						disabled={
							! state.dirty && ! state.triggersDirty && ! saving
						}
						onClick={ () => doSave() }
						data-ppcert-action="save"
					>
						{ __( 'Save', 'pressprimer-certificate' ) }
					</Button>
				</Header>

				<Layout>
					<Sider
						width={ 200 }
						theme="light"
						className="ppcert-designer__palette"
					>
						<ElementPalette />
					</Sider>

					<Content className="ppcert-designer__canvas-region">
						<Canvas
							layout={ state.layout }
							zoom={ zoom }
							rulers={ rulers }
						/>
					</Content>

					<Sider
						width={ 300 }
						theme="light"
						className="ppcert-designer__sidebar"
					>
						<Tabs
							defaultActiveKey="design"
							items={ [
								{
									key: 'design',
									label: __(
										'Design',
										'pressprimer-certificate'
									),
									children: <PropertiesPanel />,
								},
								{
									key: 'award',
									label: __(
										'Award',
										'pressprimer-certificate'
									),
									children: <TriggerPanel />,
								},
							] }
						/>
					</Sider>
				</Layout>
			</Layout>
		</DesignerViewContext.Provider>
	);
}
