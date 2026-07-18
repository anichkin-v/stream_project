/**
 * SVG-иконки плеера из MoVix_TV2-2 (набор в духе Venom / JW Player).
 * window.KIDS_PLAYER_ICONS.svg(name, className)
 */
(function (global) {
    'use strict';

    var ICONS = {
        play: '<path d="M8 5v14l11-7z"/>',
        pause: '<path d="M7 5h4v14H7zM13 5h4v14h-4z"/>',
        'center-play': '<path d="M8.75 5.25v13.5l10.25-6.75z"/>',
        'ep-prev': '<path d="M9.2 16.4v-3.7l7.4 3.7V7.1l-7.4 3.7v-3.7H7.3v9.2h1.9z"/>',
        'ep-next': '<path d="M14.9 7.2v3.8L7.2 7.2v9.6l7.7-3.8V16.8h1.9V7.2h-1.9z"/>',
        volume:
            '<path d="M3 9v6h4l5 5V4L7 9H3zm13.5 3c0-1.77-1.02-3.29-2.5-4.03v8.05c1.48-.73 2.5-2.25 2.5-4.02zM14 3.23v2.06c2.89.86 5 3.54 5 6.71s-2.11 5.85-5 6.71v2.06c4.01-.91 7-4.49 7-8.77s-2.99-7.86-7-8.77z"/>',
        'volume-low':
            '<path d="M18.5 12c0-1.77-1.02-3.29-2.5-4.03v8.05c1.48-.73 2.5-2.25 2.5-4.02zM5 9v6h4l5 5V4L9 9H5z"/>',
        'volume-mute':
            '<path d="M16.5 12c0-1.77-1.02-3.29-2.5-4.03v2.21l2.45 2.45c.03-.2.05-.41.05-.63zm2.5 0c0 .94-.2 1.82-.54 2.64l1.51 1.51C20.63 14.91 21 13.5 21 12c0-4.28-2.99-7.86-7-8.77v2.06c2.89.86 5 3.54 5 6.71zM4.27 3L3 4.27 7.73 9H3v6h4l5 5v-6.73l4.25 4.25c-.67.52-1.42.93-2.25 1.18v2.06c1.38-.31 2.63-.95 3.69-1.81L19.73 21 21 19.73l-9-9L4.27 3zM12 4L9.91 6.09 12 8.18V4z"/>',
        settings:
            '<path fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" d="M12 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6z"/>' +
            '<path fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06A1.65 1.65 0 0 0 9 4.6a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9c.1 0 .2.03.29.09H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"/>',
        fullscreen:
            '<polyline points="15 3 21 3 21 9"/><polyline points="9 21 3 21 3 15"/><line x1="14" y1="10" x2="21" y2="3"/><line x1="10" y1="14" x2="3" y2="21"/>',
        'fullscreen-exit':
            '<polyline points="4 14 10 14 10 20"/><polyline points="20 10 14 10 14 4"/><line x1="21" y1="3" x2="14" y2="10"/><line x1="3" y1="21" x2="10" y2="14"/>'
    };

    var STROKE_ICONS = {
        settings: true,
        fullscreen: true,
        'fullscreen-exit': true
    };

    function svg(name, className) {
        var inner = ICONS[name];
        if (!inner) return '';
        var cls = className || 'player-ico';
        var ns = ' xmlns="http://www.w3.org/2000/svg"';
        if (STROKE_ICONS[name]) {
            return (
                '<svg class="' + cls + '" viewBox="0 0 24 24" aria-hidden="true"' + ns +
                ' fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' +
                inner +
                '</svg>'
            );
        }
        var body = inner.replace(/<path(?![^>]*\bfill=)/gi, '<path fill="currentColor" ');
        return (
            '<svg class="' + cls + '" viewBox="0 0 24 24" aria-hidden="true"' + ns + '>' +
            body +
            '</svg>'
        );
    }

    global.KIDS_PLAYER_ICONS = { svg: svg };
})(typeof window !== 'undefined' ? window : globalThis);
