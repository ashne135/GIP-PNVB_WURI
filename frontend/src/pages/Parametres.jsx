import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { api } from '../api/client';
import { useAction } from '../outils/crochets';
import { EnTetePage } from '../composants/Page';
import { Bouton, Liste, Saisie } from '../composants/Champs';
import { Chargement } from '../composants/Chargement';
import { Echec, Succes } from '../composants/Etats';
import { dateHeure } from '../outils/format';
import { groupesParametres, libellesRoles, rolesNotifiables } from '../domaine/referentiel';

/**
 * LES PARAMÈTRES DU DISPOSITIF.
 *
 * Les seuils ne sont jamais écrits dans le code : 966 kits, 2 centres par
 * superviseur, délais d'escalade, rayon de la zone d'un site. Ils vivent ici.
 *
 * SÉPARATION DES POUVOIRS : l'administrateur national les CONSULTE, seul le
 * super administrateur les MODIFIE. L'écran le dit d'emblée, plutôt que de
 * laisser le national chercher un bouton qui n'existera jamais pour lui.
 *
 * Chaque valeur se saisit avec le champ de son type — nombre, oui ou non,
 * heure, cases de rôles — et le serveur la revérifie.
 */
const AVERTISSEMENTS = {
    'comptes.charte_version_courante':
        'Changer la version oblige chaque volontaire à accepter de nouveau la charte à sa prochaine connexion.',
    'dispositif.nombre_kits_principaux':
        'Ce nombre sert au dimensionnement des tirages : vérifiez qu’il correspond au parc réel.',
};

export function Parametres() {
    const { data, isPending, error, refetch } = useQuery({ queryKey: ['parametres'], queryFn: () => api.lire('/parametres') });

    if (isPending) {
        return <Chargement message="Chargement des paramètres…" />;
    }

    if (error) {
        return <Echec erreur={error} onReessayer={refetch} />;
    }

    const parGroupe = new Map();

    for (const parametre of data.parametres ?? []) {
        if (!parGroupe.has(parametre.groupe)) {
            parGroupe.set(parametre.groupe, []);
        }
        parGroupe.get(parametre.groupe).push(parametre);
    }

    const connus = groupesParametres.filter(([cle]) => parGroupe.has(cle));
    const autres = [...parGroupe.keys()].filter((cle) => !groupesParametres.some(([connu]) => connu === cle)).map((cle) => [cle, cle]);

    return (
        <>
            <EnTetePage
                titre="Paramètres du dispositif"
                sousTitre="Les seuils et délais que la plateforme applique. Aucun n’est écrit dans le code."
            />

            {!data.peut_modifier && (
                <p className="rounded border border-ardoise-300 bg-white px-4 py-3 text-sm text-ardoise-700" role="status">
                    <span className="font-medium">Consultation seule.</span> Seul le super administrateur modifie les paramètres :
                    les seuils du dispositif ne relèvent pas de l’exploitation courante.
                </p>
            )}

            {[...connus, ...autres].map(([cle, libelle]) => (
                <section key={cle} className="rounded-lg border border-ardoise-200 bg-white shadow-sm">
                    <h2 className="border-b border-ardoise-200 px-4 py-3 text-sm font-semibold text-ardoise-900">{libelle}</h2>
                    <ul className="divide-y divide-ardoise-100">
                        {parGroupe.get(cle).map((parametre) => (
                            <LigneParametre key={parametre.cle} parametre={parametre} peutModifier={data.peut_modifier} />
                        ))}
                    </ul>
                </section>
            ))}
        </>
    );
}

function LigneParametre({ parametre, peutModifier }) {
    const [enEdition, setEnEdition] = useState(false);

    return (
        <li className="px-4 py-3">
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div className="min-w-0 max-w-prose">
                    <p className="text-sm font-medium text-ardoise-900">{parametre.libelle}</p>
                    {parametre.description && <p className="mt-0.5 text-sm text-ardoise-600">{parametre.description}</p>}
                    <p className="mt-1 font-mono text-xs text-ardoise-500">{parametre.cle}</p>
                </div>
                <div className="flex items-center gap-3">
                    {!enEdition && <ValeurAffichee parametre={parametre} />}
                    {peutModifier && !enEdition && (
                        <Bouton variante="secondaire" onClick={() => setEnEdition(true)} aria-label={`Modifier ${parametre.libelle}`}>
                            Modifier
                        </Bouton>
                    )}
                </div>
            </div>

            {enEdition && <EditeurParametre parametre={parametre} onFermer={() => setEnEdition(false)} />}
        </li>
    );
}

