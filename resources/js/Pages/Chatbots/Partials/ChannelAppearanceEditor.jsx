import { Link, usePage } from '@inertiajs/react';
import { useMemo } from 'react';
import Card from '@/Components/Card';
import ColorInput from '@/Components/ColorInput';
import CopyBlock from '@/Components/CopyBlock';
import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import PrimaryButton from '@/Components/PrimaryButton';
import RangeField from '@/Components/RangeField';
import SecondaryButton from '@/Components/SecondaryButton';
import SegmentedControl from '@/Components/SegmentedControl';
import Textarea from '@/Components/Textarea';
import TextInput from '@/Components/TextInput';
import Toggle from '@/Components/Toggle';
import { describeButtonSize, describeChatRadius, describeChatSize, describeShadow, describeShape, shadowNames, withHelp } from '@/config/appearance';
import { channelStates } from '@/config/chatbots';
import WidgetPreview from './WidgetPreview';

const camel = (key) => key.replace(/_([a-z])/g, (_, letter) => letter.toUpperCase());

/** The public configuration the script receives, built from the draft so the preview shows it before saving. */
export function previewConfig({ channel, draft, chatbot, mode }) {
    const style = Object.fromEntries(Object.entries({ ...draft, primary_color: draft.primary_color ?? channel.defaultPrimary }).map(([key, value]) => [camel(key), value]));

    return { type: channel.kind, name: chatbot.name, description: chatbot.description, avatarUrl: chatbot.avatarUrl, primaryColor: style.primaryColor, appearance: mode, style, href: null };
}

function Field({ id, label, error, hint, children }) {
    return (
        <div>
            <InputLabel htmlFor={id} value={label} />
            <div className="mt-2">{children}</div>
            {hint && <p className="mt-1 text-xs text-ink-muted">{hint}</p>}
            <InputError message={error} className="mt-1" />
        </div>
    );
}

/** Fields shared by every channel's button: whether it shows, its text, icon, colors, size, shape, position and shadow. */
function ButtonFields({ channel, draft, set, errors, options, disabled, hasAvatar }) {
    const customText = draft.text_color !== null;

    return (
        <div className="grid gap-5">
            <Toggle checked={draft.enabled} onChange={(value) => set('enabled', value)} label="Mostrar en la página" description="Si lo apagas, el botón deja de aparecer en tu sitio sin quitar el código." />

            <Field id={`${channel.key}_button_text`} label="Texto del botón" error={errors.button_text} hint={`Opcional. Si lo dejas vacío, el botón muestra solo el icono. Máximo ${options.maxLength.buttonText} caracteres.`}>
                <TextInput id={`${channel.key}_button_text`} value={draft.button_text ?? ''} onChange={(e) => set('button_text', e.target.value)} maxLength={options.maxLength.buttonText} placeholder="¿Necesitas ayuda?" disabled={disabled} />
            </Field>

            <div>
                <SegmentedControl label="Icono" options={withHelp('icon', options.icons)} value={draft.icon} onChange={(value) => set('icon', value)} disabled={disabled} hint={draft.icon === 'avatar' && !hasAvatar ? 'Este chatbot todavía no tiene avatar: se usará el icono del canal.' : undefined} />
                <InputError message={errors.icon} className="mt-1" />
            </div>

            <div className="grid gap-5 2xl:grid-cols-2">
                <ColorInput id={`${channel.key}_primary_color`} label="Color principal" value={draft.primary_color ?? channel.defaultPrimary} onChange={(value) => set('primary_color', value)} error={errors.primary_color} disabled={disabled} />

                <div>
                    {customText ? (
                        <ColorInput id={`${channel.key}_text_color`} label="Color del texto" value={draft.text_color} onChange={(value) => set('text_color', value)} error={errors.text_color} disabled={disabled} />
                    ) : (
                        <div>
                            <InputLabel value="Color del texto" />
                            <p className="mt-2 flex h-10 items-center text-sm text-ink-muted">Automático (claro u oscuro según el botón)</p>
                        </div>
                    )}
                    <button type="button" disabled={disabled} onClick={() => set('text_color', customText ? null : '#ffffff')} className="mt-1 text-sm font-semibold text-primary underline-offset-2 hover:underline disabled:opacity-50">
                        {customText ? 'Usar color automático' : 'Elegir un color'}
                    </button>
                </div>
            </div>

            <div className="grid gap-5 2xl:grid-cols-2">
                <RangeField label="Tamaño" unit="px" {...options.ranges.size} value={draft.size} onChange={(value) => set('size', value)} describe={describeButtonSize} error={errors.size} disabled={disabled} />
                <RangeField label="Forma" format={(value) => (value >= 50 ? 'Redonda' : value === 0 ? 'Cuadrada' : `Redondeada ${value} %`)} {...options.ranges.shape} value={draft.shape} onChange={(value) => set('shape', value)} describe={describeShape} error={errors.shape} disabled={disabled} />
                <SegmentedControl label="Posición" options={withHelp('position', options.positions)} value={draft.position} onChange={(value) => set('position', value)} disabled={disabled} />
                <RangeField label="Sombra" format={(value) => shadowNames[value]} {...options.ranges.shadow} value={draft.shadow} onChange={(value) => set('shadow', value)} describe={describeShadow} error={errors.shadow} disabled={disabled} />
            </div>
        </div>
    );
}

