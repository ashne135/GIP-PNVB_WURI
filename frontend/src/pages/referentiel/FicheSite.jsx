import { useEffect, useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';
import { api } from '../../api/client';
import { useAuth } from '../../auth/ContexteAuth';
import { useAction } from '../../outils/crochets';
import { Bloc, Rubrique, Rubriques } from '../../composants/Fiche';
import { Pastille } from '../../composants/Tableau';
import { Bouton, Champ, Liste, Saisie } from '../../composants/Champs';
import { Chargement } from '../../composants/Chargement';
import { Echec, Succes } from '../../composants/Etats';
import { humaniser, nombre } from '../../outils/format';
import { statut, statutsSite } from '../../domaine/referentiel';
import { lienItineraire } from '../../outils/itineraire';

/**
 * LA FICHE D'UN SITE.
 *
 * Deux rattachements, qui ne se confondent pas : la LOCALITÉ porte la
 * population — le dénominateur de la couverture —, le CENTRE porte la
 * supervision. Le code et le centre ne se modifient pas.
 *
 * Les coordonnées saisies ici placent le site sur la carte du tableau de bord,
 * et fixent le centre de la zone où un signal d'arrivée est jugé « dans la
 * zone ».
 */
export function FicheSite() {
    const { id } = useParams();
    const auth = useAuth();

    const { data, isPending, error, refetch } = useQuery({
        queryKey: ['site', id],
        queryFn: () => api.lire(`/referentiel/sites/${id}`),
    });

    if (isPending) {
        return <Chargement message="Chargement du site…" />;
    }

    if (error) {
        return <Echec erreur={error} onReessayer={refetch} />;
    }

    return (
        <>
            <div className="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h2 className="font-mono text-lg font-semibold text-ardoise-900">{data.code}</h2>
                    <p className="text-sm text-ardoise-600">{data.nom}</p>
                </div>
                <Link
                    to={data.centre ? `/centres/${data.centre.id}` : '/sites'}
                    className="rounded border border-ardoise-300 bg-white px-3 py-2 text-sm hover:bg-ardoise-50"
                >
                    {data.centre ? `Retour au centre ${data.centre.code}` : 'Retour aux sites'}
                </Link>
            </div>

            <Bloc titre="Rattachements">
                <Rubriques colonnes={3}>
                    <Rubrique libelle="Centre (supervision)">{data.centre ? `${data.centre.code} — ${data.centre.nom}` : null}</Rubrique>
                    <Rubrique libelle="Commune">{data.centre?.commune?.nom}</Rubrique>
                    <Rubrique libelle="Localité (population)">
                        {data.localite ? `${data.localite.nom} (${humaniser(data.localite.type_localite)}) — ${nombre(data.localite.population_totale)} hab.` : null}
                    </Rubrique>
                    <Rubrique libelle="Statut">
                        <Pastille ton={statut(statutsSite, data.statut).ton}>{statut(statutsSite, data.statut).libelle}</Pastille>
                    </Rubrique>
                    <Rubrique libelle="Ordre de tournée">{data.ordre_tournee}</Rubrique>
                    <Rubrique libelle="Rayon de la zone">{data.rayon_zone_metres != null ? `${nombre(data.rayon_zone_metres)} m` : null}</Rubrique>
                    <Rubrique libelle="Coordonnées" pleineLargeur>
                        {lienItineraire(data.latitude, data.longitude)
                            ? (
                                <>
                                    <span className="font-mono">{data.latitude}, {data.longitude}</span>
                                    {/*
                                      * L'ITINÉRAIRE, pour une visite de suivi : un site de
                                      * village n'a pas d'adresse, seules ses coordonnées
                                      * mènent au bon endroit. Google Maps calcule le trajet
                                      * depuis la position du visiteur ; nous ne lui envoyons
                                      * que la destination.
                                      */}
                                    <a
                                        href={lienItineraire(data.latitude, data.longitude)}
                                        target="_blank"
                                        rel="noreferrer noopener"
                                        className="ml-3 inline-flex items-center gap-1 rounded border border-pnvb-300 px-2.5 py-1 text-sm font-medium text-pnvb-800 hover:bg-pnvb-50"
                                    >
                                        Itinéraire (Google Maps)
                                    </a>
                                </>
                            )
                            : 'Non renseignées : le site n’apparaît pas sur la carte, et aucun itinéraire n’est possible.'}
                    </Rubrique>
                </Rubriques>
            </Bloc>

            {auth.peut('referentiel.modifier') && data.statut !== 'ferme' && <ModifierSite site={data} onFait={refetch} />}
        </>
    );
}

function ModifierSite({ site, onFait }) {
    const action = useAction(['sites', 'site', 'centre', 'tableau-bord-sites-carte']);
    const initial = () => ({
        nom: site.nom ?? '',
        statut: site.statut ?? 'planifie',
        ordre_tournee: site.ordre_tournee ?? '',
        rayon_zone_metres: site.rayon_zone_metres ?? '',
        latitude: site.latitude ?? '',
        longitude: site.longitude ?? '',
    });
    const [champs, setChamps] = useState(initial);

    useEffect(() => setChamps(initial()), [site]); // eslint-disable-line react-hooks/exhaustive-deps

    const changer = (nom) => (e) => setChamps((c) => ({ ...c, [nom]: e.target.value }));
    const nombreOuNul = (valeur) => (valeur === '' || valeur === null ? null : Number(valeur));

    async function enregistrer(evenement) {
        evenement.preventDefault();

        const corps = {
            nom: champs.nom,
            statut: champs.statut,
            latitude: nombreOuNul(champs.latitude),
            longitude: nombreOuNul(champs.longitude),
        };

        if (champs.ordre_tournee !== '') {
            corps.ordre_tournee = Number(champs.ordre_tournee);
        }

        if (champs.rayon_zone_metres !== '') {
            corps.rayon_zone_metres = Number(champs.rayon_zone_metres);
        }

        if (await action.lancer(() => api.modifier(`/referentiel/sites/${site.id}`, corps))) {
            onFait();
        }
    }

    return (
        <Bloc titre="Modifier le site" precision="Le code et le centre ne se modifient pas">
            {action.message && <div className="mb-4"><Succes message={action.message} onFermer={action.oublierMessage} /></div>}
            {action.erreur && !action.erreur.estValidation && <div className="mb-4"><Echec erreur={action.erreur} /></div>}

            <form onSubmit={enregistrer} className="grid gap-4 sm:grid-cols-2">
                <Champ nom="nom" libelle="Nom" erreurs={action.erreur?.erreurs}>
                    <Saisie value={champs.nom} onChange={changer('nom')} required maxLength={120} />
                </Champ>
                <Champ nom="statut" libelle="Statut" erreurs={action.erreur?.erreurs}>
                    <Liste value={champs.statut} onChange={changer('statut')}>
                        {Object.entries(statutsSite).map(([valeur, s]) => <option key={valeur} value={valeur}>{s.libelle}</option>)}
                    </Liste>
                </Champ>
                <Champ nom="ordre_tournee" libelle="Ordre de tournée" erreurs={action.erreur?.erreurs}>
                    <Saisie type="number" min="1" value={champs.ordre_tournee} onChange={changer('ordre_tournee')} />
                </Champ>
                <Champ nom="rayon_zone_metres" libelle="Rayon de la zone (mètres)" erreurs={action.erreur?.erreurs} aide="Entre 50 et 5 000 m : distance sous laquelle une arrivée est « dans la zone ».">
                    <Saisie type="number" min="50" max="5000" value={champs.rayon_zone_metres} onChange={changer('rayon_zone_metres')} />
                </Champ>
                <Champ nom="latitude" libelle="Latitude" erreurs={action.erreur?.erreurs} aide="Entre 9,4 et 15,1 pour le Burkina Faso.">
                    <Saisie type="number" step="0.0000001" value={champs.latitude} onChange={changer('latitude')} />
                </Champ>
                <Champ nom="longitude" libelle="Longitude" erreurs={action.erreur?.erreurs} aide="Entre -5,5 et 2,4 pour le Burkina Faso.">
                    <Saisie type="number" step="0.0000001" value={champs.longitude} onChange={changer('longitude')} />
                </Champ>
                <div className="sm:col-span-2">
                    <Bouton type="submit" disabled={action.enCours}>{action.enCours ? 'Enregistrement…' : 'Enregistrer'}</Bouton>
                </div>
            </form>
        </Bloc>
    );
}
