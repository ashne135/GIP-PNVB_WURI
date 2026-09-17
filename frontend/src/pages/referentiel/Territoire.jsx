import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { api, avecParametres } from '../../api/client';
import { useAuth } from '../../auth/ContexteAuth';
import { useAction, useListe } from '../../outils/crochets';
import { EnTetePage } from '../../composants/Page';
import { Bloc } from '../../composants/Fiche';
import { Pagination, Pastille, Tableau } from '../../composants/Tableau';
import { BarreFiltres, FiltreListe, FiltreTexte } from '../../composants/Filtres';
import { Bouton, Champ, Liste, Saisie } from '../../composants/Champs';
import { Chargement } from '../../composants/Chargement';
import { Echec, Succes, Vide } from '../../composants/Etats';
import { nombre } from '../../outils/format';

/**
 * LE RÉFÉRENTIEL TERRITORIAL : régions › provinces › communes › localités.
 *
 * Chacun le parcourt dans son périmètre. L'administration nationale le CORRIGE
 * (dérogation au cadrage décidée par le client) — et c'est pourquoi cet écran
 * impose un APERÇU : une population ou un nombre de sites modifié recalcule les
 * quotas de TOUTE la région. On voit d'abord quelles localités changeraient,
 * et lesquelles ont déjà plus de sites ouverts que leur nouveau quota ; le
 * bouton Enregistrer ne s'ouvre qu'ensuite, pour ces valeurs-là exactement.
 *
 * Les codes ne se corrigent jamais : ils figurent sur des documents imprimés.
 * Rien ne se supprime : centres, sites et volontaires y sont rattachés.
 */

const TYPES_LOCALITE = [
    { valeur: 'village', libelle: 'Village' },
    { valeur: 'secteur', libelle: 'Secteur' },
    { valeur: 'quartier', libelle: 'Quartier' },
];

const A_RAFRAICHIR = [
    'territoire-regions',
    'territoire-provinces',
    'territoire-communes',
    'territoire-localites',
    'referentiel-localites',
    'referentiel-communes',
];