/** Fields of the chat panel that opens from the web button. */
function ChatFields({ channel, draft, set, errors, options, disabled }) {
    return (
        <div className="grid gap-5">
            <Field id={`${channel.key}_header_title`} label="Título del encabezado" error={errors.header_title} hint="Si lo dejas vacío se usa el nombre del chatbot.">
                <TextInput id={`${channel.key}_header_title`} value={draft.header_title ?? ''} onChange={(e) => set('header_title', e.target.value)} maxLength={options.maxLength.headerTitle} disabled={disabled} />
            </Field>

            <Field id={`${channel.key}_welcome_message`} label="Mensaje inicial" error={errors.welcome_message} hint="Aparece al abrir el chat, antes de que el visitante escriba.">
                <Textarea id={`${channel.key}_welcome_message`} rows={2} value={draft.welcome_message ?? ''} onChange={(e) => set('welcome_message', e.target.value)} maxLength={options.maxLength.welcomeMessage} placeholder="¡Hola! ¿En qué puedo ayudarte?" disabled={disabled} />
            </Field>

            <div className="grid gap-5 2xl:grid-cols-2">
                <RangeField label="Tamaño del chat" unit="px" {...options.ranges.widgetSize} value={draft.widget_size} onChange={(value) => set('widget_size', value)} describe={describeChatSize} error={errors.widget_size} disabled={disabled} />
                <SegmentedControl label="Apertura" options={withHelp('open_behavior', options.openBehaviors)} value={draft.open_behavior} onChange={(value) => set('open_behavior', value)} disabled={disabled} />
            </div>

            <RangeField label="Redondeo de las esquinas del chat" unit="px" {...options.ranges.radius} value={draft.radius} onChange={(value) => set('radius', value)} describe={describeChatRadius} error={errors.radius} disabled={disabled} />
        </div>
    );
}

/** Field only the WhatsApp button has: the message the visitor's chat starts with. */
function WhatsAppFields({ channel, draft, set, errors, options, disabled }) {
    return (
        <div className="mt-5 grid gap-5 border-t border-line pt-5">
            <Field id={`${channel.key}_message`} label="Mensaje inicial" error={errors.message} hint="Texto que aparecerá escrito cuando el visitante abra la conversación de WhatsApp. Es opcional.">
                <Textarea id={`${channel.key}_message`} rows={2} value={draft.message ?? ''} onChange={(e) => set('message', e.target.value)} maxLength={options.maxLength.message} placeholder="Hola, quisiera más información" disabled={disabled} />
            </Field>
        </div>
    );
}

/** What a WhatsApp button points at, or why it cannot work yet. Nothing for channels without an external destination. */
export function DestinationNotice({ channel, canConfigure }) {
    if (channel.kind !== 'button') return null;

    if (!channel.destination.ready) {
        return (
            <div role="alert" className="rounded-lg border border-accent-amber/30 bg-accent-amber/10 p-4 text-sm text-ink">
                <p className="font-semibold">{channel.destination.message}</p>
                {canConfigure && <Link href={route('integrations.index')} className="mt-1 inline-block font-semibold text-primary underline-offset-2 hover:underline">Ir a Integraciones</Link>}
            </div>
        );
    }

    return (
        <p className="rounded-lg border border-line bg-canvas p-3 text-sm text-ink-muted">
            El botón abrirá WhatsApp con el número verificado <strong className="text-ink">+{channel.destination.number}</strong>.
        </p>
    );
}

/** Why the install code is not available yet, and what the user can do about it. */
function InstallBlocked({ destination, state, canConfigure, onChannels }) {
    const hint = !destination.ready ? destination.message : state.hint ?? 'Activa el canal en la pestaña Canales para obtener el código.';

    return (
        <div className="rounded-lg border border-accent-amber/30 bg-accent-amber/10 p-3 text-sm text-ink">
            <p>{hint}</p>
            <div className="mt-2 flex flex-wrap gap-3 font-semibold text-primary">
                {canConfigure && (!destination.ready || ['not_configured', 'integration_inactive'].includes(state.key)) && (
                    <Link href={route('integrations.index')} className="underline-offset-2 hover:underline">Ir a Integraciones</Link>
                )}
                {destination.ready && state.key === 'inactive' && (
                    <button type="button" onClick={onChannels} className="underline-offset-2 hover:underline">Ir a Canales</button>
                )}
            </div>
        </div>
    );
}

