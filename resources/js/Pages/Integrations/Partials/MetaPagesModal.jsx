import { router } from '@inertiajs/react';
import { useState } from 'react';
import Modal from '@/Components/Modal';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';

/**
 * Messenger: the Facebook Pages the person authorized in Meta. They choose ONE; the server connects it with the Page's own token (which never reaches the browser) and subscribes it to
 * the webhook. Closing the dialog forgets the authorization.
 */
export default function MetaPagesModal({ pages }) {
    const [pageId, setPageId] = useState(pages.length === 1 ? pages[0].id : '');
    const [busy, setBusy] = useState(false);

    const connect = () => router.post(route('integrations.meta.select'), { page_id: pageId }, { preserveScroll: true, onStart: () => setBusy(true), onFinish: () => setBusy(false) });
    const cancel = () => router.delete(route('integrations.meta.select.cancel'), { preserveScroll: true });

    return (
        <Modal show onClose={cancel} maxWidth="lg" scrollable>
            <div className="flex min-h-0 flex-1 flex-col">
                <header className="border-b border-line px-5 py-4 sm:px-8">
                    <h2 className="text-lg font-bold text-ink">Elige la página de Facebook</h2>
                    <p className="mt-1 text-sm text-ink-muted">Estas son las páginas que autorizaste en Meta. Ava responderá los mensajes de Messenger de la que elijas.</p>
                </header>
                <fieldset className="min-h-0 flex-1 space-y-2 overflow-y-auto px-5 py-4 sm:px-8">
                    <legend className="sr-only">Páginas de Facebook autorizadas</legend>
                    {pages.map((page) => (
                        <label key={page.id} className={`flex cursor-pointer items-center gap-3 rounded-lg border px-3 py-2.5 text-sm ${pageId === page.id ? 'border-primary bg-primary/10' : 'border-line hover:bg-canvas'}`}>
                            <input type="radio" name="meta_page" value={page.id} checked={pageId === page.id} onChange={() => setPageId(page.id)} className="size-4 accent-primary" />
                            <span className="min-w-0">
                                <span className="block truncate font-semibold text-ink">{page.name}</span>
                                <span className="block text-xs text-ink-muted">ID {page.id}</span>
                            </span>
                        </label>
                    ))}
                </fieldset>
                <div className="flex flex-wrap justify-end gap-3 border-t border-line bg-card px-5 py-4 sm:px-8">
                    <SecondaryButton type="button" onClick={cancel}>Cancelar</SecondaryButton>
                    <PrimaryButton type="button" onClick={connect} disabled={!pageId || busy}>Conectar esta página</PrimaryButton>
                </div>
            </div>
        </Modal>
    );
}
