import forms from '@tailwindcss/forms';

/** @type {import('tailwindcss').Config} */
export default {
    content: ['./resources/views/**/*.blade.php', './app/**/*.php', './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php'],
    darkMode: 'media',
    theme: {
        extend: {
            colors: {
                brand: { 50: '#edf7f6', 100: '#d5ecea', 200: '#aedad6', 500: '#3d8f9a', 600: '#2c7387', 700: '#245f78', 800: '#244b74', 900: '#1b3a5c' },
            },
            fontFamily: { sans: ['Inter', 'ui-sans-serif', 'system-ui', '-apple-system', 'Segoe UI', 'Roboto', 'sans-serif'] },
        },
    },
    plugins: [forms],
};
