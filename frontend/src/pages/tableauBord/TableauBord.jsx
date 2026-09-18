import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { api, avecParametres } from '../../api/client';
import { useAuth } from '../../auth/ContexteAuth';
import { Chargement } from '../../composants/Chargement';
import { Echec, Vide } from '../../composants/Etats';
import { EnTetePage, Indicateur } from '../../composants/Page';
import { CourbeJournaliere } from '../../graphiques/CourbeJournaliere';
import { BarresHorizontales } from '../../graphiques/BarresHorizontales';
import { CarteCouverture } from '../../graphiques/CarteCouverture';
import { viz } from '../../graphiques/viz';
import { dateLongue, nombre, pourcentage, veille } from '../../outils/format';
import { dateCourte, debutPeriode, dernierJourActif } from './outils';
import { BandeauVague, CartoucheParc, FilesDAttente, Section } from './composants';

/**
 * LE TABLEAU DE BORD (cadrage, section 15).
 *
 * Il répond à trois questions, dans cet ordre — et l'ordre est le design :
 *
 *   1. QUI EST DÉPLOYÉ EN CE MOMENT ?   la vague en cours, le parc de kits ;
 *   2. QU'EST-CE QUI ATTEND QUELQU'UN ? les files, cliquables, et elles seules
 *                                        quand elles ne sont pas vides ;
 *   3. OÙ EN EST LA COLLECTE ?          les chiffres du jour, la courbe, la
 *                                        couverture, les centres, les retards.
 *
 * Les deux premières se lisent EN DIRECT ; la troisième vient des agrégats
 * recalculés chaque nuit, à partir des rapports VISÉS et des feuilles
 * VALIDÉES. La date de référence est donc affichée en toutes lettres : sans
 * elle, on croirait lire l'instant présent.
 *
 * L'EFFECTIF EST TOUJOURS AFFICHÉ AVEC SA NATURE : un pic régional au
 * national, une somme de sites dans une région. La règle « on n'additionne
 * jamais les régions » ne doit pas se perdre à l'affichage.
 *
 * Pendant un rechargement, les graphiques gardent leur dernier état, estompé :
 * pas de squelette, pas de saut de mise en page.
 */
const PERIODES = [
    { jours: 7, libelle: '7 derniers jours' },
    { jours: 14, libelle: '14 derniers jours' },
    { jours: 30, libelle: '30 derniers jours' },
    { jours: 90, libelle: '90 derniers jours' },
];

const garderLePrecedent = (precedent) => precedent;

