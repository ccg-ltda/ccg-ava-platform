import { router, useForm } from '@inertiajs/react';
import { Check, Copy, Eye, RotateCcw } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import Alert from '@/Components/Alert';
import Badge from '@/Components/Badge';
import Card from '@/Components/Card';
import { useConfirm } from '@/Components/ConfirmDialog';
import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import Textarea from '@/Components/Textarea';

/** Copies the variable's name (written with its braces) so it can be pasted into the HTML. Falls back to a hidden textarea where the clipboard API is not available. */
function CopyToken({ token }) {
    const [copied, setCopied] = useState(false);

    const copy = async () => {
        try {
            await navigator.clipboard.writeText(token);
        } catch {
            const area = document.createElement('textarea');

            area.value = token;
            document.body.appendChild(area);
            area.select();
            document.execCommand('copy');
            area.remove();
        }

        setCopied(true);
        setTimeout(() => setCopied(false), 1500);
    };

    return (
        <button type="button" onClick={copy} className="inline-flex items-center gap-1.5 rounded-md border border-line bg-card px-2 py-1 text-xs font-semibold text-ink transition-colors hover:bg-primary-soft focus-visible:outline-2 focus-visible:outline-primary" aria-label={`Copiar ${token}`}>
            {copied ? <Check className="size-3.5 text-accent-green" aria-hidden="true" /> : <Copy className="size-3.5" aria-hidden="true" />}
            {copied ? 'Copiado' : 'Copiar'}
        </button>
    );
}

