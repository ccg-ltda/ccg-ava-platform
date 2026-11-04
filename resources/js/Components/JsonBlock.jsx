import { Check, Copy } from 'lucide-react';
import { useState } from 'react';

/** Text of a stored payload: JSON is shown indented, anything else as it is. `null` means nobody reported it. */
export function payloadText(value) {
    if (value === null || value === undefined || value === '') return null;

    return typeof value === 'string' ? value : JSON.stringify(value, null, 2);
}

/**
 * A read-only technical payload (headers, query, body) with a button that copies it. A missing payload is said plainly ("No reportado") instead of being left empty,
 * so a blank never looks like "the call had no body".
 */
export default function JsonBlock({ label, value, emptyText = 'No reportado' }) {
    const [copied, setCopied] = useState(false);
    const text = payloadText(value);

    const copy = async () => {
        try {
            await navigator.clipboard.writeText(text);
            setCopied(true);
            setTimeout(() => setCopied(false), 2000);
        } catch {
            setCopied(false);
        }
    };

    return (
        <div className="min-w-0">
            <div className="mb-1 flex items-center justify-between gap-2">
                <h4 className="text-xs font-semibold text-ink-muted">{label}</h4>
                {text !== null && (
                    <button type="button" onClick={copy} aria-label={`Copiar ${label.toLowerCase()}`} className="inline-flex items-center gap-1 rounded-md px-1.5 py-0.5 text-xs font-semibold text-primary hover:bg-primary-soft focus-visible:outline-2 focus-visible:outline-primary">
                        {copied ? <Check className="size-3.5" aria-hidden="true" /> : <Copy className="size-3.5" aria-hidden="true" />}
                        {copied ? 'Copiado' : 'Copiar'}
                    </button>
                )}
            </div>
            {text === null ? (
                <p className="rounded-lg border border-dashed border-line px-3 py-2 text-xs text-ink-muted">{emptyText}</p>
            ) : (
                <pre aria-label={label} className="max-h-56 overflow-auto rounded-lg border border-line bg-canvas px-3 py-2 font-mono text-xs break-words whitespace-pre-wrap text-ink">{text}</pre>
            )}
        </div>
    );
}
