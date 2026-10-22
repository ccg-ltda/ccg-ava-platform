/** A section of the plan editor: its own card, with the same look in every tab (slightly apart from the page background, thin border, no nested shadows). */
export default function SectionCard({ title, description, children, id }) {
    return (
        <section aria-labelledby={id} className="space-y-5 rounded-xl border border-line bg-canvas/40 p-4 sm:p-5">
            <div>
                <h3 id={id} className="text-sm font-bold text-ink">{title}</h3>
                {description && <p className="mt-1 text-xs text-ink-muted">{description}</p>}
            </div>
            {children}
        </section>
    );
}
