/**
 * Les champs de formulaire, avec LE MESSAGE DU SERVEUR sous le bon champ.
 *
 * Le serveur rend ses erreurs de validation dans `data.erreurs`, indexées par
 * nom de champ et rédigées en français simple. Les afficher à leur place plutôt
 * que dans une bannière unique, c'est la différence entre « corrigez le
 * formulaire » et « il manque l'heure d'arrivée ».
 */

const baseChamp =
    'w-full rounded border border-ardoise-300 bg-white px-3 py-2 text-sm text-ardoise-900 '
    + 'placeholder:text-ardoise-400 focus:border-pnvb-500 focus:outline-none focus:ring-2 '
    + 'focus:ring-pnvb-200 disabled:bg-ardoise-50 disabled:text-ardoise-500';

export function Champ({ nom, libelle, erreurs, aide, children }) {
    const message = erreurs?.[nom]?.[0];

    return (
        <label className="block">
            <span className="text-sm font-medium text-ardoise-700">{libelle}</span>
            {aide && <span className="mt-0.5 block text-xs text-ardoise-500">{aide}</span>}
            <div className="mt-1.5">{children}</div>
            {message && (
                <span className="mt-1 block text-xs font-medium text-brique-700" role="alert">
                    {message}
                </span>
            )}
        </label>
    );
}

export function Saisie({ invalide, className = '', ...props }) {
    return (
        <input
            {...props}
            aria-invalid={invalide || undefined}
            className={`${baseChamp} ${invalide ? 'border-brique-400' : ''} ${className}`}
        />
    );
}

export function Liste({ invalide, className = '', children, ...props }) {
    return (
        <select
            {...props}
            aria-invalid={invalide || undefined}
            className={`${baseChamp} ${invalide ? 'border-brique-400' : ''} ${className}`}
        >
            {children}
        </select>
    );
}

export function Texte({ invalide, className = '', ...props }) {
    return (
        <textarea
            {...props}
            aria-invalid={invalide || undefined}
            className={`${baseChamp} min-h-24 ${invalide ? 'border-brique-400' : ''} ${className}`}
        />
    );
}

export function Bouton({ variante = 'principal', className = '', children, ...props }) {
    const variantes = {
        principal: 'bg-pnvb-700 text-white hover:bg-pnvb-800 disabled:bg-pnvb-300',
        secondaire:
            'border border-ardoise-300 bg-white text-ardoise-700 hover:bg-ardoise-50 '
            + 'disabled:text-ardoise-400',
        danger: 'bg-brique-700 text-white hover:bg-brique-800 disabled:bg-brique-300',
    };

    return (
        <button
            {...props}
            className={`inline-flex items-center justify-center gap-2 rounded px-4 py-2 text-sm
                font-medium transition disabled:cursor-not-allowed ${variantes[variante]} ${className}`}
        >
            {children}
        </button>
    );
}
