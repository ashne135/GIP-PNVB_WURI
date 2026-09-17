/** L'en-tête d'une page : son titre, ce qu'elle montre, et ses actions. */
export function EnTetePage({ titre, sousTitre, actions }) {
    return (
        <div className="flex flex-wrap items-end justify-between gap-4 border-b border-ardoise-200 pb-4">
            <div>
                <h1 className="text-xl font-semibold tracking-tight text-ardoise-900">{titre}</h1>
                {sousTitre && <p className="mt-1 max-w-prose text-sm text-ardoise-500">{sousTitre}</p>}
            </div>
            {actions && <div className="flex flex-wrap gap-2">{actions}</div>}
        </div>
    );
}

/**
 * Un indicateur chiffré, avec ce qu'il faut pour le comprendre.
 *
 * Le filet coloré à gauche reprend l'identité ; il ne porte aucune information.
 * Ce qui porte l'information, c'est le libellé, le chiffre, et la précision
 * en dessous — un indicateur sans sa précision se lit de travers : « effectif
 * simultané » n'a aucun sens si l'on ne sait pas que c'est un pic régional.
 */
export function Indicateur({ libelle, valeur, precision, ton = 'neutre' }) {
    const tons = {
        neutre: 'text-ardoise-900',
        bon: 'text-vert-700',
        attention: 'text-ocre-700',
        alerte: 'text-brique-700',
    };

    return (
        <div className="rounded-lg border border-ardoise-200 border-l-4 border-l-pnvb-600 bg-white px-4 py-3 shadow-sm">
            <p className="text-xs font-semibold uppercase tracking-wide text-ardoise-500">
                {libelle}
            </p>
            <p className={`mt-1 text-2xl font-semibold tabular-nums ${tons[ton]}`}>{valeur}</p>
            {precision && <p className="mt-1 text-xs leading-snug text-ardoise-500">{precision}</p>}
        </div>
    );
}
