/**
 * Trigger panel - "Award this certificate when..." (Feature 001 FR-006)
 *
 * The template's single award trigger (1.0 scope decision: one trigger
 * per certificate - duplicate the template to award it another way)
 * with an add/edit flow: trigger type (registered adapters only) ->
 * source search (the adapter's get_sources) -> conditions form
 * generated from the adapter's schema -> active toggle. Edits stage in
 * the store (outside the undo stack, FR-008) and persist with the
 * toolbar Save.
 *
 * Manual issuance is always available and shown as copy, never as a
 * trigger card. With no adapters detected the empty state explains how
 * to unlock automatic awarding (Edge US-5). A card whose source no
 * longer resolves - or whose adapter was deactivated - carries a
 * warning badge.
 */

import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import {
	Alert,
	Button,
	Empty,
	Input,
	InputNumber,
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
	InfoCircleOutlined,
} from '@ant-design/icons';
import { useDesignerStore } from '../hooks/useDesignerStore';
import {
	getTriggerTypes,
	getTriggerSources,
	getTriggerLevelOptions,
} from '../api';

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
					getPopupContainer={ ( node ) => node.parentElement }
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
				{ field.help && (
					<Tooltip title={ field.help }>
						<InfoCircleOutlined
							className="ppcert-designer__trigger-help"
							data-ppcert-condition-help={ fieldKey }
						/>
					</Tooltip>
				) }
			</span>
			<span className="ppcert-designer__prop-control">{ control }</span>
		</div>
	);
}

/**
 * Human-readable summary of a trigger's set conditions.
 *
 * Only values that deviate from the schema default appear - blank or
 * default values mean "the integration's own behavior" and would read
 * as noise; toggles contribute their label when on.
 *
 * @param {Object} trigger Trigger row.
 * @param {Object} type    Its registered type (null when unavailable).
 * @return {string[]} Summary lines.
 */
