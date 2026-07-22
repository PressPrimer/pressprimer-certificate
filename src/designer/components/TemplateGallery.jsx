/**
 * Template gallery - the designer's front door (Feature 001 FR-001)
 *
 * Starter cards plus a low-emphasis "Start blank" final card. Choosing
 * one POSTs /ppcert/v1/templates and opens the designer with the new
 * template loaded.
 *
 * @package
 */

import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';
import { Card, Col, Row, Typography, message, Spin } from 'antd';
import { FileAddOutlined } from '@ant-design/icons';

const { Title, Paragraph, Text } = Typography;

/**
 * A miniature non-interactive preview of a starter layout.
 *
 * @param {Object} props        Props.
 * @param {Object} props.layout Starter layout document.
 * @return {JSX.Element} Thumbnail.
 */
function StarterThumb( { layout } ) {
	const pageW = layout?.page?.width || 842;
	const pageH = layout?.page?.height || 595;
	const scale = 220 / pageW;

	return (
		<div
			className="ppcert-designer__thumb"
			style={ {
				width: pageW * scale,
				height: pageH * scale,
				background: layout?.background?.color || '#ffffff',
			} }
		>
			{ ( layout?.elements || [] ).map( ( element ) => {
				const style = {
					position: 'absolute',
					left: element.x * scale,
					top: element.y * scale,
					width: element.w * scale,
					height: element.h * scale,
				};

				if ( element.type === 'shape' ) {
					style.border =
						element.props.stroke_width > 0
							? `${ Math.max(
									1,
									element.props.stroke_width * scale
							  ) }px solid ${ element.props.stroke_color }`
							: 'none';
					style.background =
						element.props.fill_color || 'transparent';
				} else if ( element.type === 'qr' ) {
					style.background = element.props.dark_color;
					style.opacity = 0.85;
				} else {
					style.background = element.props.color || '#9ca3af';
					style.opacity = 0.35;
					style.borderRadius = 1;
				}

				return <div key={ element.id } style={ style } />;
			} ) }
		</div>
	);
}

/**
 * The gallery.
 *
 * @param {Object}   props          Props.
 * @param {Array}    props.starters Localized starter definitions.
 * @param {Function} props.onCreate Callback receiving the created template.
 * @return {JSX.Element} Gallery.
 */
export default function TemplateGallery( { starters, onCreate } ) {
	const [ creating, setCreating ] = useState( '' );

	const create = async ( starterSlug ) => {
		setCreating( starterSlug || 'blank' );

		try {
			const template = await apiFetch( {
				path: '/ppcert/v1/templates',
				method: 'POST',
				data: { starter: starterSlug },
			} );

			onCreate( template );
		} catch ( error ) {
			message.error(
				error?.message ||
					__(
						'The template could not be created.',
						'pressprimer-certificate'
					)
			);
			setCreating( '' );
		}
	};

	return (
		<div className="ppcert-designer__gallery">
			<Title level={ 3 }>
				{ __( 'Start from a template', 'pressprimer-certificate' ) }
			</Title>
			<Paragraph type="secondary">
				{ __(
					'Pick a starting point - everything is editable on the canvas.',
					'pressprimer-certificate'
				) }
			</Paragraph>

			<Row gutter={ [ 16, 16 ] }>
				{ starters.map( ( starter ) => (
					<Col key={ starter.slug }>
						<Card
							hoverable
							className="ppcert-designer__gallery-card"
							onClick={ () => create( starter.slug ) }
							cover={
								creating === starter.slug ? (
									<div className="ppcert-designer__thumb-loading">
										<Spin />
									</div>
								) : (
									<StarterThumb layout={ starter.layout } />
								)
							}
						>
							<Card.Meta title={ starter.label } />
						</Card>
					</Col>
				) ) }

				<Col>
					<Card
						hoverable
						className="ppcert-designer__gallery-card ppcert-designer__gallery-card--blank"
						onClick={ () => create( '' ) }
					>
						{ creating === 'blank' ? (
							<Spin />
						) : (
							<>
								<FileAddOutlined className="ppcert-designer__blank-icon" />
								<Text type="secondary">
									{ __(
										'Start blank',
										'pressprimer-certificate'
									) }
								</Text>
							</>
						) }
					</Card>
				</Col>
			</Row>
		</div>
	);
}