export function Territoire() {
    const auth = useAuth();
    const peutCorriger = auth.peut('referentiel.modifier_territoire');
    const [regionChoisie, setRegionChoisie] = useState(null);
    const [vue, setVue] = useState('localites');
    const [edition, setEdition] = useState(null);
    const [message, setMessage] = useState(null);

    const regions = useQuery({
        queryKey: ['territoire-regions'],
        queryFn: () => api.lire('/referentiel/territoire/regions'),
    });

    const region = (regions.data ?? []).find((r) => r.id === regionChoisie) ?? regions.data?.[0] ?? null;

    function editer(type, objet = null) {
        setMessage(null);
        setEdition({ type, objet });
        // jsdom ne sait pas défiler : on ne fait pas échouer un formulaire pour ça.
        try {
            window.scrollTo?.({ top: 0, behavior: 'smooth' });
        } catch {
            /* sans défilement, le formulaire reste accessible plus haut */
        }
    }

    const onglet = (cle) =>
        `border-b-2 px-3 py-2 text-sm ${
            vue === cle ? 'border-pnvb-700 font-medium text-pnvb-900' : 'border-transparent text-ardoise-600 hover:text-ardoise-900'
        }`;

    return (
        <>
            <EnTetePage
                titre="Référentiel territorial"
                sousTitre="La population de chaque localité fixe son quota de sites, calculé sur toute la région. Les codes ne changent jamais."
            />

            {message && <Succes message={message} onFermer={() => setMessage(null)} />}

            {edition && region && (
                <Edition
                    key={`${edition.type}-${edition.objet?.id ?? 'nouveau'}`}
                    edition={edition}
                    region={region}
                    onAnnuler={() => setEdition(null)}
                    onEnregistre={(texte) => { setEdition(null); setMessage(texte); }}
                />
            )}

            {regions.isPending && <Chargement message="Chargement des régions…" />}
            {regions.error && <Echec erreur={regions.error} onReessayer={regions.refetch} />}

            {regions.data && (
                <Tableau
                    cle={(r) => r.id}
                    lignes={regions.data}
                    onLigne={(r) => { setRegionChoisie(r.id); setEdition(null); }}
                    vide={<Vide titre="Aucune région" explication="Le référentiel territorial n’a pas encore été chargé." />}
                    colonnes={[
                        {
                            cle: 'nom',
                            titre: 'Région',
                            rendu: (r) => (
                                <span className={r.id === region?.id ? 'font-semibold text-pnvb-900' : ''}>
                                    {r.id === region?.id && <span aria-hidden="true">▸ </span>}
                                    {r.nom} <span className="font-mono text-xs text-ardoise-500">{r.code}</span>
                                </span>
                            ),
                        },
                        { cle: 'population', titre: 'Population', alignement: 'droite', rendu: (r) => nombre(r.population_totale) },
                        { cle: 'sites', titre: 'Sites alloués', alignement: 'droite', rendu: (r) => nombre(r.nombre_sites_alloues) },
                        {
                            cle: 'quotas',
                            titre: 'Somme des quotas',
                            alignement: 'droite',
                            rendu: (r) => (Number(r.somme_quotas ?? 0) === Number(r.nombre_sites_alloues)
                                ? nombre(r.somme_quotas ?? 0)
                                : <Pastille ton="attention">{nombre(r.somme_quotas ?? 0)}</Pastille>),
                        },
                        { cle: 'communes', titre: 'Provinces / communes', alignement: 'droite', rendu: (r) => `${r.provinces_count} / ${r.communes_count}` },
                        { cle: 'localites', titre: 'Localités', alignement: 'droite', rendu: (r) => nombre(r.localites_count) },
                        { cle: 'ouverts', titre: 'Centres / sites ouverts', alignement: 'droite', rendu: (r) => `${nombre(r.centres_count)} / ${nombre(r.sites_count)}` },
                        ...(peutCorriger
                            ? [{
                                cle: 'action',
                                titre: '',
                                compact: true,
                                rendu: (r) => (
                                    <Bouton
                                        variante="secondaire"
                                        onClick={(e) => { e.stopPropagation(); setRegionChoisie(r.id); editer('region', r); }}
                                    >
                                        Modifier
                                    </Bouton>
                                ),
                            }]
                            : []),
                    ]}
                />
            )}

            {region && (
                <Bloc
                    titre={`Région ${region.nom}`}
                    precision="Cliquez sur une autre région dans le tableau pour la parcourir."
                    actions={peutCorriger && vue === 'localites'
                        ? <Bouton onClick={() => editer('nouvelle-localite')}>Ajouter une localité</Bouton>
                        : null}
                >
                    <nav className="mb-4 flex flex-wrap gap-1 border-b border-ardoise-200" aria-label="Niveau du référentiel">
                        <button type="button" className={onglet('localites')} aria-pressed={vue === 'localites'} onClick={() => setVue('localites')}>Localités</button>
                        <button type="button" className={onglet('communes')} aria-pressed={vue === 'communes'} onClick={() => setVue('communes')}>Communes</button>
                        <button type="button" className={onglet('provinces')} aria-pressed={vue === 'provinces'} onClick={() => setVue('provinces')}>Provinces</button>
                    </nav>

                    {vue === 'localites' && <Localites key={region.id} region={region} peutCorriger={peutCorriger} onModifier={(l) => editer('localite', l)} />}
                    {vue === 'communes' && <Communes key={region.id} region={region} peutCorriger={peutCorriger} onModifier={(c) => editer('commune', c)} />}
                    {vue === 'provinces' && <Provinces key={region.id} region={region} peutCorriger={peutCorriger} onModifier={(p) => editer('province', p)} />}
                </Bloc>
            )}
        </>
    );
}

// ---------------------------------------------------------------------------
// Les trois niveaux
// ---------------------------------------------------------------------------

function useCommunesDe(regionId) {
    return useQuery({
        queryKey: ['referentiel-communes', regionId],
        queryFn: () => api.lire(avecParametres('/referentiel/communes', { region_id: regionId })),
    });
}

