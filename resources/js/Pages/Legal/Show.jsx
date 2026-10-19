import { Head, Link } from '@inertiajs/react';
import Alert from '@/Components/Alert';
import GuestLayout from '@/Layouts/GuestLayout';

/** Marks every "[POR CONFIRMAR ...]" so the pending company data cannot be mistaken for a final text. */
export function Pending({ text }) {
    return text.split(/(\[POR CONFIRMAR[^\]]*\])/g).map((part, i) =>
        part.startsWith('[POR CONFIRMAR') ? (
            <mark key={i} className="rounded bg-accent-amber/20 px-1 text-ink">
                {part}
            </mark>
        ) : (
            <span key={i}>{part}</span>
        ),
    );
}

export function DraftNotice() {
    return (
        <Alert tone="warning" className="mb-6">
            <strong>Borrador pendiente de revisión jurídica.</strong> Este texto describe cómo funciona hoy la plataforma, pero aún no está
            aprobado por CCG ni es asesoría legal. Los datos marcados como «POR CONFIRMAR» los completará CCG.
        </Alert>
    );
}

export default function Show({ document, documents, draft, currentSlug }) {
    return (
        <GuestLayout title={document.title} wide>
            <Head title={document.title} />

            {draft && <DraftNotice />}

            <p className="mb-4 text-xs text-ink-muted">Versión {document.version}</p>

            {document.intro.map((paragraph, i) => (
                <p key={i} className="mb-3 text-sm leading-relaxed text-ink">
                    <Pending text={paragraph} />
                </p>
            ))}

            {document.sections.map((section) => (
                <section key={section.heading} className="mt-6">
                    <h2 className="mb-2 text-base font-bold text-ink">{section.heading}</h2>
                    {section.paragraphs.map((paragraph, i) => (
                        <p key={i} className="mb-2 text-sm leading-relaxed text-ink">
                            <Pending text={paragraph} />
                        </p>
                    ))}
                    {section.items.length > 0 && (
                        <ul className="list-disc space-y-1 pl-5 text-sm leading-relaxed text-ink">
                            {section.items.map((item, i) => (
                                <li key={i}>
                                    <Pending text={item} />
                                </li>
                            ))}
                        </ul>
                    )}
                </section>
            ))}

            <nav aria-label="Otros documentos" className="mt-8 border-t border-line pt-4 text-sm">
                <p className="mb-2 font-semibold text-ink">Otros documentos</p>
                <ul className="space-y-1">
                    {documents
                        .filter((d) => d.slug !== currentSlug)
                        .map((d) => (
                            <li key={d.slug}>
                                <Link href={route('legal.show', { slug: d.slug })} className="text-primary hover:underline">
                                    {d.title}
                                </Link>
                            </li>
                        ))}
                </ul>
            </nav>
        </GuestLayout>
    );
}
