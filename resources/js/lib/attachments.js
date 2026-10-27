// Pure rules of the attachments of a support message (tested with `npm run test:js`). They only save the person a round trip: the server checks the real content of every
// file again and is the one that decides.

export const KIND_BY_TYPE = {
    'image/png': 'image',
    'image/jpeg': 'image',
    'image/webp': 'image',
    'video/mp4': 'video',
    'video/webm': 'video',
    'application/pdf': 'pdf',
};

const KIND_LABEL = { image: 'imágenes', video: 'videos', pdf: 'PDF' };

export function formatSize(bytes) {
    if (bytes < 1024) return `${bytes} B`;
    if (bytes < 1024 * 1024) return `${Math.round(bytes / 1024)} KB`;

    return `${(bytes / (1024 * 1024)).toFixed(bytes < 10 * 1024 * 1024 ? 1 : 0)} MB`;
}

/**
 * Adds `incoming` files to the `current` selection, keeping the ones that pass and explaining the ones that do not.
 * `limits` = { maxFiles, maxMb: { image, video, pdf }, maxTotalMb } as the server shares them.
 * @returns {{ files: Array<{name: string, size: number, type: string}>, errors: string[] }}
 */
export function addFiles(current, incoming, limits) {
    const files = [...current];
    const errors = [];

    for (const file of incoming) {
        const kind = KIND_BY_TYPE[file.type];
        const total = files.reduce((sum, item) => sum + item.size, 0) + file.size;

        if (!kind) errors.push(`«${file.name}» no es un formato permitido. Usa PNG, JPG, WebP, MP4, WebM o PDF.`);
        else if (file.size === 0) errors.push(`«${file.name}» está vacío.`);
        else if (file.size > limits.maxMb[kind] * 1024 * 1024) errors.push(`«${file.name}» pesa más de ${limits.maxMb[kind]} MB, el máximo para ${KIND_LABEL[kind]}.`);
        else if (files.length >= limits.maxFiles) errors.push(`Puedes adjuntar hasta ${limits.maxFiles} archivos por mensaje.`);
        else if (total > limits.maxTotalMb * 1024 * 1024) errors.push(`Los archivos de un mensaje no pueden superar ${limits.maxTotalMb} MB en total.`);
        else files.push(file);
    }

    return { files, errors: [...new Set(errors)] };
}
