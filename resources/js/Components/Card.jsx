/** White surface with thin border, soft shadow and a short entrance. `hover` adds a subtle lift. */
export default function Card({ as: Tag = 'section', hover = false, delay = 0, className = '', style, children, ...props }) {
    const lift = hover ? 'transition duration-200 hover:shadow-card-hover motion-safe:hover:-translate-y-0.5' : '';

    return (
        <Tag
            className={`rounded-card border border-line bg-card shadow-card motion-safe:animate-fade-up ${lift} ${className}`}
            style={{ animationDelay: `${delay}ms`, ...style }}
            {...props}
        >
            {children}
        </Tag>
    );
}
