import Badge from '@/Components/Badge';

const TONES = { draft: 'neutral', prepared: 'blue', simulated: 'violet', cancelled: 'red' };

export default function StatusBadge({ status, labels }) {
    return <Badge tone={TONES[status] ?? 'neutral'}>{labels[status] ?? status}</Badge>;
}
