import forms from '@tailwindcss/forms';
import containerQueries from '@tailwindcss/container-queries';

/** @type {import('tailwindcss').Config} */
export default {
    content: [
        './resources/views/**/*.blade.php',
    ],
    theme: {
        extend: {
            colors: {
                'canvas': '#F6F3EC',
                'card': '#FFFEFA',
                'text-heading': '#2F3A33',
                'text-body': '#5E6B63',
                'accent-sage': '#C7D4C6',
                'accent-sand': '#E8DDD0',
                'accent-label': '#8E9A92',
                'border-soft': '#E3E0D8',
                'primary-sage': '#8da38b',
                'penny-dark': '#1F2B26',
                'penny-green': '#4CAF50',
                'penny-beige': '#E3D8C6',
                'penny-blue': '#3B6DB0',
                'penny-accent-blue': '#4a89db',
                'penny-sidebar-text': '#A0AEC0',
            },
            fontFamily: {
                'sans': ['Inter', 'sans-serif'],
                'serif': ['Playfair Display', 'serif'],
            },
            borderRadius: {
                'xl': '1.5rem',
                '2xl': '2rem',
            },
        },
    },
    plugins: [forms, containerQueries],
};
