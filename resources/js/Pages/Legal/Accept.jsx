import { Head, useForm } from '@inertiajs/react';
import Checkbox from '@/Components/Checkbox';
import InputError from '@/Components/InputError';
import PrimaryButton from '@/Components/PrimaryButton';
import GuestLayout from '@/Layouts/GuestLayout';
import { DraftNotice } from './Show';

/**
 * Shown only when acceptance is required and the account has not accepted the current version of a required text.
 * Commercial communications are a separate, optional choice that starts unchecked and does not condition access.
 */
export default function Accept({ pending, draft, marketing }) {
    const { data, setData, post, processing, errors } = useForm({
        accept: [],
        marketing,
    });

    const toggle = (key, checked) => setData('accept', checked ? [...data.accept, key] : data.accept.filter((k) => k !== key));

    const submit = (e) => {
        e.preventDefault();
        post(route('legal.accept.store'));
    };

    return (
        <GuestLayout title="Aceptación de textos legales" wide>
            <Head title="Aceptación de textos legales" />

            {draft && <DraftNotice />}

            <p className="mb-4 text-sm text-ink-muted">
                Para seguir usando la plataforma debes leer y aceptar la versión vigente de estos documentos. Se guarda qué versión aceptaste y cuándo.
            </p>

            <form onSubmit={submit} className="space-y-4">
                <fieldset className="space-y-3">
                    <legend className="sr-only">Documentos necesarios para usar el servicio</legend>
                    {pending.map((doc) => (
                        <label key={doc.key} className="flex items-start gap-3 text-sm text-ink">
                            <Checkbox
                                className="mt-0.5"
                                checked={data.accept.includes(doc.key)}
                                onChange={(e) => toggle(doc.key, e.target.checked)}
                            />
                            <span>
                                He leído y acepto{' '}
                                <a href={route('legal.show', { slug: doc.slug })} target="_blank" rel="noreferrer" className="font-semibold text-primary hover:underline">
                                    {doc.title}
                                </a>{' '}
                                <span className="text-xs text-ink-muted">(versión {doc.version})</span>
                            </span>
                        </label>
                    ))}
                    <InputError message={errors.accept} />
                </fieldset>

                <div className="border-t border-line pt-4">
                    <label className="flex items-start gap-3 text-sm text-ink">
                        <Checkbox className="mt-0.5" checked={data.marketing} onChange={(e) => setData('marketing', e.target.checked)} />
                        <span>
                            Opcional: autorizo recibir comunicaciones comerciales de CCG. Puedo retirar esta autorización cuando quiera desde mi perfil;
                            no es necesaria para usar la plataforma.
                        </span>
                    </label>
                </div>

                <div className="flex justify-end">
                    <PrimaryButton disabled={processing}>Aceptar y continuar</PrimaryButton>
                </div>
            </form>
        </GuestLayout>
    );
}
