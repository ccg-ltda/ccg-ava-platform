import { Head, router, usePage } from '@inertiajs/react';
import SignupLayout from '@/Layouts/SignupLayout';

/**
 * Shown ONLY for a sign-up whose payment was verified and whose subscription is really active (SignupController::welcome redirects anything else
 * back to the wizard). It grants nothing: signing in still needs the account and the Workspace name.
 */
export default function Welcome({ workspace, plan, solution, email }) {
    const { supportChat } = usePage().props;

    return (
        <>
            <Head title="Tu Workspace está listo" />
            <div className="signup-note success" role="status">
                <div>
                    <strong>¡Felicitaciones! Tu Workspace está listo.</strong> Tu pago fue confirmado y tus módulos ya están habilitados.
                </div>
            </div>

            <ul className="summary-list">
                <li><span>Workspace</span><strong>{workspace.name}</strong></li>
                <li><span>Nombre para ingresar</span><strong>{workspace.code}</strong></li>
                <li><span>Solución</span><strong>{solution}</strong></li>
                <li><span>Plan</span><strong>{plan.name}</strong></li>
                <li><span>Módulos disponibles</span><strong>{plan.modules.map((module) => module.name).join(', ') || 'Ninguno'}</strong></li>
            </ul>

            <h2 className="signup-title">Siguientes pasos</h2>
            <p className="signup-lead">
                Inicia sesión con el nombre de tu Workspace (<strong>{workspace.code}</strong>), el correo <strong>{email}</strong> y la contraseña que elegiste. Los módulos contratados estarán disponibles desde el menú de tu Workspace; te recomendamos empezar por Configuraciones y Usuarios y roles para completar la configuración inicial.
            </p>
            <p className="signup-lead">
                {supportChat
                    ? 'Si necesitas ayuda, usa el botón «Chat de Ava» en la esquina de la pantalla: tu consulta llega al equipo de soporte de CCG.'
                    : 'El chat de ayuda todavía no está habilitado en este entorno. Si necesitas ayuda, contacta con el soporte de CCG.'}
            </p>

            <div className="signup-actions">
                <div className="group" />
                <div className="group">
                    <button type="button" className="login-btn" onClick={() => router.visit(route('pre-login'))}>Ir a iniciar sesión</button>
                </div>
            </div>
        </>
    );
}

Welcome.layout = (page) => <SignupLayout>{page}</SignupLayout>;
