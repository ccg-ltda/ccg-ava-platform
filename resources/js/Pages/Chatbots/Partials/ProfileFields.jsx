import ImageField from '@/Components/ImageField';
import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import Textarea from '@/Components/Textarea';
import TextInput from '@/Components/TextInput';

/**
 * The identity of a chatbot: avatar (its own image, never the logo of Ava or of the Workspace), name and description.
 * One piece shared by General (the first time it is set) and IA y comportamiento (every later edit); the form belongs
 * to the page and the server validates and stores it for the active Workspace.
 */
export default function ProfileFields({ form, chatbot, canManage, avatarMaxKb }) {
    const { data, setData, errors } = form;

    return (
        <>
            <ImageField
                id="chatbot_avatar"
                label="Avatar del chatbot"
                noun="avatar"
                alt={`Avatar de ${chatbot.name}`}
                file={data.avatar}
                removed={data.remove_avatar}
                currentUrl={chatbot.avatarUrl}
                onChoose={(file) => setData((previous) => ({ ...previous, avatar: file, remove_avatar: false }))}
                onRemove={() => setData((previous) => ({ ...previous, avatar: null, remove_avatar: true }))}
                error={errors.avatar}
                hint={`PNG, JPG o WebP. Máximo ${Math.round(avatarMaxKb / 1024)} MB.`}
                disabled={!canManage}
            />

            <div>
                <InputLabel htmlFor="chatbot_name" value="Nombre" />
                <TextInput id="chatbot_name" className="mt-1" value={data.name} onChange={(e) => setData('name', e.target.value)} invalid={Boolean(errors.name)} maxLength={100} disabled={!canManage} />
                <InputError message={errors.name} className="mt-1" />
            </div>

            <div>
                <InputLabel htmlFor="chatbot_description" value="Descripción (opcional)" />
                <Textarea id="chatbot_description" className="mt-1" rows={2} value={data.description} onChange={(e) => setData('description', e.target.value)} invalid={Boolean(errors.description)} maxLength={500} disabled={!canManage} />
                <InputError message={errors.description} className="mt-1" />
            </div>
        </>
    );
}
