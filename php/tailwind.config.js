import defaultTheme from 'tailwindcss/defaultTheme';

/**
 * Дизайн-токены «стекло и пастель»: светлый голубо-лавандовый фон, полупрозрачные белые карточки,
 * тонкие светлые рамки, крупные скругления, мягкие тени, пастельные акценты (голубой, барвинок, розовый).
 * Названия токенов прежние — все экраны используют их как раньше.
 */
/** @type {import('tailwindcss').Config} */
export default {
    content: ['./resources/**/*.blade.php', './resources/**/*.js', './app/**/*.php'],
    theme: {
        extend: {
            fontFamily: { sans: ['Inter', ...defaultTheme.fontFamily.sans] },
            colors: {
                background: '#eef3fb',
                surface: '#ffffff',
                glass: 'rgb(255 255 255 / 0.68)',
                muted: { DEFAULT: '#eff3fa', foreground: '#5b6b82' },
                border: '#e2e8f3',
                input: '#d6deeb',
                foreground: '#1e2a44',
                primary: { DEFAULT: '#3563e9', foreground: '#ffffff', soft: '#e7eeff', strong: '#2a52c9' },
                accent: { DEFAULT: '#5b5bd6', soft: '#eeedfd' },
                success: { DEFAULT: '#137a3c', soft: '#e5f6ec' },
                warning: { DEFAULT: '#a85208', soft: '#fff3df' },
                danger: { DEFAULT: '#c62b2b', soft: '#fdecee' },
                // Пастельные акценты образца
                sky: { soft: '#e3f1fd', DEFAULT: '#7cc3f4', strong: '#1f6fae' },
                periwinkle: { soft: '#ecebff', DEFAULT: '#a5a8f6', strong: '#4b4fc4' },
                blush: { soft: '#fdebf4', DEFAULT: '#f4a7c9', strong: '#b03a72' },
            },
            borderRadius: { sm: '0.5rem', md: '0.75rem', lg: '1rem', xl: '1.25rem', '2xl': '1.5rem', '3xl': '1.75rem' },
            boxShadow: {
                soft: '0 1px 2px rgb(30 42 68 / 0.04), 0 4px 14px -6px rgb(30 58 138 / 0.10)',
                glass: '0 1px 0 rgb(255 255 255 / 0.7) inset, 0 12px 32px -16px rgb(30 58 138 / 0.18)',
                lift: '0 2px 4px rgb(30 42 68 / 0.05), 0 16px 36px -14px rgb(30 58 138 / 0.24)',
            },
        },
    },
    plugins: [],
};
