/** Shared table pieces: horizontal scroll stays inside the card, never on the page. */
export function TableWrap({ children, busy = false }) {
    return (
        <div className={`overflow-x-auto transition-opacity duration-150 ${busy ? 'opacity-60' : ''}`} aria-busy={busy}>
            <table className="min-w-full divide-y divide-line text-sm">{children}</table>
        </div>
    );
}

export function Th({ children, className = '' }) {
    return (
        <th scope="col" className={`bg-canvas px-4 py-3 text-left text-[11px] font-semibold tracking-wider whitespace-nowrap text-ink-muted uppercase sm:px-6 ${className}`}>
            {children}
        </th>
    );
}

export function Td({ children, className = '' }) {
    return <td className={`px-4 py-3.5 align-middle sm:px-6 ${className}`}>{children}</td>;
}
