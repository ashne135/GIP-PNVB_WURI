import { useEffect, useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';
import { api, avecParametres } from '../../api/client';
import { useAuth } from '../../auth/ContexteAuth';
import { useAction } from '../../outils/crochets';
import { Bloc, Rubrique, Rubriques } from '../../composants/Fiche';
import { Pastille, Tableau } from '../../composants/Tableau';
import { Bouton, Champ, Liste, Saisie, Texte } from '../../composants/Champs';
import { Chargement } from '../../composants/Chargement';
import { Echec, Succes, Vide } from '../../composants/Etats';
import { nombre } from '../../outils/format';
import { statut, statutsCentre, statutsSite } from '../../domaine/referentiel';

/**
 * LA FICHE D'UN CENTRE.
 *
 * Ni le code ni la commune ne se modifient : le code en dépend, et il figure
 * sur les documents. Un centre ne se supprime pas non plus — il se FERME, avec
 * un motif, et ferme ses sites avec lui : un site ouvert dans un centre fermé
 * n'aurait plus personne pour le superviser.
 */
export function FicheCentre() {
    const { id } = useParams();
    const auth = useAuth();

    const { data, isPending, error, refetch } = useQuery({
        queryKey: ['centre', id],
        queryFn: () => api.lire(`/referentiel/centres/${id}`),
    });

    if (isPending) {
        return <Chargement message="Chargement du centre…" />;
    }

    if (error) {
        return <Echec erreur={error} onReessayer={refetch} />;
    }

    const peutModifier = auth.peut('referentiel.modifier');
    const ferme = data.statut === 'ferme';

    return (
        <>
            <div className="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h2 className="font-mono text-lg font-semibold text-ardoise-900">{data.code}</h2>
                    <p className="text-sm text-ardoise-600">{data.nom}</p>
                </div>
                <Link to="/centres" className="rounded border border-ardoise-300 bg-white px-3 py-2 text-sm hover:bg-ardoise-50">
                    Retour aux centres
                </Link>
            </div>

            <Bloc titre="Rattachement">
                <Rubriques colonnes={3}>
                    <Rubrique libelle="Commune">{data.commune?.nom}</Rubrique>
                    <Rubrique libelle="Région">{data.region?.nom}</Rubrique>
                    <Rubrique libelle="Statut">
                        <Pastille ton={statut(statutsCentre, data.statut).ton}>{statut(statutsCentre, data.statut).libelle}</Pastille>
                    </Rubrique>
                    <Rubrique libelle="Kits">{nombre(data.nombre_kits)}</Rubrique>
                    <Rubrique libelle="Permanent">{data.est_permanent ? 'Oui' : 'Non'}</Rubrique>
                    <Rubrique libelle="Coordonnées">
                        {data.latitude != null && data.longitude != null ? `${data.latitude}, ${data.longitude}` : 'non renseignées'}
                    </Rubrique>
                </Rubriques>
            </Bloc>

            {peutModifier && !ferme && <ModifierCentre centre={data} onFait={refetch} />}

            <Bloc titre={`Sites du centre (${nombre((data.sites ?? []).length)})`} precision="Le kit les couvre l’un après l’autre, dans l’ordre de tournée">
                <Tableau
                    cle={(s) => s.id}
                    lignes={[...(data.sites ?? [])].sort((a, b) => (a.ordre_tournee ?? 0) - (b.ordre_tournee ?? 0))}
                    vide={<Vide titre="Aucun site" explication="Ce centre n’a encore aucun site." />}
                    colonnes={[
                        { cle: 'ordre', titre: 'Ordre', alignement: 'droite', rendu: (s) => s.ordre_tournee ?? '—' },
                        {
                            cle: 'code',
                            titre: 'Code',
                            compact: true,
                            rendu: (s) => <Link to={`/sites/${s.id}`} className="font-mono text-pnvb-800 underline">{s.code}</Link>,
                        },
                        { cle: 'nom', titre: 'Nom' },
                        {
                            cle: 'localite',
                            titre: 'Localité',
                            rendu: (s) => (s.localite ? `${s.localite.nom} — ${nombre(s.localite.population_totale)} hab.` : '—'),
                        },
                        {
                            cle: 'statut',
                            titre: 'Statut',
                            compact: true,
                            rendu: (s) => <Pastille ton={statut(statutsSite, s.statut).ton}>{statut(statutsSite, s.statut).libelle}</Pastille>,
                        },
                    ]}
                />
            </Bloc>

            {peutModifier && !ferme && <AjouterSite centre={data} onFait={refetch} />}
            {peutModifier && !ferme && <FermerCentre centre={data} onFait={refetch} />}
        </>
    );
}

function ModifierCentre({ centre, onFait }) {
    const action = useAction(['centres', 'centre']);
    const initial = () => ({
        nom: centre.nom ?? '',
        nombre_kits: String(centre.nombre_kits ?? 1),
        est_permanent: Boolean(centre.est_permanent),
        statut: centre.statut ?? 'planifie',
        latitude: centre.latitude ?? '',
        longitude: centre.longitude ?? '',
    });
    const [champs, setChamps] = useState(initial);

    useEffect(() => setChamps(initial()), [centre]); // eslint-disable-line react-hooks/exhaustive-deps

    const changer = (nom) => (e) => setChamps((c) => ({ ...c, [nom]: e.target.value }));

    async function enregistrer(evenement) {
        evenement.preventDefault();

        const resultat = await action.lancer(() =>
            api.modifier(`/referentiel/centres/${centre.id}`, {
                nom: champs.nom,
                nombre_kits: Number(champs.nombre_kits),
                est_permanent: champs.est_permanent,
                statut: champs.statut,
                latitude: champs.latitude === '' ? null : Number(champs.latitude),
                longitude: champs.longitude === '' ? null : Number(champs.longitude),
            }),
        );

        if (resultat) {
            onFait();
        }
    }

    return (
        <Bloc titre="Modifier le centre" precision="Le code et la commune ne se modifient pas">
            {action.message && <div className="mb-4"><Succes message={action.message} onFermer={action.oublierMessage} /></div>}
            {action.erreur && !action.erreur.estValidation && <div className="mb-4"><Echec erreur={action.erreur} /></div>}

            <form onSubmit={enregistrer} className="grid gap-4 sm:grid-cols-2">
                <Champ nom="nom" libelle="Nom" erreurs={action.erreur?.erreurs}>
                    <Saisie value={champs.nom} onChange={changer('nom')} required maxLength={120} />
                </Champ>
                <Champ nom="statut" libelle="Statut" erreurs={action.erreur?.erreurs} aide="La fermeture se fait plus bas, avec un motif.">
                    <Liste value={champs.statut} onChange={changer('statut')}>
                        <option value="planifie">Planifié</option>
                        <option value="ouvert">Ouvert</option>
                    </Liste>
                </Champ>
                {/* Saisie libre : le plafond de 2 kits est levé (18/09/2026). */}
                <Champ nom="nombre_kits" libelle="Nombre de kits" erreurs={action.erreur?.erreurs}>
                    <Saisie type="number" min="1" step="1" value={champs.nombre_kits} onChange={changer('nombre_kits')} />
                </Champ>
                <label className="flex items-center gap-2 self-end pb-2 text-sm text-ardoise-800">
                    <input
                        type="checkbox"
                        checked={champs.est_permanent}
                        onChange={(e) => setChamps((c) => ({ ...c, est_permanent: e.target.checked }))}
                        className="h-4 w-4 rounded border-ardoise-400"
                    />
                    Centre permanent
                </label>
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

/** Ajouter un site : la localité se choisit dans la commune du centre. */
function AjouterSite({ centre, onFait }) {
    const action = useAction(['centre', 'sites']);
    const [ouvert, setOuvert] = useState(false);
    const [champs, setChamps] = useState({ localite_id: '', nom: '', ordre_tournee: '' });

    const localites = useQuery({
        queryKey: ['referentiel-localites', centre.commune?.id],
        queryFn: () => api.lire(avecParametres('/referentiel/localites', { commune_id: centre.commune?.id })),
        enabled: ouvert && Boolean(centre.commune?.id),
    });

    const changer = (nom) => (e) => setChamps((c) => ({ ...c, [nom]: e.target.value }));

    async function ajouter(evenement) {
        evenement.preventDefault();

        const resultat = await action.lancer(() =>
            api.creer('/referentiel/sites', {
                centre_id: centre.id,
                localite_id: Number(champs.localite_id),
                nom: champs.nom,
                ...(champs.ordre_tournee ? { ordre_tournee: Number(champs.ordre_tournee) } : {}),
            }),
        );

        if (resultat) {
            setChamps({ localite_id: '', nom: '', ordre_tournee: '' });
            onFait();
        }
    }

    if (!ouvert) {
        return (
            <div className="flex justify-end">
                <Bouton variante="secondaire" onClick={() => setOuvert(true)}>Ajouter un site</Bouton>
            </div>
        );
    }

    return (
        <Bloc
            titre="Ajouter un site"
            precision={`La localité se choisit dans la commune du centre : ${centre.commune?.nom ?? '—'}`}
            actions={<Bouton variante="secondaire" onClick={() => setOuvert(false)}>Fermer</Bouton>}
        >
            {action.message && <div className="mb-4"><Succes message={action.message} onFermer={action.oublierMessage} /></div>}
            {action.erreur && !action.erreur.estValidation && <div className="mb-4"><Echec erreur={action.erreur} /></div>}
            {localites.error && <div className="mb-4"><Echec erreur={localites.error} /></div>}

            <form onSubmit={ajouter} className="grid gap-4 sm:grid-cols-3">
                <Champ nom="localite_id" libelle="Localité" erreurs={action.erreur?.erreurs}>
                    <Liste value={champs.localite_id} onChange={changer('localite_id')} disabled={localites.isPending} required>
                        <option value="">{localites.isPending ? 'Chargement…' : 'Choisir…'}</option>
                        {(localites.data ?? []).map((l) => (
                            <option key={l.id} value={l.id}>{l.nom} — {nombre(l.population_totale)} hab.</option>
                        ))}
                    </Liste>
                </Champ>
                <Champ nom="nom" libelle="Nom du site" erreurs={action.erreur?.erreurs}>
                    <Saisie value={champs.nom} onChange={changer('nom')} required maxLength={120} />
                </Champ>
                <Champ nom="ordre_tournee" libelle="Ordre de tournée" erreurs={action.erreur?.erreurs} aide="Laissé vide : attribué par le serveur.">
                    <Saisie type="number" min="1" value={champs.ordre_tournee} onChange={changer('ordre_tournee')} />
                </Champ>
                <div className="sm:col-span-3">
                    <Bouton type="submit" disabled={action.enCours}>{action.enCours ? 'Ajout…' : 'Ajouter le site'}</Bouton>
                </div>
            </form>
        </Bloc>
    );
}

function FermerCentre({ centre, onFait }) {
    const action = useAction(['centres', 'centre']);
    const [motif, setMotif] = useState('');

    async function fermer() {
        const resultat = await action.lancer(() => api.agir(`/referentiel/centres/${centre.id}/fermer`, { motif }));

        if (resultat) {
            setMotif('');
            onFait();
        }
    }

    return (
        <Bloc titre="Fermer le centre" precision="Le centre et tous ses sites seront fermés. L’historique reste consultable.">
            {action.erreur && <div className="mb-4"><Echec erreur={action.erreur} /></div>}
            <Champ nom="motif" libelle="Motif de la fermeture" erreurs={action.erreur?.erreurs}>
                <Texte value={motif} onChange={(e) => setMotif(e.target.value)} maxLength={255} />
            </Champ>
            <Bouton variante="danger" className="mt-3" disabled={action.enCours || !motif.trim()} onClick={fermer}>
                Fermer le centre et ses sites
            </Bouton>
        </Bloc>
    );
}
