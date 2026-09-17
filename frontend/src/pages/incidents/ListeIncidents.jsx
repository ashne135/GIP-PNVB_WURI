import { Link } from 'react-router-dom';
import { useListe } from '../../outils/crochets';
import { EnTetePage } from '../../composants/Page';
import { Pagination, Pastille, Tableau } from '../../composants/Tableau';
import { BarreFiltres, FiltreDate, FiltreListe } from '../../composants/Filtres';
import { Chargement } from '../../composants/Chargement';
import { Echec, Vide } from '../../composants/Etats';
import { dateHeure } from '../../outils/format';
import { gravite, statutIncident, statutsIncident } from '../../domaine/gravite';

/**
 * LA LISTE DES INCIDENTS.
 *
 * Triée par le serveur du plus grave et du plus récent : c'est l'ordre dans
 * lequel on veut les lire quand il y en a beaucoup. Le périmètre est appliqué
 * côté serveur — un déclarant ne voit que les siens, un chef d'antenne ceux de
 * sa région.
 */
export function ListeIncidents() {
    const liste = useListe('incidents', '/incidents', { ouverts: '1' });

    return (
        <>
            <EnTetePage
                titre="Incidents"
                sousTitre="Les plus graves et les plus récents en premier."
                actions={
                    <Link
                        to="/incidents/en-retard"
                        className="rounded border border-ocre-400 bg-ocre-50 px-3 py-2 text-sm font-medium text-ocre-900 hover:bg-ocre-100"
                    >
                        En attente de prise en charge
                    </Link>
                }
            />

            <BarreFiltres onReinitialiser={liste.reinitialiser}>
                <FiltreListe
                    libelle="Statut"
                    valeur={liste.filtres.statut}
                    onChange={(v) => liste.changerFiltre('statut', v)}
                    options={Object.entries(statutsIncident).map(([valeur, s]) => ({
                        valeur,
                        libelle: s.libelle,
                    }))}
                />
                <FiltreListe
                    libelle="Gravité"
                    valeur={liste.filtres.gravite}
                    onChange={(v) => liste.changerFiltre('gravite', v)}
                    options={[4, 3, 2, 1].map((n) => ({
                        valeur: String(n),
                        libelle: `${n} — ${gravite(n).libelle}`,
                    }))}
                />
                <FiltreListe
                    libelle="Ouverture"
                    valeur={liste.filtres.ouverts}
                    onChange={(v) => liste.changerFiltre('ouverts', v)}
                    tous="Tous, clos compris"
                    options={[{ valeur: '1', libelle: 'Non résolus seulement' }]}
                />
                <FiltreDate
                    libelle="Déclarés à partir du"
                    valeur={liste.filtres.du}
                    onChange={(v) => liste.changerFiltre('du', v)}
                />
            </BarreFiltres>

            {liste.isPending && <Chargement message="Chargement des incidents…" />}
            {liste.error && <Echec erreur={liste.error} onReessayer={liste.refetch} />}

            {!liste.isPending && !liste.error && (
                <>
                    <Tableau
                        cle={(i) => i.id}
                        lignes={liste.lignes}
                        vide={
                            <Vide
                                titre="Aucun incident ne correspond"
                                explication={
                                    'Aucun incident de votre périmètre ne répond à ces filtres. '
                                    + 'C’est une bonne nouvelle plus souvent qu’un problème.'
                                }
                            />
                        }
                        colonnes={[
                            {
                                cle: 'numero',
                                titre: 'Numéro',
                                compact: true,
                                rendu: (i) => (
                                    <Link
                                        to={`/incidents/${i.id}`}
                                        className="font-medium text-pnvb-800 underline"
                                    >
                                        {i.numero}
                                    </Link>
                                ),
                            },
                            {
                                cle: 'gravite',
                                titre: 'Gravité',
                                compact: true,
                                rendu: (i) => (
                                    <Pastille ton={gravite(i.gravite).ton}>
                                        {i.gravite} — {gravite(i.gravite).libelle}
                                    </Pastille>
                                ),
                            },
                            {
                                cle: 'natures',
                                titre: 'Nature',
                                rendu: (i) =>
                                    (i.natures ?? []).map((n) => n.libelle).join(', ') || '—',
                            },
                            {
                                cle: 'lieu',
                                titre: 'Lieu',
                                rendu: (i) => i.site?.nom ?? i.centre?.nom ?? i.region?.nom ?? '—',
                            },
                            {
                                cle: 'declare_le',
                                titre: 'Déclaré',
                                compact: true,
                                rendu: (i) => dateHeure(i.declare_le),
                            },
                            {
                                cle: 'statut',
                                titre: 'Traitement',
                                compact: true,
                                rendu: (i) => (
                                    <span className="flex items-center gap-2">
                                        <Pastille ton={statutIncident(i.statut).ton}>
                                            {statutIncident(i.statut).libelle}
                                        </Pastille>
                                        {i.niveau_escalade > 0 && (
                                            <Pastille ton="alerte">
                                                escaladé ×{i.niveau_escalade}
                                            </Pastille>
                                        )}
                                    </span>
                                ),
                            },
                        ]}
                    />
                    <Pagination page={liste.pagination} onPage={liste.setPage} />
                </>
            )}
        </>
    );
}
