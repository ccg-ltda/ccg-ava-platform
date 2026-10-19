import { Link, useForm } from '@inertiajs/react';
import { Pencil } from 'lucide-react';
import Alert from '@/Components/Alert';
import Badge from '@/Components/Badge';
import Card from '@/Components/Card';
import Checkbox from '@/Components/Checkbox';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';

const formatDate = (iso) => new Date(iso).toLocaleDateString('es', { year: 'numeric', month: 'short', day: 'numeric' });

/**
 * Privacy and communications: whether commercial communications are authorized (an optional choice, separate from the legal texts the account accepted),
 * and which version of each text it accepted and when. «Editar» opens only the choice. Without the record's tables (migration not applied) it says so and
 * saves nothing; the texts may still be drafts, and the page says that instead of presenting them as approved.
 */
export default function PrivacySection({ marketing, accepted, available, reviewed, editing, onEdit, onClose, className = '' }) {
    const { data, setData, put, processing, reset } = useForm({ marketing });

    const startEditing = () => {
        setData('marketing', marketing);
        onEdit();
    };

    const submit = (e) => {
        e.preventDefault();
        put(route('profile.communications'), { preserveScroll: true, onSuccess: onClose });
    };

    const cancel = () => {
        reset();
        onClose();
    };

    return (
        <Card className={`overflow-hidden ${className}`}>
            <div className="flex items-start justify-between gap-3 p-4 sm:p-6">
                <div>
                    <h2 className="text-lg font-bold text-ink">Privacidad y comunicaciones</h2>
                    <p className="mt-1 text-sm text-ink-muted">Los correos del servicio (por ejemplo, restablecer tu contraseña) no dependen de esta opción.</p>
                </div>
                {!editing && available && (
                    <SecondaryButton type="button" onClick={startEditing} className="gap-2">
                        <Pencil className="size-4" aria-hidden="true" />
                        Editar
                    </SecondaryButton>
                )}
            </div>

            <div className="space-y-5 border-t border-line p-4 sm:p-6">
                {!available && (
                    <Alert tone="warning">El registro de preferencias y aceptaciones aún no está disponible en este entorno (falta aplicar una migración). Por ahora no se puede guardar nada aquí.</Alert>
                )}
                {!reviewed && <Alert tone="info">Los textos legales de la plataforma son borradores pendientes de revisión jurídica.</Alert>}

                {editing ? (
                    <form onSubmit={submit} className="space-y-4" aria-label="Editar tus preferencias de comunicación">
                        <label className="flex items-start gap-3 text-sm text-ink">
                            <Checkbox className="mt-0.5" checked={data.marketing} onChange={(e) => setData('marketing', e.target.checked)} />
                            <span>Autorizo recibir comunicaciones comerciales de CCG. Puedo retirar esta autorización cuando quiera.</span>
                        </label>
                        <div className="flex justify-end gap-3">
                            <SecondaryButton type="button" onClick={cancel} disabled={processing}>
                                Cancelar
                            </SecondaryButton>
                            <PrimaryButton type="submit" disabled={processing}>
                                {processing ? 'Guardando...' : 'Guardar preferencia'}
                            </PrimaryButton>
                        </div>
                    </form>
                ) : (
                    <div className="grid gap-5 lg:grid-cols-2">
                        <div className="rounded-lg border border-line bg-canvas/50 px-4 py-3">
                            <p className="text-[11px] font-semibold tracking-wider text-ink-muted uppercase">Comunicaciones comerciales</p>
                            <p className="mt-1 flex flex-wrap items-center gap-2 text-sm font-medium text-ink">
                                {available ? <Badge tone={marketing ? 'blue' : 'neutral'}>{marketing ? 'Autorizadas' : 'No autorizadas'}</Badge> : <span className="font-normal text-ink-muted">No disponible</span>}
                            </p>
                        </div>
                        <div className="rounded-lg border border-line bg-canvas/50 px-4 py-3">
                            <p className="text-[11px] font-semibold tracking-wider text-ink-muted uppercase">Textos legales aceptados</p>
                            {!available ? (
                                <p className="mt-1 text-sm text-ink-muted">No disponible hasta aplicar la migración.</p>
                            ) : accepted.length === 0 ? (
                                <p className="mt-1 text-sm text-ink-muted">Aún no has aceptado ningún texto en la plataforma.</p>
                            ) : (
                                <ul className="mt-1 space-y-1 text-sm text-ink">
                                    {accepted.map((item) => (
                                        <li key={item.title + item.version}>
                                            {item.title} <span className="text-ink-muted">(versión {item.version}, {formatDate(item.accepted_at)})</span>
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </div>
                    </div>
                )}

                <p className="text-sm">
                    <Link href={route('legal.index')} className="text-primary hover:underline">
                        Ver los textos vigentes
                    </Link>
                </p>
            </div>
        </Card>
    );
}
