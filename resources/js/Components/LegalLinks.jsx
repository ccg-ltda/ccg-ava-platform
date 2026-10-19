import { Link } from '@inertiajs/react';

/** The public legal pages, in the order they are offered. The slugs are the ones in config/legal.php (a test keeps them aligned). */
export const LEGAL_LINKS = [
    { slug: 'terminos-y-condiciones', label: 'Términos y condiciones' },
    { slug: 'aviso-de-privacidad', label: 'Aviso de privacidad' },
    { slug: 'politica-de-tratamiento-de-datos', label: 'Política de datos personales' },
];

/** Visible links to the legal texts, for the footer of the access screens and the account-recovery forms. */
export default function LegalLinks({ className = '', linkClassName = 'underline-offset-2 hover:underline' }) {
    return (
        <nav aria-label="Información legal" className={'flex flex-wrap justify-center gap-x-4 gap-y-1 ' + className}>
            {LEGAL_LINKS.map((link) => (
                <Link key={link.slug} href={route('legal.show', { slug: link.slug })} className={linkClassName}>
                    {link.label}
                </Link>
            ))}
        </nav>
    );
}
