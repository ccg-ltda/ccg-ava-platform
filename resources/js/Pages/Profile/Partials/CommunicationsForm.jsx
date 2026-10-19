import { Link, useForm } from '@inertiajs/react';
import Alert from '@/Components/Alert';
import Checkbox from '@/Components/Checkbox';
import PrimaryButton from '@/Components/PrimaryButton';

const formatDate = (iso) => new Date(iso).toLocaleDateString('es', { year: 'numeric', month: 'short', day: 'numeric' });

/** The account's own choice about commercial communications (separate from the legal texts it accepted) and what it accepted. */
export default function CommunicationsForm({ marketing, accepted, available = true, className = '' }) {
    const { data, setData, put, processing } = useForm({ marketing });

    const submit = (e) => {
        e.preventDefault();
        put(route('profile.communications'), { preserveScroll: true });
    };

    return (
        <section className={className}>
            <header>
                <h2 className="text-lg font-bold text-ink">Privacidad y comunicaciones</h2>
                <p className="mt-1 text-sm text-ink-muted">
                    Los correos del servicio (por ejemplo, restablecer tu contraseña) no dependen de esta opción.
                </p>
            </header>

            {!available && (
                <Alert tone="warning" className="mt-4">
                    El registro de preferencias y aceptaciones aún no está disponible en este entorno (falta aplicar una migración). Por ahora no se puede guardar nada aquí.
                </Alert>
            )}

            <form onSubmit={submit} className="mt-6 space-y-4">
                <label className="flex items-start gap-3 text-sm text-ink">
                    <Checkbox className="mt-0.5" checked={data.marketing} disabled={!available} onChange={(e) => setData('marketing', e.target.checked)} />
                    <span>Autorizo recibir comunicaciones comerciales de CCG. Puedo retirar esta autorización cuando quiera.</span>
                </label>

                <PrimaryButton disabled={processing || !available}>Guardar preferencia</PrimaryButton>
            </form>

            <div className="mt-8">
                <h3 className="text-sm font-bold text-ink">Textos legales</h3>
                {!available ? (
                    <p className="mt-1 text-sm text-ink-muted">No disponible hasta aplicar la migración.</p>
                ) : accepted.length === 0 ? (
                    <p className="mt-1 text-sm text-ink-muted">Aún no has aceptado ningún texto en la plataforma.</p>
                ) : (
                    <ul className="mt-2 space-y-1 text-sm text-ink">
                        {accepted.map((a) => (
                            <li key={a.title + a.version}>
                                {a.title} <span className="text-ink-muted">(versión {a.version}, {formatDate(a.accepted_at)})</span>
                            </li>
                        ))}
                    </ul>
                )}
                <p className="mt-2 text-sm">
                    <Link href={route('legal.index')} className="text-primary hover:underline">
                        Ver los textos vigentes
                    </Link>
                </p>
            </div>
        </section>
    );
}
