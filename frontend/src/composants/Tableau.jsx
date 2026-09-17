/**
 * Le tableau du back-office.
 *
 * Il roule horizontalement dans SON PROPRE conteneur : sur 2 778 volontaires et
 * une dizaine de colonnes, c'est le tableau qui déborde, jamais la page.
 */
export function Tableau({ colonnes, lignes, cle, vide, onLigne }) {
    if (!lignes || lignes.length === 0) {
        return vide ?? null;
    }

    return (
        <div className="overflow-x-auto rounded-lg border border-ardoise-200 bg-white shadow-sm">
            <table className="min-w-full divide-y divide-ardoise-200 text-sm">
                <thead className="bg-ardoise-50">
                    <tr>
                        {colonnes.map((colonne) => (
                            <th
                                key={colonne.cle}
                                scope="col"
                                className={`whitespace-nowrap px-3 py-2.5 text-left text-xs font-semibold
                                    uppercase tracking-wide text-ardoise-600 ${
                                        colonne.alignement === 'droite' ? 'text-right' : ''
                                    }`}
                            >
                                {colonne.titre}
                            </th>
                        ))}
                    </tr>
                </thead>
                <tbody className="divide-y divide-ardoise-100">
                    {lignes.map((ligne, rang) => (
                        <tr
                            key={cle ? cle(ligne) : rang}
                            onClick={onLigne ? () => onLigne(ligne) : undefined}
                            className={onLigne ? 'cursor-pointer hover:bg-pnvb-50' : undefined}
                        >
                            {colonnes.map((colonne) => (
                                <td
                                    key={colonne.cle}
                                    className={`px-3 py-2.5 text-ardoise-800 ${
                                        colonne.alignement === 'droite'
                                            ? 'text-right tabular-nums'
                                            : ''
                                    } ${colonne.compact ? 'whitespace-nowrap' : ''}`}
                                >
                                    {colonne.rendu ? colonne.rendu(ligne) : ligne[colonne.cle]}
                                </td>
                            ))}
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}

/** La pagination de Laravel, telle qu'elle arrive dans `data`. */
export function Pagination({ page, onPage }) {
    if (!page || !page.last_page || page.last_page <= 1) {
        return null;
    }

    return (
        <div className="flex flex-wrap items-center justify-between gap-3 py-3 text-sm">
            <p className="text-ardoise-600">
                {page.from}–{page.to} sur <span className="font-semibold">{page.total}</span>
            </p>
            <div className="flex gap-2">
                <button
                    type="button"
                    disabled={page.current_page <= 1}
                    onClick={() => onPage(page.current_page - 1)}
                    className="rounded border border-ardoise-300 px-3 py-1.5 disabled:text-ardoise-300"
                >
                    Précédent
                </button>
                <span className="px-2 py-1.5 text-ardoise-600">
                    Page {page.current_page} sur {page.last_page}
                </span>
                <button
                    type="button"
                    disabled={page.current_page >= page.last_page}
                    onClick={() => onPage(page.current_page + 1)}
                    className="rounded border border-ardoise-300 px-3 py-1.5 disabled:text-ardoise-300"
                >
                    Suivant
                </button>
            </div>
        </div>
    );
}

/**
 * Une pastille d'état.
 * LA COULEUR DOUBLE LE MOT, elle ne le remplace jamais : un état ne doit pas
 * se lire à la seule teinte.
 */
export function Pastille({ ton = 'neutre', children }) {
    const tons = {
        neutre: 'bg-ardoise-100 text-ardoise-700',
        bon: 'bg-vert-100 text-vert-800',
        attention: 'bg-ocre-100 text-ocre-800',
        alerte: 'bg-brique-100 text-brique-800',
        info: 'bg-pnvb-100 text-pnvb-800',
    };

    return (
        <span className={`inline-flex items-center rounded px-2 py-0.5 text-xs font-medium ${tons[ton]}`}>
            {children}
        </span>
    );
}
