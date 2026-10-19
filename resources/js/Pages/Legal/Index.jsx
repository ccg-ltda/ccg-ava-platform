import { Head, Link } from '@inertiajs/react';
import GuestLayout from '@/Layouts/GuestLayout';
import { DraftNotice } from './Show';

export default function Index({ documents, draft }) {
    return (
        <GuestLayout title="Información legal" wide>
            <Head title="Información legal" />

            {draft && <DraftNotice />}

            <ul className="space-y-3">
                {documents.map((d) => (
                    <li key={d.slug}>
                        <Link href={route('legal.show', { slug: d.slug })} className="font-semibold text-primary hover:underline">
                            {d.title}
                        </Link>
                        <span className="ml-2 text-xs text-ink-muted">versión {d.version}</span>
                    </li>
                ))}
            </ul>
        </GuestLayout>
    );
}
