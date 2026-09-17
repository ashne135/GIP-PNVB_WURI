import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { api } from '../../api/client';
import { useAction } from '../../outils/crochets';
import { EnTetePage } from '../../composants/Page';
import { Bloc } from '../../composants/Fiche';
import { Pastille, Tableau } from '../../composants/Tableau';
import { Bouton, Champ, Saisie } from '../../composants/Champs';
import { Chargement } from '../../composants/Chargement';
import { Echec, Succes, Vide } from '../../composants/Etats';
import { nombre } from '../../outils/format';

/**
 * LES LISTES DU CANEVAS D'INCIDENT : natures, impacts, mesures, destinataires.
 *
 * Trois règles, rappelées à l'écran parce qu'elles surprennent :
 *   - on ne supprime pas une entrée : des incidents la portent ; on la désactive ;
 *   - le code ne change pas : il sert aux statistiques ;
 *   - une entrée désactivée quitte les nouveaux formulaires, mais les incidents
 *     qui la portent la gardent.
 */
export function NomenclaturesIncident() {
    const [cle, setCle] = useState('natures');
    const [edition, setEdition] = useState(null);
    const action = useAction(['incidents-nomenclatures', 'canevas-incident']);

    const listes = useQuery({
        queryKey: ['incidents-nomenclatures'],
        queryFn: () => api.lire('/incidents-nomenclatures'),
    });

    const liste = (listes.data ?? []).find((l) => l.cle === cle);

    async function basculer(entree) {
        setEdition(null);
        await action.lancer(() => api.modifier(`/incidents-nomenclatures/${cle}/${entree.id}`, {
            libelle: entree.libelle,
            actif: !entree.actif,
        }));
    }

    const onglet = (actif) =>
        `border-b-2 px-3 py-2 text-sm ${
            actif ? 'border-pnvb-700 font-medium text-pnvb-900' : 'border-transparent text-ardoise-600 hover:text-ardoise-900'
        }`;

    return (
        <>
            <EnTetePage
                titre="Listes des incidents"
                sousTitre="Les choix proposés dans le formulaire d’incident, sur le téléphone et au bureau. Une entrée qui ne sert plus se désactive : elle ne se supprime pas."
            />

            {listes.isPending && <Chargement message="Chargement des listes…" />}
            {listes.error && <Echec erreur={listes.error} onReessayer={listes.refetch} />}

            {listes.data && (
                <>
                    <nav className="flex flex-wrap gap-1 border-b border-ardoise-200" aria-label="Listes">
                        {listes.data.map((l) => (
                            <button
                                key={l.cle}
                                type="button"
                                className={onglet(l.cle === cle)}
                                aria-pressed={l.cle === cle}
                                onClick={() => { setCle(l.cle); setEdition(null); action.oublierMessage(); }}
                            >
                                {l.libelle} ({l.entrees.length})
                            </button>
                        ))}
                    </nav>

                    {action.message && <Succes message={action.message} onFermer={action.oublierMessage} />}
                    {action.erreur && !action.erreur.estValidation && <Echec erreur={action.erreur} />}

                    <FormulaireEntree
                        key={`${cle}-${edition?.id ?? 'nouvelle'}`}
                        cle={cle}
                        entree={edition}
                        action={action}
                        onFini={() => setEdition(null)}
                    />

                    {liste && (
                        <Tableau
                            cle={(e) => e.id}
                            lignes={liste.entrees}
                            vide={<Vide titre="Liste vide" />}
                            colonnes={[
                                { cle: 'ordre', titre: 'Ordre', alignement: 'droite', rendu: (e) => e.ordre },
                                { cle: 'libelle', titre: 'Libellé', rendu: (e) => e.libelle },
                                { cle: 'moore', titre: 'Mooré', rendu: (e) => e.libelle_moore || <span className="text-ardoise-400">à traduire</span> },
                                { cle: 'dioula', titre: 'Dioula', rendu: (e) => e.libelle_dioula || <span className="text-ardoise-400">à traduire</span> },
                                { cle: 'code', titre: 'Code', compact: true, rendu: (e) => <span className="font-mono text-xs">{e.code}</span> },
                                { cle: 'usage', titre: 'Incidents', alignement: 'droite', rendu: (e) => nombre(e.incidents_count ?? 0) },
                                {
                                    cle: 'etat',
                                    titre: 'État',
                                    compact: true,
                                    rendu: (e) => (e.actif ? <Pastille ton="bon">active</Pastille> : <Pastille>désactivée</Pastille>),
                                },
                                {
                                    cle: 'actions',
                                    titre: '',
                                    compact: true,
                                    rendu: (e) => (
                                        <div className="flex gap-2">
                                            <Bouton variante="secondaire" onClick={() => { action.oublierMessage(); setEdition(e); }}>
                                                Modifier
                                            </Bouton>
                                            <Bouton variante="secondaire" disabled={action.enCours} onClick={() => basculer(e)}>
                                                {e.actif ? 'Désactiver' : 'Réactiver'}
                                            </Bouton>
                                        </div>
                                    ),
                                },
                            ]}
                        />
                    )}
                </>
            )}
        </>
    );
}

