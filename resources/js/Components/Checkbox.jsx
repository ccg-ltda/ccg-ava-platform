export default function Checkbox({ className = '', ...props }) {
    return (
        <input
            {...props}
            type="checkbox"
            className={
                'size-4 rounded border border-field accent-primary focus-visible:outline-2 focus-visible:outline-primary ' +
                className
            }
        />
    );
}
