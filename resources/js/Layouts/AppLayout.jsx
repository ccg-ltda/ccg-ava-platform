import { usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import ChatButton from '@/Components/ChatButton';
import { ConfirmProvider } from '@/Components/ConfirmDialog';
import Sidebar from '@/Components/Sidebar';
import { FlashToasts, ToastProvider } from '@/Components/Toast';
import useWorkspaceTheme from '@/Hooks/useWorkspaceTheme';
import Topbar from '@/Components/Topbar';

/**
 * The application layout: top bar, sidebar and content area. Use it as a persistent Inertia layout:
 * `Page.layout = (page) => <AppLayout>{page}</AppLayout>`.
 *
 * Composition rules shared by every module: a page starts with <PageHeader>, then cards/panels
 * (<Card>) spaced by the `space-y-6` of the content column below.
 */
export default function AppLayout({ children }) {
    const { props, url } = usePage();
    const user = props.auth.user;
    const role = user?.roles?.[0];
    const [menuOpen, setMenuOpen] = useState(false);

    // The Workspace's primary color and light/dark/system mode, applied while the app shell is mounted.
    useWorkspaceTheme(props.workspace?.settings);

    // Close the mobile panel after every navigation.
    useEffect(() => setMenuOpen(false), [url]);

    return (
        <ToastProvider>
            <ConfirmProvider>
                <div className="app-root flex min-h-screen min-h-dvh flex-col bg-canvas font-sans text-ink">
                    <Topbar user={user} role={role} onMenu={() => setMenuOpen(true)} />

                    <div className="flex min-h-0 flex-1">
                        <Sidebar permissions={user?.permissions} workspace={props.workspace} open={menuOpen} onClose={() => setMenuOpen(false)} />

                        <main className="min-w-0 flex-1 px-4 py-6 sm:px-6 lg:px-8">
                            <div key={url.split('?')[0]} className="mx-auto w-full max-w-screen-2xl space-y-6 motion-safe:animate-fade-in">
                                {children}
                            </div>
                        </main>
                    </div>

                    <ChatButton />
                </div>
                <FlashToasts />
            </ConfirmProvider>
        </ToastProvider>
    );
}