/** Ajouter une entrée, ou corriger celle qu'on a choisie. Le code n'est jamais saisi. */
function FormulaireEntree({ cle, entree, action, onFini }) {
    const [valeurs, setValeurs] = useState({
        libelle: entree?.libelle ?? '',
        libelle_moore: entree?.libelle_moore ?? '',
        libelle_dioula: entree?.libelle_dioula ?? '',
        ordre: entree?.ordre ?? '',
    });

    const changer = (champ) => (e) => setValeurs((v) => ({ ...v, [champ]: e.target.value }));
    const erreurs = action.erreur?.erreurs;

    async function enregistrer(evenement) {
        evenement.preventDefault();

        const corps = {
            libelle: valeurs.libelle,
            libelle_moore: valeurs.libelle_moore || null,
            libelle_dioula: valeurs.libelle_dioula || null,
            ordre: valeurs.ordre === '' ? null : Number(valeurs.ordre),
        };

        const resultat = await action.lancer(() => (entree
            ? api.modifier(`/incidents-nomenclatures/${cle}/${entree.id}`, corps)
            : api.creer(`/incidents-nomenclatures/${cle}`, corps)));

        if (resultat) {
            onFini();
            if (!entree) {
                setValeurs({ libelle: '', libelle_moore: '', libelle_dioula: '', ordre: '' });
            }
        }
    }

    return (
        <Bloc
            titre={entree ? `Modifier « ${entree.libelle} »` : 'Ajouter une entrée'}
            precision={entree ? `Code ${entree.code} — il ne change pas.` : 'Le code est créé à partir du libellé.'}
        >
            <form onSubmit={enregistrer} aria-label={entree ? 'Modifier l’entrée' : 'Ajouter une entrée'} className="space-y-3">
                <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    <Champ nom="libelle" libelle="Libellé en français" erreurs={erreurs}>
                        <Saisie id={`libelle-${cle}`} value={valeurs.libelle} onChange={changer('libelle')} maxLength={160} required />
                    </Champ>
                    <Champ nom="libelle_moore" libelle="En mooré" erreurs={erreurs}>
                        <Saisie id={`moore-${cle}`} value={valeurs.libelle_moore} onChange={changer('libelle_moore')} maxLength={160} />
                    </Champ>
                    <Champ nom="libelle_dioula" libelle="En dioula" erreurs={erreurs}>
                        <Saisie id={`dioula-${cle}`} value={valeurs.libelle_dioula} onChange={changer('libelle_dioula')} maxLength={160} />
                    </Champ>
                    <Champ nom="ordre" libelle="Ordre d’affichage" aide="Vide : à la fin de la liste." erreurs={erreurs}>
                        <Saisie id={`ordre-${cle}`} type="number" min="0" max="999" value={valeurs.ordre} onChange={changer('ordre')} />
                    </Champ>
                </div>
                <div className="flex gap-2">
                    <Bouton type="submit" disabled={action.enCours || !valeurs.libelle.trim()}>
                        {entree ? 'Enregistrer' : 'Ajouter'}
                    </Bouton>
                    {entree && <Bouton type="button" variante="secondaire" onClick={onFini}>Annuler</Bouton>}
                </div>
            </form>
        </Bloc>
    );
}
