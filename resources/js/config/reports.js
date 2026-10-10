import { ClipboardList, HelpCircle, LayoutDashboard, LineChart, MessageSquare, MessagesSquare, MousePointerClick } from 'lucide-react';

/** Look of each metric the server defines in config/reports.php (the server owns the data, this owns the icon and tone). */
export const metricStyles = {
    chats: { icon: MessageSquare, tone: 'blue' },
    interactions: { icon: MousePointerClick, tone: 'violet' },
    questions: { icon: HelpCircle, tone: 'green' },
    surveys: { icon: ClipboardList, tone: 'amber' },
};

/** Series of the activity charts (same colors as the Dashboard). */
export const seriesLabels = {
    conversations: { key: 'conversations', label: 'Conversaciones nuevas', className: 'text-accent-blue' },
    received: { key: 'received', label: 'Mensajes recibidos', className: 'text-accent-violet' },
    sent: { key: 'sent', label: 'Respuestas enviadas', className: 'text-accent-green' },
};

/**
 * Sections of Reportes. `summary`, `conversations`, `interactions` and `trends` are built with real data from the
 * stored conversations and messages. `questions` and `surveys` have no source (Ava stores no question text and no
 * survey answers): they say so (`reason`) and draw nothing.
 */
export const reportSections = [
    { id: 'summary', label: 'Resumen', icon: LayoutDashboard },
    { id: 'conversations', label: 'Conversaciones', icon: MessagesSquare },
    { id: 'interactions', label: 'Interacciones', icon: MousePointerClick },
    {
        id: 'questions',
        label: 'Preguntas',
        icon: HelpCircle,
        metric: 'questions',
        description: 'Qué preguntan las personas y qué temas se repiten más.',
        reason: 'Ava todavía no clasifica ni guarda las preguntas por tema, así que no hay de dónde calcular este reporte. No se muestran cifras inventadas.',
        charts: [
            { type: 'ranking', title: 'Preguntas más frecuentes', description: 'Ranking de lo que más se consulta.' },
            { type: 'donut', title: 'Distribución por tema', description: 'Proporción de preguntas por categoría.' },
        ],
    },
    {
        id: 'surveys',
        label: 'Encuestas',
        icon: ClipboardList,
        metric: 'surveys',
        description: 'Respuestas y satisfacción de las encuestas que se envían tras una conversación.',
        reason: 'Ava todavía no envía encuestas ni guarda sus respuestas, así que no hay de dónde calcular este reporte. No se muestran cifras inventadas.',
        charts: [
            { type: 'line', title: 'Respuestas en el tiempo', description: 'Encuestas contestadas por periodo.' },
            { type: 'donut', title: 'Resultado de las encuestas', description: 'Distribución de las valoraciones.' },
        ],
    },
    { id: 'trends', label: 'Tendencias', icon: LineChart },
];
