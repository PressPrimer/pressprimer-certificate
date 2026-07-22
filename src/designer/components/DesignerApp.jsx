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
	Select,
	Switch,
	Tabs,
	Tag,
	Typography,
	Button,
	Spin,
	message,
	notification,
	Alert,
} from 'antd';
import {
	ArrowLeftOutlined,
	BorderOuterOutlined,
	EyeOutlined,
	RedoOutlined,
	SaveOutlined,
	UndoOutlined,
} from '@ant-design/icons';
import { useDesignerStore } from '../hooks/useDesignerStore';
import { DesignerViewContext } from '../view-context';
import { loadMergeFields, getSampleMap } from '../mergeFields';
import { previewTemplate, saveTemplate } from '../api';
import TemplateGallery from './TemplateGallery';
import Canvas from './Canvas';
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

	// Latest save callback for the keyboard shortcut (stable effect).
	const doSaveRef = useRef( null );
	const [ previewing, setPreviewing ] = useState( false );

	const doSave = ( extra = {} ) => {
		if ( ! state.template || saving ) {
			return;
		}

		setSaving( true );

		saveTemplate( state.template.id, {
			layout: state.layout,
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

				message.success(
					'published' === extra.status
						? __( 'Template published.', 'pressprimer-certificate' )
						: __( 'Template saved.', 'pressprimer-certificate' )
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

	// Registry samples for merge-field canvas rendering (FR-004).
	useEffect( () => {
		loadMergeFields().then( () => setSamples( getSampleMap() ) );
	}, [] );

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

	// Unsaved-changes navigation guard (FR-007).
	useEffect( () => {
		if ( ! state.dirty ) {
			return undefined;
		}

		const onBeforeUnload = ( event ) => {
			event.preventDefault();
			event.returnValue = '';
		};

		window.addEventListener( 'beforeunload', onBeforeUnload );

		return () =>
			window.removeEventListener( 'beforeunload', onBeforeUnload );
	}, [ state.dirty ] );

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
								state.dirty &&
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
					<Text strong className="ppcert-designer__title">
						{ state.template.title }
					</Text>
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
					<span className="ppcert-designer__token-toggle">
						<Text type="secondary">
							{ __( 'Tokens', 'pressprimer-certificate' ) }
						</Text>
						<Switch
							size="small"
							checked={ tokenView }
							onChange={ setTokenView }
							aria-label={ __(
								'Show raw merge tokens',
								'pressprimer-certificate'
							) }
						/>
					</span>
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
						disabled={ ! state.dirty && ! saving }
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
