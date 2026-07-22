/**
 * Full designer app harness (Feature 001 FR-007, Prompt 3.6)
 *
 * Mounts the real DesignerApp (toolbar + save/publish/preview flow)
 * against a mocked apiFetch transport. The mock persists the template
 * in localStorage so a hard reload exercises the true save -> load
 * round-trip; PUT mimics the server contract by stripping the hostile
 * marker keys the PHPUnit suite uses and bumping updated_at - the specs
 * assert the CLIENT adopts the response verbatim (the real validator's
 * rebuild is asserted server-side in test-templates-rest.php).
 */

import { render, useEffect, useState } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import manifest from '../../../../fonts/manifest.json';
import starter from '../../../../templates/starter-formal-landscape.json';
import '../../../../src/designer/style.css';
import {
	DesignerProvider,
	useDesignerStore,
} from '../../../../src/designer/hooks/useDesignerStore';
import DesignerApp from '../../../../src/designer/components/DesignerApp';
import {
	seedMergeFields,
	seedMetaKeys,
} from '../../../../src/designer/mergeFields';
import { getBoot } from '../../../../src/designer/boot';

const STORAGE_KEY = 'ppcert_harness_template';

window.ppcert_designer_data = {
	template_id: 7,
	list_url: '#templates-list',
	fonts: manifest.families,
	element_types: {
		text: {
			key: 'text',
			label: 'Text',
			icon: 'text',
			default_box: { w: 220, h: 28 },
			default_props: {
				content: 'Your text',
				font_family: 'source-sans-3',
				font_size: 16,
				color: '#1f2937',
				align: 'left',
				line_height: 1.2,
				bold: false,
				italic: false,
			},
		},
		background: {
			key: 'background',
			label: 'Background',
			icon: 'background',
			default_box: null,
			default_props: {},
		},
	},
	starters: [],
	page_presets: {
		a4: { landscape: [ 842, 595 ], portrait: [ 595, 842 ] },
		letter: { landscape: [ 792, 612 ], portrait: [ 612, 792 ] },
	},
};

seedMergeFields( {
	groups: { recipient: 'Recipient', certificate: 'Certificate' },
	fields: [
		{
			key: 'recipient.full_name',
			group: 'recipient',
			label: 'Recipient Full Name',
			sample: 'Jordan Rivera',
		},
		{
			key: 'certificate.issue_date',
			group: 'certificate',
			label: 'Issue Date',
			sample: 'June 12, 2026',
		},
		{
			key: 'certificate.credential_id',
			group: 'certificate',
			label: 'Credential ID',
			sample: '7Q4M-K9P2-XT3A',
		},
		{
			key: 'certificate.issuer_name',
			group: 'certificate',
			label: 'Issuer',
			sample: 'Sunrise Training Academy',
		},
	],
} );
seedMetaKeys( 'user', [] );

/**
 * The stored template (localStorage), falling back to the starter.
 *
 * @return {Object} Template payload.
 */
function readTemplate() {
	const raw = window.localStorage.getItem( STORAGE_KEY );

	if ( raw ) {
		return JSON.parse( raw );
	}

	const layout = { ...starter };
	delete layout._meta;

	return {
		id: 7,
		title: 'Harness Template',
		status: 'draft',
		page_size: 'a4',
		orientation: 'landscape',
		updated_at: '2026-07-01T00:00:00Z',
		layout,
	};
}

/**
 * Server-contract mimic for PUT: strip the hostile marker keys and
 * bump updated_at (the real rebuild is the PHP validator's job).
 *
 * @param {Object} layout Submitted layout.
 * @return {Object} "Rebuilt" layout.
 */
function mimicRebuild( layout ) {
	const clean = JSON.parse( JSON.stringify( layout ) );
	delete clean.hostile_root;

	clean.elements = ( clean.elements || [] ).map( ( element ) => {
		const el = { ...element };
		delete el.onclick;

		if ( el.props ) {
			const props = { ...el.props };
			delete props.injected;
			el.props = props;
		}

		return el;
	} );

	return clean;
}

// Double-adapter fixtures (mirrors the PHPUnit test double).
const DOUBLE_SOURCES = [
	{ id: '101', title: 'Sample Course' },
	{ id: '102', title: 'Advanced Botany' },
];

