/** The initials of a name ("Ana Pérez" -> "AP"), the fallback of every avatar. */
export const initials = (name = '') =>
    name
        .split(' ')
        .filter(Boolean)
        .slice(0, 2)
        .map((part) => part[0].toUpperCase())
        .join('');

/**
 * A person's avatar: their own photo when there is one, their initials otherwise. `className` sets the size and the colors of the fallback; the photo
 * fills the circle. The photo URL is the application's own authenticated route, never a public file.
 */
export default function Avatar({ name, photoUrl, className = 'size-8 text-xs' }) {
    if (photoUrl) {
        return <img src={photoUrl} alt="" className={`shrink-0 rounded-full object-cover ${className}`} />;
    }

    return (
        <span className={`grid shrink-0 place-items-center rounded-full font-bold ${className}`} aria-hidden="true">
            {initials(name)}
        </span>
    );
}
