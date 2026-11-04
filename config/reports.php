<?php

/*
 * Reportes: the analytics center of a Workspace. `periods` are the ranges the selector offers (in days) with the
 * granularities each one allows (the first is the default). `metrics` is the contract with the data behind Reportes:
 * `source` names the table the figure is computed from (`DashboardMetrics`, shared with the Dashboard, always starting
 * from the Workspace `WorkspaceScope` resolved). A metric without a source reports no figure, so the page says so
 * instead of showing a number. `questions` has no source (Ava stores no question text); Encuestas are built from persisted survey responses by SurveyMetrics, outside this metric list.
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
        'chats' => ['label' => 'Chats', 'hint' => 'Conversaciones iniciadas', 'section' => 'conversations', 'source' => 'conversations'],
        'interactions' => ['label' => 'Interacciones', 'hint' => 'Mensajes recibidos y respuestas enviadas', 'section' => 'interactions', 'source' => 'messages'],
        'questions' => ['label' => 'Preguntas', 'hint' => 'Preguntas recibidas por Ava', 'section' => 'questions', 'source' => null],
        'surveys' => ['label' => 'Encuestas', 'hint' => 'Respuestas recibidas', 'section' => 'surveys', 'source' => null],
    ],
];
