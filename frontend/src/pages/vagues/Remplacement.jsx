import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { api } from '../../api/client';
import { useAction } from '../../outils/crochets';
import { Bouton, Champ, Liste, Texte } from '../../composants/Champs';
import { Echec } from '../../composants/Etats';
import { nomDe } from '../../outils/format';
import { etatsKitConstate, motifsRemplacement } from '../../domaine/remplacements';

/**
 * REMPLACER L'AGENT D'UNE AFFECTATION.
 *
 * Une affectation validée ne s'ajuste plus et ne se supprime pas : elle se
 * REMPLACE. Le sortant voit son affectation close avec un motif, bascule en
 * réserve, et son kit part au remplaçant. Tout se fait en une transaction.
 *
 * DEUX EXIGENCES DU SERVEUR QUE CET ÉCRAN SUIT PLUTÔT QUE DE LES DEVINER :
 *
 *   - le MOTIF est obligatoire — un remplacement sans motif est inexploitable
 *     six mois plus tard, et le serveur le refuse ;
 *   - l'ÉTAT DU KIT est exigé DÈS QUE le sortant en détient un. C'est le
 *     serveur qui dit s'il en détient un (`detient_un_kit`) : la règle dépend
 *     de l'état du parc, pas de ce que croit le formulaire.
 */
export function Remplacement({ affectation, onFait }) {
    const [ouvert, setOuvert] = useState(false);
    const [champs, setChamps] = useState({ motif: '', commentaire: '', etat_kit_constate: '' });
    const action = useAction(['vague', 'vague-proposition', 'remplacements', 'reserve']);

    const remplacants = useQuery({
        queryKey: ['remplacants', affectation.id],
        queryFn: () => api.lire(`/affectations/${affectation.id}/remplacants`),
        enabled: ouvert,
    });

    const detientUnKit = remplacants.data?.detient_un_kit === true;
    const candidats = remplacants.data?.candidats?.data ?? [];
    const changer = (nom) => (e) => setChamps((c) => ({ ...c, [nom]: e.target.value }));

    // Le serveur refusera sans motif, et sans constat si un kit est en jeu :
    // l'écran désactive plutôt que de laisser envoyer pour rien.
    const pretAEnvoyer = champs.motif !== '' && (! detientUnKit || champs.etat_kit_constate !== '');

    async function designer(volontaireId) {
        const resultat = await action.lancer(() =>
            api.creer('/remplacements', {
                affectation_id: affectation.id,
                volontaire_entrant_id: volontaireId,
                motif: champs.motif,
                ...(champs.commentaire ? { commentaire: champs.commentaire } : {}),
                ...(detientUnKit ? { etat_kit_constate: champs.etat_kit_constate } : {}),
            }),
        );

        if (resultat) {
            setOuvert(false);
            setChamps({ motif: '', commentaire: '', etat_kit_constate: '' });
            onFait(resultat.message);
        }
    }

    if (!ouvert) {
        return <Bouton variante="secondaire" onClick={() => setOuvert(true)}>Remplacer</Bouton>;
    }

    return (
        <div className="min-w-72 rounded-lg border border-ardoise-300 bg-ardoise-50 p-3">
            <p className="mb-2 text-xs text-ardoise-600">
                L’affectation du sortant sera close avec son motif, et il passera en réserve.
                {detientUnKit && ' Son kit sera transféré au remplaçant.'}
            </p>

            {action.erreur && <div className="mb-2"><Echec erreur={action.erreur} /></div>}
            {remplacants.error && <div className="mb-2"><Echec erreur={remplacants.error} /></div>}

            <Champ nom="motif" libelle="Motif du remplacement" erreurs={action.erreur?.erreurs}>
                <Liste value={champs.motif} onChange={changer('motif')} required>
                    <option value="">Choisir…</option>
                    {Object.entries(motifsRemplacement).map(([valeur, libelle]) => (
                        <option key={valeur} value={valeur}>{libelle}</option>
                    ))}
                </Liste>
            </Champ>

            {detientUnKit && (
                <Champ
                    nom="etat_kit_constate"
                    libelle="État constaté du kit"
                    erreurs={action.erreur?.erreurs}
                    aide="Il engage la responsabilité de chacun en cas de casse ou de perte."
                >
                    <Liste value={champs.etat_kit_constate} onChange={changer('etat_kit_constate')} required>
                        <option value="">Choisir…</option>
                        {Object.entries(etatsKitConstate).map(([valeur, libelle]) => (
                            <option key={valeur} value={valeur}>{libelle}</option>
                        ))}
                    </Liste>
                </Champ>
            )}

            <Champ nom="commentaire" libelle="Commentaire (facultatif)" erreurs={action.erreur?.erreurs}>
                <Texte value={champs.commentaire} onChange={changer('commentaire')} />
            </Champ>

            <p className="mt-3 text-xs font-medium text-ardoise-700">
                {remplacants.isPending && ouvert
                    ? 'Recherche des réservistes…'
                    : candidats.length === 0
                      ? 'Aucun réserviste disponible dans cette catégorie.'
                      : 'Désignez le réserviste qui prend la relève :'}
            </p>

            <ul className="mt-1 max-h-48 space-y-1 overflow-y-auto">
                {candidats.map((candidat) => (
                    <li key={candidat.id}>
                        <button
                            type="button"
                            disabled={action.enCours || !pretAEnvoyer}
                            onClick={() => designer(candidat.id)}
                            className="w-full rounded px-2 py-1 text-left text-sm hover:bg-white disabled:text-ardoise-400"
                        >
                            <span className="font-mono text-xs text-ardoise-600">{candidat.matricule}</span>
                            {' — '}
                            {nomDe(candidat.user)}
                        </button>
                    </li>
                ))}
            </ul>

            <Bouton variante="secondaire" className="mt-2" onClick={() => setOuvert(false)}>Fermer</Bouton>
        </div>
    );
}
