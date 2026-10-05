import { useEffect } from 'react';
import { brandVariables, isHexColor } from '@/lib/theme';

const DARK_QUERY = '(prefers-color-scheme: dark)';

/**
 * Applies the active Workspace's appearance to the whole document while the app shell is mounted:
 * its primary color as CSS variables and its light/dark/system mode as `data-app-theme` on <html>
 * (the dark tokens live in app.css). Everything is removed on unmount, so the access screens
 * (login) never inherit a Workspace's look.
 */
export default function useWorkspaceTheme(settings) {
    const appearance = settings?.appearance ?? 'light';
    const color = settings?.primaryColor;

    useEffect(() => {
        const root = document.documentElement;
        const media = window.matchMedia(DARK_QUERY);
        let applied = [];

        const apply = () => {
            const dark = appearance === 'dark' || (appearance === 'system' && media.matches);
            const variables = isHexColor(color ?? '') ? brandVariables(color, dark) : {};

            applied.forEach((name) => root.style.removeProperty(name));
            applied = Object.keys(variables);
            applied.forEach((name) => root.style.setProperty(name, variables[name]));
            root.dataset.appTheme = dark ? 'dark' : 'light';
        };

        apply();
        media.addEventListener('change', apply);

        return () => {
            media.removeEventListener('change', apply);
            applied.forEach((name) => root.style.removeProperty(name));
            delete root.dataset.appTheme;
        };
    }, [appearance, color]);
}
