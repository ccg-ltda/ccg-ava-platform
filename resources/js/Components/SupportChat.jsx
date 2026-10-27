import { usePage } from '@inertiajs/react';
import { useEffect } from 'react';

/**
 * CCG's public "Chat de Ava": loads the real web widget (public/widget/ava-widget.js) with the public key of the support channel of the
 * administrative Workspace. The server shares `supportChat` only when that channel exists, is live and really belongs to the administrative
 * Workspace (SupportChannel), so when it is not configured NOTHING is drawn instead of a button that does nothing. The destination is the key's
 * channel, resolved by the server; the visitor needs no account and cannot choose a Workspace.
 *
 * A signed-in member of a client Workspace also gets a signed `context` (SupportContext): the widget repeats it so CCG's team sees who is writing and the
 * conversation follows that person. The script is loaded once for the whole visit (Inertia does not reload the page), so when the person signs in or out the
 * live widget is told about the new token instead of keeping the one it started with.
 */
export default function SupportChat() {
    const support = usePage().props.supportChat;
    const key = support?.key;
    const script = support?.script;
    const context = support?.context ?? null;

    useEffect(() => {
        if (!key || !script) return;

        const existing = document.querySelector('script[data-ava-support]');

        if (!existing) {
            const tag = document.createElement('script');
            tag.src = script;
            tag.defer = true;
            tag.dataset.chatbot = key;
            tag.dataset.avaSupport = 'true';
            if (context) tag.dataset.context = context;
            document.body.appendChild(tag);

            return;
        }

        // Already loaded (possibly as an anonymous visitor on the access pages): keep its token current.
        if (context) existing.dataset.context = context;
        else delete existing.dataset.context;

        window.AvaWidget?.live?.[key]?.setContext?.(context);
    }, [key, script, context]);

    return null;
}
