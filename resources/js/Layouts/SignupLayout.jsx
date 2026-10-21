import { AccessProvider } from '@/Components/Access/AccessContext';
import ThemeToggle from '@/Components/Access/ThemeToggle';
import ToastContainer from '@/Components/Access/ToastContainer';
import LegalLinks from '@/Components/LegalLinks';
import SupportChat from '@/Components/SupportChat';
import { AvaBackground } from '@/Layouts/AccessLayout';

/**
 * Shell of the public sign-up: the same animated background, theme toggle, logo and card as the login, in a wider centered panel
 * (`.signup-card`, resources/css/ava.css). Use as a persistent Inertia layout.
 */
export default function SignupLayout({ children }) {
    return (
        <AccessProvider>
            <div className="ava">
                <AvaBackground />
                <ThemeToggle />
                <ToastContainer />

                <div className="login-wrapper">
                    <div className="login-card signup-card" id="loginCard">
                        <div className="logo-section">
                            <div className="logo-icon">
                                <svg viewBox="0 0 24 24">
                                    <path d="M12 2L2 7l10 5 10-5-10-5zM2 17l10 5 10-5M2 12l10 5 10-5" />
                                </svg>
                            </div>
                            <h1>CCG Platform</h1>
                            <p>Crea tu cuenta empresarial</p>
                        </div>

                        {children}

                        <LegalLinks className="footer-text" />
                    </div>
                </div>
                <SupportChat />
            </div>
        </AccessProvider>
    );
}
