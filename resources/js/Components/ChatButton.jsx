import { MessageCircle } from 'lucide-react';

/** Floating chat button of Ava. Visual only for now (no behavior yet). */
export default function ChatButton() {
    return (
        <button
            type="button"
            aria-label="Chat de Ava"
            title="Chat de Ava"
            className="fixed right-5 bottom-5 z-30 grid size-14 place-items-center rounded-full bg-linear-to-br from-accent-blue to-accent-violet text-white shadow-card-hover transition duration-200 hover:scale-105 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent-violet"
        >
            <MessageCircle className="size-6" aria-hidden="true" />
        </button>
    );
}
