/** Formatting of dates, times and money with the active Workspace's regional preferences. */

const CURRENCY_LOCALE = { COP: 'es-CO', DOP: 'es-DO', USD: 'en-US', EUR: 'es-ES' };

const parts = (moment, timeZone, hour12) =>
    Object.fromEntries(
        new Intl.DateTimeFormat('en-US', {
            timeZone,
            year: 'numeric',
            month: '2-digit',
            day: '2-digit',
            hour: '2-digit',
            minute: '2-digit',
            hour12,
            hourCycle: hour12 ? 'h12' : 'h23',
        })
            .formatToParts(moment)
            .map(({ type, value }) => [type, value]),
    );

/** dateFormat: 'dmy' | 'mdy' | 'ymd' (see config/workspace.php). */
export function formatDate(moment, { timezone, dateFormat }) {
    const { day, month, year } = parts(moment, timezone, false);

    return { dmy: `${day}/${month}/${year}`, mdy: `${month}/${day}/${year}`, ymd: `${year}-${month}-${day}` }[dateFormat];
}

/** timeFormat: '24h' | '12h'. */
export function formatTime(moment, { timezone, timeFormat }) {
    const twelve = timeFormat === '12h';
    const { hour, minute, dayPeriod } = parts(moment, timezone, twelve);

    return twelve ? `${hour}:${minute} ${dayPeriod}` : `${hour}:${minute}`;
}

export const formatMoney = (amount, currency) =>
    new Intl.NumberFormat(CURRENCY_LOCALE[currency] ?? 'es-ES', { style: 'currency', currency }).format(amount);
