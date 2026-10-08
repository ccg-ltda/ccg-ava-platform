import { Check, Copy } from 'lucide-react';
import { useState } from 'react';
import SecondaryButton from './SecondaryButton';

/** A read-only block of text (code, a URL, a token) with a button that copies it. `message` is what the user is told after copying. */
export default function CopyBlock({ value, label, message, stacked = false, className = '' }) {
    const [copied, setCopied] = useState(false);
    const [failed, setFailed] = useState(false);

    const copy = async () => {
        try {
            await navigator.clipboard.writeText(value);
            setFailed(false);
            setCopied(true);
            setTimeout(() => setCopied(false), 2000);
        } catch {
            // Clipboard blocked (insecure context): the text stays selectable in the block, and the user is told.
            setFailed(true);
        }
    };

    return (
        <div className={className}>
            <div className={`flex flex-col gap-2 ${stacked ? '' : 'sm:flex-row sm:items-start'}`}>
                <code aria-label={label} className="max-h-48 min-w-0 flex-1 overflow-auto rounded-lg border border-line bg-canvas px-3 py-2 font-mono text-[13px] break-all whitespace-pre-wrap text-ink select-all">
                    {value}
                </code>
                <SecondaryButton type="button" onClick={copy} className="shrink-0 gap-2" aria-live="polite">
                    {copied ? <Check className="size-4" aria-hidden="true" /> : <Copy className="size-4" aria-hidden="true" />}
                    {copied ? 'Copiado' : 'Copiar'}
                </SecondaryButton>
            </div>
            {failed && (
                <p role="alert" className="mt-2 text-sm text-danger">No se pudo copiar automáticamente. Selecciona el texto y cópialo manualmente.</p>
            )}
            {message && (
                <p role="status" className="mt-2 min-h-5 text-sm font-semibold text-accent-green">
                    {copied ? message : ''}
                </p>
            )}
        </div>
    );
}
