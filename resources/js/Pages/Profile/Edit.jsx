import { Head } from '@inertiajs/react';
import { useState } from 'react';
import PageHeader from '@/Components/PageHeader';
import AppLayout from '@/Layouts/AppLayout';
import AccountSection from './Partials/AccountSection';
import PrivacySection from './Partials/PrivacySection';
import ProfileHero from './Partials/ProfileHero';
import SecuritySection from './Partials/SecuritySection';

/**
 * Mi perfil: a profile card (photo, name, email, role and the state of the account) and three compact sections — account, security and privacy —
 * each a summary with its own «Editar» that opens only its form (one at a time) and returns to the summary when saved or cancelled. Nothing here can
 * read or change another person's account; the password is only ever shown as configured.
 */
export default function Edit({ mustVerifyEmail, status, account, photoUrl, photoRules, marketing, accepted, legalAvailable, legalReviewed }) {
    const [editing, setEditing] = useState(null); // 'account' | 'password' | 'privacy' | null
    const section = (id) => ({ editing: editing === id, onEdit: () => setEditing(id), onClose: () => setEditing(null) });

    return (
        <>
            <Head title="Mi perfil" />

            <PageHeader title="Mi perfil" description="Tu cuenta, tu seguridad y tus preferencias de privacidad." />

            <ProfileHero account={account} photoUrl={photoUrl} photoRules={photoRules} marketing={marketing} legalAvailable={legalAvailable} onEditAccount={() => setEditing('account')} />

            <div className="grid items-start gap-6 lg:grid-cols-2">
                <AccountSection account={account} mustVerifyEmail={mustVerifyEmail} status={status} {...section('account')} />
                <SecuritySection account={account} {...section('password')} />
                <PrivacySection className="lg:col-span-2" marketing={marketing} accepted={accepted} available={legalAvailable} reviewed={legalReviewed} {...section('privacy')} />
            </div>
        </>
    );
}

Edit.layout = (page) => <AppLayout>{page}</AppLayout>;
