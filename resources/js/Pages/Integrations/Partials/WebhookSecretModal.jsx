import Alert from '@/Components/Alert';
import CopyBlock from '@/Components/CopyBlock';
import Modal from '@/Components/Modal';
import PrimaryButton from '@/Components/PrimaryButton';

/**
 * The signing secret of a webhook, shown ONCE: right after creating it or rotating it. The server flashed it for this single page load; closing this window is the last
 * time anyone can read it (to get a new one, rotate the secret).
 */
export default function WebhookSecretModal({ secret, graceHours, onClose }) {
    return (
        <Modal show onClose={onClose} maxWidth="2xl">
            <div className="space-y-4 p-5 sm:p-6">
                <header>
                    <h2 className="text-lg font-bold text-ink">Secreto de firma de «{secret.name}»</h2>
                    <p className="mt-1 text-sm text-ink-muted">Con este secreto tu sistema comprueba que cada aviso viene de Ava y no fue alterado.</p>
                </header>

                <Alert tone="warning">Cópialo ahora: por seguridad no se volverá a mostrar. Si lo pierdes, rota el secreto para obtener uno nuevo.</Alert>
                <CopyBlock value={secret.secret} label={`Secreto de firma de ${secret.name}`} message="Secreto copiado." />

                <p className="text-sm text-ink-muted">
                    Al rotar el secreto, el anterior sigue firmando un segundo encabezado (<code className="font-mono text-ink">X-Ava-Signature-Previous</code>) durante {graceHours} h, para que cambies el valor en tu sistema sin perder avisos.
                </p>

                <div className="flex justify-end">
                    <PrimaryButton type="button" onClick={onClose}>
                        Ya lo guardé
                    </PrimaryButton>
                </div>
            </div>
        </Modal>
    );
}
