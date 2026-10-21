import { usePage } from '@inertiajs/react';
import { useEffect } from 'react';

/**
 * CCG's public "Chat de Ava": loads the real web widget (public/widget/ava-widget.js) with the public key of the support channel of the
 * administrative Workspace. The server shares `supportChat` only when that channel exists, is live and really belongs to the administrative
 * Workspace (SupportChannel), so when it is not configured NOTHING is drawn instead of a button that does nothing. The destination is the key's
 * channel, resolved by the server; the visitor needs no account and cannot choose a Workspace.
 */
export default function SupportChat() {
    const support = usePage().props.supportChat;
    const key = support?.key;
    const script = support?.script;

    useEffect(() => {
        if (!key || !script || document.querySelector('script[data-ava-support]')) return;

        const tag = document.createElement('script');
        tag.src = script;
        tag.defer = true;
        tag.dataset.chatbot = key;
        tag.dataset.avaSupport = 'true';
        document.body.appendChild(tag);
    }, [key, script]);

    return null;
}
