import { useState } from 'react';
import { Link } from 'react-router-dom';
import { api } from '../api/client';
import { useAction, useListe } from '../outils/crochets';
import { EnTetePage } from '../composants/Page';
import { Pagination, Pastille } from '../composants/Tableau';
import { BarreFiltres } from '../composants/Filtres';
import { Bouton } from '../composants/Champs';
import { Chargement } from '../composants/Chargement';
import { Echec, Succes, Vide } from '../composants/Etats';
import { date, dateHeure, nomDe } from '../outils/format';
import {
    anomaliesAgent,
    categoriesAgent,
    libelleDe,
    presencesAgent,
    productionsAgent,
} from '../domaine/suivi';

/**
 * LES APPRÉCIATIONS DE L'ÉQUIPE — et le DROIT DE RÉPONSE des agents.
 *
 * La notation quotidienne peut peser sur le maintien d'une personne dans le
 * dispositif. L'agent peut répondre depuis son téléphone ; sa réponse est
 * horodatée et définitive. Ici, le supérieur la LIT — et le dit, en la marquant
 * comme lue. Il ne peut ni la modifier, ni la supprimer.
 *
 * L'appréciation elle-même se saisit dans le rapport journalier : cet écran ne
 * l'écrit pas, il la rend lisible dans la durée.
 */
export function Appreciations() {
    const liste = useListe('appreciations', '/appreciations');
    const lecture = useAction(['appreciations']);
    const [message, setMessage] = useState(null);

    async function marquerLue(reponse) {
        setMessage(null);
        const resultat = await lecture.lancer(() => api.agir(`/appreciations/reponses/${reponse.id}/lue`));

        if (resultat) {
            setMessage(resultat.message);
        }
    }

    return (
        <>
            <EnTetePage
                titre="Appréciations"
                sousTitre="L’appréciation de chaque agent, jour par jour, et la réponse qu’il y a faite. Une réponse ne se modifie pas."
            />

            <BarreFiltres onReinitialiser={liste.reinitialiser}>
                <label className="flex items-center gap-2 self-end pb-2 text-sm text-ardoise-800">
                    <input
                        type="checkbox"
                        checked={Boolean(liste.filtres.avec_anomalie)}
                        onChange={(e) => liste.changerFiltre('avec_anomalie', e.target.checked ? 1 : undefined)}
                        className="h-4 w-4"
                    />
                    Seulement les appréciations avec une anomalie
                </label>
            </BarreFiltres>

            {message && <Succes message={message} onFermer={() => setMessage(null)} />}
            {lecture.erreur && <Echec erreur={lecture.erreur} />}
            {liste.isPending && <Chargement message="Chargement des appréciations…" />}
            {liste.error && <Echec erreur={liste.error} onReessayer={liste.refetch} />}

            {!liste.isPending && !liste.error && (
                liste.lignes.length === 0
                    ? <Vide titre="Aucune appréciation" explication="Aucune appréciation n’a été saisie dans votre périmètre pour ces filtres." />
                    : (
                        <ul className="divide-y divide-ardoise-100 rounded-lg border border-ardoise-200 bg-white shadow-sm">
                            {liste.lignes.map((suivi) => (
                                <li key={suivi.id} className="space-y-2 px-4 py-3">
                                    <div className="flex flex-wrap items-center gap-2">
                                        <span className="font-medium text-ardoise-900">{nomDe(suivi.volontaire?.user)}</span>
                                        <span className="font-mono text-xs text-ardoise-500">{suivi.volontaire?.matricule}</span>
                                        <Pastille>{libelleDe(categoriesAgent, suivi.categorie_agent)}</Pastille>
                                        {suivi.presence && (
                                            <Pastille ton={presencesAgent[suivi.presence]?.ton}>{libelleDe(presencesAgent, suivi.presence)}</Pastille>
                                        )}
                                        {suivi.production && (
                                            <Pastille ton={productionsAgent[suivi.production]?.ton}>{libelleDe(productionsAgent, suivi.production)}</Pastille>
                                        )}
                                        {(suivi.anomalies ?? []).map((code) => (
                                            <Pastille key={code} ton="alerte">{libelleDe(anomaliesAgent, code)}</Pastille>
                                        ))}
                                        <span className="ml-auto text-xs text-ardoise-500">
                                            {suivi.rapport
                                                ? <Link to={`/rapports/${suivi.rapport.id}`} className="underline">Rapport du {date(suivi.rapport.date_rapport)}</Link>
                                                : '—'}
                                        </span>
                                    </div>

                                    {suivi.observation && (
                                        <p className="text-sm text-ardoise-700">{suivi.observation}</p>
                                    )}

                                    {(suivi.reponses ?? []).map((reponse) => (
                                        <div key={reponse.id} className="rounded border-l-4 border-pnvb-300 bg-pnvb-50 px-3 py-2 text-sm">
                                            <p className="text-xs font-medium text-pnvb-900">
                                                Réponse de l’agent, le {dateHeure(reponse.repondu_le)}
                                            </p>
                                            <p className="mt-1 whitespace-pre-line text-ardoise-800">{reponse.reponse}</p>
                                            <div className="mt-2">
                                                {reponse.lu_par_superieur_le
                                                    ? <span className="text-xs text-ardoise-500">Lue le {dateHeure(reponse.lu_par_superieur_le)}</span>
                                                    : (
                                                        <Bouton variante="secondaire" disabled={lecture.enCours} onClick={() => marquerLue(reponse)}>
                                                            Marquer comme lue
                                                        </Bouton>
                                                    )}
                                            </div>
                                        </div>
                                    ))}
                                </li>
                            ))}
                        </ul>
                    )
            )}
            <Pagination page={liste.pagination} onPage={liste.setPage} />
        </>
    );
}
