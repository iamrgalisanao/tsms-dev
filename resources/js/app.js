/**
 * First we will load all of this project's JavaScript dependencies which
 * includes React and other libraries. It is a great starting point when
 * building robust, powerful web applications using React and Laravel.
 */

import "./bootstrap";

// Bundle Chart.js with Vite and expose it globally for legacy inline scripts
// Use AdminLTE's Chart.js (static plugin) on the page instead of bundling it here.
// Do NOT import Chart.js in this entry to avoid bundling a different Chart.js build
// that can conflict with AdminLTE's bundled Chart.js (v2).

/**
 * Next, we will create a fresh React component instance and attach it to
 * the page. Then, you may begin adding components to this application
 * or customize the JavaScript scaffolding to fit your unique needs.
 */

// If React setup isn't working, this will still provide basic functionality
console.log("App.js loaded - Authentication status:", !!window.authUser);
document.addEventListener("DOMContentLoaded", function () {
    const appElement = document.getElementById("app");
    if (appElement && window.authUser) {
        console.log("User authenticated:", window.authUser.name);
    }
});