const DOUBLE_TYPE = {
	id: 'double_lms',
	label: 'Double LMS',
	has_sources: true,
	conditions_schema: {
		min_score: {
			type: 'number',
			label: 'Minimum score (%)',
			min: 0,
			max: 100,
			default: null,
		},
		notify: { type: 'toggle', label: 'Notify instructor', default: false },
		mode: {
			type: 'select',
			label: 'Completion mode',
			options: [ 'full', 'lessons_only' ],
			default: 'full',
		},
		note: { type: 'text', label: 'Internal note', default: '' },
	},
};

const TRIGGERS_KEY = 'ppcert_harness_triggers';

/**
 * Enrich a stored trigger the way the REST controller does.
 *
 * @param {Object} trigger Stored trigger.
 * @return {Object} Enriched row.
 */
function enrichTrigger( trigger ) {
	const source = DOUBLE_SOURCES.find(
		( s ) => s.id === String( trigger.source_ref || '' )
	);

	return {
		...trigger,
		type_label: DOUBLE_TYPE.label,
		type_available: true,
		source_label: source ? source.title : '',
		source_found: ! trigger.source_ref || !! source,
	};
}

apiFetch.use( ( options, next ) => {
	const path = options.path || '';

	if ( path.startsWith( '/ppcert/v1/trigger-types' ) ) {
		const url = new URL( 'http://x' + path );
		const type = url.searchParams.get( 'type' );

		if ( type ) {
			const search = (
				url.searchParams.get( 'search' ) || ''
			).toLowerCase();
			return Promise.resolve(
				DOUBLE_SOURCES.filter( ( s ) =>
					s.title.toLowerCase().includes( search )
				)
			);
		}

		return Promise.resolve( [ DOUBLE_TYPE ] );
	}

	if ( path.startsWith( '/ppcert/v1/templates/7/triggers' ) ) {
		if ( 'PUT' === options.method ) {
			const rows = ( options.data.triggers || [] ).map( enrichTrigger );
			window.localStorage.setItem( TRIGGERS_KEY, JSON.stringify( rows ) );
			window.__ppcertLastTriggersPut = options.data.triggers;
			return Promise.resolve( rows );
		}

		const raw = window.localStorage.getItem( TRIGGERS_KEY );
		return Promise.resolve( raw ? JSON.parse( raw ) : [] );
	}

	if ( ! path.startsWith( '/ppcert/v1/templates/7' ) ) {
		return next( options );
	}

	if ( path.endsWith( '/preview' ) && 'POST' === options.method ) {
		return Promise.resolve( { url: 'about:blank#ppcert-preview' } );
	}

	if ( 'PUT' === options.method ) {
		const stored = readTemplate();
		const expected = options.data.expected_updated_at;

		if (
			expected &&
			expected !== stored.updated_at &&
			! options.data.force
		) {
			return Promise.reject( {
				code: 'ppcert_template_conflict',
				message: 'This template was changed elsewhere.',
				data: { status: 409 },
			} );
		}

		const nextTemplate = {
			...stored,
			title: options.data.title || stored.title,
			status: options.data.status || stored.status,
			layout: options.data.layout
				? mimicRebuild( options.data.layout )
				: stored.layout,
			updated_at: new Date().toISOString().replace( /\.\d+Z$/, 'Z' ),
		};

		window.localStorage.setItem(
			STORAGE_KEY,
			JSON.stringify( nextTemplate )
		);

		return Promise.resolve( nextTemplate );
	}

	// GET load.
	return Promise.resolve( readTemplate() );
} );

/**
 * Expose the store to the specs.
 *
 * @return {null} Nothing.
 */
function Bridge() {
	const { state, dispatch } = useDesignerStore();
	const [ , setTick ] = useState( 0 );

	useEffect( () => {
		setTick( ( t ) => t + 1 );
	}, [ state ] );

	window.__ppcertHarness = {
		ready: true,
		getState: () => state,
		dispatch,
	};

	return null;
}

render(
	<DesignerProvider>
		<Bridge />
		<DesignerApp boot={ getBoot() } />
	</DesignerProvider>,
	document.getElementById( 'root' )
);
