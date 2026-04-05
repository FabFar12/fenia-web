/** @type {import('tailwindcss').Config} */
export default {
  content: ['./src/**/*.{astro,html,js,jsx,ts,tsx}'],
  theme: {
    extend: {
      colors: {
        navy: {
          DEFAULT: '#0B1A2E',
          light: '#0F2138',
          mid: '#132847',
          dark: '#060E1A',
        },
        cyan: {
          DEFAULT: '#00B4D8',
        },
        coral: {
          DEFAULT: '#E8573D',
        },
      },
      fontFamily: {
        jakarta: ['"Plus Jakarta Sans"', 'sans-serif'],
      },
    },
  },
  plugins: [],
};