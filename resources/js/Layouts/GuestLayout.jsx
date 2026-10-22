import Card from '@/Components/Card';
import LegalLinks from '@/Components/LegalLinks';
import useBrand from '@/Hooks/useBrand';
import SupportChat from '@/Components/SupportChat';

/**
 * Shell of the account-recovery pages (forgot / reset / confirm password, verify email) and of the legal pages: the same
 * canvas, card and brand mark as the rest of Ava. The login and pre-login screens use AccessLayout instead.
 * `wide` is for long reading (legal texts).
 */
export default function GuestLayout({ title, children, wide = false }) {
    const brand = useBrand();

    return (
        <div className="flex min-h-screen min-h-dvh flex-col items-center justify-center bg-canvas px-4 py-10 font-sans text-ink">
            <p className="max-w-full text-center text-sm font-extrabold tracking-[0.2em] break-words text-primary uppercase">{brand}</p>

            <main className={'mt-6 w-full ' + (wide ? 'max-w-3xl' : 'max-w-md')}>
                <Card className="w-full p-6 sm:p-8">
                    {title && <h1 className="mb-4 text-lg font-bold text-ink">{title}</h1>}
                    {children}
                </Card>
            </main>

            <LegalLinks className="mt-6 text-xs text-ink-muted" linkClassName="hover:text-primary hover:underline" />
            <SupportChat />
        </div>
    );
}
