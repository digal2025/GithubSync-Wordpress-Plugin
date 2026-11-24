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

	/**
	 * Setup checklist carousel.
	 *
	 * Each slide carries its own state in the DOM; only visibility and the
	 * current index are managed here. No server data is read or written.
	 */
	function initCarousel(root) {
		var slides = root.querySelectorAll('[data-gs-slide]');
		var dots = root.querySelectorAll('[data-gs-goto]');
		var prev = root.querySelector('[data-gs-prev]');
		var next = root.querySelector('[data-gs-next]');
		var counter = root.querySelector('[data-gs-counter]');
		var total = slides.length;
		var current = parseInt(root.getAttribute('data-gs-current'), 10);

		if (!total) {
			return;
		}

		if (isNaN(current) || current < 0 || current >= total) {
			current = 0;
		}

		function show(index) {
			current = Math.max(0, Math.min(total - 1, index));

			Array.prototype.forEach.call(slides, function (slide, i) {
				if (i === current) {
					slide.removeAttribute('hidden');
				} else {
					slide.setAttribute('hidden', 'hidden');
				}
			});

			Array.prototype.forEach.call(dots, function (dot, i) {
				dot.setAttribute('aria-selected', i === current ? 'true' : 'false');
			});

			if (prev) {
				prev.disabled = current === 0;
			}

			if (next) {
				next.disabled = current === total - 1;
			}

			if (counter) {
				counter.textContent = (current + 1) + ' / ' + total;
			}
		}

		if (prev) {
			prev.addEventListener('click', function () {
				show(current - 1);
			});
		}

		if (next) {
			next.addEventListener('click', function () {
				show(current + 1);
			});
		}

		Array.prototype.forEach.call(dots, function (dot) {
			dot.addEventListener('click', function () {
				show(parseInt(dot.getAttribute('data-gs-goto'), 10));
			});
		});

		root.addEventListener('keydown', function (event) {
			if (event.key === 'ArrowLeft') {
				show(current - 1);
			} else if (event.key === 'ArrowRight') {
				show(current + 1);
			}
		});

		show(current);
	}

	document.addEventListener('DOMContentLoaded', function () {
		Array.prototype.forEach.call(document.querySelectorAll('[data-gs-carousel]'), initCarousel);

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
