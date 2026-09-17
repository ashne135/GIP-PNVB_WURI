import { useState } from 'react';
import { api } from '../../api/client';
import { useAuth } from '../../auth/ContexteAuth';
import { useAction, useListe } from '../../outils/crochets';
import { Pagination, Pastille, Tableau } from '../../composants/Tableau';
import { BarreFiltres, FiltreDate, FiltreListe } from '../../composants/Filtres';
import { Bouton, Champ, Liste, Texte } from '../../composants/Champs';
import { Chargement } from '../../composants/Chargement';
import { Echec, Succes, Vide } from '../../composants/Etats';
import { date, nomDe } from '../../outils/format';
import { libelleDe, statutsEcart, typesEcart } from '../../domaine/suivi';

/**
 * LES ÉCARTS DE PRÉSENCE — ce que le rapprochement automatique a constaté
 * entre la feuille validée et les relevés du téléphone.
 *
 * ON N'AFFICHE QU'UN NOMBRE DE RELEVÉS, JAMAIS UNE POSITION (cadrage,
 * section 8) : l'écart est un constat, pas une trace de déplacements. Le
 * serveur ne rend d'ailleurs aucune coordonnée.
 *
 * Traiter un écart demande le droit ecarts.traiter : on l'examine, puis on le
 * clôt, et chaque fois avec un commentaire qui s'ajoute aux précédents.
 */
export function Ecarts() {
    const auth = useAuth();
    const peutTraiter = auth.peut('ecarts.traiter');
    const liste = useListe('ecarts', '/presence/ecarts', { statut: 'ouvert' });
    const [enCours, setEnCours] = useState(null);
    const [message, setMessage] = useState(null);

    return (
        <div className="space-y-4 pt-4">
            <p className="max-w-prose text-sm text-ardoise-600">
                Un écart signale une feuille de présence que les relevés du téléphone ne confirment pas. Seul le nombre de
                relevés dans la zone du site est connu : aucune position n’est affichée.
            </p>

            <BarreFiltres onReinitialiser={liste.reinitialiser}>
                <FiltreListe
                    libelle="État"
                    valeur={liste.filtres.statut}
                    onChange={(v) => liste.changerFiltre('statut', v)}
                    options={Object.entries(statutsEcart).map(([valeur, s]) => ({ valeur, libelle: s.libelle }))}
                />
                <FiltreListe
                    libelle="Écart"
                    valeur={liste.filtres.type_ecart}
                    onChange={(v) => liste.changerFiltre('type_ecart', v)}
                    options={Object.entries(typesEcart).map(([valeur, libelle]) => ({ valeur, libelle }))}
                />
                <FiltreDate libelle="Du" valeur={liste.filtres.du} onChange={(v) => liste.changerFiltre('du', v)} />
                <FiltreDate libelle="Au" valeur={liste.filtres.au} onChange={(v) => liste.changerFiltre('au', v)} />
            </BarreFiltres>

            {message && <Succes message={message} onFermer={() => setMessage(null)} />}

            {enCours && (
                <TraiterEcart
                    key={enCours.id}
                    ecart={enCours}
                    onFermer={() => setEnCours(null)}
                    onTermine={(texte) => { setMessage(texte); setEnCours(null); }}
                />
            )}

            {liste.isPending && <Chargement message="Chargement des écarts…" />}
            {liste.error && <Echec erreur={liste.error} onReessayer={liste.refetch} />}

            {!liste.isPending && !liste.error && (
                <>
                    <Tableau
                        cle={(e) => e.id}
                        lignes={liste.lignes}
                        vide={<Vide titre="Aucun écart ne correspond" explication="Le rapprochement n’a rien constaté pour ces filtres, dans votre périmètre." />}
                        colonnes={[
                            { cle: 'date', titre: 'Constaté le', compact: true, rendu: (e) => date(e.date_constat) },
                            {
                                cle: 'agent',
                                titre: 'Agent',
                                rendu: (e) => (
                                    <>
                                        <span className="block">{nomDe(e.volontaire?.user)}</span>
                                        <span className="font-mono text-xs text-ardoise-500">{e.volontaire?.matricule}</span>
                                    </>
                                ),
                            },
                            {
                                cle: 'site',
                                titre: 'Site',
                                rendu: (e) => (e.feuille?.site ? `${e.feuille.site.nom} (${e.feuille.site.code})` : '—'),
                            },
                            { cle: 'region', titre: 'Région', compact: true, rendu: (e) => e.region?.nom ?? '—' },
                            { cle: 'type', titre: 'Écart', rendu: (e) => libelleDe(typesEcart, e.type_ecart) },
                            { cle: 'releves', titre: 'Relevés dans la zone', alignement: 'droite', rendu: (e) => e.nb_releves_zone },
                            {
                                cle: 'statut',
                                titre: 'État',
                                compact: true,
                                rendu: (e) => <Pastille ton={statutsEcart[e.statut]?.ton ?? 'neutre'}>{libelleDe(statutsEcart, e.statut)}</Pastille>,
                            },
                            {
                                cle: 'commentaire',
                                titre: 'Commentaires',
                                rendu: (e) => (e.commentaire
                                    ? <span className="whitespace-pre-line text-xs">{e.commentaire}</span>
                                    : <span className="text-ardoise-500">—</span>),
                            },
                            ...(peutTraiter
                                ? [{
                                    cle: 'action',
                                    titre: '',
                                    compact: true,
                                    rendu: (e) => (e.statut !== 'clos'
                                        ? <Bouton variante="secondaire" onClick={() => { setMessage(null); setEnCours(e); }}>Traiter</Bouton>
                                        : null),
                                }]
                                : []),
                        ]}
                    />
                    <Pagination page={liste.pagination} onPage={liste.setPage} />
                </>
            )}
        </div>
    );
}

