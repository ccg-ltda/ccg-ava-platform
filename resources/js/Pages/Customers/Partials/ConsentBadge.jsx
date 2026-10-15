import Badge from '@/Components/Badge';

const TONES = { granted: 'green', revoked: 'red', unknown: 'neutral' };

export default function ConsentBadge({ consent, labels }) {
    return <Badge tone={TONES[consent] ?? 'neutral'}>{labels[consent] ?? consent}</Badge>;
}
