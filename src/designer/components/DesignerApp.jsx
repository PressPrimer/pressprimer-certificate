/**
 * Designer app shell (Feature 001 TR-001)
 *
 * Toolbar / left palette / canvas region / right tabs. Routes between
 * the template gallery (no template) and the designer (template loaded).
 *
 * @package
 */

import { useEffect, useState } from '@wordpress/element';
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
	Alert,
} from 'antd';
import {
	ArrowLeftOutlined,
	BorderOuterOutlined,
	RedoOutlined,
	UndoOutlined,
} from '@ant-design/icons';
import { useDesignerStore } from '../hooks/useDesignerStore';
import { DesignerViewContext } from '../view-context';
import { loadMergeFields, getSampleMap } from '../mergeFields';
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

	// Registry samples for merge-field canvas rendering (FR-004).
	useEffect( () => {
		loadMergeFields().then( () => setSamples( getSampleMap() ) );
	}, [] );

	// Undo/redo shortcuts (FR-008): Cmd/Ctrl+Z, Shift+Cmd/Ctrl+Z or
	// Ctrl+Y. Skipped while typing in a field.
	useEffect( () => {
		const onKeyDown = ( event ) => {
			const target = event.target;
			const typing =
				target &&
				( 'INPUT' === target.tagName ||
					'TEXTAREA' === target.tagName ||
					'SELECT' === target.tagName ||
					target.isContentEditable );

			if ( typing || ! ( event.metaKey || event.ctrlKey ) ) {
				return;
			}

			const key = event.key.toLowerCase();

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
						href={ boot.list_url }
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
					<Text type="secondary">
						{ __(
							'Saving and preview arrive in the next steps.',
							'pressprimer-certificate'
						) }
					</Text>
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
