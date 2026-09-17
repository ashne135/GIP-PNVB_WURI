import { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';
import { api, avecParametres } from '../../api/client';
import { useAction } from '../../outils/crochets';
import { EnTetePage } from '../../composants/Page';
import { Bloc } from '../../composants/Fiche';
import { Bouton, Champ, Liste, Saisie } from '../../composants/Champs';
import { Echec } from '../../composants/Etats';
import { useRegions } from '../referentiel/ListeCentres';
import { nombre } from '../../outils/format';

/**
 * PLANIFIER UNE VAGUE.
 *
 * Une vague couvre UNE région et ouvre une liste de centres. Rien n'est tiré
 * ici : la planification pose le cadre, le tirage vient ensuite, et la
 * validation seulement après relecture. Aucun accès n'est ouvert à cette étape.
 *
 * L'objectif par kit et par jour reste facultatif — une vague peut être
 * planifiée avant qu'il soit arbitré — mais sans lui, l'écart de chaque rapport
 * d'opérateur restera vide. L'écran le dit plutôt que de le laisser découvrir.
 */
export function PlanifierVague() {
    const naviguer = useNavigate();
    const regions = useRegions();
    const action = useAction(['vagues']);

    const [champs, setChamps] = useState({
        libelle: '',
        region_id: '',
        date_debut_prevue: '',
        date_fin_prevue: '',
        objectif_enregistrements_par_kit_jour: '',
    });
    const [centres, setCentres] = useState([]);

    const changer = (nom) => (e) => setChamps((c) => ({ ...c, [nom]: e.target.value }));

    const centresDisponibles = useQuery({
        queryKey: ['centres-planifiables', champs.region_id],
        queryFn: () => api.lire(avecParametres('/referentiel/centres', {
            region_id: champs.region_id,
            statut: 'ouvert',
            par_page: 200,
        })),
        enabled: Boolean(champs.region_id),
    });

    const lignesCentres = centresDisponibles.data?.data ?? [];

    function basculer(id) {
        setCentres((actuels) =>
            actuels.includes(id) ? actuels.filter((c) => c !== id) : [...actuels, id],
        );
    }

    async function planifier(evenement) {
        evenement.preventDefault();

        const resultat = await action.lancer(() =>
            api.creer('/vagues', {
                libelle: champs.libelle,
                region_id: Number(champs.region_id),
                date_debut_prevue: champs.date_debut_prevue,
                date_fin_prevue: champs.date_fin_prevue,
                centres,
                ...(champs.objectif_enregistrements_par_kit_jour
                    ? { objectif_enregistrements_par_kit_jour: Number(champs.objectif_enregistrements_par_kit_jour) }
                    : {}),
            }),
        );

        if (resultat?.donnees?.id) {
            naviguer(`/vagues/${resultat.donnees.id}`);
        }
    }

    return (
        <>
            <EnTetePage
                titre="Planifier une vague"
                sousTitre="Une vague couvre une région et ouvre des centres. Le tirage vient ensuite : à cette étape, aucun accès n’est ouvert et personne n’est prévenu."
            />

            {action.erreur && !action.erreur.estValidation && <Echec erreur={action.erreur} />}

            <form onSubmit={planifier} className="space-y-5">
                <Bloc titre="Cadre de la vague">
                    <div className="grid gap-4 sm:grid-cols-2">
                        <Champ nom="libelle" libelle="Nom de la vague" erreurs={action.erreur?.erreurs}>
                            <Saisie value={champs.libelle} onChange={changer('libelle')} required maxLength={160} />
                        </Champ>
                        <Champ nom="region_id" libelle="Région" erreurs={action.erreur?.erreurs}>
                            <Liste
                                value={champs.region_id}
                                onChange={(e) => { changer('region_id')(e); setCentres([]); }}
                                required
                            >
                                <option value="">Choisir…</option>
                                {regions.map((r) => <option key={r.id} value={r.id}>{r.nom}</option>)}
                            </Liste>
                        </Champ>
                        <Champ nom="date_debut_prevue" libelle="Début prévu" erreurs={action.erreur?.erreurs}>
                            <Saisie type="date" value={champs.date_debut_prevue} onChange={changer('date_debut_prevue')} required />
                        </Champ>
                        <Champ nom="date_fin_prevue" libelle="Fin prévue" erreurs={action.erreur?.erreurs}>
                            <Saisie type="date" value={champs.date_fin_prevue} onChange={changer('date_fin_prevue')} required />
                        </Champ>
                        <Champ
                            nom="objectif_enregistrements_par_kit_jour"
                            libelle="Objectif par kit et par jour"
                            erreurs={action.erreur?.erreurs}
                            aide="Facultatif, mais sans lui l’écart des rapports d’opérateur restera vide."
                        >
                            <Saisie
                                type="number"
                                min="1"
                                max="10000"
                                value={champs.objectif_enregistrements_par_kit_jour}
                                onChange={changer('objectif_enregistrements_par_kit_jour')}
                            />
                        </Champ>
                    </div>
                </Bloc>

                <Bloc
                    titre={`Centres à ouvrir (${centres.length} choisi${centres.length > 1 ? 's' : ''})`}
                    precision="Seuls les centres ouverts de la région sont proposés."
                >
                    {!champs.region_id && <p className="text-sm text-ardoise-500">Choisissez d’abord une région.</p>}
                    {champs.region_id && centresDisponibles.isPending && (
                        <p className="text-sm text-ardoise-500">Chargement des centres…</p>
                    )}
                    {centresDisponibles.error && <Echec erreur={centresDisponibles.error} />}
                    {champs.region_id && !centresDisponibles.isPending && lignesCentres.length === 0 && (
                        <p className="text-sm text-ardoise-500">
                            Aucun centre ouvert dans cette région. Ouvrez-en depuis le référentiel.
                        </p>
                    )}

                    <div className="grid gap-2 sm:grid-cols-2 xl:grid-cols-3">
                        {lignesCentres.map((centre) => (
                            <label
                                key={centre.id}
                                className="flex items-start gap-2 rounded border border-ardoise-200 px-3 py-2 text-sm"
                            >
                                <input
                                    type="checkbox"
                                    checked={centres.includes(centre.id)}
                                    onChange={() => basculer(centre.id)}
                                    className="mt-0.5 h-4 w-4 rounded border-ardoise-400"
                                />
                                <span className="min-w-0">
                                    <span className="block font-mono text-xs text-ardoise-600">{centre.code}</span>
                                    <span className="block truncate text-ardoise-900">{centre.nom}</span>
                                    <span className="block text-xs text-ardoise-500">
                                        {nombre(centre.nombre_kits)} kit{centre.nombre_kits > 1 ? 's' : ''}
                                        {centre.commune?.nom ? ` · ${centre.commune.nom}` : ''}
                                    </span>
                                </span>
                            </label>
                        ))}
                    </div>
                </Bloc>

                <div className="flex flex-wrap gap-2">
                    <Bouton type="submit" disabled={action.enCours || centres.length === 0}>
                        {action.enCours ? 'Planification…' : 'Planifier la vague'}
                    </Bouton>
                    <Bouton variante="secondaire" type="button" onClick={() => naviguer('/vagues')}>
                        Annuler
                    </Bouton>
                </div>
            </form>
        </>
    );
}
