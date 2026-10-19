import { CalendarDays, ClipboardList, Contact, HelpCircle, LayoutDashboard, LineChart, MessageSquare, MessagesSquare, MousePointerClick, ShoppingCart } from 'lucide-react';

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
 *
 * `needs` lists the sources a section is built from (any one is enough): `conversations` (conversations and messages, module
 * Conversaciones), `team` (the people of the Workspace, module Usuarios y roles) and `bookings`, `orders` and `customers` (the figures of Agenda,
 * Pedidos and Clientes, only when the plan includes the module and the role may read it). The server sends `sources` with the ones
 * the plan includes, and a section none of whose sources exists is not offered.
 */
export const reportSections = [
    { id: 'summary', label: 'Resumen', icon: LayoutDashboard, needs: ['conversations', 'team'] },
    { id: 'conversations', label: 'Conversaciones', icon: MessagesSquare, needs: ['conversations'] },
    { id: 'interactions', label: 'Interacciones', icon: MousePointerClick, needs: ['conversations'] },
    {
        id: 'questions',
        label: 'Preguntas',
        icon: HelpCircle,
        metric: 'questions',
        needs: ['conversations'],
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
        needs: ['conversations'],
        description: 'Respuestas y satisfacción de las encuestas que se envían tras una conversación.',
        reason: 'Ava todavía no envía encuestas ni guarda sus respuestas, así que no hay de dónde calcular este reporte. No se muestran cifras inventadas.',
        charts: [
            { type: 'line', title: 'Respuestas en el tiempo', description: 'Encuestas contestadas por periodo.' },
            { type: 'donut', title: 'Resultado de las encuestas', description: 'Distribución de las valoraciones.' },
        ],
    },
    { id: 'trends', label: 'Tendencias', icon: LineChart, needs: ['conversations'] },
    { id: 'bookings', label: 'Reservas', icon: CalendarDays, needs: ['bookings'] },
    { id: 'orders', label: 'Pedidos', icon: ShoppingCart, needs: ['orders'] },
    { id: 'customers', label: 'Clientes', icon: Contact, needs: ['customers'] },
];
