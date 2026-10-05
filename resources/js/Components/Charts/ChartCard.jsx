import { AlertTriangle, RotateCw } from 'lucide-react';
import Card from '@/Components/Card';

/**
 * Frame of every chart: title, description, an actions slot and the three states the content can be in. `loading`
 * shows a skeleton, `error` (a message) shows the failure with an optional `onRetry`; otherwise `children` is drawn.
 */
export default function ChartCard({ title, description, actions, loading = false, error = null, onRetry, height = 240, delay = 0, className = '', children }) {
    return (
        <Card delay={delay} className={`min-w-0 p-5 sm:p-6 ${className}`}>
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div className="min-w-0">
                    <h3 className="text-sm font-bold tracking-wider text-ink uppercase">{title}</h3>
                    {description && <p className="mt-1 text-sm text-ink-muted">{description}</p>}
                </div>
                {actions && <div className="flex flex-wrap items-center gap-2">{actions}</div>}
            </div>

            <div className="mt-5" aria-busy={loading || undefined}>
                {loading ? (
                    <div className="animate-pulse rounded-xl bg-canvas" style={{ height }} role="status" aria-label="Cargando gráfico" />
                ) : error ? (
                    <div className="grid place-items-center rounded-xl border border-danger/30 bg-danger/5 px-6 text-center" style={{ minHeight: height }} role="alert">
                        <div className="flex flex-col items-center gap-2">
                            <AlertTriangle className="size-6 text-danger" aria-hidden="true" />
                            <p className="text-sm font-semibold text-ink">No se pudo cargar el gráfico</p>
                            <p className="max-w-xs text-xs text-ink-muted">{error}</p>
                            {onRetry && (
                                <button type="button" onClick={onRetry} className="mt-1 inline-flex items-center gap-1.5 text-xs font-semibold tracking-wider text-primary uppercase focus-visible:outline-2 focus-visible:outline-primary">
                                    <RotateCw className="size-3.5" aria-hidden="true" />
                                    Reintentar
                                </button>
                            )}
                        </div>
                    </div>
                ) : (
                    children
                )}
            </div>
        </Card>
    );
}
