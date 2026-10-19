/**
 * Email body helpers (2.1, Feature 2.1-001 FR-002)
 *
 * @package
 * @since 2.1.0
 */

/**
 * A stored body as editor HTML.
 *
 * Bodies with HTML markup (saved by the 2.1 editor) pass through.
 * Plain text (a 2.0 body, the built-in default) becomes paragraphs at
 * blank lines with line breaks inside, the same conversion the email
 * service applies at send time, so the editor shows what learners get.
 *
 * @param {string} body Stored body.
 * @return {string} HTML.
 */
export function toEditorHtml( body ) {
	const text = String( body || '' );

	if (
		'' === text.trim() ||
		/<\/?[a-z][a-z0-9]*(\s[^>]*)?\/?>/i.test( text )
	) {
		return text;
	}

	const escape = ( value ) =>
		value
			.replace( /&/g, '&amp;' )
			.replace( /</g, '&lt;' )
			.replace( />/g, '&gt;' );

	return text
		.replace( /\r\n?/g, '\n' )
		.trim()
		.split( /\n\s*\n/ )
		.map(
			( paragraph ) =>
				'<p>' +
				escape( paragraph.trim() ).replace( /\n/g, '<br />' ) +
				'</p>'
		)
		.join( '' );
}
