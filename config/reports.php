<?php

/*
 * Reportes: the analytics center of a Workspace. `periods` are the ranges the selector offers (in days) with the
 * granularities each one allows (the first is the default). `metrics` is the contract with the modules that will feed
 * Reportes: a metric without a source reports no value, so the page shows "Sin datos todavía" instead of a number.
 * When a module (conversations, interactions, questions, surveys) exists, point its metric `source` at the class
 * that computes it from that module's own tables, always starting from the active Workspace.
 */
return [
    'periods' => [
        '7d' => ['label' => 'Últimos 7 días', 'days' => 7, 'granularities' => ['day']],
        '30d' => ['label' => 'Últimos 30 días', 'days' => 30, 'granularities' => ['day', 'week']],
        '90d' => ['label' => 'Últimos 90 días', 'days' => 90, 'granularities' => ['week', 'day', 'month']],
        '12m' => ['label' => 'Últimos 12 meses', 'days' => 365, 'granularities' => ['month', 'week']],
    ],

    'default_period' => '30d',

    'granularities' => ['day' => 'Día', 'week' => 'Semana', 'month' => 'Mes'],

    'metrics' => [
        'chats' => ['label' => 'Chats', 'hint' => 'Conversaciones iniciadas', 'section' => 'conversations', 'source' => null],
        'interactions' => ['label' => 'Interacciones', 'hint' => 'Mensajes e intercambios', 'section' => 'interactions', 'source' => null],
        'questions' => ['label' => 'Preguntas', 'hint' => 'Preguntas recibidas por Ava', 'section' => 'questions', 'source' => null],
        'surveys' => ['label' => 'Encuestas', 'hint' => 'Respuestas recibidas', 'section' => 'surveys', 'source' => null],
    ],
];
