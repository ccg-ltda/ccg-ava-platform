import React from 'react';
import ReactDOM from 'react-dom/client';
import { createInertiaApp } from '@inertiajs/react';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';

createInertiaApp({
    // The per-request nonce of the Content-Security-Policy (App\Http\Middleware\SecurityHeaders); the progress bar's inline <style> carries it.
    nonce: document.querySelector('meta[name="csp-nonce"]')?.content || undefined,
    // The visible brand comes from the server (config/branding.php; the Blade shell always prints it), not from a build-time variable: the production image is built without an .env.
    title: (title) => `${title} - ${document.querySelector('meta[name="brand"]')?.content ?? ''}`.replace(/ - $/, ''),
    resolve: (name) =>
        resolvePageComponent(
            `./Pages/${name}.jsx`,
            import.meta.glob('./Pages/**/*.jsx'),
        ),
    setup({ el, App, props }) {
        const root = ReactDOM.createRoot(el);
        root.render(<App {...props} />);
        // The server painted a skeleton of the page (partials/boot-skeleton.blade.php): it goes once the real page is on screen.
        requestAnimationFrame(() => requestAnimationFrame(() => document.getElementById('boot-skeleton')?.remove()));
    },
    progress: {
        color: '#4B5563',
    },
});
