import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { api, avecParametres } from '../../api/client';
import { useAction } from '../../outils/crochets';
import { Bloc } from '../../composants/Fiche';
import { Pagination, Pastille, Tableau } from '../../composants/Tableau';
import { BarreFiltres, FiltreTexte } from '../../composants/Filtres';
import { Bouton, Champ, Liste, Saisie } from '../../composants/Champs';
import { Chargement } from '../../composants/Chargement';
import { Echec, Succes, Vide } from '../../composants/Etats';
import { nombre, nomDe } from '../../outils/format';
import { libelleNiveau, minimumsProfil, niveauSuffit } from '../../domaine/volontaires';

/**
 * ATTRIBUER LES PROFILS — aux fiches importées sans colonne « Profil »
 * (cadrage v2, section 6).
 *
 * On coche des fiches, on choisit UN profil, on valide : le lot entier reçoit
 * ce profil. Le serveur traite chaque fiche pour elle-même — une fiche refusée
 * n'annule pas les autres — et le compte rendu nomme celles qui restent.
 *
 * LE CHOIX EST DÉFINITIF : les catégories sont étanches, une fiche qualifiée ne
 * peut plus changer de profil. L'écran le dit avant qu'on valide.
 *
 * L'A-OPK EXIGE SA LOCALITÉ. Une localité choisie ici s'applique à tout le lot ;
 * laissée vide, chaque fiche garde celle que son fichier indiquait — et le
 * serveur refuse celles qui n'en ont pas.
 */
