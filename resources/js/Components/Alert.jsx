import { AlertTriangle, CheckCircle2, Info, XCircle } from 'lucide-react';

const TONES = {
    info: { box: 'border-primary/20 bg-primary-soft/60', icon: Info, color: 'text-primary' },
    success: { box: 'border-accent-green/25 bg-accent-green/10', icon: CheckCircle2, color: 'text-accent-green' },
    warning: { box: 'border-accent-amber/30 bg-accent-amber/10', icon: AlertTriangle, color: 'text-accent-amber' },
    danger: { box: 'border-danger/25 bg-danger/10', icon: XCircle, color: 'text-danger' },
};

/** Inline message: info, success, warning (real warnings only) or danger. */
export default function Alert({ tone = 'info', className = '', children }) {
    const { box, icon: Icon, color } = TONES[tone];

    return (
        <div role={tone === 'danger' ? 'alert' : 'status'} className={`flex gap-3 rounded-lg border p-3 text-sm text-ink ${box} ${className}`}>
            <Icon className={`mt-0.5 size-4 shrink-0 ${color}`} aria-hidden="true" />
            <div className="min-w-0">{children}</div>
        </div>
    );
}
