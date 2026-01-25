/** @type {import('tailwindcss').Config} */
module.exports = {
    content: [
        "./view/**/*.html",
        "./view/**/*.twig",
        "./plugins/**/view/**/*.html",
        "./plugins/**/view/**/*.twig",
        "./src/**/*.php",
        "./controller/**/*.php"
    ],
    theme: {
        extend: {},
    },
    plugins: [],
}
