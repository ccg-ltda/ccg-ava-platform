import { useCallback, useState } from 'react';

const STORAGE_KEY = 'ccg-theme';

const isDarkNow = () => typeof document !== 'undefined' && document.documentElement.classList.contains('dark');

/**
 * Light/dark theme stored as a class on <html> (set before first paint by resources/views/app.blade.php)
 * and persisted in localStorage under `ccg-theme`.
 */
export default function useTheme() {
    const [isDark, setIsDark] = useState(isDarkNow);

    const toggle = useCallback(() => {
        const dark = !isDarkNow();
        const root = document.documentElement;

        root.classList.remove(dark ? 'light' : 'dark');
        root.classList.add(dark ? 'dark' : 'light');

        try {
            localStorage.setItem(STORAGE_KEY, dark ? 'dark' : 'light');
        } catch {
            // storage may be unavailable (private mode); the theme still applies for this page
        }

        setIsDark(dark);

        return dark;
    }, []);

    return { isDark, toggle };
}
