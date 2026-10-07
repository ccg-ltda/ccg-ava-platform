import { Bot } from 'lucide-react';

/** The avatar of a chatbot (its own image, not the logo of Ava), or a neutral bot icon while it has none. */
export default function ChatbotAvatar({ url, name, className = 'size-12' }) {
    return (
        <span className={`grid shrink-0 place-items-center overflow-hidden rounded-xl bg-primary/15 text-accent-blue ${className}`}>
            {url ? <img src={url} alt={`Avatar de ${name}`} className="size-full object-cover" /> : <Bot className="size-1/2" aria-hidden="true" />}
        </span>
    );
}
