import { Link, useParams } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';
import { api } from '../../api/client';
import { Bloc, Rubrique, Rubriques } from '../../composants/Fiche';
import { Indicateur } from '../../composants/Page';
import { Pastille, Tableau } from '../../composants/Tableau';
import { Chargement } from '../../composants/Chargement';
import { Echec } from '../../composants/Etats';
import { date, dateHeure, heure, humaniser, nombre, nomDe } from '../../outils/format';

/**
 * UNE FEUILLE DE PRÉSENCE.
 *
 * La DISTANCE DU SUPERVISEUR au site, au moment où il a validé, est affichée :
 * c'est elle qui rend opposable un pointage collectif fait à distance.
 *
 * Le back-office ne valide pas les feuilles — le superviseur le fait depuis le
 * terrain. Il les consulte, et le chef d'antenne les corrige par l'API dédiée.
 */
export function FicheFeuille() {
    const { id } = useParams();

    const { data, isPending, error, refetch } = useQuery({
        queryKey: ['feuille', id],
        queryFn: () => api.lire(`/feuilles/${id}`),
    });

    if (isPending) {
        return <Chargement message="Chargement de la feuille…" />;
    }

    if (error) {
        return <Echec erreur={error} onReessayer={refetch} />;
    }

    const lignes = data.lignes ?? [];
    const presents = lignes.filter((l) => l.statut === 'present').length;
    const tonLigne = { present: 'bon', absent: 'alerte', absent_justifie: 'attention' };

    return (
        <div className="space-y-4 pt-4">
            <div className="flex flex-wrap items-center justify-between gap-3">
                <h2 className="text-lg font-semibold text-ardoise-900">
                    {data.site?.code} — {date(data.date_presence)}
                </h2>
                <Link to="/presences" className="rounded border border-ardoise-300 bg-white px-3 py-2 text-sm hover:bg-ardoise-50">
                    Retour aux feuilles
                </Link>
            </div>

            {data.statut === 'brouillon' && (
                <div className="rounded border border-ocre-300 bg-ocre-50 px-4 py-3 text-sm text-ocre-900" role="status">
                    Cette feuille n’est pas encore validée : elle ne fait pas foi, et n’alimente aucun indicateur.
                </div>
            )}

            <div className="grid gap-3 sm:grid-cols-3">
                <Indicateur libelle="Agents attendus" valeur={nombre(lignes.length)} />
                <Indicateur libelle="Présents" valeur={nombre(presents)} ton="bon" />
                <Indicateur libelle="Absents" valeur={nombre(lignes.length - presents)} ton={lignes.length - presents > 0 ? 'attention' : 'neutre'} />
            </div>

            <Bloc titre="Validation">
                <Rubriques colonnes={3}>
                    <Rubrique libelle="Site">{data.site?.nom}</Rubrique>
                    <Rubrique libelle="Centre">{data.centre ? `${data.centre.code} — ${data.centre.nom}` : null}</Rubrique>
                    <Rubrique libelle="Statut">{humaniser(data.statut)}</Rubrique>
                    <Rubrique libelle="Validée par">{data.valide_le ? nomDe(data.superviseur?.user) : null}</Rubrique>
                    <Rubrique libelle="Validée le">{dateHeure(data.valide_le)}</Rubrique>
                    <Rubrique libelle="Distance du superviseur au site">
                        {data.distance_site_metres == null ? null : `${nombre(data.distance_site_metres)} m`}
                    </Rubrique>
                    {data.motif_correction && (
                        <Rubrique libelle="Motif de la correction" pleineLargeur>
                            {data.motif_correction}
                        </Rubrique>
                    )}
                </Rubriques>
            </Bloc>

            <Bloc titre="Agents" precision="L’arrivée signalée est une information, pas une preuve : la colonne Présence fait foi">
                <Tableau
                    cle={(l) => l.id}
                    lignes={lignes}
                    colonnes={[
                        { cle: 'matricule', titre: 'Matricule', compact: true, rendu: (l) => <span className="font-mono">{l.volontaire?.matricule}</span> },
                        { cle: 'nom', titre: 'Nom et prénoms', rendu: (l) => nomDe(l.volontaire?.user) },
                        { cle: 'categorie', titre: 'Catégorie', compact: true, rendu: (l) => humaniser(l.categorie) },
                        { cle: 'statut', titre: 'Présence', compact: true, rendu: (l) => <Pastille ton={tonLigne[l.statut] ?? 'neutre'}>{humaniser(l.statut)}</Pastille> },
                        { cle: 'motif', titre: 'Motif d’absence', rendu: (l) => l.motif_absence ?? '—' },
                        { cle: 'arrivee', titre: 'Arrivée signalée', compact: true, rendu: (l) => (l.heure_arrivee_signalee ? heure(l.heure_arrivee_signalee) : 'aucune') },
                        {
                            cle: 'zone',
                            titre: 'Zone',
                            compact: true,
                            rendu: (l) =>
                                l.dans_zone == null ? '—' : l.dans_zone ? <Pastille ton="bon">dans la zone</Pastille> : <Pastille ton="attention">{nombre(l.distance_signalee)} m</Pastille>,
                        },
                    ]}
                />
            </Bloc>
        </div>
    );
}
