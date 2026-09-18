import { Link } from 'react-router-dom';
import { nombre } from '../../outils/format';

/**
 * LES PIÈCES DU TABLEAU DE BORD.
 *
 * Deux registres, et la distinction tient tout l'écran :
 *
 *   MESURER — ce qui s'est passé : des chiffres, des courbes, des cartes.
 *   AGIR    — ce qui attend quelqu'un : des files d'attente, cliquables.
 *
 * Les secondes ne s'affichent que lorsqu'elles ne sont pas vides. Une rangée
 * de zéros occupe la place sans rien dire, et finit par ne plus être lue.
 */

/** Un titre de section : l'œil doit pouvoir sauter d'un bloc à l'autre. */
export function Section({ titre, precision, actions, children }) {
    return (
        <section className="space-y-3">
            <div className="flex flex-wrap items-end justify-between gap-3">
                <div>
                    <h2 className="text-sm font-semibold uppercase tracking-wide text-ardoise-700">{titre}</h2>
                    {precision && <p className="mt-0.5 max-w-prose text-sm text-ardoise-500">{precision}</p>}
                </div>
                {actions}
            </div>
            {children}
        </section>
    );
}

/**
 * LA VAGUE EN COURS — ce qui est déployé en ce moment, et par qui.
 *
 * Les effectifs affichés sont ceux de CETTE vague, donc d'une même région :
 * des personnes distinctes, qui s'additionnent sans mentir. L'effectif
 * national, lui, reste un pic, et il vit dans les chiffres clés.
 */
export function BandeauVague({ vague, peut }) {
    if (!vague) {
        return (
            <div className="rounded-xl border border-dashed border-ardoise-300 bg-white px-5 py-6">
                <p className="text-sm font-medium text-ardoise-800">Aucune vague active</p>
                <p className="mt-1 max-w-prose text-sm text-ardoise-600">
                    Personne n’est déployé pour l’instant. Une vague se planifie sur une région et une période, puis le
                    tirage propose les équipes.
                </p>
                {peut('vagues.planifier') && (
                    <Link
                        to="/vagues/planifier"
                        className="mt-3 inline-block rounded border border-pnvb-300 px-3 py-1.5 text-sm font-medium text-pnvb-800 hover:bg-pnvb-50"
                    >
                        Planifier une vague
                    </Link>
                )}
            </div>
        );
    }

    const agents = vague.agents ?? {};
    const restants = vague.jours_restants;

    return (
        <div className="overflow-hidden rounded-xl border border-pnvb-200 bg-gradient-to-br from-pnvb-900 to-pnvb-700 text-white shadow-sm">
            <div className="flex flex-wrap items-start justify-between gap-4 px-5 py-4">
                <div>
                    <p className="text-xs font-semibold uppercase tracking-wide text-pnvb-100">Vague en cours</p>
                    <p className="mt-1 text-lg font-semibold">
                        {vague.libelle} <span className="font-mono text-sm text-pnvb-100">{vague.code}</span>
                    </p>
                    <p className="mt-0.5 text-sm text-pnvb-100">
                        {vague.region}
                        {vague.date_debut && vague.date_fin && <> · du {vague.date_debut} au {vague.date_fin}</>}
                    </p>
                </div>
                <div className="text-right">
                    {restants !== null && restants !== undefined && (
                        <p className="text-sm text-pnvb-100">
                            {restants > 0
                                ? <><span className="text-2xl font-semibold text-white">{nombre(restants)}</span> jours restants</>
                                : restants === 0
                                    ? 'Dernier jour de la vague'
                                    : 'Période dépassée : la vague reste à clôturer'}
                        </p>
                    )}
                    <Link to={`/vagues/${vague.id}`} className="mt-2 inline-block text-sm font-medium text-white underline">
                        Voir la vague
                    </Link>
                </div>
            </div>

            <div className="grid gap-px bg-pnvb-600/40 sm:grid-cols-4">
                {[
                    { libelle: 'Agents déployés', valeur: agents.total },
                    { libelle: 'Superviseurs', valeur: agents.superviseur },
                    { libelle: 'Opérateurs de kit', valeur: agents.operateur },
                    { libelle: 'A-OPK', valeur: agents.assistant },
                ].map((chiffre) => (
                    <div key={chiffre.libelle} className="bg-pnvb-800/50 px-5 py-3">
                        <p className="text-xl font-semibold tabular-nums">{nombre(chiffre.valeur ?? 0)}</p>
                        <p className="text-xs text-pnvb-100">{chiffre.libelle}</p>
                    </div>
                ))}
            </div>

            {vague.centres && (
                <p className="bg-pnvb-900/40 px-5 py-2 text-xs text-pnvb-100">
                    {nombre(vague.centres.ouverts)} centres ouverts sur {nombre(vague.centres.total)} prévus par la vague.
                </p>
            )}
        </div>
    );
}

/** LE PARC DE KITS : ce qui est disponible, ce qui manque. */
export function CartoucheParc({ materiel }) {
    if (!materiel) {
        return null;
    }

    const lignes = [
        { libelle: 'Disponibles', valeur: materiel.disponibles, ton: 'text-vert-700' },
        { libelle: 'Hors service', valeur: materiel.hors_service, ton: materiel.hors_service > 0 ? 'text-ocre-700' : 'text-ardoise-700' },
        { libelle: 'Non restitués', valeur: materiel.non_restitues, ton: materiel.non_restitues > 0 ? 'text-brique-700' : 'text-ardoise-700' },
    ];

    return (
        <div className="rounded-xl border border-ardoise-200 bg-white px-5 py-4 shadow-sm">
            <div className="flex items-baseline justify-between">
                <p className="text-xs font-semibold uppercase tracking-wide text-ardoise-600">Parc de kits</p>
                <Link to="/kits" className="text-xs text-pnvb-800 underline">Voir le parc</Link>
            </div>
            <p className="mt-1 text-3xl font-semibold tabular-nums text-ardoise-900">{nombre(materiel.total)}</p>
            <ul className="mt-3 space-y-1.5">
                {lignes.map((ligne) => (
                    <li key={ligne.libelle} className="flex items-baseline justify-between text-sm">
                        <span className="text-ardoise-600">{ligne.libelle}</span>
                        <span className={`font-semibold tabular-nums ${ligne.ton}`}>{nombre(ligne.valeur ?? 0)}</span>
                    </li>
                ))}
            </ul>
        </div>
    );
}

