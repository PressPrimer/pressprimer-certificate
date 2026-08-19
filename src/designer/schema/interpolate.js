/**
 * Inline merge-token substitution (schema v2)
 *
 * Client mirror of PressPrimer_Certificate_PDF_Renderer::
 * interpolate_tokens() - the same grammar scan, the same semantics
 * (docs/architecture/layout-schema.md, "Inline Tokens in Text"):
 * a grammar-matching token resolves to its value or to empty string
 * when unknown, so rendered output never leaks template syntax;
 * brace runs outside the grammar are literal text.
 */

/**
 * The embedded token grammar: {{group.field}} / {{group.meta.key}}.
 * Must stay semantically identical to the PHP renderer's pattern.
 */
export const INLINE_TOKEN_PATTERN = /\{\{([a-z0-9_]+\.[a-z0-9_.-]+)\}\}/g;

/**
 * Substitute inline merge tokens in a text run
 *
 * @param {string} content Text content.
 * @param {Object} values  Token key => display value (sample map on the
 *                         canvas, resolved merge data anywhere else).
 * @return {string} The substituted run.
 */
export function interpolateTokens( content, values ) {
	const map = values && 'object' === typeof values ? values : {};

	return String( content ?? '' ).replace(
		INLINE_TOKEN_PATTERN,
		( _match, key ) => {
			const value = map[ key ];

			return undefined !== value &&
				null !== value &&
				( 'string' === typeof value || 'number' === typeof value )
				? String( value )
				: '';
		}
	);
}