export function ValeurAffichee({ parametre }) {
    const { type_valeur: type, valeur } = parametre;

    if (type === 'booleen') {
        return <span className="text-sm font-medium text-ardoise-900">{valeur ? 'Oui' : 'Non'}</span>;
    }

    if (type === 'json' && Array.isArray(valeur)) {
        return (
            <span className="flex max-w-md flex-wrap justify-end gap-1">
                {valeur.map((element) => (
                    <span key={element} className="rounded bg-ardoise-100 px-2 py-0.5 text-xs text-ardoise-800">
                        {libellesRoles[element] ?? element}
                    </span>
                ))}
            </span>
        );
    }

    return <span className="text-sm font-medium tabular-nums text-ardoise-900">{String(valeur ?? '—')}</span>;
}

function EditeurParametre({ parametre, onFermer }) {
    const action = useAction(['parametres']);
    const [valeur, setValeur] = useState(parametre.valeur);

    const estHeure = parametre.type_valeur === 'chaine' && parametre.cle.includes('.heure_');
    const estMatrice = parametre.type_valeur === 'json' && parametre.cle.startsWith('incidents.notification.');
    const erreur = action.erreur?.erreurs?.valeur?.[0] ?? Object.values(action.erreur?.erreurs ?? {})[0]?.[0];

    async function enregistrer(evenement) {
        evenement.preventDefault();

        const envoyee = parametre.type_valeur === 'entier' || parametre.type_valeur === 'decimal'
            ? (valeur === '' ? '' : Number(valeur))
            : valeur;

        if (await action.lancer(() => api.modifier(`/parametres/${encodeURIComponent(parametre.cle)}`, { valeur: envoyee }))) {
            onFermer();
        }
    }

    return (
        <form onSubmit={enregistrer} className="mt-3 space-y-3 rounded border border-ardoise-200 bg-ardoise-50 p-3">
            {AVERTISSEMENTS[parametre.cle] && (
                <p className="rounded border border-ocre-300 bg-ocre-50 px-3 py-2 text-sm text-ocre-900" role="alert">
                    {AVERTISSEMENTS[parametre.cle]}
                </p>
            )}

            {(parametre.type_valeur === 'entier' || parametre.type_valeur === 'decimal') && (
                <Saisie
                    type="number"
                    min="0"
                    step={parametre.type_valeur === 'entier' ? '1' : '0.01'}
                    value={valeur ?? ''}
                    onChange={(e) => setValeur(e.target.value)}
                    aria-label={parametre.libelle}
                    className="max-w-40"
                    invalide={Boolean(erreur)}
                />
            )}

            {parametre.type_valeur === 'booleen' && (
                <Liste value={valeur ? '1' : '0'} onChange={(e) => setValeur(e.target.value === '1')} aria-label={parametre.libelle} className="max-w-40">
                    <option value="1">Oui</option>
                    <option value="0">Non</option>
                </Liste>
            )}

            {parametre.type_valeur === 'chaine' && (
                <Saisie
                    type={estHeure ? 'time' : 'text'}
                    value={valeur ?? ''}
                    onChange={(e) => setValeur(e.target.value)}
                    aria-label={parametre.libelle}
                    className="max-w-60"
                    invalide={Boolean(erreur)}
                />
            )}

            {estMatrice && (
                <fieldset>
                    <legend className="mb-1.5 text-sm text-ardoise-700">Rôles prévenus à ce niveau de gravité</legend>
                    <div className="flex flex-wrap gap-x-5 gap-y-2">
                        {rolesNotifiables.map((role) => (
                            <label key={role} className="flex items-center gap-2 text-sm text-ardoise-800">
                                <input
                                    type="checkbox"
                                    checked={(valeur ?? []).includes(role)}
                                    onChange={(e) => setValeur((actuelle) => (e.target.checked
                                        ? [...(actuelle ?? []), role]
                                        : (actuelle ?? []).filter((r) => r !== role)))}
                                    className="h-4 w-4"
                                />
                                {libellesRoles[role]}
                            </label>
                        ))}
                    </div>
                    <p className="mt-1.5 text-xs text-ardoise-600">
                        Les autres rôles ne sont pas proposés : ils seraient prévenus dans tout le pays.
                    </p>
                </fieldset>
            )}

            {erreur && <p className="text-sm font-medium text-brique-700" role="alert">{erreur}</p>}
            {action.erreur && !action.erreur.estValidation && <Echec erreur={action.erreur} />}
            {action.message && <Succes message={action.message} />}

            <div className="flex gap-2">
                <Bouton type="submit" disabled={action.enCours}>{action.enCours ? 'Enregistrement…' : 'Enregistrer'}</Bouton>
                <Bouton type="button" variante="secondaire" onClick={onFermer}>Annuler</Bouton>
            </div>
            {parametre.modifie_le && <p className="text-xs text-ardoise-500">Dernière modification : {dateHeure(parametre.modifie_le)}</p>}
        </form>
    );
}
