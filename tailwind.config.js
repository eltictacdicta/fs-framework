/** @type {import('tailwindcss').Config} */
// Tailwind CSS v4 - Configuration is now done in CSS with @theme
// This file is kept for compatibility with tools that may still read it
export default {
    content: [
        "./view/**/*.html",
        "./view/**/*.twig",
        "./plugins/**/view/**/*.html",
        "./plugins/**/view/**/*.twig",
        "./src/**/*.php",
        "./controller/**/*.php"
    ]
}
