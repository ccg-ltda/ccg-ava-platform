/** Look of each appointment status (the server decides the status and its label). */
export const statusTones = { requested: 'amber', confirmed: 'green', completed: 'blue', no_show: 'neutral', cancelled: 'neutral', failed: 'red' };

/** Sync state of an appointment of an external agenda (what that agenda has accepted). */
export const syncLabels = { pending: 'Enviando a la agenda', synced: 'Sincronizada', failed: 'Error de sincronización', uncertain: 'Sin confirmar: verifica en la agenda externa antes de repetirla' };

export const syncErrors = {
    auth: 'La agenda externa rechazó las credenciales.',
    timeout: 'La agenda externa no respondió a tiempo.',
    rate_limited: 'La agenda externa limitó las solicitudes.',
    unavailable: 'La agenda externa no está disponible.',
    conflict: 'El horario ya no estaba libre en la agenda externa.',
    unsupported: 'La agenda externa no ofrece esta operación.',
    invalid: 'La agenda externa no aceptó los datos.',
    not_found: 'La agenda externa ya no conoce esta cita.',
    unknown: 'La agenda externa respondió con un error.',
};

/** A price in its own currency (the one recorded with the appointment, not today's price of the service). */
export const money = (value, currency) => new Intl.NumberFormat('es', { style: 'currency', currency }).format(value);

/** Today and a few days ahead as `YYYY-MM-DD`, in the browser's own calendar (the server decides what is offered). */
export const addDays = (date, days) => {
    const next = new Date(`${date}T12:00:00`);
    next.setDate(next.getDate() + days);

    return next.toISOString().slice(0, 10);
};

/** "lun 13 oct" for a `YYYY-MM-DD`. */
export const dayLabel = (date) => new Date(`${date}T12:00:00`).toLocaleDateString('es', { weekday: 'short', day: 'numeric', month: 'short' });

/** Reads JSON from one of Ava's endpoints; a refusal carries the server's own message. */
export async function getJson(url, params = {}) {
    const query = new URLSearchParams(Object.entries(params).filter(([, value]) => value !== undefined && value !== null && value !== '')).toString();
    const response = await fetch(`${url}${query ? `?${query}` : ''}`, { headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' });
    const body = await response.json().catch(() => ({}));

    if (!response.ok) throw new Error(body.message ?? 'No se pudo completar la solicitud.');

    return body;
}

/** Sends JSON to one of Ava's open endpoints (the booking page); a refusal carries the server's own message and reason. */
export async function postJson(url, data) {
    const response = await fetch(url, { method: 'POST', headers: { Accept: 'application/json', 'Content-Type': 'application/json' }, body: JSON.stringify(data) });
    const body = await response.json().catch(() => ({}));

    if (!response.ok) {
        const first = body.errors ? Object.values(body.errors)[0]?.[0] : null;
        const error = new Error(first ?? body.message ?? 'No se pudo completar la solicitud.');
        error.reason = body.reason;

        throw error;
    }

    return body;
}
