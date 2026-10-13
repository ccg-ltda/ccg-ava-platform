import { Link, router, useForm } from '@inertiajs/react';
import { RefreshCw } from 'lucide-react';
import Alert from '@/Components/Alert';
import Card from '@/Components/Card';
import { useConfirm } from '@/Components/ConfirmDialog';
import CopyBlock from '@/Components/CopyBlock';
import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import SegmentedControl from '@/Components/SegmentedControl';
import Select from '@/Components/Select';
import TextInput from '@/Components/TextInput';
import Toggle from '@/Components/Toggle';

/** The booking rules of the Workspace: which agenda, how far ahead, how to cancel, what to ask and the open booking page. */
export default function SettingsPanel({ settings, capabilities, canManage }) {
    const confirm = useConfirm();
    const form = useForm(settings.values);
    const { data, setData, errors } = form;
    const submit = (event) => {
        event.preventDefault();
        form.put(route('bookings.settings.update'), { preserveScroll: true });
    };
    const rotate = async () => {
        const confirmed = await confirm({
            title: 'Regenerar el enlace de reservas',
            description: 'El enlace actual dejará de funcionar de inmediato (la página pública y cualquier sitio que lo use). Las reservas ya hechas conservan sus propios enlaces. Úsalo si el enlace se filtró o se está abusando de él.',
            confirmLabel: 'Regenerar enlace',
        });

        if (confirmed) router.post(route('bookings.settings.rotate-key'), {}, { preserveScroll: true });
    };
    const number = (name) => (e) => setData(name, e.target.value === '' ? '' : Number(e.target.value));
    const external = settings.providers.filter((provider) => provider.value !== 'internal');

    return (
        <form onSubmit={submit} noValidate className="space-y-6">
            <Card className="space-y-5 p-5 sm:p-6">
                <div>
                    <h2 className="text-base font-bold text-ink">Agenda</h2>
                    <p className="mt-0.5 text-sm text-ink-muted">Quién es la fuente de verdad de los horarios y las citas. Ava no mezcla las dos agendas.</p>
                </div>
                <SegmentedControl
                    label="Agenda del Workspace"
                    value={data.provider}
                    onChange={(value) => setData('provider', value)}
                    disabled={!canManage}
                    options={settings.providers.map((provider) => ({ value: provider.value, label: provider.label, description: provider.available ? undefined : 'No disponible: falta el módulo en el plan o la integración activa.' }))}
                />
                <InputError message={errors.provider} />
                {data.provider !== 'internal' && !settings.providers.find((provider) => provider.value === data.provider)?.available && (
                    <Alert tone="warning">
                        Esa agenda no está disponible todavía: el plan debe incluir Proveedores de agenda y la integración debe estar configurada y activa en <Link href={route('integrations.index')} className="font-semibold underline">Integraciones</Link>.
                    </Alert>
                )}
                {external.length === 0 && <p className="text-xs text-ink-muted">Para usar una agenda externa (Booker), el plan del Workspace debe incluir «Proveedores de agenda».</p>}
                {capabilities.external && !capabilities.book && <Alert tone="info">Con {capabilities.label}, Ava aún no puede ofrecer horarios ni reservar: se consultan servicios y profesionales y se pueden ver y cancelar citas.</Alert>}
            </Card>

            <Card className="space-y-5 p-5 sm:p-6">
                <h2 className="text-base font-bold text-ink">Reglas de reserva</h2>
                <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                    <div>
                        <InputLabel htmlFor="set_notice" value="Anticipación mínima (min)" />
                        <TextInput id="set_notice" type="number" min={0} className="mt-1" value={data.min_notice_minutes} onChange={number('min_notice_minutes')} disabled={!canManage} invalid={Boolean(errors.min_notice_minutes)} />
                        <InputError message={errors.min_notice_minutes} className="mt-1" />
                    </div>
                    <div>
                        <InputLabel htmlFor="set_advance" value="Se puede reservar hasta (días)" />
                        <TextInput id="set_advance" type="number" min={1} max={365} className="mt-1" value={data.max_advance_days} onChange={number('max_advance_days')} disabled={!canManage} invalid={Boolean(errors.max_advance_days)} />
                        <InputError message={errors.max_advance_days} className="mt-1" />
                    </div>
                    <div>
                        <InputLabel htmlFor="set_cancel" value="Cancelar o mover hasta (h antes)" />
                        <TextInput id="set_cancel" type="number" min={0} max={168} className="mt-1" value={data.cancel_notice_hours} onChange={number('cancel_notice_hours')} disabled={!canManage} invalid={Boolean(errors.cancel_notice_hours)} />
                        <InputError message={errors.cancel_notice_hours} className="mt-1" />
                    </div>
                    <div>
                        <InputLabel htmlFor="set_step" value="Intervalo de horarios (min)" />
                        <Select id="set_step" className="mt-1" value={data.slot_step_minutes} onChange={(value) => setData('slot_step_minutes', value)} options={settings.steps.map((step) => ({ value: step, label: `${step} min` }))} disabled={!canManage} />
                        <InputError message={errors.slot_step_minutes} className="mt-1" />
                    </div>
                </div>
                <div className="grid gap-4 sm:grid-cols-2">
                    <Toggle checked={data.requires_confirmation} onChange={(value) => setData('requires_confirmation', value)} label="Confirmar las reservas de clientes" description="Las que lleguen por la web o por Ava Chat quedan «Por confirmar» hasta que alguien del equipo las confirme." />
                    <div className="space-y-3">
                        <Toggle checked={data.require_phone} onChange={(value) => setData('require_phone', value)} label="Pedir teléfono" />
                        <Toggle checked={data.require_email} onChange={(value) => setData('require_email', value)} label="Pedir correo" />
                    </div>
                </div>
            </Card>

            <Card className="space-y-4 p-5 sm:p-6">
                <h2 className="text-base font-bold text-ink">Reserva en línea</h2>
                <Toggle checked={data.public_enabled} onChange={(value) => setData('public_enabled', value)} label="Activar la página pública de reservas" description="Cualquier persona con el enlace puede ver servicios y horarios y solicitar una cita. Solo se muestra lo necesario." />
                {settings.publicUrl ? (
                    <>
                        <CopyBlock value={settings.publicUrl} label="Enlace de reservas" message="Enlace copiado." />
                        {canManage && (
                            <SecondaryButton type="button" onClick={rotate} className="gap-2">
                                <RefreshCw className="size-4" aria-hidden="true" />
                                Regenerar enlace
                            </SecondaryButton>
                        )}
                    </>
                ) : (
                    <p className="text-sm text-ink-muted">Al guardar con la página activa se genera su enlace.</p>
                )}
            </Card>

            {canManage && (
                <div className="flex justify-end">
                    <PrimaryButton type="submit" disabled={form.processing}>
                        Guardar configuración
                    </PrimaryButton>
                </div>
            )}
        </form>
    );
}