function Localites({ region, peutCorriger, onModifier }) {
    const liste = useListe('territoire-localites', '/referentiel/territoire/localites', { region_id: region.id });
    const communes = useCommunesDe(region.id);

    return (
        <div className="space-y-3">
            <BarreFiltres onReinitialiser={liste.reinitialiser}>
                <FiltreTexte libelle="Localité" valeur={liste.filtres.recherche} onChange={(v) => liste.changerFiltre('recherche', v)} />
                <FiltreListe
                    libelle="Commune"
                    valeur={liste.filtres.commune_id}
                    onChange={(v) => liste.changerFiltre('commune_id', v)}
                    tous="Toutes"
                    options={(communes.data ?? []).map((c) => ({ valeur: c.id, libelle: c.nom }))}
                />
                <FiltreListe
                    libelle="Type"
                    valeur={liste.filtres.type_localite}
                    onChange={(v) => liste.changerFiltre('type_localite', v)}
                    options={TYPES_LOCALITE}
                />
                <label className="flex items-center gap-2 self-end pb-2 text-sm text-ardoise-800">
                    <input
                        type="checkbox"
                        checked={Boolean(liste.filtres.quota_non_atteint)}
                        onChange={(e) => liste.changerFiltre('quota_non_atteint', e.target.checked ? 1 : undefined)}
                        className="h-4 w-4"
                    />
                    Sites encore à ouvrir
                </label>
            </BarreFiltres>

            {liste.isPending && <Chargement message="Chargement des localités…" />}
            {liste.error && <Echec erreur={liste.error} onReessayer={liste.refetch} />}
            {!liste.isPending && !liste.error && (
                <>
                    <Tableau
                        cle={(l) => l.id}
                        lignes={liste.lignes}
                        vide={<Vide titre="Aucune localité ne correspond" />}
                        colonnes={[
                            { cle: 'nom', titre: 'Localité', rendu: (l) => l.nom },
                            { cle: 'commune', titre: 'Commune', rendu: (l) => l.commune?.nom ?? '—' },
                            { cle: 'type', titre: 'Type', compact: true, rendu: (l) => TYPES_LOCALITE.find((t) => t.valeur === l.type_localite)?.libelle ?? l.type_localite },
                            { cle: 'hommes', titre: 'Hommes', alignement: 'droite', rendu: (l) => nombre(l.population_hommes) },
                            { cle: 'femmes', titre: 'Femmes', alignement: 'droite', rendu: (l) => nombre(l.population_femmes) },
                            { cle: 'total', titre: 'Population', alignement: 'droite', rendu: (l) => nombre(l.population_totale) },
                            {
                                cle: 'sites',
                                titre: 'Sites ouverts / quota',
                                alignement: 'droite',
                                rendu: (l) => {
                                    const texte = `${l.sites_count} / ${l.quota_sites}`;

                                    if (l.sites_count > l.quota_sites) {
                                        return <Pastille ton="alerte">{texte}</Pastille>;
                                    }

                                    return l.sites_count < l.quota_sites ? <Pastille ton="attention">{texte}</Pastille> : texte;
                                },
                            },
                            {
                                cle: 'gps',
                                titre: 'Coordonnées',
                                compact: true,
                                rendu: (l) => (l.latitude != null && l.longitude != null
                                    ? <span className="font-mono text-xs">{Number(l.latitude).toFixed(4)}, {Number(l.longitude).toFixed(4)}</span>
                                    : <span className="text-ardoise-400">—</span>),
                            },
                            ...(peutCorriger
                                ? [{ cle: 'action', titre: '', compact: true, rendu: (l) => <Bouton variante="secondaire" onClick={() => onModifier(l)}>Modifier</Bouton> }]
                                : []),
                        ]}
                    />
                    <Pagination page={liste.pagination} onPage={liste.setPage} />
                </>
            )}
        </div>
    );
}

