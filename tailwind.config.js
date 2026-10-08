import forms from '@tailwindcss/forms';

/** @type {import('tailwindcss').Config} */
export default {
    content: ['./resources/views/**/*.blade.php', './app/**/*.php', './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php'],
    darkMode: 'media',
    theme: {
        extend: {
            colors: {
                brand: { 50: '#eef2ff', 100: '#e0e7ff', 200: '#c7d2fe', 500: '#6366f1', 600: '#4f46e5', 700: '#4338ca', 800: '#3730a3', 900: '#312e81' },
            },
            fontFamily: { sans: ['Inter', 'ui-sans-serif', 'system-ui', '-apple-system', 'Segoe UI', 'Roboto', 'sans-serif'] },
        },
    },
    plugins: [forms],
};