async function postJson(url, body) {
    const token = document.querySelector('meta[name="csrf-token"]')?.content;
    const response = await fetch(url, { method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': token ?? '' }, body: JSON.stringify(body) });

    return { ok: response.ok, status: response.status, body: await response.json().catch(() => ({})) };
}

/**
 * «Plantilla HTML para correos»: the ONE presentation shared by the platform's emails. Only the platform administration in Desarrollo_DEV sees it (the server
 * sends its data and accepts its changes only to them). The HTML is text with a few placeholders: it is validated by the server, never executed, and the preview
 * is drawn inside a sandboxed frame with fictitious data (nothing is sent).
 */
export default function EmailTemplatePanel({ template }) {
    const confirm = useConfirm();
    const form = useForm({ html: template.html });
    const [preview, setPreview] = useState({ html: null, errors: [], loading: false });
    const timer = useRef(null);
    const dirty = form.data.html !== template.html;
    const canEdit = template.canEdit && template.ready;

    // What the server holds (after saving or restoring) is what the editor shows.
    useEffect(() => {
        form.setDefaults({ html: template.html });
        form.setData('html', template.html);
    }, [template.html]);

    // Live preview (debounced): the server validates and renders it with fictitious data.
    useEffect(() => {
        clearTimeout(timer.current);
        timer.current = setTimeout(async () => {
            setPreview((current) => ({ ...current, loading: true }));
            const result = await postJson(route('email-template.preview'), { html: form.data.html });
            const errors = result.body.errors && !Array.isArray(result.body.errors) ? Object.values(result.body.errors).flat() : (result.body.errors ?? []);

            setPreview({ html: result.body.html ?? null, errors, loading: false });
        }, 500);

        return () => clearTimeout(timer.current);
    }, [form.data.html]);

    const save = (event) => {
        event.preventDefault();
        form.put(route('email-template.update'), { preserveScroll: true });
    };

    const restore = async () => {
        const confirmed = await confirm({
            title: 'Restaurar la plantilla predeterminada',
            description: 'Se sustituye la plantilla global por la que mantiene la aplicación. Tus cambios en esta plantilla se pierden y todos los correos de la plataforma la usarán. No se borra ningún correo ya enviado.',
            confirmLabel: 'Restaurar',
        });

        if (confirmed) router.post(route('email-template.restore'), {}, { preserveScroll: true });
    };

    const serverErrors = [].concat(form.errors.html ?? []);

    return (
        <div className="space-y-6">
            <Alert tone="warning">
                Esta plantilla es <strong>global</strong>: la usan los correos de la plataforma (código de verificación, recuperación de contraseña y recordatorios del catálogo). Un cambio los afecta a todos, de todos los clientes.
            </Alert>
            {template.storedInvalid && <Alert tone="error">La plantilla guardada no es válida, así que los correos usan la predeterminada. Corrígela y guárdala, o restaura la predeterminada.</Alert>}
            {!template.ready && <Alert tone="warning">La plantilla editable todavía no está disponible en esta base de datos (falta aplicar la migración pendiente): se usa la predeterminada.</Alert>}

            <div className="grid gap-6 xl:grid-cols-2">
                <Card className="p-4 sm:p-6">
                    <div className="flex flex-wrap items-center justify-between gap-2">
                        <h2 className="text-base font-bold text-ink">Plantilla HTML para correos</h2>
                        <Badge tone={template.isDefault ? 'neutral' : 'violet'}>{template.isDefault ? 'Predeterminada' : 'Personalizada'}</Badge>
                    </div>
                    <p className="mt-1 text-xs text-ink-muted">
                        {template.updatedAt ? `Última edición: ${new Date(template.updatedAt).toLocaleString('es')}${template.updatedBy ? ` por ${template.updatedBy}` : ''}.` : 'Todavía no se ha personalizado.'}
                    </p>

                    <form onSubmit={save} className="mt-4 space-y-3">
                        <InputLabel htmlFor="email_html" value="HTML" />
                        <Textarea id="email_html" rows={20} spellCheck={false} className="font-mono text-xs leading-5" value={form.data.html} onChange={(e) => form.setData('html', e.target.value)} readOnly={!canEdit} invalid={serverErrors.length > 0} aria-describedby="email_html_errors" />
                        <p className="text-xs text-ink-muted">{form.data.html.length.toLocaleString('es')} / {template.maxBytes.toLocaleString('es')} caracteres.</p>

                        <div id="email_html_errors" aria-live="polite">
                            {serverErrors.map((message) => <InputError key={message} message={message} className="mt-1" />)}
                        </div>

                        {canEdit ? (
                            <div className="flex flex-wrap gap-2">
                                <PrimaryButton type="submit" disabled={form.processing || !dirty || preview.errors.length > 0}>Guardar plantilla</PrimaryButton>
                                <SecondaryButton type="button" onClick={restore} className="gap-2" disabled={form.processing}>
                                    <RotateCcw className="size-4" aria-hidden="true" />
                                    Restaurar la predeterminada
                                </SecondaryButton>
                            </div>
                        ) : (
                            <p className="text-sm text-ink-muted">Solo puedes consultarla: editarla necesita el permiso de edición de la plantilla.</p>
                        )}
                    </form>
                </Card>

                <div className="space-y-6">
                    <Card className="p-4 sm:p-6">
                        <h2 className="flex items-center gap-2 text-base font-bold text-ink">
                            <Eye className="size-4" aria-hidden="true" />
                            Vista previa
                        </h2>
                        <p className="mt-1 text-xs text-ink-muted">Con datos ficticios. No se envía ningún correo.</p>
                        {preview.errors.length > 0 ? (
                            <ul className="mt-3 list-disc space-y-1 pl-5 text-sm text-danger" role="alert">
                                {preview.errors.map((message) => <li key={message}>{message}</li>)}
                            </ul>
                        ) : (
                            <iframe title="Vista previa del correo" sandbox="" srcDoc={preview.html ?? ''} className={`mt-3 h-[28rem] w-full rounded-lg border border-line bg-white ${preview.loading ? 'opacity-60' : ''}`} />
                        )}
                    </Card>

                    <Card className="p-4 sm:p-6">
                        <h2 className="text-base font-bold text-ink">Marcadores disponibles</h2>
                        <p className="mt-1 text-xs text-ink-muted">Escríbelos con llaves dobles, solo en el texto del documento (no dentro de atributos). Cualquier otro marcador se rechaza al guardar y nada de la plantilla se ejecuta.</p>
                        <div className="mt-4 space-y-5">
                            {template.variables.map((group) => (
                                <section key={group.key} aria-label={group.label}>
                                    <h3 className="text-xs font-bold tracking-wide text-ink-muted uppercase">{group.label}</h3>
                                    <ul className="mt-2 divide-y divide-line">
                                        {group.variables.map((variable) => (
                                            <li key={variable.name} className="space-y-1.5 py-3 text-sm">
                                                <div className="flex flex-wrap items-center justify-between gap-2">
                                                    <code className="rounded bg-canvas px-1.5 py-0.5 text-xs">{variable.token}</code>
                                                    <div className="flex items-center gap-2">
                                                        {variable.required && <Badge tone="amber">Obligatoria</Badge>}
                                                        <CopyToken token={variable.token} />
                                                    </div>
                                                </div>
                                                <p className="text-ink-muted">{variable.description}</p>
                                                <p className="text-xs text-ink-muted">
                                                    <span className="font-semibold text-ink">Ejemplo:</span> {variable.example}
                                                </p>
                                                <p className="text-xs text-ink-muted">
                                                    <span className="font-semibold text-ink">Uso:</span> <code className="break-all">{variable.usage}</code>
                                                </p>
                                                <p className="text-xs text-ink-muted">{variable.availability}</p>
                                            </li>
                                        ))}
                                    </ul>
                                </section>
                            ))}
                        </div>
                        <details className="mt-3 rounded-lg border border-line px-3 py-2 text-xs text-ink-muted">
                            <summary className="cursor-pointer text-sm font-semibold text-ink">Correos que usan esta plantilla</summary>
                            <ul className="mt-2 list-disc space-y-0.5 pl-5">
                                {template.messages.map((label) => <li key={label}>{label}</li>)}
                            </ul>
                            <p className="mt-2">Las variables son las mismas para todos: lo que cambia de un correo a otro (el código, el enlace, el texto del recordatorio) va dentro de <code>{'{{content}}'}</code> y <code>{'{{action}}'}</code>, no hay variables propias de cada tipo.</p>
                        </details>
                        <p className="mt-3 text-xs text-ink-muted">
                            Se admite HTML de maquetación (tablas, divisiones, texto, enlaces http, https o mailto) con estilos en línea. No se admiten scripts, imágenes, formularios, hojas de estilo ni comentarios. Marca visible: <strong>{template.brand}</strong>.
                        </p>
                    </Card>
                </div>
            </div>
        </div>
    );
}