export function InstallSection({ channel, runtime, canConfigure, onChannels }) {
    const noun = channel.kind === 'widget' ? 'el chat' : 'el botón de WhatsApp';

    return (
        <Card className="p-5">
            <h3 className="text-base font-bold text-ink">Instalar en tu página</h3>
            <p className="mt-1 text-sm text-ink-muted">
                Copia este código y pégalo antes de cerrar la etiqueta <code className="rounded bg-canvas px-1 font-mono text-xs">&lt;/body&gt;</code> de tu página. Es siempre el mismo: cuando cambies la apariencia aquí y guardes, {noun} se actualiza solo.
            </p>

            <div className="mt-4">
                {runtime.installCode ? (
                    <CopyBlock value={runtime.installCode} label="Código de instalación" message="Código copiado." stacked />
                ) : (
                    <InstallBlocked destination={channel.destination} state={{ key: runtime.state, ...channelStates[runtime.state] }} canConfigure={canConfigure} onChannels={onChannels} />
                )}
            </div>
        </Card>
    );
}

/**
 * Appearance of ONE channel: form on the left, live preview and install code on the right. The draft lives in the parent
 * so switching channels does not lose edits; saving sends the whole draft of this channel (validated again by the server).
 */
export default function ChannelAppearanceEditor({ channel, runtime, draft, onChange, dirty, status, onSave, onCancel, chatbot, options, mode, scriptUrl, canManage, canConfigure, onChannels }) {
    const { errors } = usePage().props;
    const set = (key, value) => onChange({ ...draft, [key]: value });
    const disabled = !canManage;
    const dark = mode === 'dark' || (mode === 'system' && Boolean(window.matchMedia?.('(prefers-color-scheme: dark)').matches));
    const config = useMemo(() => previewConfig({ channel, draft, chatbot, mode }), [channel, draft, chatbot, mode]);

    const message = status === 'saving' ? 'Guardando…' : dirty ? 'Cambios sin guardar' : status === 'saved' ? 'Guardado correctamente' : '';

    return (
        <div className="grid gap-6 xl:grid-cols-[minmax(0,1fr)_23rem]">
            <div className="order-2 space-y-6 xl:order-1">
                <DestinationNotice channel={channel} canConfigure={canConfigure} />

                <Card className="p-5">
                    <h3 className="text-base font-bold text-ink">{channel.kind === 'widget' ? 'Botón del chat' : 'Botón de WhatsApp'}</h3>
                    <p className="mb-5 mt-1 text-sm text-ink-muted">Cómo se ve el botón flotante en tu página.</p>
                    <ButtonFields channel={channel} draft={draft} set={set} errors={errors} options={options} disabled={disabled} hasAvatar={Boolean(chatbot.avatarUrl)} />
                    {channel.kind === 'button' && <WhatsAppFields channel={channel} draft={draft} set={set} errors={errors} options={options} disabled={disabled} />}
                </Card>

                {channel.kind === 'widget' && (
                    <Card className="p-5">
                        <h3 className="text-base font-bold text-ink">Ventana del chat</h3>
                        <p className="mb-5 mt-1 text-sm text-ink-muted">Cómo se ve la ventana que se abre al pulsar el botón.</p>
                        <ChatFields channel={channel} draft={draft} set={set} errors={errors} options={options} disabled={disabled} />
                    </Card>
                )}

                {canManage && (
                    <div className="sticky bottom-0 z-10 flex flex-wrap items-center justify-end gap-3 rounded-card border border-line bg-card p-3 shadow-card">
                        <p role="status" className={`mr-auto text-sm font-semibold ${dirty || status === 'saving' ? 'text-accent-amber' : 'text-accent-green'}`}>
                            {message}
                        </p>
                        {onCancel && (
                            <SecondaryButton type="button" onClick={onCancel} disabled={status === 'saving'}>
                                Cancelar
                            </SecondaryButton>
                        )}
                        <PrimaryButton type="button" onClick={onSave} disabled={!dirty || status === 'saving'}>
                            Guardar cambios
                        </PrimaryButton>
                    </div>
                )}
            </div>

            <div className="order-1 space-y-6 xl:order-2">
                <div className="space-y-6 xl:sticky xl:top-4">
                    <Card className="p-4">
                        <div className="mb-3 flex items-center justify-between gap-2">
                            <h3 className="text-base font-bold text-ink">Vista previa</h3>
                            <span className="text-xs text-ink-muted">Se actualiza al instante</span>
                        </div>
                        <WidgetPreview scriptUrl={scriptUrl} config={config} startOpen={channel.kind === 'widget'} dark={dark} />
                    </Card>
                    <InstallSection channel={channel} runtime={runtime} canConfigure={canConfigure} onChannels={onChannels} />
                </div>
            </div>
        </div>
    );
}
