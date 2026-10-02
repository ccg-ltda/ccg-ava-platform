import { usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import ChatButton from '@/Components/ChatButton';
import FlashAlert from '@/Components/FlashAlert';
import Sidebar from '@/Components/Sidebar';
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

    // Close the mobile panel after every navigation.
    useEffect(() => setMenuOpen(false), [url]);

    return (
        <div className="app-root flex min-h-screen min-h-dvh flex-col bg-canvas font-sans text-ink">
            <Topbar user={user} role={role} onMenu={() => setMenuOpen(true)} />

            <div className="flex min-h-0 flex-1">
                <Sidebar role={role} workspace={props.workspace} open={menuOpen} onClose={() => setMenuOpen(false)} />

                <main className="min-w-0 flex-1 px-4 py-6 sm:px-6 lg:px-8">
                    <div key={url} className="mx-auto w-full max-w-screen-2xl space-y-6 motion-safe:animate-fade-in">
                        <FlashAlert />
                        {children}
                    </div>
                </main>
            </div>

            <ChatButton />
        </div>
    );
}
