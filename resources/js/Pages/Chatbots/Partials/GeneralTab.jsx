import Card from '@/Components/Card';
import PrimaryButton from '@/Components/PrimaryButton';
import ChatbotPreview from './ChatbotPreview';
import ProfileFields from './ProfileFields';

/**
 * General: how the chatbot is presented. The first time it asks for the identity (avatar, name, description); once
 * that is saved it shows the chatbot as its customers see it. Later edits happen in IA y comportamiento, so this tab
 * never turns into a form that looks like it must be filled in again. The form belongs to the page.
 */
export default function GeneralTab({ form, chatbot, channels, canManage, avatarMaxKb, onSubmit, onEdit, onChannels }) {
    if (chatbot.profileSaved || !canManage) {
        return <ChatbotPreview chatbot={chatbot} channels={channels} canManage={canManage} onEdit={onEdit} onChannels={onChannels} />;
    }

    return (
        <form onSubmit={onSubmit} noValidate>
            <Card className="mx-auto w-full max-w-xl space-y-6 p-5 sm:p-6">
                <div>
                    <h2 className="text-lg font-bold text-ink">Presenta a tu chatbot</h2>
                    <p className="mt-1 text-sm text-ink-muted">Así lo conocerán tus clientes. Podrás cambiarlo después desde «IA y comportamiento».</p>
                </div>

                <ProfileFields form={form} chatbot={chatbot} canManage={canManage} avatarMaxKb={avatarMaxKb} />

                <div className="flex justify-end border-t border-line pt-4">
                    <PrimaryButton type="submit" disabled={form.processing || !form.data.name.trim()}>
                        {form.processing ? 'Guardando...' : 'Guardar y ver vista previa'}
                    </PrimaryButton>
                </div>
            </Card>
        </form>
    );
}
