import { useAccess } from './AccessContext';

export default function ThemeToggle({ inline = false }) {
    const { isDark, toggleTheme } = useAccess();

    return (
        <button
            type="button"
            className={`theme-toggle${inline ? ' theme-toggle--inline' : ''}`}
            onClick={toggleTheme}
            aria-label="Toggle theme"
        >
            <span>{isDark ? '☀️' : '🌙'}</span>
        </button>
    );
}
