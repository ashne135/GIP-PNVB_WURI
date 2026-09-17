import { Link } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';
import { api } from '../../api/client';
import { EnTetePage } from '../../composants/Page';
import { Pastille, Tableau } from '../../composants/Tableau';
import { Chargement } from '../../composants/Chargement';
import { Echec, Vide } from '../../composants/Etats';
import { dateHeure, depuis } from '../../outils/format';
import { gravite } from '../../domaine/gravite';

/**
 * CE QUE PERSONNE N'A PRIS EN CHARGE À TEMPS.
 *
 * La liste qui compte pour un responsable qui arrive le matin : les incidents
 * dont le délai est dépassé, ou qui ont déjà remonté d'un cran. Le nombre de
 * crans est affiché — un incident escaladé trois fois n'est pas un incident
 * escaladé une fois.
 */
export function IncidentsEnRetard() {
    const { data, isPending, error, refetch } = useQuery({
        queryKey: ['incidents-en-retard'],
        queryFn: () => api.lire('/incidents/en-retard'),
    });

    return (
        <>
            <EnTetePage
                titre="En attente de prise en charge"
                sousTitre={
                    'Ces incidents ont dépassé leur délai sans que personne s’en soit saisi. '
                    + 'La prise en charge arrête l’escalade.'
                }
                actions={
                    <Link
                        to="/incidents"
                        className="rounded border border-ardoise-300 bg-white px-3 py-2 text-sm hover:bg-ardoise-50"
                    >
                        Tous les incidents
                    </Link>
                }
            />

            {isPending && <Chargement message="Recherche des incidents en retard…" />}
            {error && <Echec erreur={error} onReessayer={refetch} />}

            {!isPending && !error && (
                <Tableau
                    cle={(i) => i.id}
                    lignes={data ?? []}
                    vide={
                        <Vide
                            titre="Rien n’attend de prise en charge"
                            explication="Tous les incidents de votre périmètre ont trouvé un responsable."
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
                            cle: 'escalade',
                            titre: 'Escalades',
                            compact: true,
                            alignement: 'droite',
                            rendu: (i) =>
                                i.niveau_escalade > 0 ? (
                                    <Pastille ton="alerte">×{i.niveau_escalade}</Pastille>
                                ) : (
                                    <span className="text-ardoise-400">aucune</span>
                                ),
                        },
                        {
                            cle: 'declare_le',
                            titre: 'Déclaré',
                            compact: true,
                            rendu: (i) => (
                                <span title={dateHeure(i.declare_le)}>{depuis(i.declare_le)}</span>
                            ),
                        },
                        {
                            cle: 'lieu',
                            titre: 'Lieu',
                            rendu: (i) => i.site?.nom ?? i.centre?.nom ?? '—',
                        },
                        {
                            cle: 'recit',
                            titre: 'Ce qui est signalé',
                            rendu: (i) => (
                                <span className="line-clamp-2 max-w-md">{i.recit}</span>
                            ),
                        },
                    ]}
                />
            )}
        </>
    );
}
