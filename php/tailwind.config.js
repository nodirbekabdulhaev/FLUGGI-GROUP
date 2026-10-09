import defaultTheme from 'tailwindcss/defaultTheme';

/** Дизайн-токены: нейтральная палитра + один акцент (как в прежней версии). */
/** @type {import('tailwindcss').Config} */
export default {
    content: ['./resources/**/*.blade.php', './resources/**/*.js', './app/**/*.php'],
    theme: {
        extend: {
            fontFamily: { sans: ['Inter', ...defaultTheme.fontFamily.sans] },
            colors: {
                background: '#fafafa',
                surface: '#ffffff',
                muted: { DEFAULT: '#f4f4f5', foreground: '#71717a' },
                border: '#e4e4e7',
                input: '#d4d4d8',
                foreground: '#18181b',
                primary: { DEFAULT: '#18181b', foreground: '#fafafa' },
                accent: { DEFAULT: '#4f46e5', soft: '#eef2ff' },
                success: { DEFAULT: '#15803d', soft: '#f0fdf4' },
                warning: { DEFAULT: '#b45309', soft: '#fffbeb' },
                danger: { DEFAULT: '#dc2626', soft: '#fef2f2' },
            },
            borderRadius: { sm: '0.375rem', md: '0.5rem', lg: '0.75rem' },
        },
    },
    plugins: [],
};
