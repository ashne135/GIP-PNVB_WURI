import { useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';
import { api } from '../../api/client';
import { useAuth } from '../../auth/ContexteAuth';
import { useAction } from '../../outils/crochets';
import { Bloc, Chronologie, Rubrique, Rubriques } from '../../composants/Fiche';
import { EnTetePage } from '../../composants/Page';
import { Pastille } from '../../composants/Tableau';
import { Bouton, Champ, Liste, Saisie, Texte } from '../../composants/Champs';
import { Chargement } from '../../composants/Chargement';
import { Echec, Succes } from '../../composants/Etats';
import { PhotosJointes } from '../../composants/Photos';
import { dateHeure, humaniser, nomDe } from '../../outils/format';
import { etatKit, mouvements } from '../../domaine/kits';

/**
 * LA FICHE D'UN KIT, et le seul chemin pour changer son détenteur.
 *
 * Il n'existe aucun champ « détenteur » modifiable : le détenteur change par un
 * MOUVEMENT, qui laisse une trace. Sans cela, le parc pourrait être corrigé en
 * silence et le journal cesserait de faire foi.
 */
export function FicheKit() {
    const { id } = useParams();
    const auth = useAuth();

    const { data, isPending, error, refetch } = useQuery({
        queryKey: ['kit', id],
        queryFn: () => api.lire(`/kits/${id}`),
    });

    if (isPending) {
        return <Chargement message="Chargement du kit…" />;
    }

    if (error) {
        return <Echec erreur={error} onReessayer={refetch} />;
    }

    const etat = etatKit(data.etat);

    return (
        <>
            <EnTetePage
                titre={data.reference}
                sousTitre={data.detenteur ? `Détenu par ${data.detenteur.matricule} — ${nomDe(data.detenteur.user)}` : 'Au parc, sans détenteur'}
                actions={
                    <Link to="/kits" className="rounded border border-ardoise-300 bg-white px-3 py-2 text-sm hover:bg-ardoise-50">
                        Retour au parc
                    </Link>
                }
            />

            <div className="flex flex-wrap gap-2">
                <Pastille ton={etat.ton}>{etat.libelle}</Pastille>
                {data.est_permanent_zone_defis && <Pastille ton="info">Kit permanent — zone à défis sécuritaires</Pastille>}
            </div>

            <Bloc titre="Situation">
                <Rubriques colonnes={3}>
                    <Rubrique libelle="Détenteur">
                        {data.detenteur ? `${data.detenteur.matricule} — ${nomDe(data.detenteur.user)}` : 'Au parc'}
                    </Rubrique>
                    <Rubrique libelle="Centre courant">
                        {data.centre_courant ? `${data.centre_courant.code} — ${data.centre_courant.nom}` : null}
                    </Rubrique>
                    <Rubrique libelle="Site courant">{data.site_courant?.nom}</Rubrique>
                    <Rubrique libelle="Composition" pleineLargeur>
                        {(data.composition ?? []).length > 0 ? data.composition.join(', ') : null}
                    </Rubrique>
                </Rubriques>
            </Bloc>

            {auth.peut('kits.declarer_mouvement') && <DeclarerMouvement kit={data} onFait={refetch} />}

            <Bloc titre="Historique des mouvements" precision="Le plus récent en premier">
                <Chronologie
                    evenements={(data.mouvements ?? []).map((m) => ({
                        cle: m.id,
                        titre: mouvements[m.type]?.libelle ?? humaniser(m.type),
                        detail: [
                            m.source ? `De ${m.source.matricule} — ${nomDe(m.source.user)}` : null,
                            m.destination ? `À ${m.destination.matricule} — ${nomDe(m.destination.user)}` : null,
                            m.etat_constate ? `État constaté : ${humaniser(m.etat_constate)}` : null,
                            m.site ? `Site : ${m.site.nom}` : null,
                            etatDesPhotos(m),
                            m.commentaire,
                        ]
                            .filter(Boolean)
                            .join('\n'),
                        quand: dateHeure(m.horodatage_telephone ?? m.effectue_le),
                        qui: nomDe(m.effectue_par),
                    }))}
                />
            </Bloc>

            {(data.mouvements ?? []).some((m) => (m.pieces_jointes ?? []).length > 0) && (
                <Bloc
                    titre="Photos de constat"
                    precision="Elles engagent la responsabilité de chacun en cas de perte ou de casse"
                >
                    {(data.mouvements ?? [])
                        .filter((m) => (m.pieces_jointes ?? []).length > 0)
                        .map((m) => (
                            <div key={m.id} className="border-t border-ardoise-100 pt-4 first:border-0 first:pt-0">
                                <p className="text-sm font-medium text-ardoise-900">
                                    {mouvements[m.type]?.libelle ?? humaniser(m.type)} —{' '}
                                    {dateHeure(m.horodatage_telephone ?? m.effectue_le)}
                                </p>
                                <PhotosJointes pieces={m.pieces_jointes} />
                            </div>
                        ))}
                </Bloc>
            )}
        </>
    );
}

/**
 * Ce qu'il manque comme photos, et pourquoi.
 *
 * Seuls les mouvements qui font changer le kit de mains en exigent. Quand le
 * téléphone a annoncé qu'elles suivaient, leur absence n'est pas une faute :
 * elles partent après les données, et le serveur attend leur arrivée avant
 * d'alerter.
 */
function etatDesPhotos(mouvement) {
    if (!mouvements[mouvement.type]?.constat) {
        return null;
    }

    if (mouvement.photo_source_chemin && mouvement.photo_destination_chemin) {
        return null;
    }

    return mouvement.photos_attendues_jusqu_au
        ? 'Photos annoncées par le téléphone, en attente d’arrivée'
        : 'Photos de constat incomplètes';
}

/**
 * DÉCLARER UN MOUVEMENT.
 *
 * Le formulaire ne propose que les mouvements qui ont un sens pour l'état du
 * kit — une remise pour un kit au parc, une restitution pour un kit détenu —
 * et ne demande que les champs que le type exige. Le serveur revérifie tout.
 */
function DeclarerMouvement({ kit, onFait }) {
    const action = useAction(['kit', 'kits', 'kits-synthese', 'kits-non-restitues']);
    const detenu = kit.volontaire_detenteur_id !== null && kit.volontaire_detenteur_id !== undefined;

    const possibles = Object.entries(mouvements).filter(([, m]) => m.detenu === detenu);
    const [type, setType] = useState(possibles[0]?.[0] ?? '');
    const [champs, setChamps] = useState({});

    const regle = mouvements[type] ?? {};
    const changer = (nom) => (e) => setChamps((c) => ({ ...c, [nom]: e.target.value }));

    async function soumettre(evenement) {
        evenement.preventDefault();

        const resultat = await action.lancer(() =>
            api.creer(`/kits/${kit.id}/mouvements`, {
                type,
                ...Object.fromEntries(Object.entries(champs).filter(([, v]) => v !== '')),
            }),
        );

        if (resultat) {
            setChamps({});
            onFait();
        }
    }

    return (
        <Bloc titre="Déclarer un mouvement" precision="Le seul moyen de changer le détenteur d’un kit : chaque mouvement laisse une trace">
            {action.message && (
                <div className="mb-4">
                    <Succes message={action.message} onFermer={action.oublierMessage} />
                </div>
            )}
            {action.erreur && !action.erreur.estValidation && (
                <div className="mb-4">
                    <Echec erreur={action.erreur} />
                </div>
            )}

            <form onSubmit={soumettre} className="grid gap-4 sm:grid-cols-2">
                <Champ nom="type" libelle="Mouvement" erreurs={action.erreur?.erreurs}>
                    <Liste value={type} onChange={(e) => { setType(e.target.value); setChamps({}); }}>
                        {possibles.map(([valeur, m]) => (
                            <option key={valeur} value={valeur}>{m.libelle}</option>
                        ))}
                    </Liste>
                </Champ>

                {regle.destinataire && (
                    <Champ nom="volontaire_destination_id" libelle="Identifiant de l’agent destinataire" erreurs={action.erreur?.erreurs} aide="L’identifiant de sa fiche de volontaire.">
                        <Saisie type="number" min="1" value={champs.volontaire_destination_id ?? ''} onChange={changer('volontaire_destination_id')} required />
                    </Champ>
                )}

                {regle.site && (
                    <Champ nom="site_id" libelle="Identifiant du nouveau site" erreurs={action.erreur?.erreurs}>
                        <Saisie type="number" min="1" value={champs.site_id ?? ''} onChange={changer('site_id')} required />
                    </Champ>
                )}

                {regle.constat && (
                    <Champ nom="etat_constate" libelle="État constaté" erreurs={action.erreur?.erreurs} aide="Il engage la responsabilité de chacun en cas de casse.">
                        <Liste value={champs.etat_constate ?? ''} onChange={changer('etat_constate')} required>
                            <option value="">Choisir…</option>
                            <option value="bon">Bon</option>
                            <option value="usage">Usagé</option>
                            <option value="endommage">Endommagé</option>
                            <option value="incomplet">Incomplet</option>
                        </Liste>
                    </Champ>
                )}

                {regle.circonstance && (
                    <Champ nom="circonstance" libelle="Circonstance" erreurs={action.erreur?.erreurs}>
                        <Liste value={champs.circonstance ?? ''} onChange={changer('circonstance')} required>
                            <option value="">Choisir…</option>
                            <option value="perte">Perte</option>
                            <option value="vol">Vol</option>
                        </Liste>
                    </Champ>
                )}

                <div className="sm:col-span-2">
                    <Champ nom="commentaire" libelle="Commentaire" erreurs={action.erreur?.erreurs}>
                        <Texte value={champs.commentaire ?? ''} onChange={changer('commentaire')} />
                    </Champ>
                </div>

                <div className="sm:col-span-2">
                    <Bouton type="submit" variante={type === 'perte_vol' ? 'danger' : 'principal'} disabled={action.enCours || !type}>
                        {action.enCours ? 'Enregistrement…' : 'Enregistrer le mouvement'}
                    </Bouton>
                    {regle.constat && (
                        <p className="mt-2 text-xs text-ardoise-500">
                            Les photos de constat se prennent depuis l’application mobile. Sans elles, le mouvement
                            passe mais une alerte part vers l’administration nationale.
                        </p>
                    )}
                </div>
            </form>
        </Bloc>
    );
}
