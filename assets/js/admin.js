/**
 * GitHubSync admin behaviour.
 *
 * Adds copy buttons next to read-only webhook fields. All server-side
 * validation and authorisation happens in PHP; this is convenience only.
 */
(function () {
	'use strict';

	function copyText(value) {
		if (navigator.clipboard && navigator.clipboard.writeText) {
			return navigator.clipboard.writeText(value);
		}

		var area = document.createElement('textarea');
		area.value = value;
		area.setAttribute('readonly', 'readonly');
		area.style.position = 'absolute';
		area.style.left = '-9999px';
		document.body.appendChild(area);
		area.select();
		try {
			document.execCommand('copy');
		} catch (e) {
			// Clipboard unavailable; the field is still selectable.
		}
		document.body.removeChild(area);
		return Promise.resolve();
	}

	document.addEventListener('DOMContentLoaded', function () {
		var fields = document.querySelectorAll('input[readonly][type="text"]');

		Array.prototype.forEach.call(fields, function (field) {
			var button = document.createElement('button');
			button.type = 'button';
			button.className = 'button button-small';
			button.textContent = 'Copy';
			button.style.marginLeft = '6px';

			button.addEventListener('click', function () {
				copyText(field.value).then(function () {
					var original = button.textContent;
					button.textContent = 'Copied';
					window.setTimeout(function () {
						button.textContent = original;
					}, 1500);
				});
			});

			if (field.parentNode) {
				field.parentNode.insertBefore(button, field.nextSibling);
			}
		});
	});
}());
