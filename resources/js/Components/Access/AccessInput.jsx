import { useRef } from 'react';
import { useConnectTarget } from './AccessContext';

const stateClass = (message, valid) => (message ? 'error' : valid ? 'success' : '');

/** Label + control + error message block shared by the Ava form fields. */
function Field({ id, label, message, children }) {
    return (
        <div className="input-group">
            <label htmlFor={id}>{label}</label>
            {children}
            <div className={`error-message${message ? ' show' : ''}`}>{message}</div>
        </div>
    );
}

/**
 * `message` is the error text (shown when not empty); `valid` marks the field as filled correctly.
 * `connect` draws the SVG line from the background sphere to the input.
 */
export function AccessInput({ id, label, message = '', valid = false, connect = false, ...props }) {
    const ref = useRef(null);
    useConnectTarget(ref, connect);

    return (
        <Field id={id} label={label} message={message}>
            <input ref={ref} id={id} className={stateClass(message, valid)} {...props} />
        </Field>
    );
}

export function AccessPasswordInput({ id, label, message = '', valid = false, connect = false, visible, onToggle, ...props }) {
    const ref = useRef(null);
    useConnectTarget(ref, connect);

    return (
        <Field id={id} label={label} message={message}>
            <div className="password-wrapper">
                <input
                    ref={ref}
                    id={id}
                    className={stateClass(message, valid)}
                    type={visible ? 'text' : 'password'}
                    {...props}
                />
                <button type="button" className="password-toggle" onClick={onToggle} aria-label="Toggle password visibility">
                    {visible ? (
                        <svg viewBox="0 0 24 24">
                            <path d="M17.94 17.94A10.07 10.07 0 0112 20c-7 0-11-8-11-8a18.45 18.45 0 015.06-5.94" />
                            <path d="M9.9 4.24A9.12 9.12 0 0112 4c7 0 11 8 11 8a18.5 18.5 0 01-2.16 3.19" />
                            <path d="M14.12 14.12a3 3 0 11-4.24-4.24" />
                            <line x1="1" y1="1" x2="23" y2="23" />
                        </svg>
                    ) : (
                        <svg viewBox="0 0 24 24">
                            <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z" />
                            <circle cx="12" cy="12" r="3" />
                        </svg>
                    )}
                </button>
            </div>
        </Field>
    );
}
