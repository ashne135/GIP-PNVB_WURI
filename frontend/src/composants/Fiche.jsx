/**
 * LES BRIQUES D'UNE FICHE : un bloc de rubriques, une paire libellé-valeur,
 * une chronologie.
 *
 * Toutes les fiches de la plateforme — incident, rapport, kit, feuille de
 * présence — montrent la même chose : un objet, ses champs, et son historique.
 * Les écrire une fois évite que chaque module invente sa propre mise en forme.
 */

export function Bloc({ titre, precision, actions, children }) {
    return (
        <section className="rounded-lg border border-ardoise-200 bg-white shadow-sm">
            {(titre || actions) && (
                <header className="flex flex-wrap items-center justify-between gap-3 border-b border-ardoise-200 px-4 py-3">
                    <div>
                        <h2 className="text-sm font-semibold text-ardoise-900">{titre}</h2>
                        {precision && (
                            <p className="mt-0.5 text-xs text-ardoise-500">{precision}</p>
                        )}
                    </div>
                    {actions && <div className="flex flex-wrap gap-2">{actions}</div>}
                </header>
            )}
            <div className="px-4 py-4">{children}</div>
        </section>
    );
}

export function Rubriques({ children, colonnes = 2 }) {
    return (
        <dl
            className={`grid gap-x-6 gap-y-3 ${
                colonnes === 2 ? 'sm:grid-cols-2' : colonnes === 3 ? 'sm:grid-cols-3' : ''
            }`}
        >
            {children}
        </dl>
    );
}

export function Rubrique({ libelle, children, pleineLargeur }) {
    return (
        <div className={pleineLargeur ? 'sm:col-span-full' : undefined}>
            <dt className="text-xs font-medium uppercase tracking-wide text-ardoise-500">
                {libelle}
            </dt>
            <dd className="mt-0.5 text-sm text-ardoise-900">{children ?? '—'}</dd>
        </div>
    );
}

/**
 * LA CHRONOLOGIE.
 *
 * Une action dont l'auteur est absent vient de l'acteur SYSTÈME — une
 * notification, une escalade. Le dire explicitement évite de laisser croire
 * qu'une personne a agi.
 */
export function Chronologie({ evenements }) {
    if (!evenements || evenements.length === 0) {
        return <p className="text-sm text-ardoise-500">Aucun événement enregistré.</p>;
    }

    return (
        <ol className="space-y-4">
            {evenements.map((evenement, rang) => (
                <li key={evenement.cle ?? rang} className="relative pl-6">
                    <span
                        className={`absolute left-0 top-1.5 h-2.5 w-2.5 rounded-full ${
                            evenement.systeme ? 'bg-ardoise-400' : 'bg-pnvb-600'
                        }`}
                        aria-hidden="true"
                    />
                    {rang < evenements.length - 1 && (
                        <span
                            className="absolute left-[4px] top-5 h-[calc(100%+0.5rem)] w-px bg-ardoise-200"
                            aria-hidden="true"
                        />
                    )}
                    <p className="text-sm font-medium text-ardoise-900">{evenement.titre}</p>
                    {evenement.detail && (
                        <p className="mt-0.5 whitespace-pre-line text-sm text-ardoise-600">
                            {evenement.detail}
                        </p>
                    )}
                    <p className="mt-0.5 text-xs text-ardoise-500">
                        {evenement.quand}
                        {' · '}
                        {evenement.systeme ? 'acteur système' : (evenement.qui ?? 'auteur inconnu')}
                    </p>
                </li>
            ))}
        </ol>
    );
}
