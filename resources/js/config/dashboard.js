/**
 * How the Dashboard presents what the server computes (the server owns the figures; this only owns words, colors and the
 * short description of each quick link).
 */

/** Colors of the series of the activity chart. */
export const activitySeries = [
    { key: 'conversations', label: 'Conversaciones nuevas', className: 'text-accent-blue' },
    { key: 'received', label: 'Mensajes recibidos', className: 'text-accent-violet' },
    { key: 'sent', label: 'Respuestas enviadas', className: 'text-accent-green' },
];

/** Tone of each state of attention of a conversation (same tones as the inbox, see config/conversations.js). */
export const stateTones = { ai: 'blue', pending: 'amber', human: 'violet', resolved: 'green' };

/** What an integration's state means. `verified` is the result of the last real test, never a promise that it works now. */
export const integrationStates = {
    verified: { label: 'Verificada en la última prueba', tone: 'green' },
    failed: { label: 'Falló la última prueba', tone: 'red' },
    untested: { label: 'Sin probar', tone: 'amber' },
    configured: { label: 'Configurada (no admite prueba)', tone: 'neutral' },
    inactive: { label: 'Inactiva', tone: 'neutral' },
};

/** The things that need a person, with where to go. `route` is the page that handles it. */
export const attentionItems = {
    pending: { label: 'Conversaciones esperando a un agente', route: 'conversations.index', params: { status: 'pending' } },
    unconfirmed: { label: 'Respuestas sin confirmar en WhatsApp', route: 'conversations.index', params: {} },
    failedExecutions: { label: 'Ejecuciones del asistente fallidas', route: 'chatbots.index', params: {} },
};

/** One line under each quick link of the sidebar's modules. */
export const quickLinkHints = {
    'chatbots.index': 'Configura tus asistentes',
    'conversations.index': 'Atiende a tus contactos',
    'reports.index': 'Analiza con más detalle',
    'audit.index': 'Quién cambió qué',
    'settings.index': 'Preferencias del Workspace',
    'integrations.index': 'Canales y conexiones',
    'users.index': 'Personas, roles y Workspaces',
    'plans.index': 'Planes y catálogo de módulos',
    'bookings.index': 'Citas, clientes y reglas de reserva',
    'services.index': 'Lo que ofreces para reservar',
    'professionals.index': 'Quién atiende y cuándo trabaja',
    'locations.index': 'Dónde atiendes y qué ofrece cada sede',
    'customers.index': 'Fichas de clientes y su historial',
    'campaigns.index': 'Plantillas y campañas',
    'catalog.index': 'Productos, precios y sedes que los ofrecen',
    'orders.index': 'Pedidos y su estado',
};
