import { usePage } from '@inertiajs/react';
import { CircleHelp, FileText, LifeBuoy, Mail, MessageCircle, TriangleAlert } from 'lucide-react';
import Modal from '@/Components/Modal';
import SecondaryButton from '@/Components/SecondaryButton';
import { openSupport } from '@/Components/SupportDesk';


/** One question: collapsible, with what to try in order and what NOT to do. */
function Question({ item }) {
    return (
        <details className="group rounded-lg border border-line px-4 py-3">
            <summary className="cursor-pointer list-none text-sm font-semibold text-ink marker:hidden group-open:text-primary">{item.q}</summary>
            <div className="mt-2 space-y-3 text-sm text-ink-muted">
                <p>{item.a}</p>
                {item.steps?.length > 0 && (
                    <div>
                        <p className="font-semibold text-ink">Qué puedes intentar</p>
                        <ol className="mt-1 list-decimal space-y-1 ps-5">
                            {item.steps.map((step) => (
                                <li key={step}>{step}</li>
                            ))}
                        </ol>
                    </div>
                )}
                {item.avoid?.length > 0 && (
                    <div className="rounded-lg border border-accent-amber/30 bg-accent-amber/10 p-3">
                        <p className="flex items-center gap-1.5 font-semibold text-ink">
                            <TriangleAlert className="size-4 text-accent-amber" aria-hidden="true" />
                            Evita
                        </p>
                        <ul className="mt-1 list-disc space-y-1 ps-5">
                            {item.avoid.map((warning) => (
                                <li key={warning}>{warning}</li>
                            ))}
                        </ul>
                    </div>
                )}
            </div>
        </details>
    );
}

/**
 * The «Ayuda» panel of the top bar: frequently asked questions, the PDF manuals this Workspace may open and the support channels that really exist (the e-mail
 * only if configured, the chat only if its channel is live). All content comes from the server (config/help.php); nothing is made up here.
 */
export default function HelpPanel({ show, onClose }) {
    const { help } = usePage().props;

    if (!help) return null;

    // The chat is the support panel of the application itself (SupportDesk): it opens in place and writes into Conversaciones of CCG's administrative Workspace.
    const chat = () => {
        onClose();
        openSupport();
    };

    return (
        <Modal show={show} onClose={onClose} maxWidth="2xl" scrollable>
            <div className="flex min-h-0 flex-1 flex-col">
                <header className="flex items-center gap-3 border-b border-line px-5 py-4 sm:px-8">
                    <span className="grid size-10 shrink-0 place-items-center rounded-xl bg-primary/15 text-accent-blue">
                        <LifeBuoy className="size-5" aria-hidden="true" />
                    </span>
                    <div className="min-w-0">
                        <h2 className="text-lg font-bold text-ink">Ayuda</h2>
                        <p className="text-sm text-ink-muted">Respuestas rápidas, manuales y cómo contactar a soporte.</p>
                    </div>
                </header>

                <div className="min-h-0 flex-1 space-y-6 overflow-y-auto overscroll-contain px-5 py-6 sm:px-8">
                    <section aria-label="Canales de soporte" className="grid gap-3 sm:grid-cols-2">
                        <div className="rounded-lg border border-line p-4">
                            <p className="flex items-center gap-2 text-sm font-semibold text-ink">
                                <MessageCircle className="size-4 text-accent-blue" aria-hidden="true" />
                                Chat de soporte
                            </p>
                            {help.chat ? (
                                <>
                                    <p className="mt-1 text-xs text-ink-muted">Escribe aquí mismo y el equipo de CCG te responde en esta conversación.</p>
                                    <SecondaryButton type="button" onClick={chat} className="mt-3">
                                        Abrir el chat
                                    </SecondaryButton>
                                </>
                            ) : (
                                <p role="status" className="mt-1 text-xs text-ink-muted">
                                    {{
                                        internal: 'Este es el Workspace de CCG: las solicitudes de soporte de los clientes llegan a Conversaciones (insignia «Soporte»). Para probar el chat, entra a un Workspace cliente como administrador.',
                                        restricted: 'El chat de soporte es solo para los administradores del Workspace. Pídele a uno de ellos que escriba, o usa el correo de soporte.',
                                    }[help.chatState] ?? 'Chat no disponible en este momento. Mientras tanto, usa el correo de soporte.'}
                                </p>
                            )}
                        </div>
                        <div className="rounded-lg border border-line p-4">
                            <p className="flex items-center gap-2 text-sm font-semibold text-ink">
                                <Mail className="size-4 text-accent-blue" aria-hidden="true" />
                                Correo de soporte
                            </p>
                            {help.supportEmail && help.mailto ? (
                                <>
                                    <p className="mt-1 text-xs break-all text-ink-muted">{help.supportEmail}</p>
                                    <a href={help.mailto} className="mt-3 inline-flex items-center gap-2 rounded-lg border border-line px-4 py-2 text-sm font-semibold text-ink transition-colors hover:bg-canvas focus-visible:outline-2 focus-visible:outline-primary">
                                        <Mail className="size-4" aria-hidden="true" />
                                        Escribir a soporte
                                    </a>
                                    <p className="mt-2 text-xs text-ink-muted">Se abre tu aplicación de correo con el mensaje listo para completar y enviar desde tu cuenta; Zonno no lo envía. Si no se abre nada, copia la dirección y escribe desde tu correo.</p>
                                </>
                            ) : (
                                <p role="status" className="mt-1 text-xs text-ink-muted">Correo no disponible: CCG todavía no configuró la dirección de soporte. Mientras tanto, usa el chat si está disponible.</p>
                            )}
                        </div>
                    </section>

                    <section aria-label="Manuales" className="space-y-2">
                        <h3 className="text-sm font-bold text-ink">Manuales</h3>
                        {help.manuals.length === 0 ? (
                            <p className="text-sm text-ink-muted">Todavía no hay manuales publicados para tu Workspace.</p>
                        ) : (
                            <ul className="divide-y divide-line rounded-lg border border-line">
                                {help.manuals.map((manual) => (
                                    <li key={manual.key}>
                                        <a href={manual.url} target="_blank" rel="noopener noreferrer" className="flex items-start gap-3 px-4 py-3 text-sm hover:bg-canvas focus-visible:outline-2 focus-visible:outline-primary">
                                            <FileText className="mt-0.5 size-4 shrink-0 text-accent-blue" aria-hidden="true" />
                                            <span className="min-w-0">
                                                <span className="block font-semibold text-ink">
                                                    {manual.title}
                                                    {manual.version && <span className="ms-2 text-xs font-normal text-ink-muted">v{manual.version}</span>}
                                                </span>
                                                {manual.description && <span className="block text-xs text-ink-muted">{manual.description}</span>}
                                            </span>
                                        </a>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </section>

                    <section aria-label="Preguntas frecuentes" className="space-y-4">
                        <h3 className="flex items-center gap-2 text-sm font-bold text-ink">
                            <CircleHelp className="size-4 text-accent-blue" aria-hidden="true" />
                            Preguntas frecuentes
                        </h3>
                        {help.faq.map((group) => (
                            <div key={group.group} className="space-y-2">
                                <p className="text-xs font-semibold tracking-wider text-ink-muted uppercase">{group.group}</p>
                                {group.items.map((item) => (
                                    <Question key={item.q} item={item} />
                                ))}
                            </div>
                        ))}
                    </section>
                </div>

                <div className="flex justify-end border-t border-line bg-card px-5 py-4 sm:px-8">
                    <SecondaryButton type="button" onClick={onClose}>
                        Cerrar
                    </SecondaryButton>
                </div>
            </div>
        </Modal>
    );
}
