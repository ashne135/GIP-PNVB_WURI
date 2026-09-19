import { useMemo, useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { api, avecParametres } from '../../api/client';
import { useAction } from '../../outils/crochets';
import { EnTetePage, Indicateur } from '../../composants/Page';
import { Bloc } from '../../composants/Fiche';
import { Bouton, Champ, Liste, Texte } from '../../composants/Champs';
import { Pastille, Tableau } from '../../composants/Tableau';
import { BarreFiltres, FiltreListe } from '../../composants/Filtres';
import { Chargement } from '../../composants/Chargement';
import { Echec, Succes, Vide } from '../../composants/Etats';
import { nombre, nomDe } from '../../outils/format';
import { useRegions } from '../referentiel/ListeCentres';

/**
 * RATTACHER LES A-OPK À UN SITE.
 *
 * ON NE RATTACHE PAS UN A-OPK À UN OPÉRATEUR, et c'est la clé de cet écran.
 * L'assistant tient l'accueil de SA localité ; le jour où le kit passe sur le
 * site de cette localité, l'opérateur de ce kit devient son supérieur. La
 * semaine suivante, le kit est ailleurs et ce n'est plus le même. Figer ce lien
 * à la main le rendrait faux au premier déplacement du kit.
 *
 * Le seul maillon qui se décide est donc : DANS QUELLE LOCALITÉ cet A-OPK
 * travaille. On le désigne par le SITE — c'est ce que l'administration a sous
 * les yeux — et le serveur en tire la localité.
 *
 * L'écran montre la chaîne qui en découle, site par site : le centre, le
 * superviseur de l'unité, l'opérateur dont le kit s'y trouve aujourd'hui.
 * Rattacher sans voir cette chaîne reviendrait à placer quelqu'un à l'aveugle.
 */
export function RattachementAopk() {
    const [regionId, setRegionId] = useState('');
    const [communeId, setCommuneId] = useState('');
    const [siteChoisi, setSiteChoisi] = useState('');
    const [motif, setMotif] = useState('');
    const [choisis, setChoisis] = useState(() => new Set());
    const [rapport, setRapport] = useState(null);

    const regions = useRegions();
    const action = useAction(['volontaires', 'rattachement-aopk']);

    const communes = useQuery({
        queryKey: ['referentiel-communes', regionId],
        queryFn: () => api.lire(avecParametres('/referentiel/communes', { region_id: regionId })),
        enabled: Boolean(regionId),
    });

    const donnees = useQuery({
        queryKey: ['rattachement-aopk', regionId, communeId],
        queryFn: () => api.lire(avecParametres('/volontaires/rattachement', {
            region_id: regionId || undefined,
            commune_id: communeId || undefined,
        })),
    });

    const assistants = donnees.data?.assistants ?? [];
    const sites = donnees.data?.sites ?? [];
    const site = sites.find((s) => String(s.id) === siteChoisi) ?? null;

    const rompus = useMemo(() => assistants.filter((a) => a.rattachement_rompu).length, [assistants]);
    const deployesChoisis = assistants.filter((a) => choisis.has(a.id) && a.deploye).length;

    function basculer(id) {
        setChoisis((actuel) => {
            const suivant = new Set(actuel);
            suivant.has(id) ? suivant.delete(id) : suivant.add(id);

            return suivant;
        });
    }

    function basculerTous() {
        setChoisis((actuel) => (
            actuel.size === assistants.length ? new Set() : new Set(assistants.map((a) => a.id))
        ));
    }

    async function rattacher(evenement) {
        evenement.preventDefault();

        const resultat = await action.lancer(() => api.creer('/volontaires/rattachement', {
            site_id: Number(siteChoisi),
            volontaire_ids: [...choisis],
            motif: motif.trim() || undefined,
        }));

        if (resultat) {
            setRapport(resultat.donnees);
            setChoisis(new Set());
            setMotif('');
            donnees.refetch();
        }
    }

    return (
        <>
            <EnTetePage
                titre="Rattachement des A-OPK"
                sousTitre="Un A-OPK tient l’accueil de sa localité. C’est le site qui détermine son centre, son superviseur, et l’opérateur dont le kit passe ce jour-là."
            />

            <BarreFiltres onReinitialiser={() => { setRegionId(''); setCommuneId(''); setSiteChoisi(''); }}>
                <FiltreListe
                    libelle="Région"
                    tous="Toutes les régions"
                    valeur={regionId}
                    onChange={(v) => { setRegionId(v); setCommuneId(''); setSiteChoisi(''); }}
                    options={regions.map((r) => ({ valeur: String(r.id), libelle: r.nom }))}
                />
                <FiltreListe
                    libelle="Commune"
                    tous="Toutes les communes"
                    valeur={communeId}
                    onChange={(v) => { setCommuneId(v); setSiteChoisi(''); }}
                    options={(communes.data ?? []).map((c) => ({ valeur: String(c.id), libelle: c.nom }))}
                />
            </BarreFiltres>

            {donnees.isPending && <Chargement message="Chargement des A-OPK…" />}
            {donnees.error && <Echec erreur={donnees.error} onReessayer={donnees.refetch} />}

            {donnees.data && (
                <>
                    <div className="grid gap-3 sm:grid-cols-3">
                        <Indicateur libelle="A-OPK du périmètre" valeur={nombre(assistants.length)} />
                        <Indicateur
                            libelle="Chaîne rompue"
                            valeur={nombre(rompus)}
                            precision="Aucun site dans leur localité : aucun kit n’y passera"
                            ton={rompus > 0 ? 'alerte' : 'bon'}
                        />
                        <Indicateur libelle="Sites disponibles" valeur={nombre(sites.length)} />
                    </div>

                    {rapport && (
                        <div className="rounded border border-ardoise-200 bg-white px-4 py-3 text-sm">
                            {rapport.rattaches.length > 0 && (
                                <p className="font-medium text-ardoise-900">
                                    {rapport.rattaches.length} rattaché{rapport.rattaches.length > 1 ? 's' : ''} —{' '}
                                    <span className="font-mono">{rapport.rattaches.map((r) => r.matricule).join(', ')}</span>
                                </p>
                            )}
                            {rapport.refuses.length > 0 && (
                                <ul className="mt-2 space-y-1">
                                    {rapport.refuses.map((r) => (
                                        <li key={r.id} className="text-brique-800">
                                            <span className="font-mono font-medium">{r.matricule}</span> — {r.motif}
                                        </li>
                                    ))}
                                </ul>
                            )}
                            <button type="button" onClick={() => setRapport(null)} className="mt-2 text-xs text-ardoise-600 underline">
                                Fermer
                            </button>
                        </div>
                    )}

                    {choisis.size > 0 && (
                        <Bloc
                            titre={`Rattacher ${nombre(choisis.size)} A-OPK à un site`}
                            precision="Le serveur prend la localité du site : c’est elle qui relie l’agent au kit qui y passe."
                        >
                            {action.message && <div className="mb-4"><Succes message={action.message} onFermer={action.oublierMessage} /></div>}
                            {action.erreur && !action.erreur.estValidation && <div className="mb-4"><Echec erreur={action.erreur} /></div>}

                            <form onSubmit={rattacher} aria-label="Rattacher à un site" className="space-y-4">
                                <Champ nom="site_id" libelle="Site de rattachement" erreurs={action.erreur?.erreurs}>
                                    <Liste value={siteChoisi} onChange={(e) => setSiteChoisi(e.target.value)} required>
                                        <option value="">Choisir un site…</option>
                                        {sites.map((s) => (
                                            <option key={s.id} value={s.id}>
                                                {s.code} — {s.nom} ({s.localite})
                                            </option>
                                        ))}
                                    </Liste>
                                </Champ>

                                {/*
                                  * LA CHAÎNE DU SITE CHOISI, avant de valider.
                                  * L'opérateur est celui d'AUJOURD'HUI : il changera
                                  * au passage suivant du kit, et l'écran le dit.
                                  */}
                                {site && (
                                    <div className="rounded border border-ardoise-200 bg-ardoise-50 px-4 py-3 text-sm">
                                        <p className="text-ardoise-900">
                                            <span className="font-medium">Centre :</span> {site.centre ? `${site.centre.code} — ${site.centre.nom}` : '—'}
                                        </p>
                                        <p className="mt-1 text-ardoise-900">
                                            <span className="font-medium">Superviseur :</span>{' '}
                                            {site.superviseur ? `${site.superviseur.nom} (${site.superviseur.matricule})` : 'aucune unité de supervision sur ce centre'}
                                        </p>
                                        <p className="mt-1 text-ardoise-900">
                                            <span className="font-medium">Opérateur du jour :</span>{' '}
                                            {site.operateur_du_jour
                                                ? `${site.operateur_du_jour.nom} (${site.operateur_du_jour.matricule})`
                                                : 'aucun passage de kit programmé aujourd’hui'}
                                        </p>
                                        <p className="mt-2 text-xs text-ardoise-600">
                                            L’opérateur change à chaque passage du kit. Le rattachement, lui, ne change pas :
                                            il lie l’agent à la localité.
                                        </p>
                                    </div>
                                )}

                                {deployesChoisis > 0 && (
                                    <Champ
                                        nom="motif"
                                        libelle="Motif du déplacement"
                                        erreurs={action.erreur?.erreurs}
                                        aide={`${nombre(deployesChoisis)} des agents choisis sont déjà déployés : les déplacer change leur affectation en cours, et le motif reste sur leur fiche.`}
                                    >
                                        <Texte value={motif} onChange={(e) => setMotif(e.target.value)} rows={2} required />
                                    </Champ>
                                )}

                                <div className="flex gap-2">
                                    <Bouton type="submit" disabled={action.enCours || !siteChoisi}>
                                        {action.enCours ? 'Rattachement…' : 'Rattacher au site'}
                                    </Bouton>
                                    <Bouton variante="secondaire" onClick={() => setChoisis(new Set())}>Tout décocher</Bouton>
                                </div>
                            </form>
                        </Bloc>
                    )}

                    <Tableau
                        cle={(a) => a.id}
                        lignes={assistants}
                        vide={<Vide titre="Aucun A-OPK dans ce périmètre" explication="Élargissez la région ou la commune, ou vérifiez que les fiches ont bien été qualifiées en assistant." />}
                        colonnes={[
                            {
                                cle: 'choix',
                                compact: true,
                                titre: (
                                    <input
                                        type="checkbox"
                                        aria-label="Cocher toute la page"
                                        checked={assistants.length > 0 && choisis.size === assistants.length}
                                        onChange={basculerTous}
                                        className="h-4 w-4"
                                    />
                                ),
                                rendu: (a) => (
                                    <input
                                        type="checkbox"
                                        aria-label={`Choisir ${a.matricule}`}
                                        checked={choisis.has(a.id)}
                                        onChange={() => basculer(a.id)}
                                        className="h-4 w-4"
                                    />
                                ),
                            },
                            { cle: 'matricule', titre: 'Matricule', compact: true, rendu: (a) => <span className="font-mono">{a.matricule}</span> },
                            { cle: 'nom', titre: 'Nom et prénoms', rendu: (a) => nomDe({ nom: a.nom, prenoms: a.prenoms }) },
                            { cle: 'localite', titre: 'Localité', rendu: (a) => a.localite ?? <span className="text-brique-700">aucune</span> },
                            {
                                cle: 'site',
                                titre: 'Site rattaché',
                                rendu: (a) => (a.site
                                    ? <span className="font-mono text-xs">{a.site.code}</span>
                                    : <Pastille ton="alerte">chaîne rompue</Pastille>),
                            },
                            { cle: 'centre', titre: 'Centre', rendu: (a) => a.centre?.code ?? '—' },
                            {
                                cle: 'etat',
                                titre: 'État',
                                compact: true,
                                rendu: (a) => (a.deploye
                                    ? <Pastille ton="bon">déployé</Pastille>
                                    : <Pastille ton="neutre">non déployé</Pastille>),
                            },
                        ]}
                    />
                </>
            )}
        </>
    );
}