/**
 * CE QUI ATTEND QUELQU'UN — chaque file mène à l'écran où on la traite.
 *
 * Une file vide ne s'affiche pas : ce bloc dit ce qu'il reste à faire, pas ce
 * qui est fait. S'il ne reste rien, il le dit en une phrase et disparaît des
 * préoccupations.
 *
 * Chaque entrée porte la permission de l'écran qu'elle ouvre : on ne propose
 * pas un chemin que le serveur refusera.
 */
export function FilesDAttente({ pilotage, peut }) {
    const volontaires = pilotage?.volontaires ?? {};
    const terrain = pilotage?.terrain ?? {};
    const materiel = pilotage?.materiel ?? {};

    const files = [
        {
            cle: 'incidents_en_retard',
            valeur: terrain.incidents_en_retard,
            libelle: 'incidents sans prise en charge',
            precision: 'Échéance d’escalade dépassée',
            chemin: '/incidents/en-retard',
            permission: 'incidents.consulter',
            ton: 'alerte',
        },
        {
            cle: 'incidents_critiques',
            valeur: terrain.incidents_critiques,
            libelle: 'incidents critiques ouverts',
            precision: 'Gravité 4',
            chemin: '/incidents',
            permission: 'incidents.consulter',
            ton: 'alerte',
        },
        {
            cle: 'kits',
            valeur: materiel.non_restitues,
            libelle: 'kits non restitués',
            precision: 'Mission terminée, kit toujours détenu',
            chemin: '/kits/non-restitues',
            permission: 'kits.consulter',
            ton: 'alerte',
        },
        {
            cle: 'rapports',
            valeur: terrain.rapports_a_viser,
            libelle: 'rapports attendent votre visa',
            precision: 'La chaîne s’arrête tant qu’ils ne sont pas visés',
            chemin: '/rapports/a-viser',
            permission: 'rapports.viser',
            ton: 'attention',
        },
        {
            cle: 'ecarts',
            valeur: terrain.ecarts_ouverts,
            libelle: 'écarts de présence à examiner',
            precision: 'Feuille validée que les relevés ne confirment pas',
            chemin: '/presences/ecarts',
            permission: 'ecarts.consulter',
            ton: 'attention',
        },
        {
            cle: 'a_qualifier',
            valeur: volontaires.a_qualifier,
            libelle: 'fiches sans profil',
            precision: 'Aucune n’entre dans un tirage',
            chemin: '/volontaires/a-qualifier',
            permission: 'volontaires.qualifier',
            ton: 'attention',
        },
        {
            cle: 'sans_niveau',
            valeur: volontaires.sans_niveau,
            libelle: 'fiches sans niveau d’étude',
            precision: 'Le niveau commande le profil',
            chemin: '/volontaires',
            permission: 'volontaires.modifier',
            ton: 'attention',
        },
        {
            cle: 'identifiants',
            valeur: volontaires.identifiants_non_remis,
            libelle: 'identifiants non remis',
            precision: 'Non envoyés, ou envoi en échec',
            chemin: '/volontaires/identifiants',
            permission: 'comptes.consulter',
            ton: 'info',
        },
        {
            cle: 'alertes',
            valeur: terrain.alertes_non_lues,
            libelle: 'alertes non lues',
            precision: 'Publiées et toujours en cours',
            chemin: '/alertes',
            permission: 'alertes.consulter',
            ton: 'info',
        },
    ];

    const visibles = files.filter((file) => Number(file.valeur ?? 0) > 0 && peut(file.permission));

    if (visibles.length === 0) {
        return (
            <p className="rounded-xl border border-vert-100 bg-vert-50 px-5 py-4 text-sm text-vert-800">
                Rien n’attend de vous : aucune file d’attente dans votre périmètre.
            </p>
        );
    }

    const tons = {
        alerte: 'border-l-brique-600 hover:bg-brique-50',
        attention: 'border-l-ocre-600 hover:bg-ocre-50',
        info: 'border-l-pnvb-600 hover:bg-pnvb-50',
    };

    const chiffres = {
        alerte: 'text-brique-700',
        attention: 'text-ocre-700',
        info: 'text-pnvb-800',
    };

    return (
        <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
            {visibles.map((file) => (
                <Link
                    key={file.cle}
                    to={file.chemin}
                    className={`flex items-baseline gap-3 rounded-lg border border-ardoise-200 border-l-4 bg-white px-4 py-3 shadow-sm transition ${tons[file.ton]}`}
                >
                    <span className={`text-2xl font-semibold tabular-nums ${chiffres[file.ton]}`}>
                        {nombre(file.valeur)}
                    </span>
                    <span className="min-w-0">
                        <span className="block text-sm font-medium text-ardoise-900">{file.libelle}</span>
                        <span className="block text-xs text-ardoise-600">{file.precision}</span>
                    </span>
                </Link>
            ))}
        </div>
    );
}
