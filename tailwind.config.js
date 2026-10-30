/** @type {import('tailwindcss').Config} */
module.exports = {
  content: ['./*.php', './admin/*.php', './includes/*.php'],
  theme: {
    extend: {
      fontFamily: {
        sans: ['Sarabun', 'system-ui', 'sans-serif'],
      },
      colors: {
        // แดงเข้มแบบผ้าทอ/เสาวัด
        brand: {
          50: '#fdf2f2',
          100: '#fbe4e4',
          200: '#f6c4c4',
          500: '#c53030',
          600: '#9b1c1c',
          700: '#7a1212',
          800: '#5e0e0e',
          900: '#4a0b0b',
        },
        // ทองอ่อน/ทองเข้ม
        gold: {
          50: '#fbf6e9',
          100: '#f6ecd2',
          200: '#ecd9a3',
          400: '#d4a94a',
          500: '#b8892e',
          600: '#98701f',
        },
      },
    },
  },
  plugins: [],
};
