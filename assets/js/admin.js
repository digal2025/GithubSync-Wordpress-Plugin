/**
 * GitHubSync admin behaviour.
 *
 * Progressive enhancement only: every screen works without this file.
 *
 *  1. Dialogs     - [data-gs-dialog-open="id"] opens <dialog id="id">.
 *  2. Confirmations - [data-gs-confirm] on a form asks before submitting.
 *  3. Copy buttons - [data-gs-copy] copies its target's value.
 *
 * All server-side validation and authorisation happens in PHP.
 */
(function () {
	'use strict';

	var l10n = window.githubSyncL10n || {};

	/**
	 * Translated UI string.
	 *
	 * @param {string} key  String key.
	 * @param {string} fall Backend fallback.
	 * @return {string} Localised text.
	 */
	function t(key, fall) {
		return l10n[key] || fall;
	}

	/**
	 * Open a dialog and keep focus inside it.
	 *
	 * @param {HTMLDialogElement} dialog Dialog element.
	 */
	function openDialog(dialog) {
		if (typeof dialog.showModal !== 'function') {
			return;
		}

		if ( ! dialog.open) {
			dialog.showModal();
		}

		var target = dialog.querySelector( '[data-gs-slide]:not([hidden]) a, [data-gs-slide]:not([hidden]) button' );

		if ( ! target) {
			target = dialog.querySelector( '.gs-dialog__x' );
		}

		if (target) {
			target.focus();
		}
	}

	/**
	 * Wire closing behaviour for one dialog.
	 *
	 * @param {HTMLDialogElement} dialog Dialog element.
	 * @param {Element|null}      trigger Button that opened it, if any.
	 */
	function bindDialog(dialog, trigger) {
		Array.prototype.forEach.call(
			dialog.querySelectorAll( '[data-gs-dialog-close]' ),
			function (button) {
				button.addEventListener(
					'click',
					function () {
						dialog.close();
					}
				);
			}
		);

		dialog.addEventListener(
			'click',
			function (event) {
				// Clicking the backdrop closes; clicks inside the panel do not.
				if (event.target === dialog) {
					dialog.close();
				}
			}
		);

		if (trigger) {
			dialog.addEventListener(
				'close',
				function () {
					trigger.focus();
				}
			);
		}
	}

	/** Dialogs opened from a trigger button. */
	function initDialogs() {
		Array.prototype.forEach.call(
			document.querySelectorAll( '[data-gs-dialog-open]' ),
			function (trigger) {
				var id     = trigger.getAttribute( 'data-gs-dialog-open' );
				var dialog = id ? document.getElementById( id ) : null;

				if ( ! dialog) {
					return;
				}

				bindDialog( dialog, trigger );

				trigger.addEventListener(
					'click',
					function () {
						openDialog( dialog );
					}
				);
			}
		);
	}

	/** One shared confirmation dialog for destructive forms. */
	function initConfirmations() {
		var forms = document.querySelectorAll( 'form[data-gs-confirm]' );

		if ( ! forms.length || ! window.HTMLDialogElement ) {
			// Without <dialog> support the form simply submits; PHP still guards it.
			return;
		}

		var dialog = document.createElement( 'dialog' );
		dialog.className = 'gs-dialog gs-dialog--confirm';
		dialog.setAttribute( 'aria-labelledby', 'gs-confirm-title' );
		dialog.setAttribute( 'aria-describedby', 'gs-confirm-text' );
		dialog.innerHTML =
			'<div class="gs-dialog__inner">' +
				'<div class="gs-dialog__head">' +
					'<div class="gs-dialog__title">' +
						'<h2 id="gs-confirm-title"></h2>' +
					'</div>' +
					'<button type="button" class="gs-dialog__x" data-gs-dialog-close aria-label="' + t( 'cancel', 'Cancel' ) + '">' +
						'<span aria-hidden="true">&times;</span>' +
					'</button>' +
				'</div>' +
				'<p id="gs-confirm-text" class="gs-confirm__text"></p>' +
				'<div class="gs-dialog__foot">' +
					'<button type="button" class="button" data-gs-dialog-close>' + t( 'cancel', 'Cancel' ) + '</button>' +
					'<button type="button" class="button button-primary gs-confirm__ok"></button>' +
				'</div>' +
			'</div>';

		document.body.appendChild( dialog );

		var titleEl  = dialog.querySelector( '#gs-confirm-title' );
		var textEl   = dialog.querySelector( '.gs-confirm__text' );
		var okButton = dialog.querySelector( '.gs-confirm__ok' );
		var pending  = null;

		bindDialog( dialog, null );

		okButton.addEventListener(
			'click',
			function () {
				var form = pending;
				pending  = null;
				dialog.close();

				if (form) {
					form.submit();
				}
			}
		);

		Array.prototype.forEach.call(
			forms,
			function (form) {
				form.addEventListener(
					'submit',
					function (event) {
						if (form.getAttribute( 'data-gs-confirmed' ) === '1') {
							// Already confirmed once; let the native submit through.
							form.removeAttribute( 'data-gs-confirmed' );
							return;
						}

						event.preventDefault();
						pending = form;

						titleEl.textContent = form.getAttribute( 'data-gs-confirm' );
						textEl.textContent  = form.getAttribute( 'data-gs-confirm-text' ) || form.getAttribute( 'data-gs-confirm' );
						okButton.textContent = form.getAttribute( 'data-gs-confirm-ok' ) || t( 'continue', 'Continue' );
						okButton.className   = form.getAttribute( 'data-gs-confirm-danger' ) === '1'
							? 'button button-primary gs-confirm__ok gs-danger'
							: 'button button-primary gs-confirm__ok';

						openDialog( dialog );
						okButton.focus();
					}
				);
			}
		);
	}

	/**
	 * Write text to the clipboard.
	 *
	 * @param {string} value Text to copy.
	 * @return {Promise} Resolves when the copy attempt finished.
	 */
	function copyText(value) {
		if (navigator.clipboard && navigator.clipboard.writeText && window.isSecureContext) {
			return navigator.clipboard.writeText( value ).catch(
				function () {
					legacyCopy( value );
				}
			);
		}

		legacyCopy( value );
		return Promise.resolve();
	}

	/**
	 * Clipboard fallback for insecure contexts.
	 *
	 * @param {string} value Text to copy.
	 */
	function legacyCopy(value) {
		var area   = document.createElement( 'textarea' );
		area.value = value;
		area.setAttribute( 'readonly', 'readonly' );
		area.style.position = 'absolute';
		area.style.left     = '-9999px';
		document.body.appendChild( area );
		area.select();

		try {
			document.execCommand( 'copy' );
		} catch (e) {
			// Clipboard unavailable; the field is still selectable.
		}

		document.body.removeChild( area );
	}

	/** Copy buttons on read-only fields. */
	function initCopyButtons() {
		Array.prototype.forEach.call(
			document.querySelectorAll( '[data-gs-copy]' ),
			function (button) {
				var selector = button.getAttribute( 'data-gs-copy' );
				var field    = selector ? document.querySelector( selector ) : null;

				if ( ! field) {
					return;
				}

				button.addEventListener(
					'click',
					function () {
						var original = button.getAttribute( 'data-gs-copy-idle' ) || button.textContent;

						button.setAttribute( 'data-gs-copy-idle', original );

						copyText( field.value ).then(
							function () {
								button.textContent = button.getAttribute( 'data-gs-copy-done' ) || t( 'copied', 'Copied' );
								window.setTimeout(
									function () {
										button.textContent = original;
									},
									1500
								);
							}
						);
					}
				);
			}
		);
	}

	document.addEventListener(
		'DOMContentLoaded',
		function () {
			initDialogs();
			initConfirmations();
			initCopyButtons();
		}
	);
}());
