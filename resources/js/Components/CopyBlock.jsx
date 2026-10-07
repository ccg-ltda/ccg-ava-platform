import { Check, Copy } from 'lucide-react';
import { useState } from 'react';
import SecondaryButton from './SecondaryButton';

/** A read-only block of text (code, a URL, a token) with a button that copies it. */
export default function CopyBlock({ value, label, className = '' }) {
    const [copied, setCopied] = useState(false);

    const copy = async () => {
        try {
            await navigator.clipboard.writeText(value);
            setCopied(true);
            setTimeout(() => setCopied(false), 2000);
        } catch {
            // Clipboard blocked (insecure context): the text stays selectable in the block.
        }
    };

    return (
        <div className={`flex flex-col gap-2 sm:flex-row sm:items-start ${className}`}>
            <code aria-label={label} className="min-w-0 flex-1 rounded-lg border border-line bg-canvas px-3 py-2 font-mono text-[13px] break-all whitespace-pre-wrap text-ink select-all">
                {value}
            </code>
            <SecondaryButton type="button" onClick={copy} className="shrink-0 gap-2" aria-live="polite">
                {copied ? <Check className="size-4" aria-hidden="true" /> : <Copy className="size-4" aria-hidden="true" />}
                {copied ? 'Copiado' : 'Copiar'}
            </SecondaryButton>
        </div>
    );
}
