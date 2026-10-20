import { Building2, PanelTop } from 'lucide-react';
import { isHexColor } from '@/lib/theme';

/**
 * The identity of the business as a visual card: the brand color as a band, the logo (or an icon) over it, the name and the description. It only
 * draws the values it receives, so the same card shows the saved identity in the summary and the one being typed in the form's preview.
 */
export default function IdentityCard({ name, description, color, logoUrl }) {
    const band = isHexColor(color ?? '') ? color : undefined;

    return (
        <figure className="overflow-hidden rounded-xl border border-line bg-card shadow-card" aria-label="Ficha del negocio">
            <div className="h-20 bg-primary" style={band ? { backgroundColor: band } : undefined} aria-hidden="true" />
            <div className="px-5 pb-5">
                <span className="-mt-8 grid size-16 place-items-center overflow-hidden rounded-2xl border-4 border-card bg-canvas text-primary shadow-card">
                    {logoUrl ? <img src={logoUrl} alt="" className="size-full object-contain" /> : <Building2 className="size-7" aria-hidden="true" />}
                </span>
                <figcaption className="mt-3 min-w-0">
                    <p className={`text-xl font-bold break-words ${name ? 'text-ink' : 'text-ink-muted'}`}>{name || 'Nombre de tu negocio'}</p>
                    <p className={`mt-1 text-sm break-words ${description ? 'text-ink-muted' : 'text-ink-muted/70 italic'}`}>
                        {description || 'Aquí aparecerá la descripción de tu negocio.'}
                    </p>
                </figcaption>
                <p className="mt-4 flex items-center gap-2 border-t border-line pt-3 text-xs text-ink-muted">
                    <PanelTop className="size-4 shrink-0 text-primary" aria-hidden="true" />
                    El nombre se muestra en la barra superior de Ava para ti y tu equipo.
                </p>
            </div>
        </figure>
    );
}
