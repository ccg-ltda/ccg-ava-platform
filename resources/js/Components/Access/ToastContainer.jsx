import { useAccess } from './AccessContext';

export default function ToastContainer() {
    const { toasts } = useAccess();

    return (
        <div className="toast-container">
            {toasts.map((toast) => (
                <div key={toast.id} className={`toast ${toast.type}${toast.show ? ' show' : ''}`}>
                    {toast.message}
                </div>
            ))}
        </div>
    );
}
