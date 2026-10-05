import { forwardRef } from 'react';

/** Multi-line field with the same look as TextInput (`.field`). */
export default forwardRef(function Textarea({ className = '', invalid = false, rows = 3, ...props }, ref) {
    return <textarea {...props} ref={ref} rows={rows} aria-invalid={invalid || undefined} className={`field resize-y ${className}`} />;
});
