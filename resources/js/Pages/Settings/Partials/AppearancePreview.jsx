import { Building2 } from 'lucide-react';
import Badge from '@/Components/Badge';
import { brandVariables, isHexColor } from '@/lib/theme';

/**
 * A miniature of Ava's shell (top bar, menu, a card, a button) drawn with the given primary color and mode. It is scoped to its own box through
 * `data-preview-theme` and local CSS variables: it never touches the theme of the real application, so previewing a change changes nothing.
 */
function Mini({ name, color, dark, logoUrl }) {
    const valid = isHexColor(color ?? '');

    return (
        <div
            data-preview-theme={dark ? 'dark' : 'light'}
            style={valid ? brandVariables(color, dark) : undefined}
            className="overflow-hidden rounded-xl border border-line bg-canvas text-ink shadow-card"
            role="img"
            aria-label={`Vista previa en modo ${dark ? 'oscuro' : 'claro'}`}
        >
            <div className="flex items-center gap-2 bg-primary px-3 py-2 text-white">
                {logoUrl ? (
                    <img src={logoUrl} alt="" className="size-5 rounded object-contain" />
                ) : (
                    <Building2 className="size-4" aria-hidden="true" />
                )}
                <span className="truncate text-[11px] font-bold tracking-widest uppercase">{name || 'Tu Workspace'}</span>
            </div>
            <div className="flex">
                <div className="w-14 shrink-0 space-y-2 bg-primary-strong/90 p-2" aria-hidden="true">
                    {[0, 1, 2, 3].map((item) => (
                        <div key={item} className={`h-2 rounded ${item === 0 ? 'bg-white/90' : 'bg-white/30'}`} />
                    ))}
                </div>
                <div className="min-w-0 flex-1 space-y-2 p-3">
                    <div className="rounded-lg border border-line bg-card p-2.5 shadow-card">
                        <p className="text-[11px] font-bold text-ink">Resumen</p>
                        <p className="text-[10px] text-ink-muted">Así se ve una tarjeta con tus ajustes.</p>
                        <div className="mt-2 flex flex-wrap items-center gap-2">
                            <span className="rounded-md bg-primary px-2.5 py-1 text-[10px] font-semibold tracking-wide text-white uppercase">Botón</span>
                            <Badge tone="blue">Etiqueta</Badge>
                            <span className="text-[11px] font-semibold text-primary underline">Enlace</span>
                        </div>
                    </div>
                    <div className="flex gap-2" aria-hidden="true">
                        <div className="h-8 flex-1 rounded-lg border border-line bg-card" />
                        <div className="h-8 flex-1 rounded-lg border border-line bg-card" />
                    </div>
                </div>
            </div>
        </div>
    );
}

/**
 * Preview of the appearance: «Claro» and «Oscuro» show one miniature, «Sistema» shows both (each user's device decides which they see). The inputs are the
 * saved values in the summary and the values being edited in the form.
 */
export default function AppearancePreview({ name, color, appearance, logoUrl }) {
    const modes = appearance === 'system' ? [false, true] : [appearance === 'dark'];

    return (
        <div className={`grid gap-4 ${modes.length > 1 ? 'sm:grid-cols-2' : ''}`}>
            {modes.map((dark) => (
                <figure key={String(dark)} className="space-y-1.5">
                    <Mini name={name} color={color} dark={dark} logoUrl={logoUrl} />
                    <figcaption className="text-xs text-ink-muted">{dark ? 'Modo oscuro' : 'Modo claro'}</figcaption>
                </figure>
            ))}
        </div>
    );
}
