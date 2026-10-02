import { createContext, useCallback, useContext, useEffect, useRef, useState } from 'react';
import useTheme from '@/Hooks/useTheme';

const AccessContext = createContext(null);

const SVG_NS = 'http://www.w3.org/2000/svg';

/**
 * Shared state of the "Ava" screens: theme, toasts and the SVG lines that connect the
 * background sphere to the registered form fields.
 */
export function AccessProvider({ children }) {
    const { isDark, toggle } = useTheme();
    const [toasts, setToasts] = useState([]);
    const toastId = useRef(0);
    const svgRef = useRef(null);
    const sphereRef = useRef(null);
    const targets = useRef(new Set());

    const showToast = useCallback((message, type = 'info') => {
        const id = ++toastId.current;
        const patch = (show) => setToasts((list) => list.map((t) => (t.id === id ? { ...t, show } : t)));

        setToasts((list) => [...list, { id, message, type, show: false }]);
        requestAnimationFrame(() => patch(true));
        setTimeout(() => {
            patch(false);
            setTimeout(() => setToasts((list) => list.filter((t) => t.id !== id)), 400);
        }, 3000);
    }, []);

    const updateConnections = useCallback(() => {
        const svg = svgRef.current;
        const sphere = sphereRef.current?.getSphereScreen?.();
        if (!svg || !sphere) return;

        const elements = [...targets.current].filter((el) => el.isConnected);

        while (svg.children.length < elements.length) {
            const line = document.createElementNS(SVG_NS, 'line');
            ['x1', 'y1', 'x2', 'y2'].forEach((attr) => line.setAttribute(attr, 0));
            svg.appendChild(line);
        }
        while (svg.children.length > elements.length) {
            svg.removeChild(svg.lastChild);
        }

        elements.forEach((el, i) => {
            const rect = el.getBoundingClientRect();
            const line = svg.children[i];
            line.setAttribute('x1', sphere.x);
            line.setAttribute('y1', sphere.y);
            line.setAttribute('x2', rect.left + rect.width / 2);
            line.setAttribute('y2', rect.top + rect.height / 2);
        });
    }, []);

    const registerTarget = useCallback(
        (element) => {
            targets.current.add(element);
            updateConnections();

            return () => {
                targets.current.delete(element);
                updateConnections();
            };
        },
        [updateConnections],
    );

    useEffect(() => {
        const timers = [setTimeout(updateConnections, 100), setTimeout(updateConnections, 500)];

        window.addEventListener('resize', updateConnections);
        window.addEventListener('scroll', updateConnections);

        return () => {
            timers.forEach(clearTimeout);
            window.removeEventListener('resize', updateConnections);
            window.removeEventListener('scroll', updateConnections);
        };
    }, [updateConnections]);

    const toggleTheme = useCallback(() => {
        const dark = toggle();
        showToast(dark ? 'Modo Oscuro activado' : 'Modo Claro activado', 'success');
        requestAnimationFrame(updateConnections);
    }, [toggle, showToast, updateConnections]);

    return (
        <AccessContext.Provider
            value={{ isDark, toggleTheme, toasts, showToast, svgRef, sphereRef, registerTarget, updateConnections }}
        >
            {children}
        </AccessContext.Provider>
    );
}

export function useAccess() {
    const context = useContext(AccessContext);

    if (!context) {
        throw new Error('useAccess must be used inside <AccessProvider>');
    }

    return context;
}

/** Draw a line from the background sphere to this element (the form field). */
export function useConnectTarget(ref, enabled = true) {
    const { registerTarget } = useAccess();

    useEffect(() => {
        if (!enabled || !ref.current) return undefined;

        return registerTarget(ref.current);
    }, [ref, enabled, registerTarget]);
}
