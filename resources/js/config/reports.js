import { ClipboardList, HelpCircle, LayoutDashboard, LineChart, MessageSquare, MessagesSquare, MousePointerClick } from 'lucide-react';

/** Look of each metric the server defines in config/reports.php (the server owns the data, this owns the icon and tone). */
export const metricStyles = {
    chats: { icon: MessageSquare, tone: 'blue' },
    interactions: { icon: MousePointerClick, tone: 'violet' },
    questions: { icon: HelpCircle, tone: 'green' },
    surveys: { icon: ClipboardList, tone: 'amber' },
};

/**
 * Sections of Reportes. `summary` is built with real data; every other section is a prepared space for a module that
 * does not exist yet: `module` is that module's name, `metric` the metric it will feed and `charts` what it will draw.
 */
export const reportSections = [
    { id: 'summary', label: 'Resumen', icon: LayoutDashboard },
    {
        id: 'conversations',
        label: 'Conversaciones',
        icon: MessagesSquare,
        module: 'Conversaciones',
        metric: 'chats',
        description: 'Cuántas conversaciones inicia la gente con Ava, cuándo ocurren y cuáles se resuelven.',
        charts: [
            { type: 'line', title: 'Chats en el tiempo', description: 'Conversaciones iniciadas por día, semana o mes.' },
            { type: 'bars', title: 'Chats por franja horaria', description: 'En qué momentos del día se concentra la actividad.' },
        ],
    },
    {
        id: 'interactions',
        label: 'Interacciones',
        icon: MousePointerClick,
        module: 'Interacciones',
        metric: 'interactions',
        description: 'Mensajes e intercambios entre las personas y Ava, y cuánto dura cada conversación.',
        charts: [
            { type: 'line', title: 'Interacciones en el tiempo', description: 'Volumen de intercambios por periodo.' },
            { type: 'ranking', title: 'Canales con más actividad', description: 'Desde dónde llegan las interacciones.' },
        ],
    },
    {
        id: 'questions',
        label: 'Preguntas',
        icon: HelpCircle,
        module: 'Preguntas',
        metric: 'questions',
        description: 'Qué preguntan las personas y qué temas se repiten más.',
        charts: [
            { type: 'ranking', title: 'Preguntas más frecuentes', description: 'Ranking de lo que más se consulta.' },
            { type: 'donut', title: 'Distribución por tema', description: 'Proporción de preguntas por categoría.' },
        ],
    },
    {
        id: 'surveys',
        label: 'Encuestas',
        icon: ClipboardList,
        module: 'Encuestas',
        metric: 'surveys',
        description: 'Respuestas y satisfacción de las encuestas que se envían tras una conversación.',
        charts: [
            { type: 'line', title: 'Respuestas en el tiempo', description: 'Encuestas contestadas por periodo.' },
            { type: 'donut', title: 'Resultado de las encuestas', description: 'Distribución de las valoraciones.' },
        ],
    },
    {
        id: 'trends',
        label: 'Tendencias',
        icon: LineChart,
        module: 'las métricas de Ava',
        description: 'Cómo evoluciona todo lo anterior y qué cambia frente al periodo anterior.',
        charts: [
            { type: 'line', title: 'Evolución comparada', description: 'Varias métricas sobre la misma línea de tiempo.' },
            { type: 'bars', title: 'Variación frente al periodo anterior', description: 'Qué sube y qué baja respecto al periodo previo.' },
        ],
    },
];
