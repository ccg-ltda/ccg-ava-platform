import { AccessProvider, useAccess } from '@/Components/Access/AccessContext';
import ParticleBackground from '@/Components/Access/ParticleBackground';
import ThemeToggle from '@/Components/Access/ThemeToggle';
import ToastContainer from '@/Components/Access/ToastContainer';

/** Animated background shared by every Ava screen: grid, particle sphere and field connection lines. */
export function AvaBackground() {
    const { svgRef } = useAccess();

    return (
        <>
            <div className="cyber-grid-bg" />
            <ParticleBackground />
            <svg className="svg-connections" ref={svgRef} />
        </>
    );
}

function AccessShell({ children }) {
    return (
        <div className="ava">
            <AvaBackground />
            <ThemeToggle />
            <ToastContainer />

            <div className="login-wrapper">
                <div className="login-card" id="loginCard">
                    <div className="logo-section">
                        <div className="logo-icon">
                            <svg viewBox="0 0 24 24">
                                <path d="M12 2L2 7l10 5 10-5-10-5zM2 17l10 5 10-5M2 12l10 5 10-5" />
                            </svg>
                        </div>
                        <h1>CCG Platform</h1>
                        <p>Secure Access Portal</p>
                    </div>

                    {children}
                </div>
            </div>
        </div>
    );
}

/** Layout of /pre-login and /login (approved design). Use as a persistent Inertia layout. */
export default function AccessLayout({ children }) {
    return (
        <AccessProvider>
            <AccessShell>{children}</AccessShell>
        </AccessProvider>
    );
}