export function TableauBord() {
    const auth = useAuth();
    const [periode, setPeriode] = useState(14);
    const [vueCourbe, setVueCourbe] = useState('jour');

    const au = veille();
    const du = debutPeriode(periode);

    const pilotage = useQuery({
        queryKey: ['tableau-bord-pilotage'],
        queryFn: () => api.lire('/tableau-bord/pilotage'),
    });

    const evolution = useQuery({
        queryKey: ['tableau-bord-evolution', du, au],
        queryFn: () => api.lire(avecParametres('/tableau-bord/evolution', { du, au })),
        placeholderData: garderLePrecedent,
    });

    const jourSynthese = dernierJourActif(evolution.data?.jours);

    const synthese = useQuery({
        queryKey: ['tableau-bord', jourSynthese],
        queryFn: () => api.lire(avecParametres('/tableau-bord', { date: jourSynthese })),
        enabled: jourSynthese !== null,
        placeholderData: garderLePrecedent,
    });

    const centres = useQuery({
        queryKey: ['tableau-bord-centres', jourSynthese],
        queryFn: () => api.lire(avecParametres('/tableau-bord/centres', { date: jourSynthese })),
        enabled: jourSynthese !== null,
        placeholderData: garderLePrecedent,
    });

    const couverture = useQuery({ queryKey: ['tableau-bord-couverture'], queryFn: () => api.lire('/tableau-bord/couverture') });
    const sitesCarte = useQuery({ queryKey: ['tableau-bord-sites-carte'], queryFn: () => api.lire('/tableau-bord/sites-carte') });
    const retards = useQuery({
        queryKey: ['tableau-bord-retards'],
        queryFn: () => api.lire(avecParametres('/tableau-bord/retards', { limite: 10 })),
    });

    const estompe = (requete) => (requete.isFetching && requete.data ? 'opacity-60 transition-opacity' : '');
    const jours = evolution.data?.jours ?? [];

    return (
        <div className="space-y-8">
            <EnTetePage
                titre="Tableau de bord"
                sousTitre={auth.estNational
                    ? 'Les douze régions. Les effectifs ne s’additionnent jamais entre elles.'
                    : 'Votre région.'}
            />

            {/* 1. Ce qui est déployé en ce moment — lu en direct. */}
            <Section
                titre="Déploiement en cours"
                precision="L’état de la plateforme à l’instant où vous lisez cet écran."
            >
                {pilotage.error && <Echec erreur={pilotage.error} onReessayer={pilotage.refetch} />}
                {pilotage.isPending && <Chargement message="Chargement du déploiement…" />}
                {pilotage.data && (
                    <div className="grid gap-4 lg:grid-cols-3">
                        <div className="lg:col-span-2">
                            <BandeauVague vague={pilotage.data.vague} peut={auth.peut} />
                        </div>
                        {auth.peut('kits.consulter') && <CartoucheParc materiel={pilotage.data.materiel} />}
                    </div>
                )}
            </Section>

            {/* 2. Ce qui attend quelqu'un — les files, et elles seules. */}
            <Section
                titre="À traiter"
                precision="Chaque chiffre ouvre l’écran où l’on s’en occupe. Les files vides ne s’affichent pas."
            >
                {pilotage.data && <FilesDAttente pilotage={pilotage.data} peut={auth.peut} />}
            </Section>

            {/* 3. Où en est la collecte — les agrégats de la nuit. */}
            <Section
                titre="Collecte"
                precision={jourSynthese
                    ? `Chiffres du ${dateLongue(jourSynthese)}, dernier jour avec des rapports visés.`
                    : 'Recalculée chaque nuit à partir des rapports visés et des feuilles validées.'}
                actions={(
                    <label className="block">
                        <span className="mb-1 block text-xs font-medium text-ardoise-600">Période</span>
                        <select
                            value={periode}
                            onChange={(e) => setPeriode(Number(e.target.value))}
                            className="rounded border border-ardoise-300 bg-white px-3 py-2 text-sm text-ardoise-900 focus:border-pnvb-500 focus:outline-none focus:ring-2 focus:ring-pnvb-200"
                        >
                            {PERIODES.map((p) => (
                                <option key={p.jours} value={p.jours}>{p.libelle}</option>
                            ))}
                        </select>
                    </label>
                )}
            >
                {evolution.isPending && <Chargement message="Chargement des chiffres…" />}
                {evolution.error && <Echec erreur={evolution.error} onReessayer={evolution.refetch} />}

                {!evolution.isPending && !evolution.error && (jourSynthese === null ? (
                    <Vide
                        titre="Aucune activité sur cette période"
                        explication={
                            'Les indicateurs sont recalculés chaque nuit à partir des rapports VISÉS et des feuilles de '
                            + 'présence VALIDÉES. Élargissez la période, ou attendez les premiers visas.'
                        }
                    />
                ) : (
                    <>
                        {synthese.error && <Echec erreur={synthese.error} onReessayer={synthese.refetch} />}
                        {synthese.data && (
                            <Indicateurs synthese={synthese.data} jours={jours} classe={estompe(synthese)} />
                        )}

                        <div className={estompe(evolution)}>
                            <CourbeJournaliere
                                titre={vueCourbe === 'jour' ? 'Enregistrements par jour' : 'Enregistrements cumulés'}
                                sousTitre={vueCourbe === 'jour'
                                    ? 'Somme des rapports d’opérateur visés. Les jours sans activité ne figurent pas.'
                                    : 'Cumul depuis le début de la période affichée.'}
                                jours={jours.map((jour) => ({
                                    date: jour.date,
                                    valeur: Number(vueCourbe === 'jour' ? jour.enregistrements : jour.cumul),
                                }))}
                                libelleValeur="enregistrements"
                                formatDate={dateCourte}
                                actions={(
                                    <div className="flex gap-1 rounded border border-ardoise-200 p-0.5" role="group" aria-label="Vue de la courbe">
                                        {[
                                            { cle: 'jour', libelle: 'Par jour' },
                                            { cle: 'cumul', libelle: 'Cumulé' },
                                        ].map((vue) => (
                                            <button
                                                key={vue.cle}
                                                type="button"
                                                aria-pressed={vueCourbe === vue.cle}
                                                onClick={() => setVueCourbe(vue.cle)}
                                                className={`rounded px-2.5 py-1 text-xs font-medium ${
                                                    vueCourbe === vue.cle
                                                        ? 'bg-pnvb-700 text-white'
                                                        : 'text-ardoise-600 hover:bg-ardoise-50'
                                                }`}
                                            >
                                                {vue.libelle}
                                            </button>
                                        ))}
                                    </div>
                                )}
                            />
                        </div>
                    </>
                ))}
            </Section>

            <Section
                titre="Couverture du territoire"
                precision="Personnes enregistrées rapportées à la population. Une région sans population connue n’est pas mesurable."
            >
                <div className="grid gap-4 xl:grid-cols-2">
                    <div>
                        {couverture.error && <Echec erreur={couverture.error} onReessayer={couverture.refetch} />}
                        {couverture.data && (
                            <BarresHorizontales
                                titre="Taux de couverture par région"
                                sousTitre="Personnes enregistrées rapportées à la population de la région."
                                lignes={couverture.data.map((region) => ({
                                    cle: region.code,
                                    libelle: region.nom,
                                    valeur: region.taux_couverture,
                                    precision: `${nombre(region.enregistres)} sur ${nombre(region.population)} habitants`,
                                }))}
                                formatValeur={(valeur) => pourcentage(valeur)}
                                libelleNonMesurable="non mesurable : population inconnue"
                            />
                        )}
                    </div>
                    <div>
                        {sitesCarte.error && <Echec erreur={sitesCarte.error} onReessayer={sitesCarte.refetch} />}
                        <CarteCouverture regions={couverture.data ?? []} sitesCarte={sitesCarte.data ?? null} />
                    </div>
                </div>
            </Section>

            <Section
                titre="Le détail qui sert à décider"
                precision="Les centres du jour, et les localités où il faut retourner."
            >
                {jourSynthese !== null && (
                    <div className={estompe(centres)}>
                        <ClassementCentres centres={centres.data} erreur={centres.error} jour={jourSynthese} />
                    </div>
                )}
                <LocalitesEnRetard retards={retards.data} erreur={retards.error} />
            </Section>
        </div>
    );
}

