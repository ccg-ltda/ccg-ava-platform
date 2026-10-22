import { roleTone } from '@/config/roles';

const TEXT = { violet: 'text-accent-violet', blue: 'text-primary', neutral: 'text-ink-muted' };

/**
 * A role as TEXT with a semantic color (Admin, Supervisor, Agente, Cliente, Plataforma, Soporte…), not inside a capsule: the name is what identifies the role, the
 * color only reinforces it, so it never depends on color alone. Long names wrap instead of widening the row. Used wherever a role is shown; the capsule `Badge`
 * stays for states and groups of values. It changes nothing about roles or permissions.
 */
export default function RoleLabel({ role, fallback = 'Sin rol', className = '' }) {
    const name = role || fallback;
    const tone = role ? roleTone(role) : 'neutral';

    return <span className={`inline-block max-w-full text-sm leading-tight font-semibold break-words capitalize ${TEXT[tone] ?? TEXT.neutral} ${className}`}>{name}</span>;
}
