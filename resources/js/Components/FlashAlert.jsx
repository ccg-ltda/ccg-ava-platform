import { usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import Alert from './Alert';

/** Success message flashed by the server after an action (`flash.success`). Disappears by itself. */
export default function FlashAlert() {
    const message = usePage().props.flash?.success;
    const [visible, setVisible] = useState(false);

    useEffect(() => {
        if (!message) return undefined;

        setVisible(true);
        const timer = setTimeout(() => setVisible(false), 4500);

        return () => clearTimeout(timer);
    }, [message]);

    if (!message || !visible) return null;

    return (
        <Alert tone="success" className="motion-safe:animate-fade-in">
            {message}
        </Alert>
    );
}
