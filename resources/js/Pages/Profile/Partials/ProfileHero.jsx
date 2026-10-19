import { router, useForm } from '@inertiajs/react';
import { Camera, CheckCircle2, KeyRound, MailCheck, MailWarning, Megaphone, Pencil, Trash2, UserCheck } from 'lucide-react';
import { useEffect, useMemo, useRef, useState } from 'react';
import Avatar from '@/Components/Avatar';
import Badge from '@/Components/Badge';
import Card from '@/Components/Card';
import { useConfirm } from '@/Components/ConfirmDialog';
import InputError from '@/Components/InputError';
import Modal from '@/Components/Modal';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import { roleTone } from '@/config/roles';

/** A small status of the account, with an icon and plain words (never colour alone). */
function Chip({ icon: Icon, children, tone = 'neutral' }) {
    return (
        <Badge tone={tone} className="gap-1 normal-case tracking-normal">
            <Icon className="size-3" aria-hidden="true" />
            {children}
        </Badge>
    );
}

/** Choose, preview and upload the personal photo. The server decodes and re-encodes it; the rules shown here are the ones it enforces. */
function PhotoDialog({ photoUrl, rules, onClose }) {
    const input = useRef(null);
    const form = useForm({ photo: null });
    const preview = useMemo(() => (form.data.photo ? URL.createObjectURL(form.data.photo) : null), [form.data.photo]);

    useEffect(() => () => preview && URL.revokeObjectURL(preview), [preview]);

    const submit = (e) => {
        e.preventDefault();
        form.post(route('profile.photo.store'), { forceFormData: true, preserveScroll: true, onSuccess: onClose });
    };

    return (
        <Modal show onClose={onClose} maxWidth="md">
            <form onSubmit={submit} className="p-6">
                <h2 className="text-lg font-bold text-ink">Foto de perfil</h2>
                <p className="mt-1 text-sm text-ink-muted">
                    JPG, PNG o WebP, de {rules.min} a {rules.max} píxeles por lado y hasta {Math.round(rules.maxKb / 1024)} MB. Se recorta a un cuadrado.
                </p>

                <div className="mt-5 flex items-center gap-4">
                    <div className="size-24 shrink-0 overflow-hidden rounded-full border border-line bg-canvas">
                        {preview ? <img src={preview} alt="Vista previa de la foto" className="size-full object-cover" /> : photoUrl ? <img src={photoUrl} alt="Tu foto actual" className="size-full object-cover" /> : null}
                    </div>
                    <div className="space-y-2">
                        <SecondaryButton type="button" onClick={() => input.current?.click()} className="gap-2">
                            <Camera className="size-4" aria-hidden="true" />
                            Elegir imagen
                        </SecondaryButton>
                        <input
                            ref={input}
                            type="file"
                            accept="image/jpeg,image/png,image/webp"
                            className="sr-only"
                            aria-label="Elegir una imagen para la foto de perfil"
                            onChange={(e) => form.setData('photo', e.target.files?.[0] ?? null)}
                        />
                        {form.data.photo && <p className="max-w-56 truncate text-xs text-ink-muted">{form.data.photo.name}</p>}
                    </div>
                </div>
                <InputError message={form.errors.photo} className="mt-3" />

                <div className="mt-6 flex justify-end gap-3">
                    <SecondaryButton type="button" onClick={onClose}>
                        Cancelar
                    </SecondaryButton>
                    <PrimaryButton type="submit" disabled={form.processing || !form.data.photo}>
                        {form.processing ? 'Subiendo...' : 'Guardar foto'}
                    </PrimaryButton>
                </div>
            </form>
        </Modal>
    );
}

/** The profile card: who the person is in this Workspace and the state of their account, with the actions on the photo and the account. */
export default function ProfileHero({ account, photoUrl, photoRules, marketing, legalAvailable, onEditAccount }) {
    const confirm = useConfirm();
    const [photoOpen, setPhotoOpen] = useState(false);

    const removePhoto = async () => {
        const confirmed = await confirm({ description: 'Se retirará tu foto y volverán a mostrarse tus iniciales.', confirmLabel: 'Retirar foto' });

        if (confirmed) router.delete(route('profile.photo.destroy'), { preserveScroll: true });
    };

    return (
        <Card className="p-5 sm:p-6">
            <div className="flex flex-wrap items-center gap-5">
                <Avatar name={account.name} photoUrl={photoUrl} className="size-20 bg-primary/15 text-2xl text-primary" />

                <div className="min-w-0 flex-1">
                    <h2 className="truncate text-xl font-bold text-ink">{account.name}</h2>
                    <p className="truncate text-sm text-ink-muted">{account.email}</p>
                    <div className="mt-2 flex flex-wrap items-center gap-2">
                        {account.role && <Badge tone={roleTone(account.role)}>{account.role}</Badge>}
                        <span className="text-xs text-ink-muted">en {account.workspace}</span>
                    </div>
                    <div className="mt-3 flex flex-wrap gap-2" aria-label="Estado de la cuenta">
                        <Chip icon={UserCheck} tone={account.active ? 'green' : 'red'}>{account.active ? 'Cuenta activa' : 'Cuenta desactivada'}</Chip>
                        <Chip icon={account.verified ? MailCheck : MailWarning} tone={account.verified ? 'green' : 'amber'}>{account.verified ? 'Correo verificado' : 'Correo sin verificar'}</Chip>
                        <Chip icon={KeyRound} tone={account.passwordSet ? 'green' : 'amber'}>{account.passwordSet ? 'Contraseña configurada' : 'Sin contraseña'}</Chip>
                        {legalAvailable && (
                            <Chip icon={marketing ? CheckCircle2 : Megaphone} tone={marketing ? 'blue' : 'neutral'}>
                                {marketing ? 'Comunicaciones autorizadas' : 'Sin comunicaciones comerciales'}
                            </Chip>
                        )}
                    </div>
                </div>

                <div className="flex flex-wrap gap-2">
                    <SecondaryButton type="button" onClick={onEditAccount} className="gap-2">
                        <Pencil className="size-4" aria-hidden="true" />
                        Editar perfil
                    </SecondaryButton>
                    <SecondaryButton type="button" onClick={() => setPhotoOpen(true)} className="gap-2">
                        <Camera className="size-4" aria-hidden="true" />
                        {photoUrl ? 'Cambiar foto' : 'Añadir foto'}
                    </SecondaryButton>
                    {photoUrl && (
                        <SecondaryButton type="button" onClick={removePhoto} className="gap-2 text-danger">
                            <Trash2 className="size-4" aria-hidden="true" />
                            Quitar foto
                        </SecondaryButton>
                    )}
                </div>
            </div>

            {photoOpen && <PhotoDialog photoUrl={photoUrl} rules={photoRules} onClose={() => setPhotoOpen(false)} />}
        </Card>
    );
}
