import { useState } from 'react';
import { Link } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';
import { api, avecParametres } from '../../api/client';
import { useAuth } from '../../auth/ContexteAuth';
import { useAction, useListe } from '../../outils/crochets';
import { Pagination, Pastille, Tableau } from '../../composants/Tableau';
import { BarreFiltres, FiltreListe, FiltreTexte } from '../../composants/Filtres';
import { Bloc } from '../../composants/Fiche';
import { Bouton, Champ, Liste, Saisie } from '../../composants/Champs';
import { Chargement } from '../../composants/Chargement';
import { Echec, Succes, Vide } from '../../composants/Etats';
import { nombre } from '../../outils/format';
import { statut, statutsCentre } from '../../domaine/referentiel';

/** Les régions du référentiel, quelle que soit la forme exacte de la réponse. */
export function useRegions() {
    const requete = useQuery({ queryKey: ['referentiel-regions'], queryFn: () => api.lire('/referentiel/regions') });
    const donnees = requete.data;

    return Array.isArray(donnees) ? donnees : (donnees?.data ?? []);
}

export function ListeCentres() {
    const auth = useAuth();
    const liste = useListe('centres', '/referentiel/centres');
    const regions = useRegions();

    return (
        <>
            <BarreFiltres onReinitialiser={liste.reinitialiser}>
                <FiltreTexte
                    libelle="Recherche"
                    valeur={liste.filtres.recherche}
                    onChange={(v) => liste.changerFiltre('recherche', v)}
                    placeholder="Code ou nom"
                />
                <FiltreListe
                    libelle="Région"
                    valeur={liste.filtres.region_id}
                    onChange={(v) => liste.changerFiltre('region_id', v)}
                    tous="Toutes"
                    options={regions.map((r) => ({ valeur: String(r.id), libelle: r.nom }))}
                />
                <FiltreListe
                    libelle="Statut"
                    valeur={liste.filtres.statut}
                    onChange={(v) => liste.changerFiltre('statut', v)}
                    options={Object.entries(statutsCentre).map(([valeur, s]) => ({ valeur, libelle: s.libelle }))}
                />
            </BarreFiltres>

            {auth.peut('referentiel.modifier') && <CreerCentre regions={regions} />}

            {liste.isPending && <Chargement message="Chargement des centres…" />}
            {liste.error && <Echec erreur={liste.error} onReessayer={liste.refetch} />}

            {!liste.isPending && !liste.error && (
                <>
                    <Tableau
                        cle={(c) => c.id}
                        lignes={liste.lignes}
                        vide={<Vide titre="Aucun centre ne correspond" explication="Aucun centre de votre périmètre ne répond à ces filtres." />}
                        colonnes={[
                            {
                                cle: 'code',
                                titre: 'Code',
                                compact: true,
                                rendu: (c) => (
                                    <Link to={`/centres/${c.id}`} className="font-mono font-medium text-pnvb-800 underline">
                                        {c.code}
                                    </Link>
                                ),
                            },
                            { cle: 'nom', titre: 'Nom' },
                            { cle: 'commune', titre: 'Commune', rendu: (c) => c.commune?.nom ?? '—' },
                            { cle: 'region', titre: 'Région', rendu: (c) => c.region?.nom ?? '—' },
                            { cle: 'kits', titre: 'Kits', alignement: 'droite', rendu: (c) => nombre(c.nombre_kits) },
                            { cle: 'sites', titre: 'Sites', alignement: 'droite', rendu: (c) => nombre(c.sites_count) },
                            {
                                cle: 'statut',
                                titre: 'Statut',
                                compact: true,
                                rendu: (c) => (
                                    <span className="flex flex-wrap gap-1">
                                        <Pastille ton={statut(statutsCentre, c.statut).ton}>{statut(statutsCentre, c.statut).libelle}</Pastille>
                                        {c.est_permanent && <Pastille ton="info">permanent</Pastille>}
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

/**
 * CRÉER UN CENTRE.
 *
 * On choisit une région, puis une commune dans une liste : le code du centre
 * en dépend et ne changera plus jamais. Le code lui-même n'est pas demandé,
 * c'est le serveur qui l'attribue.
 */
function CreerCentre({ regions }) {
    const [ouvert, setOuvert] = useState(false);
    const [regionId, setRegionId] = useState('');
    const [champs, setChamps] = useState({ commune_id: '', nom: '', nombre_kits: '1', est_permanent: false });
    const action = useAction(['centres']);

    const communes = useQuery({
        queryKey: ['referentiel-communes', regionId],
        queryFn: () => api.lire(avecParametres('/referentiel/communes', { region_id: regionId })),
        enabled: Boolean(regionId),
    });

    async function creer(evenement) {
        evenement.preventDefault();

        const resultat = await action.lancer(() =>
            api.creer('/referentiel/centres', {
                commune_id: Number(champs.commune_id),
                nom: champs.nom,
                nombre_kits: Number(champs.nombre_kits),
                est_permanent: champs.est_permanent,
            }),
        );

        if (resultat) {
            setChamps({ commune_id: '', nom: '', nombre_kits: '1', est_permanent: false });
        }
    }

    if (!ouvert) {
        return (
            <div className="flex justify-end">
                <Bouton variante="secondaire" onClick={() => setOuvert(true)}>Créer un centre</Bouton>
            </div>
        );
    }

    return (
        <Bloc
            titre="Créer un centre"
            precision="Le code est attribué par le serveur à partir de la commune, et ne changera plus."
            actions={<Bouton variante="secondaire" onClick={() => setOuvert(false)}>Fermer</Bouton>}
        >
            {action.message && <div className="mb-4"><Succes message={action.message} onFermer={action.oublierMessage} /></div>}
            {action.erreur && !action.erreur.estValidation && <div className="mb-4"><Echec erreur={action.erreur} /></div>}

            <form onSubmit={creer} className="grid gap-4 sm:grid-cols-2">
                <Champ nom="region" libelle="Région">
                    <Liste value={regionId} onChange={(e) => { setRegionId(e.target.value); setChamps((c) => ({ ...c, commune_id: '' })); }} required>
                        <option value="">Choisir…</option>
                        {regions.map((r) => <option key={r.id} value={r.id}>{r.nom}</option>)}
                    </Liste>
                </Champ>
                <Champ nom="commune_id" libelle="Commune" erreurs={action.erreur?.erreurs}>
                    <Liste
                        value={champs.commune_id}
                        onChange={(e) => setChamps((c) => ({ ...c, commune_id: e.target.value }))}
                        disabled={!regionId || communes.isPending}
                        required
                    >
                        <option value="">{regionId ? 'Choisir…' : 'Choisissez d’abord une région'}</option>
                        {(communes.data ?? []).map((c) => <option key={c.id} value={c.id}>{c.nom}</option>)}
                    </Liste>
                </Champ>
                <Champ nom="nom" libelle="Nom du centre" erreurs={action.erreur?.erreurs}>
                    <Saisie value={champs.nom} onChange={(e) => setChamps((c) => ({ ...c, nom: e.target.value }))} required maxLength={120} />
                </Champ>
                {/*
                  * SAISIE LIBRE, et non plus une liste de deux choix : le
                  * plafond de 2 kits par centre a été levé le 18/09/2026. Le
                  * serveur garde la borne — celle du paramètre — et c'est lui
                  * qui refuse : on ne remplace pas un contrôle serveur par une
                  * limite d'écran.
                  */}
                <Champ nom="nombre_kits" libelle="Nombre de kits" erreurs={action.erreur?.erreurs} aide="Autant que le centre en reçoit.">
                    <Saisie
                        type="number"
                        min="1"
                        step="1"
                        value={champs.nombre_kits}
                        onChange={(e) => setChamps((c) => ({ ...c, nombre_kits: e.target.value }))}
                    />
                </Champ>
                <label className="flex items-center gap-2 text-sm text-ardoise-800 sm:col-span-2">
                    <input
                        type="checkbox"
                        checked={champs.est_permanent}
                        onChange={(e) => setChamps((c) => ({ ...c, est_permanent: e.target.checked }))}
                        className="h-4 w-4 rounded border-ardoise-400"
                    />
                    Centre permanent
                </label>
                <div className="sm:col-span-2">
                    <Bouton type="submit" disabled={action.enCours}>{action.enCours ? 'Création…' : 'Créer le centre'}</Bouton>
                </div>
            </form>
        </Bloc>
    );
}
