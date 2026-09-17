import { Link } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';
import { api } from '../api/client';
import { useListe } from '../outils/crochets';
import { EnTetePage } from '../composants/Page';
import { Pagination, Pastille } from '../composants/Tableau';
import { BarreFiltres, FiltreListe, FiltreTexte } from '../composants/Filtres';
import { Chargement } from '../composants/Chargement';
import { Echec, Vide } from '../composants/Etats';
import { dateHeure, nomDe } from '../outils/format';
import { libelleJournal, libellesJournaux, sujetDe } from '../domaine/journal';

/**
 * LE JOURNAL D'ACTIVITÉ.
 *
 * Il répond à une seule question : QUI a fait QUOI, et quand. Les écritures
 * existaient depuis la première tâche, mais rien ne permettait de les relire —
 * un journal qu'on n'ouvre jamais ne protège personne.
 *
 * L'AUTEUR PEUT MANQUER, et c'est une information, pas un trou : quand le
 * planificateur nocturne recalcule un accès, personne n'a rien décidé. On
 * l'affiche « acteur système » plutôt que d'inventer un responsable, et un
 * filtre permet justement d'isoler ce qui s'est fait tout seul.
 *
 * CE N'EST PAS un historique des déplacements d'un agent : on y lit des actes
 * posés dans la plateforme, jamais une trace de position.
 */
export function Journal() {
    const liste = useListe('journal', '/journal');

    const journaux = useQuery({
        queryKey: ['journal-journaux'],
        queryFn: () => api.lire('/journal/journaux'),
    });

    return (
        <>
            <EnTetePage
                titre="Journal d’activité"
                sousTitre="Qui a fait quoi, et quand. Chaque acte posé dans la plateforme y laisse une ligne."
            />

            <BarreFiltres onReinitialiser={liste.reinitialiser}>
                <FiltreListe
                    libelle="Journal"
                    valeur={liste.filtres.log}
                    onChange={(v) => liste.changerFiltre('log', v)}
                    options={(journaux.data ?? []).map((entree) => ({
                        valeur: entree.log_name,
                        libelle: `${libelleJournal(entree.log_name)} (${entree.total})`,
                    }))}
                />
                <FiltreListe
                    libelle="Auteur"
                    valeur={liste.filtres.sans_auteur}
                    onChange={(v) => liste.changerFiltre('sans_auteur', v)}
                    tous="Tous les actes"
                    options={[{ valeur: '1', libelle: 'Faits sans auteur (système)' }]}
                />
                <FiltreTexte
                    libelle="Recherche"
                    valeur={liste.filtres.recherche}
                    onChange={(v) => liste.changerFiltre('recherche', v)}
                    placeholder="Dans l’intitulé de l’acte"
                />
            </BarreFiltres>

            {liste.isPending && <Chargement message="Chargement du journal…" />}
            {liste.error && <Echec erreur={liste.error} onReessayer={liste.refetch} />}

            {!liste.isPending && !liste.error && liste.lignes.length === 0 && (
                <Vide
                    titre="Aucun acte"
                    explication="Aucun acte ne correspond à ces filtres."
                />
            )}

            <ul className="space-y-2">
                {liste.lignes.map((acte) => (
                    <li
                        key={acte.id}
                        className="rounded-lg border border-ardoise-200 bg-white px-4 py-3 shadow-sm"
                    >
                        <div className="flex flex-wrap items-start justify-between gap-3">
                            <div className="min-w-0">
                                <div className="flex flex-wrap items-center gap-2">
                                    <Pastille ton="neutre">{libelleJournal(acte.log_name)}</Pastille>
                                    <Sujet type={acte.subject_type} id={acte.subject_id} />
                                </div>
                                <p className="mt-1.5 text-sm font-medium text-ardoise-900">
                                    {acte.description}
                                </p>
                                <Auteur causer={acte.causer} />
                            </div>

                            <p className="shrink-0 text-xs text-ardoise-500">
                                {dateHeure(acte.created_at)}
                            </p>
                        </div>

                        <Proprietes valeurs={acte.properties} />
                    </li>
                ))}
            </ul>

            <Pagination page={liste.pagination} onPage={liste.setPage} />
        </>
    );
}

/**
 * L'auteur, ou l'absence d'auteur.
 *
 * « Acteur système » n'est pas un pis-aller : c'est l'information exacte quand
 * le planificateur agit seul. Laisser la ligne vide laisserait croire à un
 * oubli d'enregistrement.
 */
function Auteur({ causer }) {
    if (!causer) {
        return (
            <p className="mt-1 text-xs italic text-ardoise-500">
                Acteur système — aucun humain n’a déclenché cet acte
            </p>
        );
    }

    return (
        <p className="mt-1 text-xs text-ardoise-600">
            {nomDe(causer)}
            {causer.telephone ? ` · ${causer.telephone}` : ''}
        </p>
    );
}

function Sujet({ type, id }) {
    const sujet = sujetDe(type);

    if (!sujet.libelle) {
        return null;
    }

    const cible = sujet.lien && id ? sujet.lien(id) : null;

    return cible ? (
        <Link to={cible} className="text-xs text-pnvb-800 underline">
            {sujet.libelle}
        </Link>
    ) : (
        <span className="text-xs text-ardoise-500">{sujet.libelle}</span>
    );
}

/** Le détail de l'acte, rendu tel qu'il a été enregistré. */
function Proprietes({ valeurs }) {
    const entrees = Object.entries(valeurs ?? {});

    if (entrees.length === 0) {
        return null;
    }

    return (
        <dl className="mt-2 grid gap-x-5 gap-y-1 border-t border-ardoise-100 pt-2 text-xs sm:grid-cols-2">
            {entrees.map(([cle, valeur]) => (
                <div key={cle} className="flex gap-2">
                    <dt className="shrink-0 font-medium text-ardoise-500">{cle}</dt>
                    <dd className="min-w-0 break-words text-ardoise-700">{lisible(valeur)}</dd>
                </div>
            ))}
        </dl>
    );
}

function lisible(valeur) {
    if (valeur === null || valeur === undefined || valeur === '') {
        return '—';
    }

    if (typeof valeur === 'boolean') {
        return valeur ? 'oui' : 'non';
    }

    if (typeof valeur === 'object') {
        return Object.entries(valeur)
            .map(([cle, valeurImbriquee]) => `${cle} : ${lisible(valeurImbriquee)}`)
            .join(' · ');
    }

    return String(valeur);
}
