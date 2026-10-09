import { Bot, CheckCheck, Headset, UserRoundCheck } from 'lucide-react';

/**
 * Who answers a conversation (`conversations.handling`, decided by the server): how each state is named and shown.
 * The order is the order of the filters of the inbox.
 */
export const handlingStates = {
    ai: { label: 'IA atendiendo', short: 'IA', tone: 'blue', icon: Bot },
    pending: { label: 'Pendiente de agente', short: 'Pendientes', tone: 'amber', icon: Headset },
    human: { label: 'En atención', short: 'En atención', tone: 'violet', icon: UserRoundCheck },
    resolved: { label: 'Resuelta', short: 'Resueltas', tone: 'green', icon: CheckCheck },
};

/** Delivery states of an outgoing message. `pending` is a message Ava accepted from an agent and has not handed to the channel yet. */
export const messageStatuses = { pending: 'Enviando…', sent: 'Enviado', delivered: 'Entregado', read: 'Leído', failed: 'No se pudo enviar' };
