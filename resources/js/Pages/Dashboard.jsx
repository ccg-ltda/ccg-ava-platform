import { Head, usePage } from '@inertiajs/react';
import AvaLayout from '@/Layouts/AvaLayout';

/** First internal page: welcome and summary of the active Workspace (server-provided context only). */
export default function Dashboard({ stats }) {
    const { auth, workspace } = usePage().props;
    const role = auth.user?.roles?.[0];

    return (
        <>
            <Head title="Dashboard" />

            <section className="app-card">
                <h1>Bienvenido, {auth.user?.name}</h1>
                <p>
                    Estás trabajando en <strong>{workspace?.name}</strong> · {workspace?.organization}
                </p>
            </section>

            <section className="app-grid">
                <div className="app-stat">
                    <span>Organization</span>
                    <strong title={workspace?.organization}>{workspace?.organization}</strong>
                </div>
                <div className="app-stat">
                    <span>Workspace</span>
                    <strong title={workspace?.code}>{workspace?.code}</strong>
                </div>
                <div className="app-stat">
                    <span>Tu rol</span>
                    <strong>{role}</strong>
                </div>
                <div className="app-stat">
                    <span>Usuarios del Workspace</span>
                    <strong>{stats?.users ?? 0}</strong>
                </div>
            </section>

            <section className="app-card">
                <h2>Áreas de Ava</h2>
                <div className="app-placeholders">
                    <div className="app-placeholder">Próximamente</div>
                    <div className="app-placeholder">Próximamente</div>
                    <div className="app-placeholder">Próximamente</div>
                </div>
            </section>
        </>
    );
}

Dashboard.layout = (page) => <AvaLayout>{page}</AvaLayout>;
