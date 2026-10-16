import Badge from '@/Components/Badge';

/** Look of each order state (the server decides the state and its label). */
const tones = { received: 'amber', confirmed: 'blue', preparing: 'violet', ready: 'blue', dispatched: 'violet', delivered: 'green', cancelled: 'red' };

export default function OrderStatusBadge({ status, label }) {
    return <Badge tone={tones[status] ?? 'neutral'}>{label}</Badge>;
}
