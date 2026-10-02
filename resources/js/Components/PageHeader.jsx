/** Title block of every page: heading, optional description and an actions slot. */
export default function PageHeader({ title, description, children }) {
    return (
        <header className="flex flex-wrap items-end justify-between gap-4">
            <div className="min-w-0">
                <h1 className="text-2xl font-extrabold tracking-tight text-ink sm:text-3xl">{title}</h1>
                {description && <p className="mt-1 text-sm text-ink-muted">{description}</p>}
            </div>
            {children && <div className="flex flex-wrap items-center gap-3">{children}</div>}
        </header>
    );
}