function Communes({ region, peutCorriger, onModifier }) {
    const liste = useListe('territoire-communes', '/referentiel/territoire/communes', { region_id: region.id });
    const provinces = useQuery({
        queryKey: ['territoire-provinces', region.id],
        queryFn: () => api.lire(avecParametres('/referentiel/territoire/provinces', { region_id: region.id })),
    });

    return (
        <div className="space-y-3">
            <p className="max-w-prose text-sm text-ardoise-600">
                La population déclarée d’une commune vient du fichier officiel ; la somme de ses localités est recalculée.
                Un écart entre les deux signale une incohérence du fichier source.
            </p>
            <BarreFiltres onReinitialiser={liste.reinitialiser}>
                <FiltreTexte libelle="Commune" valeur={liste.filtres.recherche} onChange={(v) => liste.changerFiltre('recherche', v)} />
                <FiltreListe
                    libelle="Province"
                    valeur={liste.filtres.province_id}
                    onChange={(v) => liste.changerFiltre('province_id', v)}
                    tous="Toutes"
                    options={(provinces.data ?? []).map((p) => ({ valeur: p.id, libelle: p.nom }))}
                />
                <label className="flex items-center gap-2 self-end pb-2 text-sm text-ardoise-800">
                    <input
                        type="checkbox"
                        checked={Boolean(liste.filtres.avec_ecart)}
                        onChange={(e) => liste.changerFiltre('avec_ecart', e.target.checked ? 1 : undefined)}
                        className="h-4 w-4"
                    />
                    Seulement les écarts de population
                </label>
            </BarreFiltres>

            {liste.isPending && <Chargement message="Chargement des communes…" />}
            {liste.error && <Echec erreur={liste.error} onReessayer={liste.refetch} />}
            {!liste.isPending && !liste.error && (
                <>
                    <Tableau
                        cle={(c) => c.id}
                        lignes={liste.lignes}
                        vide={<Vide titre="Aucune commune ne correspond" />}
                        colonnes={[
                            { cle: 'nom', titre: 'Commune', rendu: (c) => <>{c.nom} <span className="font-mono text-xs text-ardoise-500">{c.code}</span></> },
                            { cle: 'province', titre: 'Province', rendu: (c) => c.province?.nom ?? '—' },
                            { cle: 'type', titre: 'Type', compact: true, rendu: (c) => (c.type === 'urbaine' ? 'Urbaine' : 'Rurale') },
                            { cle: 'declaree', titre: 'Population déclarée', alignement: 'droite', rendu: (c) => nombre(c.population_totale) },
                            { cle: 'localites_pop', titre: 'Somme des localités', alignement: 'droite', rendu: (c) => nombre(c.population_localites) },
                            {
                                cle: 'ecart',
                                titre: 'Écart',
                                alignement: 'droite',
                                rendu: (c) => {
                                    const ecart = Number(c.population_localites ?? 0) - Number(c.population_totale ?? 0);

                                    return ecart === 0 ? '0' : <Pastille ton="attention">{ecart > 0 ? '+' : ''}{nombre(ecart)}</Pastille>;
                                },
                            },
                            {
                                cle: 'securite',
                                titre: 'Zone à défis sécuritaires',
                                compact: true,
                                rendu: (c) => (c.est_zone_defis_securitaires ? <Pastille ton="alerte">oui</Pastille> : 'non'),
                            },
                            { cle: 'nb', titre: 'Localités / centres', alignement: 'droite', rendu: (c) => `${c.localites_count} / ${c.centres_count}` },
                            ...(peutCorriger
                                ? [{ cle: 'action', titre: '', compact: true, rendu: (c) => <Bouton variante="secondaire" onClick={() => onModifier(c)}>Modifier</Bouton> }]
                                : []),
                        ]}
                    />
                    <Pagination page={liste.pagination} onPage={liste.setPage} />
                </>
            )}
        </div>
    );
}

