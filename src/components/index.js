/**
 * Shared components for addons (2.1, Feature 2.1-001 TR-004)
 *
 * Built as the 'ppcert-components' script handle: publishes the free
 * plugin's components on window.ppcert_components so addon screens
 * (Educator's reminder section and email template manager) reuse them
 * instead of bundling copies. Enqueue the handle and call
 * wp_enqueue_editor() on the screen.
 *
 * @package
 * @since 2.1.0
 */

import RichTextEditor from '../shared/components/RichTextEditor';

window.ppcert_components = {
	...( window.ppcert_components || {} ),
	RichTextEditor,
};
