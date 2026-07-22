/**
 * Trigger panel - "Award this certificate when..." (Feature 001 FR-006)
 *
 * Lists this template's triggers with an add/edit flow: trigger type
 * (registered adapters only) -> source search (the adapter's
 * get_sources) -> conditions form generated from the adapter's schema
 * -> active toggle. Edits stage in the store (outside the undo stack,
 * FR-008) and persist with the toolbar Save.
 *
 * Manual issuance is always available and shown as copy, never as a
 * trigger row. With no adapters detected the empty state explains how
 * to unlock automatic awarding (Edge US-5). Rows whose source no
 * longer resolves - or whose adapter was deactivated - carry a warning
 * badge.
 */

import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import {
	Alert,
	Button,
	Empty,
	Input,
	InputNumber,
	List,
	Modal,
	Select,
	Switch,
	Tag,
	Tooltip,
	Typography,
} from 'antd';
import {
	PlusOutlined,
	EditOutlined,
	DeleteOutlined,
	WarningOutlined,
} from '@ant-design/icons';
import { useDesignerStore } from '../hooks/useDesignerStore';
import { getTriggerTypes, getTriggerSources } from '../api';

const { Text, Paragraph } = Typography;

/**
 * Default conditions per a schema (field defaults).
 *
 * @param {Object} schema Conditions schema.
 * @return {Object} Conditions.
 */
function schemaDefaults( schema ) {
	const conditions = {};

	Object.keys( schema || {} ).forEach( ( key ) => {
		conditions[ key ] = schema[ key ].default;
	} );

	return conditions;
}

/**
 * One generated conditions field.
 *
 * @param {Object}   props          Props.
 * @param {string}   props.fieldKey Schema key.
 * @param {Object}   props.field    Schema field.
 * @param {*}        props.value    Current value.
 * @param {Function} props.onChange Value setter.
 * @return {JSX.Element} Field row.
 */
function ConditionField( { fieldKey, field, value, onChange } ) {
	let control;

	switch ( field.type ) {
		case 'number':
			control = (
				<InputNumber
					size="small"
					min={ field.min }
					max={ field.max }
					value={ null === value ? undefined : value }
					data-ppcert-condition={ fieldKey }
					onChange={ ( next ) =>
						onChange( 'number' === typeof next ? next : null )
					}
				/>
			);
			break;

		case 'toggle':
			control = (
				<Switch
					size="small"
					checked={ !! value }
					data-ppcert-condition={ fieldKey }
					onChange={ onChange }
				/>
			);
			break;

		case 'select':
			control = (
				<Select
					size="small"
					value={ value }
					data-ppcert-condition={ fieldKey }
					popupMatchSelectWidth={ false }
					onChange={ onChange }
					options={ ( field.options || [] ).map( ( option ) => ( {
						value: option,
						label: option,
					} ) ) }
					className="ppcert-designer__prop-wide"
				/>
			);
			break;

		default:
			control = (
				<Input
					size="small"
					value={ value || '' }
					data-ppcert-condition={ fieldKey }
					onChange={ ( event ) => onChange( event.target.value ) }
				/>
			);
	}

	return (
		<div className="ppcert-designer__prop-row">
			<span className="ppcert-designer__trigger-label">
				{ field.label }
			</span>
			<span className="ppcert-designer__prop-control">{ control }</span>
		</div>
	);
}

/**
 * The add/edit trigger modal.
 *
 * @param {Object}   props          Props.
 * @param {boolean}  props.open     Visibility.
 * @param {Array}    props.types    Registered trigger types.
 * @param {Object}   props.initial  Trigger being edited (null = new).
 * @param {Function} props.onSubmit Receives the trigger payload.
 * @param {Function} props.onClose  Close handler.
 * @return {JSX.Element} Modal.
 */
