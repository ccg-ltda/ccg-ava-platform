import { Head } from '@inertiajs/react';
import Card from '@/Components/Card';
import PageHeader from '@/Components/PageHeader';
import AppLayout from '@/Layouts/AppLayout';
import CommunicationsForm from './Partials/CommunicationsForm';
import UpdatePasswordForm from './Partials/UpdatePasswordForm';
import UpdateProfileInformationForm from './Partials/UpdateProfileInformationForm';

export default function Edit({ mustVerifyEmail, status, marketing, accepted, legalAvailable }) {
    return (
        <>
            <Head title="Mi perfil" />

            <PageHeader title="Mi perfil" description="Datos de tu cuenta y seguridad." />

            <Card className="p-4 sm:p-8">
                <UpdateProfileInformationForm mustVerifyEmail={mustVerifyEmail} status={status} className="max-w-xl" />
            </Card>

            <Card delay={80} className="p-4 sm:p-8">
                <UpdatePasswordForm className="max-w-xl" />
            </Card>

            <Card delay={160} className="p-4 sm:p-8">
                <CommunicationsForm marketing={marketing} accepted={accepted} available={legalAvailable} className="max-w-xl" />
            </Card>
        </>
    );
}

Edit.layout = (page) => <AppLayout>{page}</AppLayout>;