function Indicateurs({ synthese, jours, classe }) {
    const effectif = synthese.deploiement?.effectif_simultane ?? {};
    const couverture = synthese.couverture ?? {};
    const cumulPeriode = jours.reduce((total, jour) => total + Number(jour.enregistrements), 0);

    return (
        <div className={`space-y-3 ${classe}`}>
            <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                <Indicateur
                    libelle="Enregistrements du jour"
                    valeur={nombre(synthese.enregistrements?.du_jour)}
                    precision={`${nombre(cumulPeriode)} sur la période · ${nombre(synthese.enregistrements?.cumul)} depuis le début`}
                />
                <Indicateur
                    libelle="Effectif simultané"
                    valeur={nombre(effectif.valeur)}
                    // La nature du chiffre fait partie du chiffre.
                    precision={effectif.nature === 'pic_regional'
                        ? `Pic régional${effectif.region ? ` — ${effectif.region}` : ''}, jamais un cumul`
                        : 'Somme des sites de la région'}
                />
                <Indicateur
                    libelle="Taux de présence"
                    valeur={pourcentage(synthese.deploiement?.taux_presence)}
                    precision="Pondéré par les effectifs attendus"
                    ton={tonPresence(synthese.deploiement?.taux_presence)}
                />
                <Indicateur
                    libelle="Taux de couverture"
                    valeur={couverture.taux === null || couverture.taux === undefined ? '—' : pourcentage(couverture.taux, 2)}
                    precision={`${nombre(couverture.enregistres)} sur ${nombre(couverture.population_cible)} habitants`}
                />
            </div>
            <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                <Indicateur libelle="Centres ouverts" valeur={nombre(synthese.deploiement?.centres_ouverts)} />
                <Indicateur libelle="Sites couverts" valeur={nombre(synthese.deploiement?.sites_couverts)} />
                <Indicateur
                    libelle="Incidents ouverts"
                    valeur={nombre(synthese.incidents_ouverts?.total)}
                    precision={`dont ${nombre(synthese.incidents_ouverts?.niveau_4)} critiques`}
                    ton={synthese.incidents_ouverts?.niveau_4 > 0 ? 'alerte' : 'neutre'}
                />
                <Indicateur libelle="Rejets du jour" valeur={nombre(synthese.enregistrements?.rejetes_du_jour)} />
            </div>
        </div>
    );
}

/** Le taux de présence porte un sens bon/mauvais : il prend les couleurs d'état, avec son libellé. */
function tonPresence(taux) {
    if (taux === null || taux === undefined) {
        return 'neutre';
    }

    return taux >= 85 ? 'bon' : taux >= 70 ? 'attention' : 'alerte';
}

/**
 * LES CENTRES DU JOUR — en tableau : trop de lignes pour un graphique. Une
 * barre fine accompagne la production pour situer chaque centre d'un coup d'œil.
 */
