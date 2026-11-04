<?php

/*
 * Skeleton loading: the placeholder the application shows while a page is on its way, instead of a blank screen. ONE source for the server (the first paint of the HTML shell,
 * before any JavaScript runs) and the client (a navigation that takes longer than `delay_ms`): `paths` says which shape each module's page has, by the first segment of its URL.
 *
 *  - dashboard: indicator cards, charts and lists, with the period selector in the header (Dashboard)
 *  - reports: period selector, the tabs of the report, indicators and charts (Reportes)
 *  - tabbed_table: header with its button, tabs, filter card and list (Clientes, Recordatorios)
 *  - orders: tabs, hint line, filters and list (Pedidos)
 *  - agenda: tabs, calendar toolbar and the calendar (Agenda)
 *  - table: header, filters and a list or table (the other modules)
 *  - cards: header with its button, tabs and a grid of cards (Integraciones, Asistentes)
 *  - form: header and a stack of form sections (Configuraciones, Perfil)
 *  - conversations: its own title row, the assignment bar, the filter pills and the list next to the chat
 * The proportions were measured on the real pages (see Components/Skeleton.jsx and partials/boot-skeleton.blade.php, twins of each other).
 * A path that is not listed uses `default`. Pages of the access screens (login, registro, legales) have no shell and no skeleton (`guest_components`).
 */
return [
    'paths' => [
        'dashboard' => 'dashboard',
        'reports' => 'reports',
        'conversations' => 'conversations',
        'orders' => 'orders',
        'catalog' => 'table',
        'customers' => 'tabbed_table',
        'campaigns' => 'table',
        'reminders' => 'tabbed_table',
        'users' => 'table',
        'audit' => 'table',
        'bookings' => 'agenda',
        'services' => 'table',
        'professionals' => 'table',
        'locations' => 'table',
        'plans' => 'table',
        'integrations' => 'cards',
        'chatbots' => 'cards',
        'settings' => 'form',
        'profile' => 'form',
    ],

    'default' => 'table',

    // Inertia page components that do not use the application shell (their first paint is the page itself).
    'guest_components' => ['Auth/', 'Signup/', 'Legal/', 'Booking/', 'Errors/'],

    // A navigation that finishes before this many milliseconds never shows a skeleton (no flash on a fast answer).
    // Measured on the real pages: a navigation answers in 150-600 ms, so the old 150 ms showed a skeleton on almost every click and the page seemed to wait for it. 300 ms leaves a normal answer
    // untouched (the current page stays until the new one is ready) and only a slow one gets the skeleton. There is never a minimum time on screen: it goes the moment the page arrives.
    'delay_ms' => 300,

    // A page that has not arrived after this many milliseconds stops showing the skeleton and says so, with a way to retry (never a skeleton forever).
    'give_up_ms' => 20000,
];