function TriggerModal( { open, types, initial, onSubmit, onClose } ) {
	const [ typeId, setTypeId ] = useState( null );
	const [ sourceRef, setSourceRef ] = useState( null );
	const [ sourceLabel, setSourceLabel ] = useState( '' );
	const [ sources, setSources ] = useState( [] );
	const [ conditions, setConditions ] = useState( {} );
	const [ isActive, setIsActive ] = useState( true );

	const type = types.find( ( t ) => t.id === typeId ) || null;

	// (Re)initialize whenever the modal opens.
	useEffect( () => {
		if ( ! open ) {
			return;
		}

		if ( initial ) {
			setTypeId( initial.trigger_type );
			setSourceRef( initial.source_ref );
			setSourceLabel( initial.source_label || initial.source_ref || '' );
			setConditions( { ...initial.conditions } );
			setIsActive( initial.is_active );
		} else {
			setTypeId( null );
			setSourceRef( null );
			setSourceLabel( '' );
			setConditions( {} );
			setIsActive( true );
		}

		setSources( [] );
	}, [ open, initial ] );

	// Initial source list when a type with sources is chosen.
	useEffect( () => {
		if ( ! open || ! type || ! type.has_sources ) {
			return;
		}

		getTriggerSources( type.id ).then( setSources );
	}, [ open, type ] );

	const onTypeChange = ( next ) => {
		setTypeId( next );
		setSourceRef( null );
		setSourceLabel( '' );

		const nextType = types.find( ( t ) => t.id === next );
		setConditions(
			schemaDefaults( nextType ? nextType.conditions_schema : {} )
		);
	};

	const canSubmit = !! type && ( ! type.has_sources || null !== sourceRef );

	return (
		<Modal
			open={ open }
			onCancel={ onClose }
			okText={
				initial
					? __( 'Update trigger', 'pressprimer-certificate' )
					: __( 'Add trigger', 'pressprimer-certificate' )
			}
			okButtonProps={ {
				disabled: ! canSubmit,
				'data-ppcert-trigger-submit': true,
			} }
			onOk={ () => {
				onSubmit( {
					trigger_type: type.id,
					type_label: type.label,
					type_available: true,
					source_ref: sourceRef,
					source_label: sourceLabel,
					source_found: true,
					conditions,
					is_active: isActive,
				} );
				onClose();
			} }
			title={
				initial
					? __( 'Edit trigger', 'pressprimer-certificate' )
					: __( 'Add trigger', 'pressprimer-certificate' )
			}
		>
			<div className="ppcert-designer__trigger-form">
				<div className="ppcert-designer__prop-row">
					<span className="ppcert-designer__trigger-label">
						{ __( 'When', 'pressprimer-certificate' ) }
					</span>
					<span className="ppcert-designer__prop-control">
						<Select
							size="small"
							value={ typeId }
							data-ppcert-trigger-type
							placeholder={ __(
								'Choose a trigger…',
								'pressprimer-certificate'
							) }
							popupMatchSelectWidth={ false }
							onChange={ onTypeChange }
							options={ types.map( ( t ) => ( {
								value: t.id,
								label: t.label,
							} ) ) }
							className="ppcert-designer__prop-wide"
						/>
					</span>
				</div>

				{ type && type.has_sources && (
					<div className="ppcert-designer__prop-row">
						<span className="ppcert-designer__trigger-label">
							{ __( 'Source', 'pressprimer-certificate' ) }
						</span>
						<span className="ppcert-designer__prop-control">
							<Select
								size="small"
								showSearch
								value={ sourceRef }
								data-ppcert-trigger-source
								placeholder={ __(
									'Search…',
									'pressprimer-certificate'
								) }
								filterOption={ false }
								popupMatchSelectWidth={ false }
								onSearch={ ( term ) =>
									getTriggerSources( type.id, term ).then(
										setSources
									)
								}
								onChange={ ( id, option ) => {
									setSourceRef( id );
									setSourceLabel( option.label );
								} }
								options={ sources.map( ( source ) => ( {
									value: source.id,
									label: source.title,
								} ) ) }
								className="ppcert-designer__prop-wide"
							/>
						</span>
					</div>
				) }

				{ type &&
					Object.keys( type.conditions_schema || {} ).map(
						( key ) => (
							<ConditionField
								key={ key }
								fieldKey={ key }
								field={ type.conditions_schema[ key ] }
								value={ conditions[ key ] }
								onChange={ ( value ) =>
									setConditions( ( prev ) => ( {
										...prev,
										[ key ]: value,
									} ) )
								}
							/>
						)
					) }

				{ type && (
					<div className="ppcert-designer__prop-row">
						<span className="ppcert-designer__trigger-label">
							{ __( 'Active', 'pressprimer-certificate' ) }
						</span>
						<span className="ppcert-designer__prop-control">
							<Switch
								size="small"
								checked={ isActive }
								data-ppcert-trigger-active
								onChange={ setIsActive }
							/>
						</span>
					</div>
				) }
			</div>
		</Modal>
	);
}

/**
 * The panel.
 *
 * @return {JSX.Element} Panel.
 */
