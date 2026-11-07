import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    plugins: [
        tailwindcss(),
        laravel({
            input: ['resources/css/app.css', 'resources/css/signed-in.css', 'resources/css/engage.css', 'resources/js/app.js', 'resources/js/engage.js', 'resources/js/viewer/viewer.js', 'resources/js/viewer/compare.js', 'resources/css/engage-meeting.css', 'resources/js/meeting/room.js', 'resources/js/signing/prepare.js', 'resources/js/signing/sign.js'],
            refresh: true,
        }),
    ],
});
