import { router, usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import Alert from '@/Components/Alert';
import ConversationAlerts from '@/Components/ConversationAlerts';
import SupportDesk from '@/Components/SupportDesk';
import { ConfirmProvider } from '@/Components/ConfirmDialog';
import SecondaryButton from '@/Components/SecondaryButton';
import Sidebar from '@/Components/Sidebar';
import PageSkeleton from '@/Components/Skeleton';
import { FlashToasts, ToastProvider } from '@/Components/Toast';
import useNavigationSkeleton from '@/Hooks/useNavigationSkeleton';
import useSidebarCollapsed from '@/Hooks/useSidebarCollapsed';
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
    const [collapsed, toggleCollapsed] = useSidebarCollapsed();
    // The page that is on its way: a skeleton of its module when it takes longer than a moment, or a retry when it does not arrive.
    const loading = useNavigationSkeleton(props.skeleton);

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
                        <Sidebar workspace={props.workspace} open={menuOpen} onClose={() => setMenuOpen(false)} collapsed={collapsed} onToggleCollapsed={toggleCollapsed} />

                        <main className="min-w-0 flex-1 px-4 py-6 sm:px-6 lg:px-8">
                            <div className="mx-auto w-full max-w-screen-2xl space-y-6">
                                {loading.variant && <PageSkeleton variant={loading.variant} />}
                                {loading.failed && (
                                    <Alert tone="warning">
                                        La página tarda más de lo normal en cargar. Sigue esperando o{' '}
                                        <SecondaryButton type="button" className="ml-1" onClick={() => router.visit(loading.url)}>
                                            Reintentar
                                        </SecondaryButton>
                                    </Alert>
                                )}
                                {/* No entrance animation: a fade-in made every page start invisible for 200 ms after its data had already arrived. The current page stays mounted (hidden) while the skeleton shows, so a failed navigation gives it back exactly as it was. */}
                                <div key={url.split('?')[0]} hidden={Boolean(loading.variant)} className="space-y-6">
                                    {children}
                                </div>
                            </div>
                        </main>
                    </div>

                    <SupportDesk />
                    <ConversationAlerts />
                </div>
                <FlashToasts />
            </ConfirmProvider>
        </ToastProvider>
    );
}
