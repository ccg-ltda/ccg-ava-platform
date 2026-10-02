import { Search } from 'lucide-react';

/** Search field for list toolbars. Controlled: parent debounces and runs the query. */
export default function SearchInput({ value, onChange, placeholder = 'Buscar', label = 'Buscar', className = '' }) {
    return (
        <label className={`relative block ${className}`}>
            <span className="sr-only">{label}</span>
            <Search className="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-ink-muted" aria-hidden="true" />
            <input
                type="search"
                value={value}
                onChange={(e) => onChange(e.target.value)}
                placeholder={placeholder}
                className="w-full rounded-lg border border-line bg-card py-2 pr-3 pl-9 text-sm text-ink placeholder:text-ink-muted focus:border-primary focus:ring-1 focus:ring-primary"
            />
        </label>
    );
}
