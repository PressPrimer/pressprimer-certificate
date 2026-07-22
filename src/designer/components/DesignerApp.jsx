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
	Tabs,
	Tag,
	Typography,
	Button,
	Spin,
	message,
	Alert,
} from 'antd';
import { ArrowLeftOutlined } from '@ant-design/icons';
import { useDesignerStore } from '../hooks/useDesignerStore';
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
				<span className="ppcert-designer__toolbar-spacer" />
				<Text type="secondary">
					{ __(
						'Editing, saving, and preview arrive in the next steps.',
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
					<Canvas layout={ state.layout } />
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
								label: __( 'Award', 'pressprimer-certificate' ),
								children: <TriggerPanel />,
							},
						] }
					/>
				</Sider>
			</Layout>
		</Layout>
	);
}
