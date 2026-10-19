/**
 * Rich Text Editor Component (TinyMCE wrapper)
 *
 * Thin React wrapper over window.wp.editor (TinyMCE) that exposes a
 * value/onChange contract compatible with Ant Design's Form.Item
 * cloneElement injection. Toolbar: bold, italic, bullet list, numbered
 * list, link, undo, redo.
 *
 * Ported from PressPrimer Assignment 2.2.3
 * (src/shared/components/RichTextEditor.jsx), the suite reference
 * (Certificate 2.1, Feature 2.1-001 TR-004). Addons reuse this exact
 * component through window.ppcert_components.RichTextEditor (the
 * 'ppcert-components' script handle) instead of bundling a copy. The
 * screen must call wp_enqueue_editor().
 *
 * @package
 * @since 2.1.0
 */

import {
	useState,
	useRef,
	useEffect,
	forwardRef,
	useImperativeHandle,
} from '@wordpress/element';

import './RichTextEditor.css';

let editorCounter = 0;

const TOOLBAR = 'bold italic bullist numlist link undo redo';
const PLUGINS = 'lists link';

const RichTextEditor = forwardRef(
	(
		{ value, onChange, placeholder, rows = 8, disabled = false, id },
		ref
	) => {
		const editorRef = useRef( null );
		const editorInstanceRef = useRef( null );
		const onChangeRef = useRef( onChange );
		const [ editorId ] = useState(
			() => id || `ppcert-rte-${ ++editorCounter }`
		);
		const [ isInitialized, setIsInitialized ] = useState( false );

		// Always keep the latest onChange callback in a ref so the
		// TinyMCE event listener (registered once) uses the current one.
		useEffect( () => {
			onChangeRef.current = onChange;
		}, [ onChange ] );

		// Initialize the editor once after mount.
		useEffect( () => {
			let timeoutId;

			const initEditor = () => {
				if ( ! editorRef.current ) {
					return;
				}

				if ( ! window.wp || ! window.wp.editor ) {
					// wp.editor not loaded yet — retry until it is.
					timeoutId = setTimeout( initEditor, 100 );
					return;
				}

				try {
					window.wp.editor.remove( editorId );
				} catch ( e ) {
					// No prior instance to remove — first init.
				}

				try {
					window.wp.editor.initialize( editorId, {
						tinymce: {
							wpautop: true,
							plugins: PLUGINS,
							toolbar1: TOOLBAR,
							toolbar2: '',
							menubar: false,
							statusbar: false,
							branding: false,
							elementpath: false,
							// rows * line-height plus one extra line of room
							// to compensate for the toolbar overhead, plus a
							// few pixels of breathing room at the bottom.
							height: rows * 24 + 36,
							placeholder,
							// Force every link to open in a new tab. Removing
							// target_list hides the "Open in" dropdown from the
							// Insert Link dialog so the user can't override it.
							default_link_target: '_blank',
							target_list: false,
							init_instance_callback: ( editor ) => {
								editorInstanceRef.current = editor;
								setIsInitialized( true );

								if ( value ) {
									editor.setContent( value );
								}

								editor.on(
									'input change keyup undo redo',
									() => {
										if ( onChangeRef.current ) {
											onChangeRef.current(
												editor.getContent()
											);
										}
									}
								);
							},
						},
						quicktags: false,
						mediaButtons: false,
					} );
				} catch ( e ) {
					// Initialization failed — leave the textarea fallback in place.
				}
			};

			timeoutId = setTimeout( initEditor, 50 );

			return () => {
				clearTimeout( timeoutId );
				if ( editorInstanceRef.current ) {
					try {
						window.wp.editor.remove( editorId );
					} catch ( e ) {
						// Ignore cleanup errors.
					}
					editorInstanceRef.current = null;
				}
				setIsInitialized( false );
			};
			// editorId is stable; reinit only on mount/unmount.
			// eslint-disable-next-line react-hooks/exhaustive-deps
		}, [ editorId ] );

		// Propagate external value changes (e.g. Ant Form setFieldsValue
		// after async data load) into the editor without breaking
		// in-progress typing.
		useEffect( () => {
			if ( ! isInitialized || ! editorInstanceRef.current ) {
				return;
			}
			const current = editorInstanceRef.current.getContent();
			if ( ( value || '' ) !== current ) {
				editorInstanceRef.current.setContent( value || '' );
			}
		}, [ value, isInitialized ] );

		// Toggle disabled state on the editor body when the prop changes.
		useEffect( () => {
			if ( ! isInitialized || ! editorInstanceRef.current ) {
				return;
			}
			editorInstanceRef.current.setMode(
				disabled ? 'readonly' : 'design'
			);
		}, [ disabled, isInitialized ] );

		useImperativeHandle( ref, () => ( {
			focus: () => {
				if ( editorInstanceRef.current ) {
					editorInstanceRef.current.focus();
				}
			},
		} ) );

		return (
			<div className="ppcert-rich-text-editor">
				<textarea
					ref={ editorRef }
					id={ editorId }
					className="wp-editor-area"
					style={ { width: '100%' } }
					defaultValue={ value || '' }
					disabled={ disabled }
				/>
			</div>
		);
	}
);

export default RichTextEditor;