function conditionSummary( trigger, type ) {
	if ( ! type ) {
		return [];
	}

	const schema = type.conditions_schema || {};
	const conditions = trigger.conditions || {};

	return Object.keys( schema )
		.map( ( key ) => {
			const field = schema[ key ];
			const value = conditions[ key ];

			if (
				null === value ||
				undefined === value ||
				'' === value ||
				value === field.default
			) {
				return null;
			}

			if ( 'toggle' === field.type ) {
				return value ? field.label : null;
			}

			return `${ field.label }: ${ value }`;
		} )
		.filter( Boolean );
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
	const [ integration, setIntegration ] = useState( null );
	const [ typeId, setTypeId ] = useState( null );
	const [ levels, setLevels ] = useState( {} );
	const [ levelOptions, setLevelOptions ] = useState( {} );
	const [ sourceRef, setSourceRef ] = useState( null );
	const [ sourceLabel, setSourceLabel ] = useState( '' );
	const [ sources, setSources ] = useState( [] );
	const [ conditions, setConditions ] = useState( {} );
	const [ isActive, setIsActive ] = useState( true );

	const type = types.find( ( t ) => t.id === typeId ) || null;

	// Integration-first picking (Ryan's Award-tab review, 2026-07-23):
	// the list is already integration+label sorted server-side; keep
	// that order for the unique integration names.
	const integrations = types.reduce( ( list, t ) => {
		if ( ! list.includes( t.integration ) ) {
			list.push( t.integration );
		}
		return list;
	}, [] );

	const sourceLevels = ( type && type.source_levels ) || [];
	const levelsComplete = sourceLevels.every(
		( level ) => levels[ level.key ]
	);

	// (Re)initialize whenever the modal opens.
	useEffect( () => {
		if ( ! open ) {
			return;
		}

		if ( initial ) {
			const initialType = types.find(
				( t ) => t.id === initial.trigger_type
			);

			setIntegration( initialType ? initialType.integration : null );
			setTypeId( initial.trigger_type );
			setSourceRef( initial.source_ref );
			setSourceLabel( initial.source_label || initial.source_ref || '' );
			setConditions( { ...initial.conditions } );
			setIsActive( initial.is_active );
		} else {
			setIntegration( null );
			setTypeId( null );
			setSourceRef( null );
			setSourceLabel( '' );
			setConditions( {} );
			setIsActive( true );
		}

		setLevels( {} );
		setLevelOptions( {} );
		setSources( [] );
	}, [ open, initial, types ] );

	// Source options load once the cascade is satisfied (immediately
	// for flat types). While editing, the stored source stays selected
	// until the user touches the cascade.
	useEffect( () => {
		if ( ! open || ! type || ! type.has_sources || ! levelsComplete ) {
			return;
		}

		getTriggerSources( type.id, '', levels ).then( setSources );
		// eslint-disable-next-line react-hooks/exhaustive-deps -- levels is captured via levelsComplete + the change handlers.
	}, [ open, type, levelsComplete ] );

	// First cascade level's options load when a hierarchical type is
	// chosen.
	useEffect( () => {
		if ( ! open || ! type || 0 === sourceLevels.length ) {
			return;
		}

		const first = sourceLevels[ 0 ].key;

		getTriggerLevelOptions( type.id, first, {} ).then( ( options ) =>
			setLevelOptions( ( prev ) => ( { ...prev, [ first ]: options } ) )
		);
		// eslint-disable-next-line react-hooks/exhaustive-deps -- keyed by the type id.
	}, [ open, type && type.id ] );

	const onIntegrationChange = ( next ) => {
		setIntegration( next );
		setTypeId( null );
		setLevels( {} );
		setLevelOptions( {} );
		setSources( [] );
		setSourceRef( null );
		setSourceLabel( '' );
		setConditions( {} );
	};

	const onTypeChange = ( next ) => {
		setTypeId( next );
		setLevels( {} );
		setLevelOptions( {} );
		setSources( [] );
		setSourceRef( null );
		setSourceLabel( '' );

		const nextType = types.find( ( t ) => t.id === next );
		setConditions(
			schemaDefaults( nextType ? nextType.conditions_schema : {} )
		);
	};

	const onLevelChange = ( index, id ) => {
		const key = sourceLevels[ index ].key;
		const next = {};

		// Keep this level and everything above it; deeper selections
		// and the source reset.
		sourceLevels.slice( 0, index ).forEach( ( level ) => {
			next[ level.key ] = levels[ level.key ];
		} );
		next[ key ] = id;

		setLevels( next );
		setSources( [] );
		setSourceRef( null );
		setSourceLabel( '' );

		const deeper = sourceLevels[ index + 1 ];

		if ( deeper ) {
			getTriggerLevelOptions( type.id, deeper.key, next ).then(
				( options ) =>
					setLevelOptions( ( prev ) => ( {
						...prev,
						[ deeper.key ]: options,
					} ) )
			);
		}
	};

	const canSubmit = !! type && ( ! type.has_sources || null !== sourceRef );

	return (
		<Modal
			open={ open }
			maskClosable={ false }
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
						{ __( 'Integration', 'pressprimer-certificate' ) }
					</span>
					<span className="ppcert-designer__prop-control">
						<Select
							size="small"
							getPopupContainer={ ( node ) => node.parentElement }
							value={ integration }
							data-ppcert-trigger-integration
							placeholder={ __(
								'Choose a plugin…',
								'pressprimer-certificate'
							) }
							popupMatchSelectWidth={ false }
							onChange={ onIntegrationChange }
							options={ integrations.map( ( name ) => ( {
								value: name,
								label: name,
							} ) ) }
							className="ppcert-designer__prop-wide"
						/>
					</span>
				</div>

				{ integration && (
					<div className="ppcert-designer__prop-row">
						<span className="ppcert-designer__trigger-label">
							{ __( 'Trigger', 'pressprimer-certificate' ) }
						</span>
						<span className="ppcert-designer__prop-control">
							<Select
								size="small"
								getPopupContainer={ ( node ) =>
									node.parentElement
								}
								value={ typeId }
								data-ppcert-trigger-type
								placeholder={ __(
									'Choose a trigger…',
									'pressprimer-certificate'
								) }
								popupMatchSelectWidth={ false }
								onChange={ onTypeChange }
								options={ types
									.filter(
										( t ) => t.integration === integration
									)
									.map( ( t ) => ( {
										value: t.id,
										label: t.short_label,
									} ) ) }
								className="ppcert-designer__prop-wide"
							/>
						</span>
					</div>
				) }

				{ type &&
					type.has_sources &&
					sourceLevels.map( ( level, index ) => {
						const enabled = sourceLevels
							.slice( 0, index )
							.every( ( earlier ) => levels[ earlier.key ] );

						return (
							<div
								key={ level.key }
								className="ppcert-designer__prop-row"
							>
								<span className="ppcert-designer__trigger-label">
									{ level.label }
								</span>
								<span className="ppcert-designer__prop-control">
									<Select
										size="small"
										getPopupContainer={ ( node ) =>
											node.parentElement
										}
										showSearch
										disabled={ ! enabled }
										value={ levels[ level.key ] || null }
										data-ppcert-trigger-level={ level.key }
										placeholder={ __(
											'Search…',
											'pressprimer-certificate'
										) }
										filterOption={ false }
										popupMatchSelectWidth={ false }
										onSearch={ ( term ) =>
											getTriggerLevelOptions(
												type.id,
												level.key,
												levels,
												term
											).then( ( options ) =>
												setLevelOptions( ( prev ) => ( {
													...prev,
													[ level.key ]: options,
												} ) )
											)
										}
										onChange={ ( id ) =>
											onLevelChange( index, id )
										}
										options={ (
											levelOptions[ level.key ] || []
										).map( ( option ) => ( {
											value: option.id,
											label: option.title,
										} ) ) }
										className="ppcert-designer__prop-wide"
									/>
								</span>
							</div>
						);
					} ) }

				{ type && type.has_sources && (
					<div className="ppcert-designer__prop-row">
						<span className="ppcert-designer__trigger-label">
							{ type.source_label ||
								__( 'Source', 'pressprimer-certificate' ) }
						</span>
						<span className="ppcert-designer__prop-control">
							<Select
								size="small"
								getPopupContainer={ ( node ) =>
									node.parentElement
								}
								showSearch
								disabled={ ! levelsComplete }
								value={ sourceRef }
								data-ppcert-trigger-source
								placeholder={ __(
									'Search…',
									'pressprimer-certificate'
								) }
								filterOption={ false }
								popupMatchSelectWidth={ false }
								onSearch={ ( term ) =>
									getTriggerSources(
										type.id,
										term,
										levels
									).then( setSources )
								}
								onChange={ ( id, option ) => {
									setSourceRef( id );
									setSourceLabel( option.label );
								} }
								options={
									// While editing an untouched cascade the
									// stored source is the only option.
									sources.length || null === sourceRef
										? sources.map( ( source ) => ( {
												value: source.id,
												label: source.title,
										  } ) )
										: [
												{
													value: sourceRef,
													label: sourceLabel,
												},
										  ]
								}
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

	const addButton = (
		<Button
			size="small"
			icon={ <PlusOutlined /> }
			disabled={ ! types || 0 === types.length || triggers.length > 0 }
			data-ppcert-trigger-add
			onClick={ () => {
				setEditIndex( null );
				setModalOpen( true );
			} }
		>
			{ __( 'Add trigger', 'pressprimer-certificate' ) }
		</Button>
	);

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
				{ triggers.length > 0 ? (
					<Tooltip
						title={ __(
							'Only one trigger can be added per certificate. To use another trigger, clone the certificate.',
							'pressprimer-certificate'
						) }
					>
						{ /* span: tooltips need events a disabled button eats */ }
						<span>{ addButton }</span>
					</Tooltip>
				) : (
					addButton
				) }
			</div>

			{ 0 === triggers.length ? (
				<Empty
					image={ Empty.PRESENTED_IMAGE_SIMPLE }
					description={ __(
						'No trigger yet.',
						'pressprimer-certificate'
					) }
				/>
			) : (
				triggers.map( ( trigger, index ) => {
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

					const type =
						( types || [] ).find(
							( t ) => t.id === trigger.trigger_type
						) || null;
					const summary = conditionSummary( trigger, type );
					const sourceNoun =
						( type && type.source_label ) ||
						__( 'Source', 'pressprimer-certificate' );

					// Ryan's Award-tab layout (2026-07-22 review): with a
					// single trigger there is vertical room - the trigger
					// name, the source, and the controls each get their
					// own full-width line; nothing is squeezed or cut off.
					return (
						<div
							key={ trigger.id || `staged-${ index }` }
							className="ppcert-designer__trigger-card"
							data-ppcert-trigger-row={ index }
						>
							<Text
								strong
								className="ppcert-designer__trigger-title"
							>
								{ trigger.type_label }
							</Text>

							{ warning && (
								<Tag
									color="warning"
									icon={ <WarningOutlined /> }
									data-ppcert-trigger-warning
								>
									{ warning }
								</Tag>
							) }

							<div className="ppcert-designer__trigger-card-line">
								<Text
									type="secondary"
									className="ppcert-designer__trigger-card-label"
								>
									{ sourceNoun }
								</Text>
								{ /* Wraps, never truncates: the card has
								     the vertical room (Ryan, 2026-07-22). */ }
								<Text className="ppcert-designer__trigger-card-value">
									{ trigger.source_label ||
										trigger.source_ref ||
										__(
											'Any source',
											'pressprimer-certificate'
										) }
								</Text>
							</div>

							{ summary.length > 0 && (
								<div className="ppcert-designer__trigger-card-line">
									<Text
										type="secondary"
										className="ppcert-designer__trigger-card-label"
									>
										{ __(
											'Conditions',
											'pressprimer-certificate'
										) }
									</Text>
									<Text
										className="ppcert-designer__trigger-card-value"
										data-ppcert-trigger-conditions={ index }
									>
										{ summary.join( ' · ' ) }
									</Text>
								</div>
							) }

							<div className="ppcert-designer__trigger-controls">
								<span className="ppcert-designer__trigger-active">
									<Text type="secondary">
										{ __(
											'Active',
											'pressprimer-certificate'
										) }
									</Text>
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
								</span>
								<span className="ppcert-designer__trigger-actions">
									<Button
										size="small"
										icon={ <EditOutlined /> }
										disabled={ ! trigger.type_available }
										data-ppcert-trigger-edit={ index }
										onClick={ () => {
											setEditIndex( index );
											setModalOpen( true );
										} }
									>
										{ __(
											'Edit',
											'pressprimer-certificate'
										) }
									</Button>
									<Button
										size="small"
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
								</span>
							</div>
						</div>
					);
				} )
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