export function Qualification() {
    const [recherche, setRecherche] = useState('');
    const [page, setPage] = useState(1);
    const [choisies, setChoisies] = useState(() => new Map());
    const [categorie, setCategorie] = useState('');
    const [regionId, setRegionId] = useState('');
    const [communeId, setCommuneId] = useState('');
    const [localiteId, setLocaliteId] = useState('');
    const [motifDerogation, setMotifDerogation] = useState('');
    const [bilan, setBilan] = useState(null);

    const action = useAction(['volontaires-a-qualifier', 'volontaires']);

    const requete = useQuery({
        queryKey: ['volontaires-a-qualifier', recherche, page],
        queryFn: () => api.lire(avecParametres('/volontaires/a-qualifier', { recherche, page })),
        placeholderData: (precedent) => precedent,
    });

    const pourAssistant = categorie === 'assistant';

    const regions = useQuery({
        queryKey: ['referentiel-regions'],
        queryFn: () => api.lire('/referentiel/regions'),
        enabled: pourAssistant,
    });
    const communes = useQuery({
        queryKey: ['referentiel-communes', regionId],
        queryFn: () => api.lire(avecParametres('/referentiel/communes', { region_id: regionId })),
        enabled: pourAssistant && Boolean(regionId),
    });
    const localites = useQuery({
        queryKey: ['referentiel-localites', communeId],
        queryFn: () => api.lire(avecParametres('/referentiel/localites', { commune_id: communeId })),
        enabled: pourAssistant && Boolean(communeId),
    });

    const fiches = requete.data?.fiches;
    const lignes = fiches?.data ?? [];
    const profils = requete.data?.profils_possibles ?? [];
    const toutesCochees = lignes.length > 0 && lignes.every((f) => choisies.has(f.id));

    function basculer(fiche) {
        setChoisies((actuelles) => {
            const suivantes = new Map(actuelles);

            if (suivantes.has(fiche.id)) {
                suivantes.delete(fiche.id);
            } else {
                suivantes.set(fiche.id, fiche);
            }

            return suivantes;
        });
    }

    function basculerPage() {
        setChoisies((actuelles) => {
            const suivantes = new Map(actuelles);
            lignes.forEach((f) => (toutesCochees ? suivantes.delete(f.id) : suivantes.set(f.id, f)));

            return suivantes;
        });
    }

    async function qualifier(evenement) {
        evenement.preventDefault();
        setBilan(null);

        const qualifications = [...choisies.keys()].map((id) => ({
            volontaire_id: id,
            categorie,
            ...(pourAssistant && localiteId ? { localite_id: Number(localiteId) } : {}),
            // Le serveur ne retient la dérogation que pour les fiches qui en ont
            // besoin : l'envoyer pour tout le lot ne « déroge » pour personne
            // d'autre.
            ...(motifDerogation.trim() !== '' ? { motif_derogation: motifDerogation.trim() } : {}),
        }));

        const resultat = await action.lancer(() => api.agir('/volontaires/a-qualifier', { qualifications }));

        if (resultat) {
            setBilan(resultat.donnees);
            // Les fiches refusées restent cochées : c'est sur elles qu'il faut revenir.
            const refusees = new Set((resultat.donnees?.refusees ?? []).map((r) => r.volontaire_id));
            setChoisies((actuelles) => new Map([...actuelles].filter(([id]) => refusees.has(id))));
        }
    }

    const sansLocalite = pourAssistant && !localiteId
        ? [...choisies.values()].filter((f) => !f.localite_id).length
        : 0;

    // Les fiches dont le niveau d'étude ne permet pas le profil choisi : sans
    // dérogation motivée, le serveur les refusera.
    const aDeroger = categorie
        ? [...choisies.values()].filter((f) => !niveauSuffit(f.niveau_etude, categorie))
        : [];
    const minimum = categorie ? libelleNiveau(minimumsProfil[categorie]) : null;

    return (
        <>
            <BarreFiltres>
                <FiltreTexte
                    libelle="Recherche"
                    valeur={recherche}
                    onChange={(v) => { setRecherche(v); setPage(1); }}
                    placeholder="Nom ou téléphone"
                />
                {fiches && (
                    <p className="self-end pb-2 text-sm text-ardoise-600">
                        <span className="font-semibold tabular-nums text-ardoise-900">{nombre(fiches.total)}</span> fiches sans profil
                    </p>
                )}
            </BarreFiltres>

            {action.message && <Succes message={action.message} onFermer={action.oublierMessage} />}
            {action.erreur && <Echec erreur={action.erreur} />}

            {bilan?.refusees?.length > 0 && (
                <div className="rounded border border-ocre-300 bg-ocre-50 px-4 py-3 text-sm text-ocre-900" role="alert">
                    <p className="font-medium">Fiches non qualifiées — elles restent cochées :</p>
                    <ul className="mt-1 list-disc space-y-0.5 pl-5">
                        {bilan.refusees.map((r) => (
                            <li key={r.volontaire_id}>
                                {r.nom_complet ? `${r.nom_complet} : ` : ''}{r.motif}
                            </li>
                        ))}
                    </ul>
                </div>
            )}

            {choisies.size > 0 && (
                <Bloc titre={`${nombre(choisies.size)} fiches choisies`} precision="Le profil attribué est définitif : les catégories sont étanches.">
                    <form onSubmit={qualifier} className="space-y-4" aria-label="Attribuer un profil">
                        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                            <Champ nom="categorie" libelle="Profil à attribuer">
                                <Liste id="profil-lot" value={categorie} onChange={(e) => setCategorie(e.target.value)} required>
                                    <option value="">Choisir…</option>
                                    {profils.map((p) => <option key={p.valeur} value={p.valeur}>{p.libelle}</option>)}
                                </Liste>
                            </Champ>

                            {pourAssistant && (
                                <>
                                    <Champ nom="region" libelle="Région de la localité">
                                        <Liste id="region-lot" value={regionId} onChange={(e) => { setRegionId(e.target.value); setCommuneId(''); setLocaliteId(''); }}>
                                            <option value="">Garder celle du fichier</option>
                                            {(regions.data ?? []).map((r) => <option key={r.id} value={r.id}>{r.nom}</option>)}
                                        </Liste>
                                    </Champ>
                                    <Champ nom="commune" libelle="Commune">
                                        <Liste id="commune-lot" value={communeId} disabled={!regionId} onChange={(e) => { setCommuneId(e.target.value); setLocaliteId(''); }}>
                                            <option value="">Choisir…</option>
                                            {(communes.data ?? []).map((c) => <option key={c.id} value={c.id}>{c.nom}</option>)}
                                        </Liste>
                                    </Champ>
                                    <Champ nom="localite" libelle="Localité (village, secteur, quartier)">
                                        <Liste id="localite-lot" value={localiteId} disabled={!communeId} onChange={(e) => setLocaliteId(e.target.value)}>
                                            <option value="">Choisir…</option>
                                            {(localites.data ?? []).map((l) => <option key={l.id} value={l.id}>{l.nom}</option>)}
                                        </Liste>
                                    </Champ>
                                </>
                            )}
                        </div>

                        {pourAssistant && (
                            <p className="text-sm text-ardoise-600">
                                Une localité choisie ici s’applique aux {nombre(choisies.size)} fiches. Laissée vide, chaque fiche garde la
                                localité lue dans le fichier.
                                {sansLocalite > 0 && (
                                    <span className="font-medium text-brique-800">
                                        {' '}{nombre(sansLocalite)} fiches choisies n’en ont aucune : le serveur les refusera.
                                    </span>
                                )}
                            </p>
                        )}

                        {aDeroger.length > 0 && (
                            <div className="space-y-2 rounded border border-ocre-300 bg-ocre-50 px-3 py-3">
                                <p className="text-sm text-ocre-900">
                                    <span className="font-medium">{nombre(aDeroger.length)} fiches n’atteignent pas le niveau exigé</span>
                                    {minimum && <> — ce profil demande au moins « {minimum} ».</>}
                                    {' '}Sans dérogation motivée, le serveur les refusera :{' '}
                                    {aDeroger.slice(0, 3).map((f) => nomDe(f.user)).join(', ')}
                                    {aDeroger.length > 3 && `, et ${nombre(aDeroger.length - 3)} autres`}.
                                </p>
                                <Champ
                                    nom="motif_derogation"
                                    libelle="Motif de la dérogation"
                                    aide="Il restera inscrit sur chaque fiche concernée, et au journal."
                                    erreurs={action.erreur?.erreurs}
                                >
                                    <Saisie
                                        id="motif-derogation"
                                        value={motifDerogation}
                                        onChange={(e) => setMotifDerogation(e.target.value)}
                                        maxLength={500}
                                        placeholder="Expérience, décision de la coordination…"
                                    />
                                </Champ>
                            </div>
                        )}

                        <div className="flex flex-wrap gap-2">
                            <Bouton type="submit" disabled={!categorie || action.enCours}>
                                {action.enCours ? 'Attribution…' : 'Attribuer ce profil'}
                            </Bouton>
                            <Bouton type="button" variante="secondaire" onClick={() => setChoisies(new Map())}>Tout décocher</Bouton>
                        </div>
                    </form>
                </Bloc>
            )}

            {requete.isPending && <Chargement message="Chargement des fiches…" />}
            {requete.error && <Echec erreur={requete.error} onReessayer={requete.refetch} />}

            {fiches && (
                <>
                    <Tableau
                        cle={(f) => f.id}
                        lignes={lignes}
                        vide={<Vide titre="Aucune fiche sans profil" explication="Toutes les fiches importées ont un profil : vous pouvez planifier une vague." />}
                        colonnes={[
                            {
                                cle: 'choix',
                                titre: (
                                    <input
                                        type="checkbox"
                                        aria-label="Cocher toute la page"
                                        checked={toutesCochees}
                                        onChange={basculerPage}
                                        className="h-4 w-4"
                                    />
                                ),
                                compact: true,
                                rendu: (f) => (
                                    <input
                                        type="checkbox"
                                        aria-label={`Choisir ${nomDe(f.user)}`}
                                        checked={choisies.has(f.id)}
                                        onChange={() => basculer(f)}
                                        className="h-4 w-4"
                                    />
                                ),
                            },
                            { cle: 'matricule', titre: 'Matricule provisoire', compact: true, rendu: (f) => <span className="font-mono">{f.matricule}</span> },
                            { cle: 'nom', titre: 'Nom et prénoms', rendu: (f) => nomDe(f.user) },
                            { cle: 'telephone', titre: 'Téléphone', compact: true, rendu: (f) => f.user?.telephone ?? '—' },
                            {
                                cle: 'niveau',
                                titre: 'Niveau d’étude',
                                rendu: (f) => libelleNiveau(f.niveau_etude)
                                    ?? <Pastille ton="attention">à renseigner</Pastille>,
                            },
                            { cle: 'diplome', titre: 'Diplôme', rendu: (f) => f.diplome ?? '—' },
                            {
                                cle: 'localite',
                                titre: 'Localité du fichier',
                                rendu: (f) => (f.localite ? `${f.localite.nom}${f.localite.commune ? ` (${f.localite.commune.nom})` : ''}` : '—'),
                            },
                            { cle: 'region', titre: 'Région d’origine', rendu: (f) => f.region_origine?.nom ?? '—' },
                        ]}
                    />
                    <Pagination page={fiches} onPage={setPage} />
                </>
            )}
        </>
    );
}
