import Tooltip from './Tooltip';

const TONES = {
    primary: 'text-primary hover:bg-primary-soft focus-visible:outline-primary',
    danger: 'text-danger hover:bg-danger/10 focus-visible:outline-danger',
};

/**
 * Icon-only action for table rows. `label` is the tooltip text ("Editar", "Desactivar"...); `context`
 * (e.g. the row name) is appended to the accessible name so screen readers hear what it acts on.
 * The tooltip shows on hover, on keyboard focus and on touch (a tap keeps it open for a moment), never as permanent text. It is drawn by the shared
 * `Tooltip` in a portal next to the button, so a card, a table or a list with `overflow: hidden` can not clip it or hide it behind a neighbour.
 */
export default function IconButton({ icon: Icon, label, context, tone = 'primary', className = '', ...props }) {
    return (
        <Tooltip content={label}>
            {(describedBy) => (
                <button
                    type="button"
                    aria-label={context ? `${label} ${context}` : label}
                    className={`grid size-9 place-items-center rounded-lg transition-colors duration-150 focus-visible:outline-2 disabled:cursor-not-allowed disabled:opacity-40 ${TONES[tone]} ${className}`}
                    {...describedBy}
                    {...props}
                >
                    <Icon className="size-[18px]" aria-hidden="true" />
                </button>
            )}
        </Tooltip>
    );
}
