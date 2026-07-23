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
import { seedMetaKeys } from '../../../../src/designer/mergeFields';
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
		merge_field: {
			key: 'merge_field',
			label: 'Merge Field',
			icon: 'merge_field',
			default_box: { w: 260, h: 28 },
			default_props: {
				token: '{{recipient.display_name}}',
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

// Registry fixtures: core fields are untagged; source fields carry the
// contributing trigger type (mirrors the server's adapter tagging). The
// /merge-fields mock below scopes them by trigger_types - specs
// exercise the real client scoping, so seedMergeFields is NOT used.
const REGISTRY_FIELDS = [
	{
		key: 'recipient.full_name',
		group: 'recipient',
		label: 'Recipient Full Name',
		sample: 'Jordan Rivera',
		trigger_type: '',
	},
	{
		key: 'certificate.issue_date',
		group: 'certificate',
		label: 'Issue Date',
		sample: 'June 12, 2026',
		trigger_type: '',
	},
	{
		key: 'certificate.credential_id',
		group: 'certificate',
		label: 'Credential ID',
		sample: '7Q4M-K9P2-XT3A',
		trigger_type: '',
	},
	{
		key: 'certificate.issuer_name',
		group: 'certificate',
		label: 'Issuer',
		sample: 'Sunrise Training Academy',
		trigger_type: '',
	},
	{
		key: 'source.course_title',
		group: 'source',
		label: 'Course Title',
		sample: 'Advanced Botany',
		trigger_type: 'double_lms',
	},
	{
		key: 'source.completion_date',
		group: 'source',
		label: 'Completion Date',
		sample: 'June 10, 2026',
		trigger_type: 'double_lms',
	},
];

seedMetaKeys( 'user', [] );
seedMetaKeys( 'post', [
	{ key: 'course_code', sample: 'BOT-301' },
	{ key: 'instructor', sample: 'Dr. Moss' },
] );

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

// Double-adapter fixtures (mirrors the PHPUnit test double), plus a
// hierarchical sibling exercising the cascade picker.
const DOUBLE_SOURCES = [
	{ id: '101', title: 'Sample Course' },
	{ id: '102', title: 'Advanced Botany' },
];

const HIER_COURSES = [
	{ id: '11', title: 'Botany 101' },
	{ id: '12', title: 'Watercolor Basics' },
];

const HIER_LESSONS = {
	11: [
		{ id: '201', title: 'Roots and Stems' },
		{ id: '202', title: 'Leaves' },
	],
	12: [ { id: '301', title: 'Brushes' } ],
};

const DOUBLE_TYPE = {
	id: 'double_lms',
	label: 'Course completed (Double LMS)',
	integration: 'Double LMS',
	short_label: 'Course completed',
	source_label: 'Course',
	has_sources: true,
	source_levels: [],
	source_post_types: [ 'page' ],
	conditions_schema: {
		min_score: {
			type: 'number',
			label: 'Minimum score (%)',
			help: 'Leave blank to award on any passing score.',
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

const HIER_TYPE = {
	id: 'double_lms_lesson',
	label: 'Lesson completed (Double LMS)',
	integration: 'Double LMS',
	short_label: 'Lesson completed',
	source_label: 'Lesson',
	has_sources: true,
	source_levels: [ { key: 'course', label: 'Course' } ],
	source_post_types: [ 'page' ],
	conditions_schema: {},
};

const HARNESS_TYPES = [ DOUBLE_TYPE, HIER_TYPE ];

const TRIGGERS_KEY = 'ppcert_harness_triggers';

/**
 * Cascade parents map from a mocked request path.
 *
 * @param {URL} url Parsed request URL.
 * @return {Object} Level key => id.
 */
function parentsFromUrl( url ) {
	const parents = {};

	url.searchParams.forEach( ( value, key ) => {
		const match = key.match( /^parents\[(.+)\]$/ );

		if ( match ) {
			parents[ match[ 1 ] ] = value;
		}
	} );

	return parents;
}

/**
 * Enrich a stored trigger the way the REST controller does.
 *
 * @param {Object} trigger Stored trigger.
 * @return {Object} Enriched row.
 */
function enrichTrigger( trigger ) {
	const type =
		HARNESS_TYPES.find( ( t ) => t.id === trigger.trigger_type ) ||
		DOUBLE_TYPE;
	const pool = DOUBLE_SOURCES.concat(
		...Object.keys( HIER_LESSONS ).map( ( key ) => HIER_LESSONS[ key ] )
	);
	const source = pool.find(
		( s ) => s.id === String( trigger.source_ref || '' )
	);

	return {
		...trigger,
		type_label: type.label,
		type_available: true,
		source_label: source ? source.title : '',
		source_found: ! trigger.source_ref || !! source,
	};
}

apiFetch.use( ( options, next ) => {
	const path = options.path || '';

	if ( path.startsWith( '/ppcert/v1/merge-fields' ) ) {
		// Mirror the controller: absent param = unscoped; present =
		// adapter fields only for the listed types; a single scoped
		// type relabels the source group with its noun.
		const url = new URL( 'http://x' + path );
		const scopeParam = url.searchParams.get( 'trigger_types' );
		const scope =
			null === scopeParam
				? null
				: scopeParam.split( ',' ).filter( Boolean );

		const fields = REGISTRY_FIELDS.filter(
			( field ) =>
				null === scope ||
				'' === field.trigger_type ||
				scope.includes( field.trigger_type )
		);

		const groups = {
			recipient: 'Recipient',
			certificate: 'Certificate',
			source: 'Source',
		};

		if ( scope && 1 === scope.length && scope[ 0 ] === DOUBLE_TYPE.id ) {
			groups.source = DOUBLE_TYPE.source_label;
		}

		return Promise.resolve( { groups, fields } );
	}

	if ( path.startsWith( '/ppcert/v1/trigger-types' ) ) {
		const url = new URL( 'http://x' + path );
		const type = url.searchParams.get( 'type' );

		if ( type ) {
			const search = (
				url.searchParams.get( 'search' ) || ''
			).toLowerCase();
			const level = url.searchParams.get( 'level' );
			const parents = parentsFromUrl( url );
			const bySearch = ( list ) =>
				list.filter( ( s ) =>
					s.title.toLowerCase().includes( search )
				);

			// Cascade level options (hierarchical type only).
			if ( level ) {
				return Promise.resolve(
					'course' === level ? bySearch( HIER_COURSES ) : []
				);
			}

			// Scoped sources for the hierarchical type.
			if ( type === HIER_TYPE.id ) {
				const lessons = parents.course
					? HIER_LESSONS[ parents.course ] || []
					: [];
				return Promise.resolve( bySearch( lessons ) );
			}

			return Promise.resolve( bySearch( DOUBLE_SOURCES ) );
		}

		return Promise.resolve( HARNESS_TYPES );
	}

	if ( path.startsWith( '/ppcert/v1/templates/7/triggers' ) ) {
		if ( 'PUT' === options.method ) {
			// Server contract: one trigger per template in 1.0.
			if ( ( options.data.triggers || [] ).length > 1 ) {
				return Promise.reject( {
					code: 'ppcert_too_many_triggers',
					message:
						'Certificates support a single award trigger in this version.',
					data: { status: 400 },
				} );
			}

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
