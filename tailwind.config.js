/**
 * Tailwind build for the GitHubSync admin screens.
 *
 * The `tw-` prefix and `preflight: false` keep Tailwind from touching any
 * WordPress admin styles. Only the compiled assets/css/admin.css ships; the
 * source stylesheet and this config are development files (see .distignore).
 *
 * @type {import('tailwindcss').Config}
 */
module.exports = {
	prefix: 'tw-',
	corePlugins: {
		preflight: false,
	},
	content: [
		'./templates/**/*.php',
		'./includes/**/*.php',
		'./assets/js/**/*.js',
		// Utilities are applied from the component layer below rather than
		// written directly in templates; include the source so Tailwind sees
		// every class used by @apply.
		'./assets/css/tailwind.css',
	],
	theme: {
		extend: {
			colors: {
				brand: {
					50: '#ecfdf5',
					100: '#d1fae5',
					200: '#a7f3d0',
					300: '#6ee7b7',
					400: '#34d399',
					500: '#10b981',
					600: '#059669',
					700: '#047857',
					800: '#065f46',
					900: '#064e3b',
					950: '#022c22',
				},
			},
			boxShadow: {
				card: '0 1px 2px rgba(15, 23, 42, 0.04), 0 10px 30px -20px rgba(15, 23, 42, 0.25)',
				lift: '0 18px 40px -24px rgba(15, 23, 42, 0.35)',
			},
		},
	},
	plugins: [],
};
