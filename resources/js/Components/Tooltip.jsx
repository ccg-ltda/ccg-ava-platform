import { useCallback, useEffect, useId, useLayoutEffect, useRef, useState } from 'react';
import { createPortal } from 'react-dom';

const GAP = 8;
const MARGIN = 8;
const TOUCH_MS = 3000;

/**
 * Short explanation shown over a control. It opens with the mouse (hover), the keyboard (focus) and touch (a tap keeps it
 * open for a moment), closes with Escape, and never takes pointer events, so it cannot block the control. The bubble is
 * drawn in a portal with fixed position, so no ancestor with `overflow` clips it; it goes above the control and flips
 * below when there is no room. The text is also in the page for assistive technology (`aria-describedby`).
 *
 * `children` is a node, or a function receiving the props the focusable control needs (`{ 'aria-describedby' }`).
 * `content` may change while open (a slider's description follows its value).
 */
export default function Tooltip({ content, children, className = '' }) {
    const id = useId();
    const anchor = useRef(null);
    const bubble = useRef(null);
    const touchTimer = useRef(null);
    const [open, setOpen] = useState(false);
    const [place, setPlace] = useState(null);

    const close = useCallback(() => {
        clearTimeout(touchTimer.current);
        setOpen(false);
    }, []);

    const position = useCallback(() => {
        if (!anchor.current || !bubble.current) return;

        const target = anchor.current.getBoundingClientRect();
        const { width, height } = bubble.current.getBoundingClientRect();
        const above = target.top - height - GAP >= MARGIN;
        const left = Math.min(Math.max(target.left + target.width / 2 - width / 2, MARGIN), Math.max(window.innerWidth - width - MARGIN, MARGIN));

        setPlace({ top: above ? target.top - height - GAP : target.bottom + GAP, left });
    }, []);

    useLayoutEffect(() => {
        if (open) position();
        else setPlace(null);
    }, [open, content, position]);

    useEffect(() => {
        if (!open) return undefined;

        const onKey = (event) => event.key === 'Escape' && close();
        const onOutside = (event) => !anchor.current?.contains(event.target) && close();

        window.addEventListener('scroll', position, true);
        window.addEventListener('resize', position);
        document.addEventListener('keydown', onKey);
        document.addEventListener('pointerdown', onOutside);

        return () => {
            window.removeEventListener('scroll', position, true);
            window.removeEventListener('resize', position);
            document.removeEventListener('keydown', onKey);
            document.removeEventListener('pointerdown', onOutside);
        };
    }, [open, position, close]);

    useEffect(() => () => clearTimeout(touchTimer.current), []);

    const onPointerDown = (event) => {
        if (event.pointerType === 'mouse') return;

        // A tap (or a finger on a slider) shows it and keeps it a few seconds after the last touch.
        clearTimeout(touchTimer.current);
        setOpen(true);
        touchTimer.current = setTimeout(close, TOUCH_MS);
    };

    if (!content) return typeof children === 'function' ? children({}) : children;

    return (
        <span
            ref={anchor}
            className={`relative inline-flex max-w-full ${className}`}
            onPointerEnter={(event) => event.pointerType === 'mouse' && setOpen(true)}
            onPointerLeave={(event) => event.pointerType === 'mouse' && close()}
            onPointerDown={onPointerDown}
            onFocus={() => setOpen(true)}
            onBlur={close}
        >
            {typeof children === 'function' ? children({ 'aria-describedby': id }) : children}
            <span id={id} className="sr-only">
                {content}
            </span>
            {open &&
                createPortal(
                    <span
                        ref={bubble}
                        role="tooltip"
                        aria-hidden="true"
                        style={{ top: place?.top ?? 0, left: place?.left ?? 0, visibility: place ? 'visible' : 'hidden' }}
                        className="pointer-events-none fixed z-[80] w-max max-w-[min(20rem,calc(100vw-1rem))] rounded-lg bg-ink px-3 py-2 text-xs leading-snug font-medium text-card shadow-card-hover"
                    >
                        {content}
                    </span>,
                    document.body,
                )}
        </span>
    );
}
