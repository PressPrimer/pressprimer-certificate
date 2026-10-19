/**
 * PressPrimer Certificate review prompt (2.1, Feature 2.1-006 FR-001)
 *
 * Records the answer for the review notice's buttons over admin-ajax
 * and removes the notice. "Yes, I love it!" and "It could be better"
 * are real links opening in a new tab (no popup-blocked window.open);
 * Escape closes the notice like the close button.
 *
 * @since 2.1.0
 */

/**
 * @param {Object} $ jQuery.
 */
( function ( $ ) {
	'use strict';

	$( function () {
		const $notice = $( '.ppcert-review-notice' );

		if ( ! $notice.length ) {
			return;
		}

		const nonce = $notice.data( 'ppcert-review-nonce' );

		/**
		 * Record a response and remove the notice.
		 *
		 * @param {string} response review | feedback | snooze | dismiss.
		 */
		const answer = function ( response ) {
			$.post( window.ajaxurl, {
				action: 'ppcert_review_prompt_' + response,
				nonce,
			} );

			$notice.fadeOut( 200, function () {
				$notice.remove();
			} );
		};

		$notice.on( 'click', '[data-ppcert-review]', function ( event ) {
			const response = $( this ).data( 'ppcert-review' );

			// Links still open their page in a new tab.
			if ( 'A' !== this.tagName ) {
				event.preventDefault();
			}

			answer( response );
		} );

		$( document ).on( 'keydown', function ( event ) {
			if ( 'Escape' === event.key && $notice.is( ':visible' ) ) {
				answer( 'dismiss' );
			}
		} );
	} );
} )( window.jQuery );
