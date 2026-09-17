import { Link } from 'react-router-dom';
import { useAuth } from '../auth/ContexteAuth';
import { useListe } from '../outils/crochets';
import { EnTetePage } from '../composants/Page';
import { Pagination, Pastille, Tableau } from '../composants/Tableau';
import { BarreFiltres, FiltreListe } from '../composants/Filtres';
import { Chargement } from '../composants/Chargement';
import { Echec, Vide } from '../composants/Etats';
import { date, nombre, nomDe } from '../outils/format';
import { statutVague, statutsVague } from '../domaine/vagues';

/**
 * LES VAGUES DE DÉPLOIEMENT.
 *
 * Le code de la vague ouvre sa fiche : c'est là que tout se passe — tirage,
 * relecture de la proposition, ajustement, validation, clôture.
 */
export function Vagues() {
    const auth = useAuth();
    const liste = useListe('vagues', '/vagues');

    return (
        <>
            <EnTetePage
                titre="Vagues et affectations"
                sousTitre="Une vague couvre une région : les mêmes équipes tournent ensuite vers la suivante."
                actions={
                    auth.peut('vagues.planifier') && (
                        <Link
                            to="/vagues/planifier"
                            className="inline-flex items-center rounded bg-pnvb-700 px-4 py-2 text-sm font-medium text-white hover:bg-pnvb-800"
                        >
                            Planifier une vague
                        </Link>
                    )
                }
            />

            <BarreFiltres onReinitialiser={liste.reinitialiser}>
                <FiltreListe
                    libelle="Statut"
                    valeur={liste.filtres.statut}
                    onChange={(v) => liste.changerFiltre('statut', v)}
                    options={Object.entries(statutsVague).map(([valeur, s]) => ({ valeur, libelle: s.libelle }))}
                />
            </BarreFiltres>

            {liste.isPending && <Chargement message="Chargement des vagues…" />}
            {liste.error && <Echec erreur={liste.error} onReessayer={liste.refetch} />}

            {!liste.isPending && !liste.error && (
                <>
                    <Tableau
                        cle={(v) => v.id}
                        lignes={liste.lignes}
                        vide={<Vide titre="Aucune vague planifiée" explication="Aucune vague n’est encore planifiée dans votre périmètre." />}
                        colonnes={[
                            {
                                cle: 'code',
                                titre: 'Code',
                                compact: true,
                                rendu: (v) => (
                                    <Link to={`/vagues/${v.id}`} className="font-mono font-medium text-pnvb-800 underline">
                                        {v.code}
                                    </Link>
                                ),
                            },
                            { cle: 'libelle', titre: 'Libellé' },
                            { cle: 'region', titre: 'Région', rendu: (v) => v.region?.nom ?? '—' },
                            {
                                cle: 'periode',
                                titre: 'Période prévue',
                                compact: true,
                                rendu: (v) => `${date(v.date_debut_prevue)} → ${date(v.date_fin_prevue)}`,
                            },
                            { cle: 'centres', titre: 'Centres', alignement: 'droite', rendu: (v) => nombre(v.centres_count) },
                            { cle: 'affectations', titre: 'Affectations', alignement: 'droite', rendu: (v) => nombre(v.affectations_count) },
                            {
                                cle: 'objectif',
                                titre: 'Objectif / kit / jour',
                                alignement: 'droite',
                                rendu: (v) => nombre(v.objectif_enregistrements_par_kit_jour),
                            },
                            {
                                cle: 'statut',
                                titre: 'Statut',
                                compact: true,
                                rendu: (v) => <Pastille ton={statutVague(v.statut).ton}>{statutVague(v.statut).libelle}</Pastille>,
                            },
                            { cle: 'cree_par', titre: 'Planifiée par', rendu: (v) => nomDe(v.cree_par) },
                        ]}
                    />
                    <Pagination page={liste.pagination} onPage={liste.setPage} />
                </>
            )}
        </>
    );
}
