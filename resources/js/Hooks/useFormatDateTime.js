import { usePage } from '@inertiajs/react';
import { formatDate, formatTime } from '@/lib/format';

/** Formats an ISO moment as "date time" with the regional preferences of the active Workspace (Configuraciones). */
export default function useFormatDateTime() {
    const preferences = usePage().props.workspace?.settings;

    return (iso) => {
        if (!iso || !preferences) return iso ?? '';

        const moment = new Date(iso);

        return `${formatDate(moment, preferences)} ${formatTime(moment, preferences)}`;
    };
}
