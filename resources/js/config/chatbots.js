import { Camera, Globe, MessageCircle, MessagesSquare } from 'lucide-react';

/** Icon of each channel of the global catalog (config/chatbots.php). A channel the server adds without an icon gets the generic one. */
export const channelIcons = { web: Globe, whatsapp: MessageCircle, instagram: Camera, messenger: MessagesSquare };

export const channelIcon = (key) => channelIcons[key] ?? MessagesSquare;

/**
 * How each state a chatbot channel can be in (decided by the server) is shown. `hint` says what the user can do.
 * `locked` states cannot be switched on from the chatbot.
 */
export const channelStates = {
    active: { label: 'Activo', tone: 'green' },
    inactive: { label: 'Inactivo', tone: 'neutral' },
    not_configured: { label: 'Sin configurar', tone: 'amber', locked: true, hint: 'Configura la integración de este canal en Integraciones.' },
    integration_inactive: { label: 'Integración inactiva', tone: 'amber', locked: true, hint: 'Activa la integración de este canal en Integraciones.' },
    in_use: { label: 'En uso', tone: 'blue', locked: true, hint: 'Otro chatbot de este Workspace responde por este canal.' },
    unavailable: { label: 'No disponible todavía', tone: 'neutral', locked: true },
};