function ClassementCentres({ centres, erreur, jour }) {
    if (erreur) {
        return <Echec erreur={erreur} />;
    }

    const lignes = (centres ?? []).slice(0, 15);
    const maximum = Math.max(1, ...lignes.map((c) => c.enregistrements));

    return (
        <section className="rounded-lg border border-ardoise-200 bg-white shadow-sm">
            <header className="border-b border-ardoise-200 px-4 py-3">
                <h3 className="text-sm font-semibold text-ardoise-900">Centres du {dateLongue(jour)}</h3>
                <p className="mt-0.5 text-xs text-ardoise-600">
                    Les quinze plus productifs{centres && centres.length > 15 ? ` sur ${nombre(centres.length)}` : ''}.
                </p>
            </header>
            {lignes.length === 0 ? (
                <p className="px-4 py-8 text-center text-sm text-ardoise-600">Aucun centre actif ce jour-là.</p>
            ) : (
                <div className="overflow-x-auto">
                    <table className="min-w-full divide-y divide-ardoise-200 text-sm">
                        <thead className="bg-ardoise-50">
                            <tr className="text-left text-xs uppercase tracking-wide text-ardoise-600">
                                <th className="px-4 py-2 font-semibold">Centre</th>
                                <th className="px-4 py-2 font-semibold">Région</th>
                                <th className="px-4 py-2 font-semibold">Enregistrements</th>
                                <th className="px-4 py-2 text-right font-semibold">Présence</th>
                                <th className="px-4 py-2 text-right font-semibold">Incidents ouverts</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-ardoise-100">
                            {lignes.map((centre) => (
                                <tr key={centre.centre}>
                                    <td className="px-4 py-2">
                                        <p className="font-medium text-ardoise-900">{centre.nom}</p>
                                        <p className="font-mono text-xs text-ardoise-600">{centre.centre}</p>
                                    </td>
                                    <td className="px-4 py-2 text-ardoise-800">{centre.region}</td>
                                    <td className="px-4 py-2">
                                        <div className="flex items-center gap-3">
                                            <span className="w-14 text-right tabular-nums text-ardoise-900">{nombre(centre.enregistrements)}</span>
                                            <span className="relative block h-2 w-32 max-w-full">
                                                <span
                                                    className="absolute inset-y-0 left-0 rounded-r"
                                                    style={{ width: `${(centre.enregistrements / maximum) * 100}%`, background: viz.serie }}
                                                    aria-hidden="true"
                                                />
                                            </span>
                                        </div>
                                    </td>
                                    <td className="px-4 py-2 text-right tabular-nums text-ardoise-900">
                                        {pourcentage(centre.taux_presence)}
                                        <span className="block text-xs text-ardoise-600">
                                            {nombre(centre.effectif_present)} / {nombre(centre.effectif_attendu)}
                                        </span>
                                    </td>
                                    <td className="px-4 py-2 text-right tabular-nums text-ardoise-900">{nombre(centre.incidents_ouverts)}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}
        </section>
    );
}

/** LES LOCALITÉS OÙ IL FAUT RETOURNER — la liste qui sert à décider, bien plus qu'une moyenne. */
function LocalitesEnRetard({ retards, erreur }) {
    if (erreur) {
        return <Echec erreur={erreur} />;
    }

    const lignes = retards ?? [];

    return (
        <section className="rounded-lg border border-ardoise-200 bg-white shadow-sm">
            <header className="border-b border-ardoise-200 px-4 py-3">
                <h3 className="text-sm font-semibold text-ardoise-900">Localités les moins couvertes</h3>
                <p className="mt-0.5 text-xs text-ardoise-600">
                    Parmi celles qui ont déjà eu de l’activité. Les localités sans population connue ne sont pas classées.
                </p>
            </header>
            {lignes.length === 0 ? (
                <p className="px-4 py-8 text-center text-sm text-ardoise-600">Aucune localité mesurable pour l’instant.</p>
            ) : (
                <div className="overflow-x-auto">
                    <table className="min-w-full divide-y divide-ardoise-200 text-sm">
                        <thead className="bg-ardoise-50">
                            <tr className="text-left text-xs uppercase tracking-wide text-ardoise-600">
                                <th className="px-4 py-2 font-semibold">Localité</th>
                                <th className="px-4 py-2 font-semibold">Commune · région</th>
                                <th className="px-4 py-2 text-right font-semibold">Couverture</th>
                                <th className="px-4 py-2 text-right font-semibold">Enregistrés / population</th>
                                <th className="px-4 py-2 text-right font-semibold">Sites couverts</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-ardoise-100">
                            {lignes.map((ligne) => (
                                <tr key={`${ligne.commune}-${ligne.localite}`}>
                                    <td className="px-4 py-2 font-medium text-ardoise-900">{ligne.localite}</td>
                                    <td className="px-4 py-2 text-ardoise-800">{ligne.commune} · {ligne.region}</td>
                                    <td className="px-4 py-2 text-right tabular-nums text-ardoise-900">{pourcentage(ligne.taux_couverture)}</td>
                                    <td className="px-4 py-2 text-right tabular-nums text-ardoise-800">
                                        {nombre(ligne.enregistres)} / {nombre(ligne.population_cible)}
                                    </td>
                                    <td className="px-4 py-2 text-right tabular-nums text-ardoise-800">
                                        {nombre(ligne.sites_couverts)} / {nombre(ligne.sites)}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}
        </section>
    );
}
