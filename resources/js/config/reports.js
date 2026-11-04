import { CalendarDays, ClipboardList, Coins, Contact, HelpCircle, LayoutDashboard, LineChart, MessageSquare, MessagesSquare, MousePointerClick, ShoppingCart } from 'lucide-react';

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
 * stored conversations and messages. `questions` has no source (Ava stores no question text): it says so (`reason`) and draws nothing. `surveys` is
 * built from the surveys the automation engine reports (SurveysPanel).
 *
 * `needs` lists the sources a section is built from (any one is enough): `conversations` (conversations and messages, module
 * Conversaciones), `team` (the people of the Workspace, module Usuarios y roles) and `bookings`, `orders`, `economics` (the money of Agenda and Pedidos) and `customers` (the figures of Agenda,
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
    { id: 'surveys', label: 'Encuestas', icon: ClipboardList, metric: 'surveys', needs: ['conversations'] },
    { id: 'trends', label: 'Tendencias', icon: LineChart, needs: ['conversations'] },
    { id: 'bookings', label: 'Reservas', icon: CalendarDays, needs: ['bookings', 'bookingRecords'] },
    { id: 'orders', label: 'Pedidos', icon: ShoppingCart, needs: ['orders'] },
    { id: 'economics', label: 'Economía', icon: Coins, needs: ['economics'] },
    { id: 'customers', label: 'Clientes', icon: Contact, needs: ['customers'] },
];
