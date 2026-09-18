import { useMemo, useState } from 'react';
import { api } from '../api/client';
import { useAction } from '../outils/crochets';
import { Bouton } from './Champs';
import { Echec } from './Etats';

/**
 * COCHER DES LIGNES, ET LES EFFACER POUR DE BON.
 *
 * Le dispositif ne supprime normalement rien : un volontaire se RETIRE, un
 * centre se FERME, un kit se RÉFORME, et la trace demeure. Ce composant sert au
 * JEU D'ESSAI — les fiches créées pour tester avant la mise en service.
 *
 * Trois partis pris, qui se voient à l'écran :
 *
 *   - UNE OU TOUTES. La case d'en-tête coche la page affichée, pas la base
 *     entière : cocher 12 294 sites d'un geste, sans les voir, n'est pas une
 *     intention qu'on peut prêter à quelqu'un.
 *   - DEUX TEMPS. Le premier clic annonce ce qui va partir, en le nommant ; le
 *     second l'exécute. Rien ne se supprime au premier clic.
 *   - LE COMPTE RENDU SE LIT. Le serveur refuse ligne par ligne, avec un motif
 *     chacune ; l'écran les affiche toutes plutôt qu'un « certaines ont échoué ».
 */
export function useSelection(lignes = []) {
    const [choisis, setChoisis] = useState(() => new Map());

    const idsPage = useMemo(() => lignes.map((l) => l.id), [lignes]);
    const tousCoches = idsPage.length > 0 && idsPage.every((id) => choisis.has(id));

    function basculer(ligne) {
        setChoisis((actuel) => {
            const suivant = new Map(actuel);
            suivant.has(ligne.id) ? suivant.delete(ligne.id) : suivant.set(ligne.id, ligne);

            return suivant;
        });
    }

    function basculerPage() {
        setChoisis((actuel) => {
            const suivant = new Map(actuel);
            // Tout décocher si la page l'est déjà : la case d'en-tête fait
            // l'aller ET le retour, sinon il faudrait décocher une par une.
            idsPage.forEach((id) => (tousCoches ? suivant.delete(id) : suivant.set(id, lignes.find((l) => l.id === id))));

            return suivant;
        });
    }

    return {
        choisis,
        basculer,
        basculerPage,
        tousCoches,
        vider: () => setChoisis(new Map()),
    };
}

/**
 * La colonne de cases à cocher, identique sur tous les écrans.
 *
 * `nommer` donne à chaque case son libellé accessible : « Choisir KIT-0001 »
 * se lit, « case à cocher » non.
 */
export function colonneChoix(selection, nommer) {
    return {
        cle: 'choix',
        compact: true,
        titre: (
            <input
                type="checkbox"
                aria-label="Cocher toute la page"
                checked={selection.tousCoches}
                onChange={selection.basculerPage}
                className="h-4 w-4"
            />
        ),
        rendu: (ligne) => (
            <input
                type="checkbox"
                aria-label={`Choisir ${nommer(ligne)}`}
                checked={selection.choisis.has(ligne.id)}
                onChange={() => selection.basculer(ligne)}
                className="h-4 w-4"
            />
        ),
    };
}

/**
 * Le bandeau de suppression : ce qui est coché, et ce qu'on peut en faire.
 *
 * Il ne s'affiche que lorsque quelque chose est coché — un bandeau vide en
 * permanence apprendrait à l'ignorer.
 */
export function BarreSuppression({ famille, selection, nommer, aRafraichir = [], nom = 'ligne' }) {
    const [confirme, setConfirme] = useState(false);
    const [rapport, setRapport] = useState(null);
    const action = useAction(aRafraichir);

    const choisis = [...selection.choisis.values()];

    if (choisis.length === 0 && !rapport) {
        return null;
    }

    async function supprimer() {
        const resultat = await action.lancer(() =>
            api.creer('/suppressions', { famille, ids: choisis.map((l) => l.id) }),
        );

        if (resultat) {
            setRapport(resultat.donnees);
            setConfirme(false);
            selection.vider();
        }
    }

    const pluriel = choisis.length > 1 ? 's' : '';

    return (
        // Le bandeau est NOMMÉ : à la voix, « suppression définitive » situe
        // l'endroit où l'on est, et distingue ses libellés de ceux du tableau.
        <div
            role="region"
            aria-label="Suppression définitive"
            className="rounded border border-brique-300 bg-brique-50 px-4 py-3"
        >
            {action.erreur && !action.erreur.estValidation && (
                <div className="mb-3"><Echec erreur={action.erreur} /></div>
            )}

            {rapport && (
                <div className="mb-3 space-y-2 text-sm">
                    {rapport.supprimes.length > 0 && (
                        <p className="font-medium text-ardoise-900">
                            {rapport.supprimes.length} supprimé{rapport.supprimes.length > 1 ? 's' : ''} définitivement :{' '}
                            <span className="font-mono text-ardoise-700">
                                {rapport.supprimes.map((l) => l.libelle).join(', ')}
                            </span>
                        </p>
                    )}

                    {/*
                      * CHAQUE REFUS EST NOMMÉ AVEC SON MOTIF. « Certaines n'ont
                      * pas pu être supprimées » obligerait à deviner lesquelles
                      * et pourquoi — donc à réessayer au hasard.
                      */}
                    {rapport.refusees.length > 0 && (
                        <div>
                            <p className="font-medium text-brique-900">
                                {rapport.refusees.length} conservé{rapport.refusees.length > 1 ? 's' : ''} :
                            </p>
                            <ul className="mt-1 space-y-1">
                                {rapport.refusees.map((l) => (
                                    <li key={l.id} className="text-ardoise-800">
                                        <span className="font-mono font-medium">{l.libelle}</span> — {l.motif}
                                    </li>
                                ))}
                            </ul>
                        </div>
                    )}

                    <button type="button" onClick={() => setRapport(null)} className="text-xs text-ardoise-600 underline">
                        Fermer ce compte rendu
                    </button>
                </div>
            )}

            {choisis.length > 0 && !confirme && (
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <p className="text-sm text-ardoise-800">
                        <span className="font-medium">{choisis.length}</span> {nom}{pluriel} coché{pluriel}.
                    </p>
                    <div className="flex gap-2">
                        <Bouton variante="secondaire" onClick={selection.vider}>Tout décocher</Bouton>
                        <Bouton variante="danger" onClick={() => setConfirme(true)}>
                            Supprimer définitivement
                        </Bouton>
                    </div>
                </div>
            )}

            {choisis.length > 0 && confirme && (
                <div className="space-y-3">
                    <div>
                        <p className="text-sm font-semibold text-brique-900">
                            Supprimer définitivement {choisis.length} {nom}{pluriel} ?
                        </p>
                        <p className="mt-1 text-sm text-ardoise-800">
                            Cette suppression ne s’annule pas. Elle sera refusée pour toute ligne dont
                            dépendent des données qui font foi — une feuille de présence, un rapport,
                            un mouvement de kit — et le compte rendu le dira ligne par ligne.
                        </p>
                        <p className="mt-2 font-mono text-xs text-ardoise-700">
                            {choisis.map(nommer).join(', ')}
                        </p>
                    </div>
                    <div className="flex gap-2">
                        <Bouton variante="secondaire" onClick={() => setConfirme(false)}>Annuler</Bouton>
                        <Bouton variante="danger" onClick={supprimer} disabled={action.enCours}>
                            {action.enCours ? 'Suppression…' : 'Oui, supprimer définitivement'}
                        </Bouton>
                    </div>
                </div>
            )}
        </div>
    );
}
