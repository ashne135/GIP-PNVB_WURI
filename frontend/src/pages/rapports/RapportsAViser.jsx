import { Link } from 'react-router-dom';
import { useListe } from '../../outils/crochets';
import { EnTetePage } from '../../composants/Page';
import { Pagination, Tableau } from '../../composants/Tableau';
import { Chargement } from '../../composants/Chargement';
import { Echec, Vide } from '../../composants/Etats';
import { date, dateHeure, nombre, nomDe } from '../../outils/format';
import { typeRapport } from '../../domaine/rapports';

/**
 * CE QUE J'AI À VISER.
 *
 * Le serveur ne rend ici que les rapports dont je suis LE SUPÉRIEUR DÉSIGNÉ —
 * pas tous ceux que ma permission m'autoriserait à voir. Un opérateur ne vise
 * pas le rapport d'un A-OPK qui n'est pas le sien.
 */
export function RapportsAViser() {
    const liste = useListe('rapports-a-viser', '/rapports/a-viser');

    return (
        <>
            <EnTetePage
                titre="Rapports à viser"
                sousTitre="Vos agents ont signé : leurs chiffres ne remonteront qu’après votre visa."
                actions={
                    <Link
                        to="/rapports"
                        className="rounded border border-ardoise-300 bg-white px-3 py-2 text-sm hover:bg-ardoise-50"
                    >
                        Tous les rapports
                    </Link>
                }
            />

            {liste.isPending && <Chargement message="Recherche des rapports en attente…" />}
            {liste.error && <Echec erreur={liste.error} onReessayer={liste.refetch} />}

            {!liste.isPending && !liste.error && (
                <>
                    <Tableau
                        cle={(r) => r.id}
                        lignes={liste.lignes}
                        vide={
                            <Vide
                                titre="Aucun rapport n’attend votre visa"
                                explication="Vous êtes à jour."
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
                                cle: 'chiffre',
                                titre: 'Chiffre clé',
                                alignement: 'droite',
                                rendu: (r) =>
                                    r.production_opk
                                        ? `${nombre(r.production_opk.enregistrements_realises)} enreg.`
                                        : r.evolution
                                          ? `${nombre(r.evolution.personnes_enregistrees)} enreg.`
                                          : r.activites_aopk
                                            ? `${nombre(r.activites_aopk.justificatifs_transmis_realise)} justif.`
                                            : '—',
                            },
                            {
                                cle: 'soumis_le',
                                titre: 'Signé le',
                                compact: true,
                                rendu: (r) => dateHeure(r.soumis_le),
                            },
                        ]}
                    />
                    <Pagination page={liste.pagination} onPage={liste.setPage} />
                </>
            )}
        </>
    );
}
