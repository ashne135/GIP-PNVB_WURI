import { Link } from 'react-router-dom';
import { useAuth } from '../../auth/ContexteAuth';
import { useListe, useTelechargement } from '../../outils/crochets';
import { avecParametres } from '../../api/client';
import { EnTetePage } from '../../composants/Page';
import { Pagination, Pastille, Tableau } from '../../composants/Tableau';
import { BarreFiltres, FiltreDate, FiltreListe } from '../../composants/Filtres';
import { Chargement } from '../../composants/Chargement';
import { Echec, Vide } from '../../composants/Etats';
import { date, nomDe } from '../../outils/format';
import { statutRapport, statutsRapport, typeRapport, typesRapport } from '../../domaine/rapports';

/**
 * LA LISTE DES RAPPORTS JOURNALIERS.
 *
 * Le périmètre est appliqué côté serveur : un agent voit les siens et ceux
 * qu'il doit viser, un superviseur ceux de ses centres.
 */
export function ListeRapports() {
    const auth = useAuth();
    const liste = useListe('rapports', '/rapports');
    const export_ = useTelechargement();

    return (
        <>
            <EnTetePage
                titre="Rapports journaliers"
                sousTitre="Chaîne de visas : A-OPK → opérateur → superviseur → contrôleur terrain."
                actions={
                    <>
                        <Link
                            to="/rapports/a-viser"
                            className="rounded border border-ocre-400 bg-ocre-50 px-3 py-2 text-sm font-medium text-ocre-900 hover:bg-ocre-100"
                        >
                            À viser
                        </Link>
                        {auth.peut('rapports.exporter') && (
                            <button
                                type="button"
                                disabled={export_.enCours}
                                onClick={() =>
                                    export_.telecharger(
                                        avecParametres('/rapports/export/csv', liste.filtres),
                                        'rapports.csv',
                                    )
                                }
                                className="rounded border border-ardoise-300 bg-white px-3 py-2 text-sm hover:bg-ardoise-50"
                            >
                                {export_.enCours ? 'Export…' : 'Exporter (tableur)'}
                            </button>
                        )}
                    </>
                }
            />

            {export_.erreur && <Echec erreur={export_.erreur} />}

            <BarreFiltres onReinitialiser={liste.reinitialiser}>
                <FiltreListe
                    libelle="Niveau"
                    valeur={liste.filtres.type}
                    onChange={(v) => liste.changerFiltre('type', v)}
                    options={Object.entries(typesRapport).map(([valeur, t]) => ({
                        valeur,
                        libelle: t.libelle,
                    }))}
                />
                <FiltreListe
                    libelle="Statut"
                    valeur={liste.filtres.statut}
                    onChange={(v) => liste.changerFiltre('statut', v)}
                    options={Object.entries(statutsRapport).map(([valeur, s]) => ({
                        valeur,
                        libelle: s.libelle,
                    }))}
                />
                <FiltreDate
                    libelle="Journée"
                    valeur={liste.filtres.date}
                    onChange={(v) => liste.changerFiltre('date', v)}
                />
            </BarreFiltres>

            {liste.isPending && <Chargement message="Chargement des rapports…" />}
            {liste.error && <Echec erreur={liste.error} onReessayer={liste.refetch} />}

            {!liste.isPending && !liste.error && (
                <>
                    <Tableau
                        cle={(r) => r.id}
                        lignes={liste.lignes}
                        vide={
                            <Vide
                                titre="Aucun rapport ne correspond"
                                explication="Aucun rapport de votre périmètre ne répond à ces filtres."
                            />
                        }
                        colonnes={[
                            {
                                cle: 'date_rapport',
                                titre: 'Journée',
                                compact: true,
                                rendu: (r) => (
                                    <Link
                                        to={`/rapports/${r.id}`}
                                        className="font-medium text-pnvb-800 underline"
                                    >
                                        {date(r.date_rapport)}
                                    </Link>
                                ),
                            },
                            {
                                cle: 'type',
                                titre: 'Niveau',
                                compact: true,
                                rendu: (r) => typeRapport(r.type).court,
                            },
                            {
                                cle: 'auteur',
                                titre: 'Auteur',
                                rendu: (r) =>
                                    r.auteur
                                        ? `${r.auteur.matricule} — ${nomDe(r.auteur.user)}`
                                        : '—',
                            },
                            {
                                cle: 'lieu',
                                titre: 'Lieu',
                                rendu: (r) => r.site?.nom ?? r.centre?.nom ?? '—',
                            },
                            {
                                cle: 'statut',
                                titre: 'État',
                                compact: true,
                                rendu: (r) => (
                                    <Pastille ton={statutRapport(r.statut).ton}>
                                        {statutRapport(r.statut).libelle}
                                    </Pastille>
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
