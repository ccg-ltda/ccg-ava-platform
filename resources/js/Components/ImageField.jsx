import { ImagePlus, Trash2 } from 'lucide-react';
import { useEffect, useMemo, useRef } from 'react';
import InputError from './InputError';
import InputLabel from './InputLabel';
import SecondaryButton from './SecondaryButton';

/**
 * Upload, preview and removal of one image (the logo of a Workspace, the avatar of a chatbot). Controlled: the
 * parent keeps `file` (a chosen File or null), `removed` and the `currentUrl` the server serves today. The file is
 * validated again by the server; `hint` tells the user the accepted formats and weight.
 */
export default function ImageField({ id, label, noun, alt, file, removed, currentUrl, onChoose, onRemove, error, hint = 'PNG, JPG o WebP. Máximo 1 MB.', rounded = 'rounded-xl', disabled = false }) {
    const input = useRef(null);
    const preview = useMemo(() => (file ? URL.createObjectURL(file) : null), [file]);
    const shown = removed ? null : (preview ?? currentUrl);

    useEffect(() => () => preview && URL.revokeObjectURL(preview), [preview]);

    const choose = (event) => {
        const chosen = event.target.files?.[0];

        if (chosen) onChoose(chosen);

        event.target.value = '';
    };

    return (
        <div>
            <InputLabel htmlFor={id} value={label} />
            <div className="mt-2 flex flex-wrap items-center gap-4">
                <div className={`grid size-20 shrink-0 place-items-center overflow-hidden border border-line bg-canvas ${rounded}`}>
                    {shown ? <img src={shown} alt={alt} className="size-full object-contain p-1" /> : <ImagePlus className="size-6 text-ink-muted" aria-hidden="true" />}
                </div>

                <div className="space-y-2">
                    <div className="flex flex-wrap gap-2">
                        <SecondaryButton type="button" disabled={disabled} onClick={() => input.current?.click()} className="gap-2">
                            <ImagePlus className="size-4" aria-hidden="true" />
                            {shown ? `Cambiar ${noun}` : `Subir ${noun}`}
                        </SecondaryButton>
                        {shown && (
                            <SecondaryButton type="button" disabled={disabled} onClick={onRemove} className="gap-2 text-danger">
                                <Trash2 className="size-4" aria-hidden="true" />
                                Quitar
                            </SecondaryButton>
                        )}
                    </div>
                    <p className="text-xs text-ink-muted">{hint}</p>
                </div>

                <input ref={input} id={id} type="file" accept="image/png,image/jpeg,image/webp" className="sr-only" onChange={choose} disabled={disabled} />
            </div>
            <InputError message={error} className="mt-2" />
        </div>
    );
}
