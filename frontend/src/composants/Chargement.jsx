/** Attente annoncée plutôt que subie : on dit CE QU'ON attend. */
export function Chargement({ message = 'Chargement…' }) {
    return (
        <div className="flex items-center justify-center gap-3 py-16 text-sm text-ardoise-500">
            <span
                className="h-4 w-4 animate-spin rounded-full border-2 border-pnvb-200 border-t-pnvb-600"
                aria-hidden="true"
            />
            <span role="status">{message}</span>
        </div>
    );
}
