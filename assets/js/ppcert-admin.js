/**
 * PressPrimer Certificate admin utilities
 *
 * The house confirmation modal for link-driven list-table actions,
 * ported from the Quiz admin.js pattern: links flagged with
 * .ppcert-confirm-link open a styled modal instead of navigating;
 * confirming follows the link's href. An optional text input (the
 * revocation reason) is declared per link via data attributes and
 * appended to the href as a query argument.
 *
 * Link data attributes:
 *   data-ppcert-title        Modal title.
 *   data-ppcert-message      Modal body text.
 *   data-ppcert-confirm      Confirm button label.
 *   data-ppcert-cancel      Cancel button label.
 *   data-ppcert-input-label  Optional input label (renders the input).
 *   data-ppcert-input-name   Query argument name for the input value.
 *
 * @since 1.0.0
 */

/**
 * @param {Object} $ jQuery.
 */
( function ( $ ) {
	'use strict';

	const PPCert = {
		/**
		 * Bind the confirm-link interceptor.
		 */
		init() {
			$( document ).on(
				'click',
				'.ppcert-confirm-link',
				this.onConfirmLink
			);
		},

		/**
		 * Intercept a flagged link and confirm through the modal.
		 *
		 * @param {Event} e Click event.
		 */
		onConfirmLink( e ) {
			e.preventDefault();

			const $link = $( this );
			const href = $link.attr( 'href' );
			const inputLabel = $link.data( 'ppcert-input-label' ) || '';
			const inputName = $link.data( 'ppcert-input-name' ) || '';

			PPCert.confirm( {
				title: $link.data( 'ppcert-title' ) || '',
				message: $link.data( 'ppcert-message' ) || '',
				confirmText: $link.data( 'ppcert-confirm' ) || 'OK',
				cancelText: $link.data( 'ppcert-cancel' ) || 'Cancel',
				inputLabel,
				onConfirm( inputValue ) {
					let target = href;

					if ( inputName && inputValue ) {
						target +=
							( -1 === target.indexOf( '?' ) ? '?' : '&' ) +
							encodeURIComponent( inputName ) +
							'=' +
							encodeURIComponent( inputValue );
					}

					window.location.href = target;
				},
			} );
		},

		/**
		 * Show the confirmation modal.
		 *
		 * @param {Object} options Title, message, labels, callbacks.
		 * @return {Object} The modal element (jQuery wrapped).
		 */
		confirm( options ) {
			const self = this;

			// One modal at a time.
			$( '.ppcert-modal-overlay' ).remove();

			const modalHtml =
				'<div class="ppcert-modal-overlay" role="dialog" aria-modal="true">' +
				'<div class="ppcert-modal">' +
				'<div class="ppcert-modal-header">' +
				( options.title
					? '<h3 class="ppcert-modal-title">' +
					  self.escapeHtml( options.title ) +
					  '</h3>'
					: '' ) +
				'<button type="button" class="ppcert-modal-close" aria-label="Close">' +
				'<span class="dashicons dashicons-no-alt"></span>' +
				'</button>' +
				'</div>' +
				'<div class="ppcert-modal-body">' +
				'<div class="ppcert-modal-icon ppcert-modal-icon--warning">' +
				'<span class="dashicons dashicons-warning"></span>' +
				'</div>' +
				'<div class="ppcert-modal-message">' +
				self.escapeHtml( options.message ) +
				'</div>' +
				( options.inputLabel
					? '<label class="ppcert-modal-input-label" for="ppcert-modal-input">' +
					  self.escapeHtml( options.inputLabel ) +
					  '</label>' +
					  '<input type="text" id="ppcert-modal-input" class="ppcert-modal-input" maxlength="191" />'
					: '' ) +
				'</div>' +
				'<div class="ppcert-modal-footer">' +
				'<button type="button" class="button ppcert-modal-cancel">' +
				self.escapeHtml( options.cancelText ) +
				'</button>' +
				'<button type="button" class="button button-primary ppcert-modal-confirm">' +
				self.escapeHtml( options.confirmText ) +
				'</button>' +
				'</div>' +
				'</div>' +
				'</div>';

			const $modal = $( modalHtml );
			$( 'body' ).append( $modal );

			// Animate in.
			setTimeout( function () {
				$modal.addClass( 'ppcert-modal-overlay--visible' );
			}, 10 );

			// Focus the input when present, else the confirm button.
			if ( options.inputLabel ) {
				$modal.find( '.ppcert-modal-input' ).trigger( 'focus' );
			} else {
				$modal.find( '.ppcert-modal-confirm' ).trigger( 'focus' );
			}

			const close = function () {
				$modal.removeClass( 'ppcert-modal-overlay--visible' );
				$( document ).off( 'keydown.ppcertModal' );
				setTimeout( function () {
					$modal.remove();
				}, 200 );
			};

			$modal.on( 'click', '.ppcert-modal-confirm', function () {
				const value = $modal.find( '.ppcert-modal-input' ).val() || '';
				close();
				if ( typeof options.onConfirm === 'function' ) {
					options.onConfirm( value );
				}
			} );

			$modal.on(
				'click',
				'.ppcert-modal-cancel, .ppcert-modal-close',
				close
			);

			$modal.on( 'click', function ( e ) {
				if ( $( e.target ).hasClass( 'ppcert-modal-overlay' ) ) {
					close();
				}
			} );

			// Enter inside the input confirms; Escape cancels anywhere.
			$modal.on( 'keydown', '.ppcert-modal-input', function ( e ) {
				if ( 'Enter' === e.key ) {
					e.preventDefault();
					$modal.find( '.ppcert-modal-confirm' ).trigger( 'click' );
				}
			} );

			$( document ).on( 'keydown.ppcertModal', function ( e ) {
				if ( 'Escape' === e.key ) {
					close();
				}
			} );

			return $modal;
		},

		/**
		 * Escape HTML entities.
		 *
		 * @param {string} str Raw string.
		 * @return {string} Escaped string.
		 */
		escapeHtml( str ) {
			if ( ! str ) {
				return '';
			}
			const div = document.createElement( 'div' );
			div.textContent = str;
			return div.innerHTML;
		},
	};

	$( function () {
		PPCert.init();
	} );
} )( window.jQuery );
