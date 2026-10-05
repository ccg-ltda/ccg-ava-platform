const TONES = {
    primary: 'text-primary hover:bg-primary-soft focus-visible:outline-primary',
    danger: 'text-danger hover:bg-danger/10 focus-visible:outline-danger',
};

/**
 * Icon-only action for table rows. `label` is the tooltip text ("Editar", "Desactivar"...); `context`
 * (e.g. the row name) is appended to the accessible name so screen readers hear what it acts on.
 * The tooltip shows on hover and on keyboard focus, never as permanent text.
 */
export default function IconButton({ icon: Icon, label, context, tone = 'primary', className = '', ...props }) {
    return (
        <span className="group/tip relative inline-flex">
            <button
                type="button"
                aria-label={context ? `${label} ${context}` : label}
                className={`grid size-9 place-items-center rounded-lg transition-colors duration-150 focus-visible:outline-2 disabled:cursor-not-allowed disabled:opacity-40 ${TONES[tone]} ${className}`}
                {...props}
            >
                <Icon className="size-[18px]" aria-hidden="true" />
            </button>
            <span
                role="tooltip"
                className="pointer-events-none absolute right-0 bottom-full z-20 mb-1.5 rounded-md bg-navy px-2 py-1 text-xs font-medium whitespace-nowrap text-white opacity-0 shadow-card transition-opacity duration-150 group-hover/tip:opacity-100 group-has-focus-visible/tip:opacity-100"
            >
                {label}
            </span>
        </span>
    );
}