function Provinces({ region, peutCorriger, onModifier }) {
    const provinces = useQuery({
        queryKey: ['territoire-provinces', region.id],
        queryFn: () => api.lire(avecParametres('/referentiel/territoire/provinces', { region_id: region.id })),
    });

    if (provinces.isPending) {
        return <Chargement message="Chargement des provinces…" />;
    }

    if (provinces.error) {
        return <Echec erreur={provinces.error} onReessayer={provinces.refetch} />;
    }

    return (
        <Tableau
            cle={(p) => p.id}
            lignes={provinces.data}
            vide={<Vide titre="Aucune province" />}
            colonnes={[
                { cle: 'nom', titre: 'Province', rendu: (p) => p.nom },
                { cle: 'code', titre: 'Code', compact: true, rendu: (p) => <span className="font-mono text-xs">{p.code}</span> },
                { cle: 'population', titre: 'Population (somme des localités)', alignement: 'droite', rendu: (p) => nombre(p.population_totale) },
                { cle: 'communes', titre: 'Communes', alignement: 'droite', rendu: (p) => p.communes_count },
                ...(peutCorriger
                    ? [{ cle: 'action', titre: '', compact: true, rendu: (p) => <Bouton variante="secondaire" onClick={() => onModifier(p)}>Renommer</Bouton> }]
                    : []),
            ]}
        />
    );
}

// ---------------------------------------------------------------------------
// La correction
// ---------------------------------------------------------------------------

/** Les champs de chaque formulaire, et s'il faut un aperçu des quotas. */
function definition(type, objet) {
    switch (type) {
        case 'region':
            return {
                titre: `Modifier la région ${objet.nom}`,
                url: `/referentiel/territoire/regions/${objet.id}`,
                methode: 'modifier',
                apercu: true,
                valeurs: { nom: objet.nom, nombre_sites_alloues: objet.nombre_sites_alloues },
            };
        case 'province':
            return {
                titre: `Renommer la province ${objet.nom}`,
                url: `/referentiel/territoire/provinces/${objet.id}`,
                methode: 'modifier',
                apercu: false,
                valeurs: { nom: objet.nom },
            };
        case 'commune':
            return {
                titre: `Modifier la commune ${objet.nom}`,
                url: `/referentiel/territoire/communes/${objet.id}`,
                methode: 'modifier',
                apercu: false,
                valeurs: {
                    nom: objet.nom,
                    type: objet.type,
                    population_hommes: objet.population_hommes ?? 0,
                    population_femmes: objet.population_femmes ?? 0,
                    est_zone_defis_securitaires: Boolean(objet.est_zone_defis_securitaires),
                },
            };
        case 'localite':
            return {
                titre: `Modifier la localité ${objet.nom}`,
                url: `/referentiel/territoire/localites/${objet.id}`,
                methode: 'modifier',
                apercu: true,
                valeurs: {
                    nom: objet.nom,
                    type_localite: objet.type_localite,
                    population_hommes: objet.population_hommes ?? 0,
                    population_femmes: objet.population_femmes ?? 0,
                    latitude: objet.latitude ?? '',
                    longitude: objet.longitude ?? '',
                },
            };
        default:
            return {
                titre: 'Ajouter une localité',
                url: '/referentiel/territoire/localites',
                methode: 'creer',
                apercu: true,
                valeurs: {
                    commune_id: '',
                    nom: '',
                    type_localite: 'village',
                    population_hommes: 0,
                    population_femmes: 0,
                    latitude: '',
                    longitude: '',
                },
            };
    }
}

/** Les valeurs telles que le serveur les attend : nombres en nombres, vide en null. */
function corpsDe(valeurs) {
    const corps = {};

    Object.entries(valeurs).forEach(([cle, valeur]) => {
        if (['population_hommes', 'population_femmes', 'nombre_sites_alloues', 'commune_id'].includes(cle)) {
            corps[cle] = valeur === '' ? null : Number(valeur);
        } else if (['latitude', 'longitude'].includes(cle)) {
            corps[cle] = valeur === '' || valeur === null ? null : Number(valeur);
        } else {
            corps[cle] = valeur;
        }
    });

    return corps;
}

