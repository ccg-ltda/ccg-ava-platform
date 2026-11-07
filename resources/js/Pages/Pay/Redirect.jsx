import { Head } from '@inertiajs/react';
import { useEffect, useRef } from 'react';
import GuestLayout from '@/Layouts/GuestLayout';

/** Sends the customer to the provider's own page: the browser POSTs the fields the server prepared (no card data ever passes through Ava). */
export default function Redirect({ action, fields }) {
    const form = useRef(null);

    useEffect(() => {
        form.current?.submit();
    }, []);

    return (
        <GuestLayout title="Redirigiendo al sitio de pago">
            <Head title="Redirigiendo" />
            <form ref={form} method="POST" action={action}>
                {Object.entries(fields).map(([name, value]) => (
                    <input key={name} type="hidden" name={name} value={value} />
                ))}
                <p className="text-sm text-ink-muted">Te estamos llevando al sitio seguro del proveedor…</p>
                <noscript>
                    <button type="submit" className="mt-4 rounded-md bg-primary px-4 py-2 text-sm font-semibold text-white">Continuar al pago</button>
                </noscript>
            </form>
        </GuestLayout>
    );
}
