import Card from '@/Components/Card';

/**
 * Shell of the account-recovery pages (forgot / reset / confirm password, verify email): the same canvas,
 * card and brand mark as the rest of Ava. The login and pre-login screens use AccessLayout instead.
 */
export default function GuestLayout({ title, children }) {
    return (
        <div className="flex min-h-screen min-h-dvh flex-col items-center justify-center bg-canvas px-4 py-10 font-sans text-ink">
            <p className="text-sm font-extrabold tracking-[0.2em] text-primary uppercase">CCG Avachat</p>

            <Card className="mt-6 w-full max-w-md p-6 sm:p-8">
                {title && <h1 className="mb-4 text-lg font-bold text-ink">{title}</h1>}
                {children}
            </Card>
        </div>
    );
}