function Edition({ edition, region, onAnnuler, onEnregistre }) {
    const def = definition(edition.type, edition.objet);
    const [valeurs, setValeurs] = useState(def.valeurs);
    const [apercu, setApercu] = useState(null);
    const simulation = useAction([]);
    const enregistrement = useAction(A_RAFRAICHIR);
    const communes = useCommunesDe(region.id);

    const corps = corpsDe(valeurs);
    const empreinte = JSON.stringify(corps);
    const apercuAJour = apercu?.empreinte === empreinte;
    const erreurs = (enregistrement.erreur ?? simulation.erreur)?.erreurs;
    const erreurGenerale = [enregistrement.erreur, simulation.erreur].find((e) => e && !e.estValidation);

    const changer = (champ) => (e) => {
        const valeur = e.target.type === 'checkbox' ? e.target.checked : e.target.value;
        setValeurs((v) => ({ ...v, [champ]: valeur }));
    };

    async function voirEffet() {
        const resultat = await simulation.lancer(() => api[def.methode](def.url, { ...corps, simulation: true }));

        if (resultat) {
            setApercu({ empreinte, message: resultat.message, ...resultat.donnees });
        }
    }

    async function enregistrer(evenement) {
        evenement.preventDefault();

        if (def.apercu && !apercuAJour) {
            return;
        }

        const resultat = await enregistrement.lancer(() => api[def.methode](def.url, corps));

        if (resultat) {
            onEnregistre(resultat.message);
        }
    }

    return (
        <Bloc
            titre={def.titre}
            precision={def.apercu
                ? 'Voyez d’abord l’effet sur les quotas de sites de la région : l’enregistrement s’ouvre ensuite, pour ces valeurs-là.'
                : 'Cette correction ne change aucun quota de sites.'}
        >
            <form onSubmit={enregistrer} aria-label={def.titre} className="space-y-4">
                {erreurGenerale && <Echec erreur={erreurGenerale} />}

                <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                    {'commune_id' in valeurs && (
                        <Champ nom="commune_id" libelle="Commune" erreurs={erreurs}>
                            <Liste id="territoire-commune" value={valeurs.commune_id} onChange={changer('commune_id')} required>
                                <option value="">Choisir…</option>
                                {(communes.data ?? []).map((c) => <option key={c.id} value={c.id}>{c.nom}</option>)}
                            </Liste>
                        </Champ>
                    )}
                    {'nom' in valeurs && (
                        <Champ nom="nom" libelle="Nom" erreurs={erreurs}>
                            <Saisie id="territoire-nom" value={valeurs.nom} onChange={changer('nom')} maxLength={120} required />
                        </Champ>
                    )}
                    {'nombre_sites_alloues' in valeurs && (
                        <Champ nom="nombre_sites_alloues" libelle="Sites alloués à la région" erreurs={erreurs}>
                            <Saisie id="territoire-sites" type="number" min="1" value={valeurs.nombre_sites_alloues} onChange={changer('nombre_sites_alloues')} required />
                        </Champ>
                    )}
                    {'type' in valeurs && (
                        <Champ nom="type" libelle="Type de commune" erreurs={erreurs}>
                            <Liste id="territoire-type-commune" value={valeurs.type} onChange={changer('type')}>
                                <option value="rurale">Rurale</option>
                                <option value="urbaine">Urbaine</option>
                            </Liste>
                        </Champ>
                    )}
                    {'type_localite' in valeurs && (
                        <Champ nom="type_localite" libelle="Type de localité" erreurs={erreurs}>
                            <Liste id="territoire-type-localite" value={valeurs.type_localite} onChange={changer('type_localite')}>
                                {TYPES_LOCALITE.map((t) => <option key={t.valeur} value={t.valeur}>{t.libelle}</option>)}
                            </Liste>
                        </Champ>
                    )}
                    {'population_hommes' in valeurs && (
                        <Champ nom="population_hommes" libelle={edition.type === 'commune' ? 'Hommes (déclarés)' : 'Hommes'} erreurs={erreurs}>
                            <Saisie id="territoire-hommes" type="number" min="0" value={valeurs.population_hommes} onChange={changer('population_hommes')} required />
                        </Champ>
                    )}
                    {'population_femmes' in valeurs && (
                        <Champ nom="population_femmes" libelle={edition.type === 'commune' ? 'Femmes (déclarées)' : 'Femmes'} erreurs={erreurs}>
                            <Saisie id="territoire-femmes" type="number" min="0" value={valeurs.population_femmes} onChange={changer('population_femmes')} required />
                        </Champ>
                    )}
                    {'latitude' in valeurs && (
                        <Champ nom="latitude" libelle="Latitude (facultative)" erreurs={erreurs}>
                            <Saisie id="territoire-latitude" type="number" step="any" min="-90" max="90" value={valeurs.latitude} onChange={changer('latitude')} />
                        </Champ>
                    )}
                    {'longitude' in valeurs && (
                        <Champ nom="longitude" libelle="Longitude (facultative)" erreurs={erreurs}>
                            <Saisie id="territoire-longitude" type="number" step="any" min="-180" max="180" value={valeurs.longitude} onChange={changer('longitude')} />
                        </Champ>
                    )}
                    {'est_zone_defis_securitaires' in valeurs && (
                        <label className="flex items-center gap-2 self-end pb-2 text-sm text-ardoise-800">
                            <input
                                id="territoire-securite"
                                type="checkbox"
                                checked={valeurs.est_zone_defis_securitaires}
                                onChange={changer('est_zone_defis_securitaires')}
                                className="h-4 w-4"
                            />
                            Zone à défis sécuritaires
                        </label>
                    )}
                </div>

                {def.apercu && apercu && (
                    <Apercu apercu={apercu} perime={!apercuAJour} />
                )}

                <div className="flex flex-wrap gap-2">
                    {def.apercu && (
                        <Bouton type="button" variante={apercuAJour ? 'secondaire' : 'principal'} disabled={simulation.enCours} onClick={voirEffet}>
                            {simulation.enCours ? 'Calcul…' : 'Voir l’effet sur les quotas'}
                        </Bouton>
                    )}
                    <Bouton type="submit" disabled={enregistrement.enCours || (def.apercu && !apercuAJour)}>
                        {enregistrement.enCours ? 'Enregistrement…' : 'Enregistrer'}
                    </Bouton>
                    <Bouton type="button" variante="secondaire" onClick={onAnnuler}>Annuler</Bouton>
                </div>
            </form>
        </Bloc>
    );
}