function TraiterEcart({ ecart, onFermer, onTermine }) {
    const [statut, setStatut] = useState(ecart.statut === 'examine' ? 'clos' : 'examine');
    const [commentaire, setCommentaire] = useState('');
    const action = useAction(['ecarts']);

    async function envoyer(evenement) {
        evenement.preventDefault();

        const resultat = await action.lancer(() => api.agir(`/presence/ecarts/${ecart.id}/traiter`, { statut, commentaire }));

        if (resultat) {
            onTermine(resultat.message);
        }
    }

    return (
        <form
            onSubmit={envoyer}
            aria-label="Traiter l’écart"
            className="space-y-3 rounded-lg border border-pnvb-200 bg-white px-4 py-4 shadow-sm"
        >
            <p className="text-sm font-medium text-ardoise-900">
                {nomDe(ecart.volontaire?.user)} — {libelleDe(typesEcart, ecart.type_ecart)}, le {date(ecart.date_constat)}
            </p>
            {action.erreur && !action.erreur.estValidation && <Echec erreur={action.erreur} />}
            <div className="grid gap-3 sm:grid-cols-3">
                <Champ nom="statut" libelle="Nouvel état" erreurs={action.erreur?.erreurs}>
                    <Liste id="statut-ecart" value={statut} onChange={(e) => setStatut(e.target.value)}>
                        <option value="examine">Examiné</option>
                        <option value="clos">Clos</option>
                    </Liste>
                </Champ>
                <div className="sm:col-span-2">
                    <Champ nom="commentaire" libelle="Commentaire (obligatoire, ajouté aux précédents)" erreurs={action.erreur?.erreurs}>
                        <Texte
                            id="commentaire-ecart"
                            value={commentaire}
                            onChange={(e) => setCommentaire(e.target.value)}
                            maxLength={2000}
                            required
                        />
                    </Champ>
                </div>
            </div>
            {statut === 'clos' && (
                <p className="text-sm text-ocre-800">Un écart clos ne peut plus être modifié.</p>
            )}
            <div className="flex gap-2">
                <Bouton type="submit" disabled={action.enCours || commentaire.trim().length < 5}>
                    {action.enCours ? 'Enregistrement…' : 'Enregistrer'}
                </Bouton>
                <Bouton type="button" variante="secondaire" onClick={onFermer}>Annuler</Bouton>
            </div>
        </form>
    );
}
