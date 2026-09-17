import { Liste, Saisie } from './Champs';

/**
 * LA BARRE DE FILTRES.
 *
 * Elle se replie sur une colonne au téléphone : le back-office se consulte
 * aussi depuis une antenne régionale, sur l'écran qu'on a sous la main.
 */
export function BarreFiltres({ children, onReinitialiser }) {
    return (
        <div className="flex flex-wrap items-end gap-3 rounded-lg border border-ardoise-200 bg-white px-4 py-3 shadow-sm">
            {children}
            {onReinitialiser && (
                <button
                    type="button"
                    onClick={onReinitialiser}
                    className="ml-auto self-end text-sm text-ardoise-600 underline hover:text-ardoise-800"
                >
                    Tout effacer
                </button>
            )}
        </div>
    );
}

export function FiltreListe({ libelle, valeur, onChange, options, tous = 'Tous' }) {
    return (
        <label className="block min-w-40">
            <span className="mb-1 block text-xs font-medium text-ardoise-600">{libelle}</span>
            <Liste value={valeur ?? ''} onChange={(e) => onChange(e.target.value)}>
                <option value="">{tous}</option>
                {options.map((option) => (
                    <option key={option.valeur} value={option.valeur}>
                        {option.libelle}
                    </option>
                ))}
            </Liste>
        </label>
    );
}

export function FiltreDate({ libelle, valeur, onChange }) {
    return (
        <label className="block">
            <span className="mb-1 block text-xs font-medium text-ardoise-600">{libelle}</span>
            <Saisie type="date" value={valeur ?? ''} onChange={(e) => onChange(e.target.value)} />
        </label>
    );
}

export function FiltreTexte({ libelle, valeur, onChange, placeholder }) {
    return (
        <label className="block min-w-48">
            <span className="mb-1 block text-xs font-medium text-ardoise-600">{libelle}</span>
            <Saisie
                type="search"
                value={valeur ?? ''}
                placeholder={placeholder}
                onChange={(e) => onChange(e.target.value)}
            />
        </label>
    );
}