function Apercu({ apercu, perime }) {
    const alertes = new Set((apercu.alertes ?? []).map((a) => a.localite_id));

    return (
        <div className={`space-y-2 rounded border px-3 py-3 ${perime ? 'border-ardoise-200 opacity-60' : 'border-pnvb-200 bg-pnvb-50'}`}>
            <p className="text-sm font-medium text-ardoise-900" role="status">
                {perime ? 'Les valeurs ont changé depuis cet aperçu : relancez-le avant d’enregistrer.' : apercu.message}
            </p>
            {apercu.changements?.length > 0 && (
                <Tableau
                    cle={(c) => c.localite_id}
                    lignes={apercu.changements}
                    colonnes={[
                        { cle: 'localite', titre: 'Localité', rendu: (c) => c.localite },
                        { cle: 'commune', titre: 'Commune', rendu: (c) => c.commune ?? '—' },
                        { cle: 'avant', titre: 'Quota actuel', alignement: 'droite', rendu: (c) => (c.quota_avant ?? 'nouvelle') },
                        { cle: 'apres', titre: 'Nouveau quota', alignement: 'droite', rendu: (c) => c.quota_apres },
                        {
                            cle: 'sites',
                            titre: 'Sites déjà ouverts',
                            alignement: 'droite',
                            rendu: (c) => (alertes.has(c.localite_id)
                                ? <Pastille ton="alerte">{c.sites_existants} — au-dessus du quota</Pastille>
                                : c.sites_existants),
                        },
                    ]}
                />
            )}
        </div>
    );
}
