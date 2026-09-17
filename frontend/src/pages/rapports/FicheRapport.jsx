import { useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';
import { api } from '../../api/client';
import { useAuth } from '../../auth/ContexteAuth';
import { useAction, useTelechargement } from '../../outils/crochets';
import { Bloc, Chronologie, Rubrique, Rubriques } from '../../composants/Fiche';
import { EnTetePage, Indicateur } from '../../composants/Page';
import { Pastille, Tableau } from '../../composants/Tableau';
import { Bouton, Champ, Texte } from '../../composants/Champs';
import { Chargement } from '../../composants/Chargement';
import { Echec, Succes } from '../../composants/Etats';
import { date, dateHeure, heure, humaniser, nombre, nomDe, pourcentage } from '../../outils/format';
import { statutRapport, typeRapport } from '../../domaine/rapports';

/**
 * LA FICHE D'UN RAPPORT JOURNALIER.
 *
 * Le back-office ne SAISIT pas les rapports — c'est le rôle du mobile, sur le
 * terrain. Il les LIT, les VISE ou les RENVOIE. D'où une fiche en lecture, avec
 * la chaîne de visas en évidence et deux gestes seulement.
 *
 * Les colonnes calculées — écart, taux de réalisation, taux de conformité —
 * sont affichées telles que la base les calcule. Le front ne recalcule rien :
 * un taux refait côté client finirait par diverger du PDF opposable.
 */
export function FicheRapport() {
    const { id } = useParams();
    const auth = useAuth();
    const pdf = useTelechargement();

    const { data, isPending, error, refetch } = useQuery({
        queryKey: ['rapport', id],
        queryFn: () => api.lire(`/rapports/${id}`),
    });

    if (isPending) {
        return <Chargement message="Chargement du rapport…" />;
    }

    if (error) {
        return <Echec erreur={error} onReessayer={refetch} />;
    }

    const statut = statutRapport(data.statut);
    const type = typeRapport(data.type);

    return (
        <>
            <EnTetePage
                titre={`${type.libelle} — ${date(data.date_rapport)}`}
                sousTitre={
                    data.auteur ? `${data.auteur.matricule} — ${nomDe(data.auteur.user)}` : null
                }
                actions={
                    <>
                        {auth.peut('rapports.exporter') && (
                            <button
                                type="button"
                                disabled={pdf.enCours}
                                onClick={() =>
                                    pdf.telecharger(
                                        `/rapports/${data.id}/export/pdf`,
                                        `rapport-${data.id}.pdf`,
                                    )
                                }
                                className="rounded border border-ardoise-300 bg-white px-3 py-2 text-sm hover:bg-ardoise-50"
                            >
                                {pdf.enCours ? 'Génération…' : 'Télécharger le PDF'}
                            </button>
                        )}
                        <Link
                            to="/rapports"
                            className="rounded border border-ardoise-300 bg-white px-3 py-2 text-sm hover:bg-ardoise-50"
                        >
                            Retour à la liste
                        </Link>
                    </>
                }
            />

            {pdf.erreur && <Echec erreur={pdf.erreur} />}

            <div className="flex flex-wrap gap-2">
                <Pastille ton={statut.ton}>{statut.libelle}</Pastille>
                {data.motif_rejet && data.statut === 'rejete' && (
                    <Pastille ton="alerte">Motif : {data.motif_rejet}</Pastille>
                )}
            </div>

            <Visa rapport={data} auth={auth} onFait={refetch} />

            <Bloc titre="Identification" precision="Pré-remplie depuis l’affectation : l’agent la vérifie, il ne la saisit pas">
                <Rubriques colonnes={3}>
                    <Rubrique libelle="Région">{data.region?.nom}</Rubrique>
                    <Rubrique libelle="Centre">
                        {data.centre ? `${data.centre.code} — ${data.centre.nom}` : null}
                    </Rubrique>
                    <Rubrique libelle="Site">
                        {data.site ? `${data.site.code} — ${data.site.nom}` : null}
                    </Rubrique>
                    <Rubrique libelle="Supérieur désigné">
                        {data.superieur
                            ? `${data.superieur.matricule} — ${nomDe(data.superieur.user)}`
                            : null}
                    </Rubrique>
                    <Rubrique libelle="Arrivée">{heure(data.heure_arrivee)}</Rubrique>
                    <Rubrique libelle="Départ">{heure(data.heure_depart)}</Rubrique>
                </Rubriques>
            </Bloc>

            {data.type === 'opk' && data.production_opk && <ProductionOpk production={data.production_opk} />}
            {data.type === 'aopk' && data.activites_aopk && <ActivitesAopk activites={data.activites_aopk} />}
            {data.type === 'superviseur' && <ContenuSuperviseur rapport={data} />}

            <SuiviAgents suivis={data.suivi_agents ?? []} />

            {(data.difficultes ?? []).length > 0 && (
                <Bloc titre="Difficultés rencontrées et solutions">
                    <Tableau
                        cle={(l) => l.id}
                        lignes={data.difficultes}
                        colonnes={[
                            { cle: 'difficulte', titre: 'Difficulté' },
                            { cle: 'solution', titre: 'Solution apportée ou proposée', rendu: (l) => l.solution ?? '—' },
                        ]}
                    />
                </Bloc>
            )}

            {(data.points_amelioration ?? []).length > 0 && (
                <Bloc titre="Points à améliorer">
                    <ul className="list-disc space-y-1 pl-5 text-sm text-ardoise-800">
                        {data.points_amelioration.map((p) => (
                            <li key={p.id}>{p.point}</li>
                        ))}
                    </ul>
                </Bloc>
            )}

            {(data.corrections ?? []).length > 0 && (
                <Bloc
                    titre="Corrections de chiffres pré-remplis"
                    precision="Chaque correction garde la valeur d’origine et son motif"
                >
                    <Tableau
                        cle={(c) => c.id}
                        lignes={data.corrections}
                        colonnes={[
                            { cle: 'champ', titre: 'Champ', rendu: (c) => humaniser(c.champ) },
                            { cle: 'valeur_origine', titre: 'Origine', alignement: 'droite' },
                            { cle: 'valeur_corrigee', titre: 'Retenue', alignement: 'droite' },
                            { cle: 'motif', titre: 'Motif' },
                            {
                                cle: 'qui',
                                titre: 'Par',
                                // La relation corrigePar se sérialise en corrige_par et
                                // recouvre la clé étrangère du même nom.
                                rendu: (c) => (typeof c.corrige_par === 'object' ? nomDe(c.corrige_par) : '—'),
                            },
                            { cle: 'corrige_le', titre: 'Le', compact: true, rendu: (c) => dateHeure(c.corrige_le) },
                        ]}
                    />
                </Bloc>
            )}

            <Bloc titre="Chaîne de visas" precision="Chaque acte est horodaté, nominatif et définitif">
                <Chronologie
                    evenements={(data.visas ?? []).map((visa) => ({
                        cle: visa.id,
                        titre: humaniser(visa.acte),
                        detail: visa.commentaire,
                        quand: dateHeure(visa.effectue_le),
                        qui: nomDe(visa.user),
                    }))}
                />
            </Bloc>
        </>
    );
}

function ProductionOpk({ production }) {
    const ecart = production.ecart_enregistrements;

    return (
        <Bloc titre="Production du kit" precision="Écart et taux calculés par la base, jamais saisis">
            <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                <Indicateur
                    libelle="Objectif du jour"
                    valeur={nombre(production.objectif_enregistrements)}
                    precision="Figé à l’ouverture du rapport"
                />
                <Indicateur libelle="Enregistrements" valeur={nombre(production.enregistrements_realises)} />
                <Indicateur
                    libelle="Écart"
                    valeur={ecart == null ? '—' : `${ecart > 0 ? '+' : ''}${nombre(ecart)}`}
                    ton={ecart == null ? 'neutre' : ecart < 0 ? 'alerte' : 'bon'}
                />
                <Indicateur libelle="Taux de réalisation" valeur={pourcentage(production.taux_realisation)} />
            </div>
            <Rubriques>
                <Rubrique libelle="Récépissés transmis">{nombre(production.recepisses_transmis)}</Rubrique>
                <Rubrique libelle="État du kit">{humaniser(production.etat_kit)}</Rubrique>
                <Rubrique libelle="Enregistrements non validés">
                    {nombre(production.enregistrements_non_valides)}
                </Rubrique>
                <Rubrique libelle="Motif">{production.motif_non_valides}</Rubrique>
            </Rubriques>
        </Bloc>
    );
}

function ActivitesAopk({ activites }) {
    const lignes = [
        ['Affluence', humaniser(activites.affluence_prevue), humaniser(activites.affluence_realisee)],
        ['Justificatifs reçus', activites.justificatifs_recus_prevu, activites.justificatifs_recus_realise],
        ['Justificatifs transmis', activites.justificatifs_transmis_prevu, activites.justificatifs_transmis_realise],
        ['Plaintes enregistrées', activites.plaintes_enregistrees_prevu, activites.plaintes_enregistrees_realise],
        ['Plaintes reversées', activites.plaintes_reversees_prevu, activites.plaintes_reversees_realise],
    ].map(([activite, prevu, realise]) => ({ activite, prevu, realise }));

    return (
        <Bloc titre="Activités d’accueil" precision="L’assistant n’enregistre personne : il accueille et prépare les dossiers">
            <Tableau
                cle={(l) => l.activite}
                lignes={lignes}
                colonnes={[
                    { cle: 'activite', titre: 'Activité' },
                    { cle: 'prevu', titre: 'Prévu', alignement: 'droite', rendu: (l) => l.prevu ?? '—' },
                    { cle: 'realise', titre: 'Réalisé', alignement: 'droite', rendu: (l) => l.realise ?? '—' },
                ]}
            />
        </Bloc>
    );
}

function ContenuSuperviseur({ rapport }) {
    const e = rapport.evolution;
    const q = rapport.qualite;

    return (
        <>
            {e && (
                <Bloc titre="Évolution des enregistrements" precision="Consolidée depuis les rapports d’opérateur déjà visés">
                    <div className="grid gap-3 sm:grid-cols-3">
                        <Indicateur libelle="Personnes enregistrées" valeur={nombre(e.personnes_enregistrees)} />
                        <Indicateur libelle="Dossiers validés" valeur={nombre(e.dossiers_valides)} />
                        <Indicateur libelle="Dossiers à reprendre" valeur={nombre(e.dossiers_a_reprendre)} />
                    </div>
                </Bloc>
            )}
            {q && (
                <Bloc titre="Contrôle qualité">
                    <Rubriques colonnes={3}>
                        <Rubrique libelle="Contrôlés">{nombre(q.dossiers_controles)}</Rubrique>
                        <Rubrique libelle="Conformes">{nombre(q.dossiers_conformes)}</Rubrique>
                        <Rubrique libelle="Taux de conformité">{pourcentage(q.taux_conformite)}</Rubrique>
                        <Rubrique libelle="Doublons détectés">{nombre(q.doublons_detectes)}</Rubrique>
                        <Rubrique libelle="Erreurs de saisie">{nombre(q.erreurs_saisie)}</Rubrique>
                        <Rubrique libelle="Incidents majeurs">{nombre(q.incidents_majeurs)}</Rubrique>
                    </Rubriques>
                </Bloc>
            )}
            {(rapport.logistique ?? []).length > 0 && (
                <Bloc titre="Situation logistique">
                    <Tableau
                        cle={(l) => l.id}
                        lignes={rapport.logistique}
                        colonnes={[
                            { cle: 'ressource', titre: 'Ressource' },
                            { cle: 'disponible', titre: 'Disponible', alignement: 'droite' },
                            { cle: 'fonctionnelle', titre: 'Fonctionnelle', alignement: 'droite' },
                            { cle: 'besoin', titre: 'Besoin', alignement: 'droite' },
                            { cle: 'observation', titre: 'Observation', rendu: (l) => l.observation ?? '—' },
                        ]}
                    />
                </Bloc>
            )}
        </>
    );
}

/**
 * LE SUIVI DES AGENTS, avec leurs réponses.
 *
 * Une appréciation ne s'affiche jamais sans l'observation que l'agent y a
 * opposée : le cadrage exclut toute notation invisible ou non contestable.
 */
function SuiviAgents({ suivis }) {
    if (suivis.length === 0) {
        return null;
    }

    const tonProduction = { satisfaisant: 'bon', passable: 'attention', peu_satisfaisant: 'alerte' };

    return (
        <Bloc titre="Suivi des agents" precision="La présence vient de la feuille validée du jour, jamais d’une saisie">
            <ul className="divide-y divide-ardoise-100">
                {suivis.map((suivi) => (
                    <li key={suivi.id} className="py-3 first:pt-0 last:pb-0">
                        <div className="flex flex-wrap items-center gap-2">
                            <span className="text-sm font-medium text-ardoise-900">
                                {suivi.volontaire?.matricule} — {nomDe(suivi.volontaire?.user)}
                            </span>
                            <Pastille>{humaniser(suivi.categorie_agent)}</Pastille>
                            {suivi.presence && <Pastille ton={suivi.presence === 'present' ? 'bon' : 'attention'}>{humaniser(suivi.presence)}</Pastille>}
                            {suivi.production && (
                                <Pastille ton={tonProduction[suivi.production] ?? 'neutre'}>{humaniser(suivi.production)}</Pastille>
                            )}
                            {(suivi.anomalies ?? []).map((a) => (
                                <Pastille key={a} ton="alerte">{humaniser(a)}</Pastille>
                            ))}
                        </div>
                        {suivi.observation && <p className="mt-1 text-sm text-ardoise-700">{suivi.observation}</p>}
                        {(suivi.reponses ?? []).map((reponse) => (
                            <div key={reponse.id} className="mt-2 border-l-2 border-ocre-400 bg-ocre-50 px-3 py-2 text-sm text-ocre-900">
                                <p className="text-xs font-semibold uppercase tracking-wide">
                                    Réponse de l’agent · {dateHeure(reponse.repondu_le)}
                                </p>
                                <p className="mt-0.5">{reponse.reponse}</p>
                            </div>
                        ))}
                    </li>
                ))}
            </ul>
        </Bloc>
    );
}

/**
 * VISER OU RENVOYER.
 *
 * Le bloc n'apparaît que sur un rapport SIGNÉ et pour un compte qui porte le
 * droit de viser. Le serveur vérifie en plus qu'on est LE supérieur désigné :
 * s'il refuse, son message l'explique mieux que ne le ferait un bouton grisé.
 */
function Visa({ rapport, auth, onFait }) {
    const action = useAction(['rapport', 'rapports', 'rapports-a-viser']);
    const [commentaire, setCommentaire] = useState('');
    const [motif, setMotif] = useState('');

    if (rapport.statut !== 'soumis' || !auth.peut('rapports.viser')) {
        return action.message ? <Succes message={action.message} onFermer={action.oublierMessage} /> : null;
    }

    async function lancer(appel) {
        if (await action.lancer(appel)) {
            setCommentaire('');
            setMotif('');
            onFait();
        }
    }

    return (
        <Bloc titre="Visa" precision="Vos chiffres ne remonteront qu’après votre visa">
            {action.erreur && (
                <div className="mb-4">
                    <Echec erreur={action.erreur} />
                </div>
            )}
            <div className="grid gap-5 lg:grid-cols-2">
                <div>
                    <Champ nom="commentaire" libelle="Commentaire (facultatif)" erreurs={action.erreur?.erreurs}>
                        <Texte value={commentaire} onChange={(e) => setCommentaire(e.target.value)} />
                    </Champ>
                    <Bouton
                        className="mt-3"
                        disabled={action.enCours}
                        onClick={() => lancer(() => api.agir(`/rapports/${rapport.id}/viser`, { commentaire: commentaire || undefined }))}
                    >
                        Viser le rapport
                    </Bouton>
                </div>
                <div className="lg:border-l lg:border-ardoise-200 lg:pl-5">
                    <Champ
                        nom="motif"
                        libelle="Renvoyer pour correction"
                        erreurs={action.erreur?.erreurs}
                        aide="Dites ce qui doit être corrigé : un renvoi sans motif est inexploitable."
                    >
                        <Texte value={motif} onChange={(e) => setMotif(e.target.value)} />
                    </Champ>
                    <Bouton
                        variante="danger"
                        className="mt-3"
                        disabled={action.enCours || !motif.trim()}
                        onClick={() => lancer(() => api.agir(`/rapports/${rapport.id}/rejeter`, { motif }))}
                    >
                        Renvoyer à l’auteur
                    </Bouton>
                </div>
            </div>
        </Bloc>
    );
}

