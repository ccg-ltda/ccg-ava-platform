/**
 * A price in the currency of the order (the one recorded with it, not today's). Whole amounts keep the currency's usual look (18.500 COP); an amount with
 * cents shows them (4.000,50 COP) so a price is never rounded on screen and the lines always add up to the total the server computed.
 */
export const money = (value, currency) => {
    const amount = Number(value);
    const hasCents = Math.round(amount * 100) % 100 !== 0;

    return new Intl.NumberFormat('es', { style: 'currency', currency, maximumFractionDigits: 2, ...(hasCents ? { minimumFractionDigits: 2 } : {}) }).format(amount);
};

/** "16/10/26, 14:05" for an ISO moment, in the browser's own timezone. */
export const day = (iso) => new Date(iso).toLocaleString('es', { dateStyle: 'short', timeStyle: 'short' });
