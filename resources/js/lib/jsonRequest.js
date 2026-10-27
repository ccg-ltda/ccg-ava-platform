const cookie = (name) => {
    const match = document.cookie.split('; ').find((part) => part.startsWith(`${name}=`));

    return match ? decodeURIComponent(match.slice(name.length + 1)) : null;
};

/**
 * A JSON request to the application with the session of the signed-in person. Use a RELATIVE path (`route(name, params, false)`), so it always goes to the origin the page
 * was opened from. Writes carry the CSRF token the server keeps current in the `XSRF-TOKEN` cookie (the `<meta>` of the first page load goes stale when the session is
 * renewed, for example after signing in without a full reload).
 *
 * Resolves with the parsed body. Rejects with an Error that carries `status` and a message the interface can show as it is: the first validation message of the server, or a
 * plain explanation when the session expired (the server answered with a redirect or a page instead of JSON), the page expired (419) or the server failed.
 */
export default async function jsonRequest(url, { method = 'GET', body } = {}) {
    const headers = { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' };

    if (body !== undefined) {
        headers['Content-Type'] = 'application/json';
        headers['X-XSRF-TOKEN'] = cookie('XSRF-TOKEN') ?? document.querySelector('meta[name="csrf-token"]')?.content ?? '';
    }

    let response;

    try {
        response = await fetch(url, { method, headers, credentials: 'same-origin', body: body === undefined ? undefined : JSON.stringify(body) });
    } catch {
        throw Object.assign(new Error('No hay conexión con el servidor. Revisa tu red e inténtalo de nuevo.'), { status: 0 });
    }

    const isJson = (response.headers.get('content-type') ?? '').includes('application/json');

    if (response.redirected || (response.ok && !isJson)) {
        throw Object.assign(new Error('Tu sesión expiró o cambió de Workspace. Recarga la página e inicia sesión de nuevo.'), { status: 401 });
    }

    const data = isJson ? await response.json().catch(() => ({})) : {};

    if (!response.ok) {
        const first = data.errors ? Object.values(data.errors).flat()[0] : null;
        const fallback = response.status === 419 ? 'La página caducó. Recárgala e inténtalo de nuevo.' : response.status >= 500 ? 'El servidor no pudo procesar la solicitud. Inténtalo de nuevo en un momento.' : null;

        throw Object.assign(new Error(first ?? data.message ?? fallback ?? 'No se pudo completar la solicitud.'), { status: response.status });
    }

    return data;
}
