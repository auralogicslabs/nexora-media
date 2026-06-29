/** @type {import('tailwindcss').Config} */
export default {
  darkMode: 'class',
  content: ['./frontend/**/*.{tsx,ts,jsx,js}'],
  theme: {
    extend: {
      colors: {
        // ── Nexora Media — Deep Purple platform palette ──
        // Dark sidebar
        violet: {
          50:  '#F4F1FB',
          100: '#E7DFF7',
          200: '#CDC0EE',
          300: '#A893E1',
          400: '#8068D0',
          500: '#5F44BA',
          600: '#4A329C',
          700: '#3B287D',
          800: '#2E1F62',
          900: '#22184A',
          950: '#150E2D',
        },
        // Lime CTA / accent
        lime: {
          50:  '#F4FCEA',
          100: '#E5F8CC',
          200: '#CCEF9C',
          300: '#A8E165',
          400: '#84CC2C',
          500: '#65B113',
          600: '#4F8C10',
          700: '#3F6E12',
          800: '#345714',
          900: '#2C4A16',
        },
        // Warm off-white background
        cream: {
          50:  '#FBFAF7',
          100: '#F6F4ED',
          200: '#EFEBDE',
          300: '#E2DCC8',
          400: '#CFC6A8',
          500: '#B8AC87',
        },
        // Legacy aliases so cards/buttons that say "pulse" still work
        pulse: {
          50:  '#F4F1FB',
          100: '#E7DFF7',
          200: '#CDC0EE',
          300: '#A893E1',
          400: '#8068D0',
          500: '#5F44BA',
          600: '#4A329C',
          700: '#3B287D',
          800: '#2E1F62',
          900: '#22184A',
          950: '#150E2D',
        },
      },
      fontFamily: {
        sans: ['"Plus Jakarta Sans"', 'ui-sans-serif', 'system-ui', 'sans-serif'],
        mono: ['"JetBrains Mono"', 'ui-monospace', 'monospace'],
      },
      borderRadius: {
        'xl':  '14px',
        '2xl': '18px',
        '3xl': '24px',
      },
      boxShadow: {
        'np-card':       '0 1px 2px 0 rgb(15 23 42 / 0.04), 0 1px 6px 0 rgb(15 23 42 / 0.04)',
        'np-card-hover': '0 4px 12px -2px rgb(15 23 42 / 0.08), 0 2px 6px -1px rgb(15 23 42 / 0.05)',
        'np-modal':      '0 24px 80px -12px rgb(15 23 42 / 0.30), 0 8px 24px -6px rgb(15 23 42 / 0.15)',
      },
      animation: {
        'fade-in':  'fadeIn 0.2s ease-out',
        'slide-up': 'slideUp 0.25s ease-out',
      },
      keyframes: {
        fadeIn:  { from: { opacity: '0' }, to: { opacity: '1' } },
        slideUp: { from: { opacity: '0', transform: 'translateY(8px)' }, to: { opacity: '1', transform: 'translateY(0)' } },
      },
    },
  },
  plugins: [require('@tailwindcss/forms')],
};
