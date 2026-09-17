import { useListe } from '../../outils/crochets';
import { EnTetePage } from '../../composants/Page';
import { Pagination, Pastille, Tableau } from '../../composants/Tableau';
import { BarreFiltres, FiltreDate, FiltreTexte } from '../../composants/Filtres';
import { Chargement } from '../../composants/Chargement';
import { Echec, Vide } from '../../composants/Etats';
import { dateHeure, nombre, nomDe } from '../../outils/format';
import { libelleDe, typesSynchronisation } from '../../domaine/suivi';

/**
 * LES SYNCHRONISATIONS DES TÉLÉPHONES — l'outil de diagnostic du DSI.
 *
 * Il répond à « l'agent dit avoir envoyé son rapport : est-ce arrivé ? ». Un
 * lot refusé en partie dit, élément par élément, ce qui n'est pas passé et si
 * le téléphone doit réessayer.
 *
 * LE CONTENU DES ÉLÉMENTS N'EST JAMAIS AFFICHÉ, et le serveur ne le rend pas :
 * un relevé de position refusé apparaît comme tel, sans coordonnée.
 */
export function Synchronisations() {
    const liste = useListe('synchronisations', '/sync/supervision');

    return (
        <>
            <EnTetePage
                titre="Synchronisations"
                sousTitre="Ce que chaque téléphone a envoyé, ce qui a été enregistré et ce qui a été refusé — sans jamais le contenu."
            />

            <BarreFiltres onReinitialiser={liste.reinitialiser}>
                <FiltreTexte
                    libelle="Agent"
                    valeur={liste.filtres.recherche}
                    onChange={(v) => liste.changerFiltre('recherche', v)}
                    placeholder="Nom, téléphone ou matricule"
                />
                <FiltreDate libelle="Du" valeur={liste.filtres.du} onChange={(v) => liste.changerFiltre('du', v)} />
                <FiltreDate libelle="Au" valeur={liste.filtres.au} onChange={(v) => liste.changerFiltre('au', v)} />
                <label className="flex items-center gap-2 self-end pb-2 text-sm text-ardoise-800">
                    <input
                        type="checkbox"
                        checked={Boolean(liste.filtres.avec_rejets)}
                        onChange={(e) => liste.changerFiltre('avec_rejets', e.target.checked ? 1 : undefined)}
                        className="h-4 w-4"
                    />
                    Seulement les envois avec un refus
                </label>
            </BarreFiltres>

            {liste.isPending && <Chargement message="Chargement des synchronisations…" />}
            {liste.error && <Echec erreur={liste.error} onReessayer={liste.refetch} />}

            {!liste.isPending && !liste.error && (
                <>
                    <Tableau
                        cle={(l) => l.id}
                        lignes={liste.lignes}
                        vide={<Vide titre="Aucune synchronisation" explication="Aucun téléphone n’a envoyé de données pour ces filtres." />}
                        colonnes={[
                            { cle: 'recu', titre: 'Reçu le', compact: true, rendu: (l) => dateHeure(l.recu_le) },
                            {
                                cle: 'agent',
                                titre: 'Agent',
                                rendu: (l) => (
                                    <>
                                        <span className="block">{nomDe(l.user)}</span>
                                        <span className="text-xs text-ardoise-500">
                                            {[l.user?.matricule, l.user?.telephone].filter(Boolean).join(' · ')}
                                        </span>
                                    </>
                                ),
                            },
                            { cle: 'elements', titre: 'Éléments', alignement: 'droite', rendu: (l) => nombre(l.nb_elements) },
                            { cle: 'acceptes', titre: 'Enregistrés', alignement: 'droite', rendu: (l) => nombre(l.nb_acceptes) },
                            {
                                cle: 'rejetes',
                                titre: 'Refusés',
                                compact: true,
                                rendu: (l) => (l.nb_rejetes > 0
                                    ? <Pastille ton="alerte">{nombre(l.nb_rejetes)}</Pastille>
                                    : <Pastille ton="bon">0</Pastille>),
                            },
                            {
                                cle: 'detail',
                                titre: 'Motif des refus',
                                rendu: (l) => (l.rejets?.length
                                    ? (
                                        <ul className="space-y-1 text-xs">
                                            {l.rejets.map((r) => (
                                                <li key={`${r.rang}-${r.type}`}>
                                                    <span className="font-medium">{libelleDe(typesSynchronisation, r.type)}</span>
                                                    {' · '}{r.code_libelle ?? r.code}
                                                    {r.motif ? ` — ${r.motif}` : ''}
                                                    {r.reessayer && <span className="text-ocre-800"> (le téléphone réessaiera)</span>}
                                                </li>
                                            ))}
                                        </ul>
                                    )
                                    : <span className="text-ardoise-500">—</span>),
                            },
                            {
                                cle: 'duree',
                                titre: 'Durée',
                                alignement: 'droite',
                                rendu: (l) => (l.duree_ms != null ? `${nombre(l.duree_ms)} ms` : '—'),
                            },
                        ]}
                    />
                    <Pagination page={liste.pagination} onPage={liste.setPage} />
                </>
            )}
        </>
    );
}
