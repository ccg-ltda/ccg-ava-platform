export default function EmptyState({ icon: Icon, title, description, children, className = '' }) {
    return (
        <div
            className={`flex flex-col items-center rounded-xl border border-dashed border-line bg-canvas/60 px-6 py-10 text-center ${className}`}
        >
            {Icon && (
                <span className="mb-3 grid size-12 place-items-center rounded-full bg-accent-blue/10 text-accent-blue">
                    <Icon className="size-6" aria-hidden="true" />
                </span>
            )}
            <p className="text-sm font-semibold text-ink">{title}</p>
            {description && <p className="mt-1 max-w-sm text-sm text-ink-muted">{description}</p>}
            {children && <div className="mt-4">{children}</div>}
        </div>
    );
}
