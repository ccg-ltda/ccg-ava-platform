<?php

/*
 * Catalog behind Configuraciones (workspace_settings). Everything the UI offers comes from here, so the form and
 * the validation share one source. Fiscal data is informational: the tax defaults are starting values, not rules.
 */
return [
    'currencies' => [
        'COP' => 'Peso colombiano',
        'DOP' => 'Peso dominicano',
        'USD' => 'Dólar estadounidense',
        'EUR' => 'Euro',
    ],

    'timezones' => [
        'America/Bogota' => 'Colombia (Bogotá)',
        'America/Santo_Domingo' => 'República Dominicana (Santo Domingo)',
        'America/New_York' => 'EE. UU. — Este (Nueva York)',
        'America/Chicago' => 'EE. UU. — Centro (Chicago)',
        'America/Denver' => 'EE. UU. — Montaña (Denver)',
        'America/Los_Angeles' => 'EE. UU. — Pacífico (Los Ángeles)',
        'Europe/Madrid' => 'España (Madrid)',
        'Atlantic/Canary' => 'España — Canarias',
        'UTC' => 'UTC',
    ],

    'date_formats' => [
        'dmy' => ['label' => 'DD/MM/AAAA', 'php' => 'd/m/Y'],
        'mdy' => ['label' => 'MM/DD/AAAA', 'php' => 'm/d/Y'],
        'ymd' => ['label' => 'AAAA-MM-DD', 'php' => 'Y-m-d'],
    ],

    'time_formats' => [
        '24h' => ['label' => '24 horas', 'php' => 'H:i'],
        '12h' => ['label' => '12 horas (AM/PM)', 'php' => 'h:i A'],
    ],

    'appearances' => [
        'light' => 'Claro',
        'dark' => 'Oscuro',
        'system' => 'Sistema',
    ],

    /* Suggested brand colors (any other color is accepted if white text stays readable on it). */
    'palette' => ['#1d4ed8', '#0369a1', '#0f766e', '#15803d', '#6d28d9', '#be185d', '#b91c1c', '#c2410c'],

    /* Minimum contrast between white text and the primary color (WCAG AA for normal text). */
    'min_contrast' => 4.5,

    'tax_countries' => [
        'DO' => [
            'name' => 'República Dominicana',
            'flag' => '🇩🇴',
            'currency' => 'DOP',
            'tax' => ['enabled' => true, 'name' => 'ITBIS', 'rate' => 18],
            'authority' => 'DGII — Dirección General de Impuestos Internos',
            'system' => 'Comprobantes fiscales electrónicos (e-CF)',
            'note' => null,
        ],
        'CO' => [
            'name' => 'Colombia',
            'flag' => '🇨🇴',
            'currency' => 'COP',
            'tax' => ['enabled' => true, 'name' => 'IVA', 'rate' => 19],
            'authority' => 'DIAN — Dirección de Impuestos y Aduanas Nacionales',
            'system' => 'Facturación electrónica DIAN',
            'note' => null,
        ],
        'US' => [
            'name' => 'Estados Unidos',
            'flag' => '🇺🇸',
            'currency' => 'USD',
            'tax' => ['enabled' => false, 'name' => 'Sales Tax', 'rate' => 0],
            'authority' => 'IRS (federal) y autoridades estatales y locales',
            'system' => 'Sin sistema nacional único de facturación electrónica',
            'note' => 'No existe una tasa nacional única: el Sales Tax depende del estado y la localidad. Indica la tasa que aplique a tu negocio.',
        ],
        'ES' => [
            'name' => 'España',
            'flag' => '🇪🇸',
            'currency' => 'EUR',
            'tax' => ['enabled' => true, 'name' => 'IVA', 'rate' => 21],
            'authority' => 'AEAT — Agencia Estatal de Administración Tributaria',
            'system' => 'Sistema Verifactu / SII',
            'note' => null,
        ],
    ],

    /* Values of a Workspace that has not saved its settings yet (the Dominican reference market). */
    'defaults' => [
        'currency' => 'DOP',
        'timezone' => 'America/Santo_Domingo',
        'date_format' => 'dmy',
        'time_format' => '12h',
        'appearance' => 'light',
        'primary_color' => '#1d4ed8',
        'tax_country' => 'DO',
        'tax_enabled' => true,
        'tax_name' => 'ITBIS',
        'tax_rate' => 18,
    ],
];