export default function TriggerPanel() {
	const { state, dispatch } = useDesignerStore();
	const [ types, setTypes ] = useState( null );
	const [ modalOpen, setModalOpen ] = useState( false );
	const [ editIndex, setEditIndex ] = useState( null );

	useEffect( () => {
		getTriggerTypes().then( setTypes );
	}, [] );

	const triggers = state.triggers || [];

	const edit = ( next ) => {
		dispatch( { type: 'EDIT_TRIGGERS', triggers: next } );
	};

	const onSubmit = ( trigger ) => {
		if ( null === editIndex ) {
			edit( [ ...triggers, trigger ] );
		} else {
			edit(
				triggers.map( ( row, index ) =>
					index === editIndex ? { ...row, ...trigger } : row
				)
			);
		}
	};

	const manualNote = (
		<Paragraph type="secondary" className="ppcert-designer__trigger-manual">
			{ __(
				'Certificates can always be issued manually from the Certificates screen, with or without triggers.',
				'pressprimer-certificate'
			) }
		</Paragraph>
	);

	// Edge US-5: no adapters detected - explain, don't look broken.
	if ( types && 0 === types.length && 0 === triggers.length ) {
		return (
			<div className="ppcert-designer__prop-section">
				<Empty
					image={ Empty.PRESENTED_IMAGE_SIMPLE }
					description={ __(
						'No automatic award sources detected.',
						'pressprimer-certificate'
					) }
				/>
				<Alert
					type="info"
					showIcon
					message={ __(
						'Install or activate an LMS plugin - or PressPrimer Quiz - to unlock automatic awarding when learners complete courses, quizzes, or assignments.',
						'pressprimer-certificate'
					) }
				/>
				{ manualNote }
			</div>
		);
	}

	return (
		<div className="ppcert-designer__prop-section" data-ppcert-triggers>
			<div className="ppcert-designer__trigger-head">
				<Text
					type="secondary"
					className="ppcert-designer__panel-heading"
				>
					{ __(
						'Award this certificate when…',
						'pressprimer-certificate'
					) }
				</Text>
				<Button
					size="small"
					icon={ <PlusOutlined /> }
					disabled={ ! types || 0 === types.length }
					data-ppcert-trigger-add
					onClick={ () => {
						setEditIndex( null );
						setModalOpen( true );
					} }
				>
					{ __( 'Add trigger', 'pressprimer-certificate' ) }
				</Button>
			</div>

			{ 0 === triggers.length ? (
				<Empty
					image={ Empty.PRESENTED_IMAGE_SIMPLE }
					description={ __(
						'No triggers yet.',
						'pressprimer-certificate'
					) }
				/>
			) : (
				<List
					size="small"
					dataSource={ triggers }
					renderItem={ ( trigger, index ) => {
						let warning = '';

						if ( ! trigger.type_available ) {
							warning = __(
								'The plugin providing this trigger is not active.',
								'pressprimer-certificate'
							);
						} else if ( ! trigger.source_found ) {
							warning = __(
								'The source for this trigger no longer exists.',
								'pressprimer-certificate'
							);
						}

						return (
							<List.Item
								className="ppcert-designer__trigger-row"
								data-ppcert-trigger-row={ index }
							>
								<div className="ppcert-designer__trigger-main">
									<div>
										<Tag>{ trigger.type_label }</Tag>
										{ warning && (
											<Tooltip title={ warning }>
												<Tag
													color="warning"
													icon={ <WarningOutlined /> }
													data-ppcert-trigger-warning
												>
													{ __(
														'Attention',
														'pressprimer-certificate'
													) }
												</Tag>
											</Tooltip>
										) }
									</div>
									<Text ellipsis>
										{ trigger.source_label ||
											trigger.source_ref ||
											__(
												'Any source',
												'pressprimer-certificate'
											) }
									</Text>
								</div>
								<div className="ppcert-designer__trigger-actions">
									<Switch
										size="small"
										checked={ trigger.is_active }
										data-ppcert-trigger-toggle={ index }
										onChange={ ( checked ) =>
											edit(
												triggers.map( ( row, i ) =>
													i === index
														? {
																...row,
																is_active:
																	checked,
														  }
														: row
												)
											)
										}
									/>
									<Button
										size="small"
										type="text"
										icon={ <EditOutlined /> }
										disabled={ ! trigger.type_available }
										data-ppcert-trigger-edit={ index }
										onClick={ () => {
											setEditIndex( index );
											setModalOpen( true );
										} }
									/>
									<Button
										size="small"
										type="text"
										danger
										icon={ <DeleteOutlined /> }
										data-ppcert-trigger-remove={ index }
										onClick={ () =>
											edit(
												triggers.filter(
													( row, i ) => i !== index
												)
											)
										}
									/>
								</div>
							</List.Item>
						);
					} }
				/>
			) }

			{ manualNote }

			{ state.triggersDirty && (
				<Alert
					type="warning"
					showIcon
					message={ __(
						'Trigger changes save with the Save button.',
						'pressprimer-certificate'
					) }
				/>
			) }

			<TriggerModal
				open={ modalOpen }
				types={ types || [] }
				initial={ null === editIndex ? null : triggers[ editIndex ] }
				onSubmit={ onSubmit }
				onClose={ () => setModalOpen( false ) }
			/>
		</div>
	);
}
