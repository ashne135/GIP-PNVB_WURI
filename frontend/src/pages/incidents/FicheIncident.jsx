import { useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';
import { api } from '../../api/client';
import { useAuth } from '../../auth/ContexteAuth';
import { useAction } from '../../outils/crochets';
import { Bloc, Chronologie, Rubrique, Rubriques } from '../../composants/Fiche';
import { EnTetePage } from '../../composants/Page';
import { Pastille } from '../../composants/Tableau';
import { Bouton, Champ, Texte } from '../../composants/Champs';
import { Chargement } from '../../composants/Chargement';
import { Echec, Succes } from '../../composants/Etats';
import { PhotosJointes } from '../../composants/Photos';
import { dateHeure, humaniser, nomDe } from '../../outils/format';
import { gravite, statutIncident } from '../../domaine/gravite';

/**
 * LA FICHE D'UN INCIDENT — canevas client, sections A à J.
 *
 * La section J (traitement) n'apparaît QU'À QUI PEUT AGIR. Le déclarant voit sa
 * fiche et son historique, pas les boutons de traitement : le cadrage réserve
 * le traitement aux responsables habilités, et le serveur le refuserait de
 * toute façon.
 */
export function FicheIncident() {
    const { id } = useParams();
    const auth = useAuth();

    const { data, isPending, error, refetch } = useQuery({
        queryKey: ['incident', id],
        queryFn: () => api.lire(`/incidents/${id}`),
    });

    if (isPending) {
        return <Chargement message="Chargement de la fiche…" />;
    }

    if (error) {
        return <Echec erreur={error} onReessayer={refetch} />;
    }

    const niveau = gravite(data.gravite);
    const statut = statutIncident(data.statut);

    return (
        <>
            <EnTetePage
                titre={data.numero}
                sousTitre={(data.natures ?? []).map((n) => n.libelle).join(', ')}
                actions={
                    <Link
                        to="/incidents"
                        className="rounded border border-ardoise-300 bg-white px-3 py-2 text-sm hover:bg-ardoise-50"
                    >
                        Retour à la liste
                    </Link>
                }
            />

            <div className="flex flex-wrap gap-2">
                <Pastille ton={niveau.ton}>
                    Gravité {data.gravite} — {niveau.libelle}
                </Pastille>
                <Pastille ton={statut.ton}>{statut.libelle}</Pastille>
                {data.danger_immediat && <Pastille ton="alerte">Danger immédiat signalé</Pastille>}
                {data.niveau_escalade > 0 && (
                    <Pastille ton="alerte">Escaladé ×{data.niveau_escalade}</Pastille>
                )}
                {data.deja_signale && <Pastille ton="attention">Déjà signalé auparavant</Pastille>}
            </div>

            <Bloc titre="Ce qui s’est passé" precision="Sections B, C et D du canevas">
                <Rubriques>
                    <Rubrique libelle="Récit" pleineLargeur>
                        <p className="whitespace-pre-line">{data.recit}</p>
                    </Rubrique>
                    <Rubrique libelle="Survenu le">{dateHeure(data.survenu_le)}</Rubrique>
                    <Rubrique libelle="Toujours en cours">
                        {humaniser(data.toujours_en_cours)}
                    </Rubrique>
                    <Rubrique libelle="Type de lieu">{humaniser(data.type_lieu)}</Rubrique>
                    <Rubrique libelle="Précision du lieu">{data.lieu_precision}</Rubrique>
                    <Rubrique libelle="Personnes concernées" pleineLargeur>
                        {data.personnes_concernees}
                    </Rubrique>
                </Rubriques>
            </Bloc>

            <div className="grid gap-4 lg:grid-cols-2">
                <Bloc titre="Où" precision="Déduit du site, section A">
                    <Rubriques colonnes={1}>
                        <Rubrique libelle="Région">{data.region?.nom}</Rubrique>
                        <Rubrique libelle="Centre">
                            {data.centre ? `${data.centre.code} — ${data.centre.nom}` : null}
                        </Rubrique>
                        <Rubrique libelle="Site">
                            {data.site ? `${data.site.code} — ${data.site.nom}` : null}
                        </Rubrique>
                    </Rubriques>
                </Bloc>

                <Bloc titre="Qui a déclaré" precision="Repris du compte, jamais saisi">
                    <Rubriques colonnes={1}>
                        <Rubrique libelle="Déclarant">{nomDe(data.declarant)}</Rubrique>
                        <Rubrique libelle="Téléphone">{data.declarant_telephone}</Rubrique>
                        <Rubrique libelle="Déclaré le">{dateHeure(data.declare_le)}</Rubrique>
                        <Rubrique libelle="Canal">{humaniser(data.canal)}</Rubrique>
                    </Rubriques>
                </Bloc>
            </div>

            <div className="grid gap-4 lg:grid-cols-2">
                <Bloc titre="Impact constaté" precision="Section E">
                    <Liste elements={(data.impacts ?? []).map((i) => i.libelle)} />
                    {data.nb_personnes_affectees != null && (
                        <p className="mt-3 text-sm text-ardoise-600">
                            Personnes affectées (estimation) :{' '}
                            <span className="font-medium">{data.nb_personnes_affectees}</span>
                        </p>
                    )}
                </Bloc>

                <Bloc titre="Mesures immédiates prises" precision="Section H">
                    <Liste elements={(data.mesures ?? []).map((m) => m.libelle)} />
                    {data.mesures_precisions && (
                        <p className="mt-3 whitespace-pre-line text-sm text-ardoise-700">
                            {data.mesures_precisions}
                        </p>
                    )}
                </Bloc>
            </div>

            <div className="grid gap-4 lg:grid-cols-2">
                <Bloc titre="Preuves jointes" precision="Section G">
                    <Liste
                        elements={(data.preuves ?? []).map(humaniser)}
                        vide="Aucune preuve jointe."
                    />
                    {/* Les photos partent après la fiche : une déclaration remontée
                        sans réseau peut n'en porter aucune au moment où on la lit. */}
                    <PhotosJointes
                        pieces={data.pieces_jointes}
                        vide="Aucune photo reçue pour cette déclaration."
                    />
                </Bloc>

                <Bloc titre="Personnes informées sur place" precision="Section I">
                    {/* Laravel serialise les relations en snake_case :
                        personnesInformees devient personnes_informees. */}
                    <Liste
                        elements={(data.personnes_informees ?? []).map((p) => p.libelle)}
                        vide="Personne n’a été mentionné."
                    />
                </Bloc>
            </div>

            <Traitement incident={data} auth={auth} onFait={refetch} />

            <Bloc
                titre="Historique"
                precision="Tout ce qui a été fait sur cette fiche, jusqu’à sa clôture"
            >
                <Chronologie
                    evenements={(data.actions ?? []).map((action) => ({
                        cle: action.id,
                        titre: titreAction(action),
                        detail: action.commentaire,
                        quand: dateHeure(action.effectue_le),
                        qui: nomDe(action.user),
                        // Une action sans auteur vient du système : notification,
                        // escalade automatique.
                        systeme: !action.user,
                    }))}
                />
            </Bloc>
        </>
    );
}

function Liste({ elements, vide = 'Rien de renseigné.' }) {
    if (!elements || elements.length === 0) {
        return <p className="text-sm text-ardoise-500">{vide}</p>;
    }

    return (
        <ul className="space-y-1 text-sm text-ardoise-800">
            {elements.map((element) => (
                <li key={element} className="flex gap-2">
                    <span className="text-ardoise-400" aria-hidden="true">
                        •
                    </span>
                    {element}
                </li>
            ))}
        </ul>
    );
}

function titreAction(action) {
    const titres = {
        creation: 'Incident déclaré',
        notification: 'Responsables prévenus',
        escalade: 'Escalade automatique — remonté au niveau supérieur',
        prise_en_charge: 'Pris en charge',
        changement_statut: `Statut : ${humaniser(action.ancien_statut)} → ${humaniser(action.nouveau_statut)}`,
        commentaire: 'Commentaire',
        ajout_preuve: 'Preuves ajoutées',
        cloture: 'Clôture',
        reouverture: 'Rouvert',
    };

    return titres[action.type_action] ?? humaniser(action.type_action);
}

/**
 * SECTION J — le traitement, réservé aux responsables habilités.
 *
 * Le bloc ne s'affiche pas du tout pour qui n'a pas le droit : montrer des
 * boutons désactivés à un déclarant lui laisserait croire qu'il pourrait un
 * jour les actionner.
 */
function Traitement({ incident, auth, onFait }) {
    const peutTraiter = auth.peut('incidents.traiter');
    const peutCloturer = auth.peut('incidents.cloturer');

    const action = useAction(['incident', 'incidents', 'incidents-en-retard']);
    const [commentaire, setCommentaire] = useState('');
    const [rapport, setRapport] = useState('');

    if (!peutTraiter && !peutCloturer) {
        return null;
    }

    async function lancer(appel) {
        const resultat = await action.lancer(appel);

        if (resultat) {
            setCommentaire('');
            setRapport('');
            onFait();
        }
    }

    const clos = incident.statut === 'cloture';

    return (
        <Bloc
            titre="Traitement"
            precision="Réservé aux responsables habilités — section J du canevas"
        >
            {action.message && (
                <div className="mb-4">
                    <Succes message={action.message} onFermer={action.oublierMessage} />
                </div>
            )}
            {action.erreur && (
                <div className="mb-4">
                    <Echec erreur={action.erreur} />
                </div>
            )}

            <Rubriques>
                <Rubrique libelle="Responsable">{nomDe(incident.responsable)}</Rubrique>
                <Rubrique libelle="Pris en charge le">
                    {dateHeure(incident.pris_en_charge_le)}
                </Rubrique>
                <Rubrique libelle="Mesures correctives" pleineLargeur>
                    {incident.mesures_correctives && (
                        <p className="whitespace-pre-line">{incident.mesures_correctives}</p>
                    )}
                </Rubrique>
                {incident.rapport_cloture && (
                    <Rubrique libelle="Rapport de clôture" pleineLargeur>
                        <p className="whitespace-pre-line">{incident.rapport_cloture}</p>
                    </Rubrique>
                )}
            </Rubriques>

            {!clos && (
                <div className="mt-5 space-y-4 border-t border-ardoise-200 pt-4">
                    {incident.statut === 'nouveau' && peutTraiter && (
                        <div>
                            <Champ
                                nom="commentaire"
                                libelle="Commentaire (facultatif)"
                                erreurs={action.erreur?.erreurs}
                            >
                                <Texte
                                    value={commentaire}
                                    onChange={(e) => setCommentaire(e.target.value)}
                                    placeholder="Ce que vous engagez comme première mesure…"
                                />
                            </Champ>
                            <Bouton
                                className="mt-3"
                                disabled={action.enCours}
                                onClick={() =>
                                    lancer(() =>
                                        api.agir(`/incidents/${incident.id}/prendre-en-charge`, {
                                            commentaire: commentaire || undefined,
                                        }),
                                    )
                                }
                            >
                                Prendre en charge
                            </Bouton>
                            <p className="mt-2 text-xs text-ardoise-500">
                                La prise en charge arrête l’escalade automatique.
                            </p>
                        </div>
                    )}

                    {['pris_en_charge', 'en_cours'].includes(incident.statut) && peutTraiter && (
                        <div>
                            <Champ
                                nom="mesures_correctives"
                                libelle="Mesures correctives"
                                erreurs={action.erreur?.erreurs}
                            >
                                <Texte
                                    value={commentaire}
                                    onChange={(e) => setCommentaire(e.target.value)}
                                    placeholder="Ce qui a été engagé pour traiter l’incident…"
                                />
                            </Champ>
                            <div className="mt-3 flex flex-wrap gap-2">
                                {incident.statut === 'pris_en_charge' && (
                                    <Bouton
                                        variante="secondaire"
                                        disabled={action.enCours}
                                        onClick={() =>
                                            lancer(() =>
                                                api.agir(`/incidents/${incident.id}/avancer`, {
                                                    statut: 'en_cours',
                                                    mesures_correctives: commentaire || undefined,
                                                }),
                                            )
                                        }
                                    >
                                        Marquer en cours de traitement
                                    </Bouton>
                                )}
                                <Bouton
                                    disabled={action.enCours}
                                    onClick={() =>
                                        lancer(() =>
                                            api.agir(`/incidents/${incident.id}/avancer`, {
                                                statut: 'resolu',
                                                mesures_correctives: commentaire || undefined,
                                            }),
                                        )
                                    }
                                >
                                    Marquer résolu
                                </Bouton>
                            </div>
                        </div>
                    )}

                    {['resolu', 'en_cours'].includes(incident.statut) && peutCloturer && (
                        <div className="border-t border-ardoise-200 pt-4">
                            <Champ
                                nom="rapport_cloture"
                                libelle="Rapport de clôture"
                                erreurs={action.erreur?.erreurs}
                                aide="Ce qui a été fait. Une fiche close sans rapport ne sert plus à rien dans six mois."
                            >
                                <Texte
                                    value={rapport}
                                    onChange={(e) => setRapport(e.target.value)}
                                />
                            </Champ>
                            <Bouton
                                className="mt-3"
                                disabled={action.enCours || rapport.trim().length < 10}
                                onClick={() =>
                                    lancer(() =>
                                        api.agir(`/incidents/${incident.id}/cloturer`, {
                                            rapport_cloture: rapport,
                                        }),
                                    )
                                }
                            >
                                Clore l’incident
                            </Bouton>
                        </div>
                    )}

                    {peutTraiter && (
                        <div className="border-t border-ardoise-200 pt-4">
                            <Champ nom="commentaire_libre" libelle="Ajouter un commentaire">
                                <Texte
                                    value={commentaire}
                                    onChange={(e) => setCommentaire(e.target.value)}
                                    placeholder="Une information à verser au dossier…"
                                />
                            </Champ>
                            <Bouton
                                variante="secondaire"
                                className="mt-3"
                                disabled={action.enCours || !commentaire.trim()}
                                onClick={() =>
                                    lancer(() =>
                                        api.agir(`/incidents/${incident.id}/commenter`, {
                                            commentaire,
                                        }),
                                    )
                                }
                            >
                                Ajouter au dossier
                            </Bouton>
                        </div>
                    )}
                </div>
            )}
        </Bloc>
    );
}
