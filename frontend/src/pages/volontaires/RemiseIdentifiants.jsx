import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { api, avecParametres } from '../../api/client';
import { useAuth } from '../../auth/ContexteAuth';
import { useAction, useListe, useTelechargement } from '../../outils/crochets';
import { Bloc } from '../../composants/Fiche';
import { Indicateur } from '../../composants/Page';
import { Pagination, Pastille, Tableau } from '../../composants/Tableau';
import { BarreFiltres, FiltreListe, FiltreTexte } from '../../composants/Filtres';
import { Bouton, Champ, Saisie } from '../../composants/Champs';
import { Chargement } from '../../composants/Chargement';
import { Echec, Succes, Vide } from '../../composants/Etats';
import { dateHeure, nombre, nomDe } from '../../outils/format';
import {
    canauxRemise,
    categories,
    etatsRemise,
    libelleCategorie,
    peutSeConnecter,
    statutsCompte,
} from '../../domaine/volontaires';

/**
 * LA REMISE DES IDENTIFIANTS — qui n'a pas encore les siens, et pourquoi
 * (cadrage, section 6).
 *
 * La cascade : courriel, puis SMS, puis BORDEREAU remis en main propre en
 * formation. Le bordereau est le seul document où un mot de passe apparaît en
 * clair : on l'imprime, on distribue les talons contre signature, on le détruit.
 *
 * TOUT ENVOI RÉGÉNÈRE LE MOT DE PASSE. L'écran le dit avant le clic : un
 * renvoi fait cesser le mot de passe précédent, même s'il était déjà arrivé.
 *
 * QUAND LES CANAUX SONT SIMULÉS — pilote « log » sur le serveur — un envoi est
 * compté comme réussi alors que rien ne part. L'écran l'annonce en tête : sans
 * cela, on attend un SMS qui n'arrivera jamais.
 */
export function RemiseIdentifiants() {
    const auth = useAuth();
    const peutAgir = auth.peut('comptes.renvoyer_identifiants');

    const liste = useListe('remises', '/comptes/remises');
    const regions = useQuery({
        queryKey: ['referentiel-regions'],
        queryFn: () => api.lire('/referentiel/regions'),
    });
    const [choisis, setChoisis] = useState(() => new Map());
    const [session, setSession] = useState('');

    const renvoi = useAction(['remises']);
    const bordereau = useAction(['remises']);
    const telechargement = useTelechargement();

    const comptes = liste.data?.comptes;
    const lignes = comptes?.data ?? [];
    const repartition = liste.data?.repartition ?? {};
    const canaux = liste.data?.canaux ?? {};
    const tousCoches = lignes.length > 0 && lignes.every((c) => choisis.has(c.id));
    const sansAcces = [...choisis.values()].filter((c) => !peutSeConnecter(c.statut_compte)).length;

    function basculer(compte) {
        setChoisis((actuels) => {
            const suivants = new Map(actuels);

            if (suivants.has(compte.id)) {
                suivants.delete(compte.id);
            } else {
                suivants.set(compte.id, compte);
            }

            return suivants;
        });
    }

    function basculerPage() {
        setChoisis((actuels) => {
            const suivants = new Map(actuels);
            lignes.forEach((c) => (tousCoches ? suivants.delete(c.id) : suivants.set(c.id, c)));

            return suivants;
        });
    }

    async function renvoyer() {
        bordereau.oublierMessage();

        if (await renvoi.lancer(() => api.agir('/comptes/remises/renvoyer', { user_ids: [...choisis.keys()] }))) {
            setChoisis(new Map());
        }
    }

    async function genererBordereau(evenement) {
        evenement.preventDefault();
        renvoi.oublierMessage();

        const resultat = await bordereau.lancer(() =>
            api.agir('/comptes/remises/bordereau', { user_ids: [...choisis.keys()], session }),
        );

        const fichier = resultat?.donnees?.fichier;

        if (fichier) {
            setChoisis(new Map());
            await telechargement.telecharger(`/comptes/remises/bordereau/${encodeURIComponent(fichier)}`, fichier);
        }
    }

    return (
        <>
            {(canaux.courriel_simule || canaux.sms_simule) && (
                <div className="rounded border border-ocre-300 bg-ocre-50 px-4 py-3 text-sm text-ocre-900" role="alert">
                    <p className="font-semibold">
                        {canaux.courriel_simule && canaux.sms_simule
                            ? 'Ce serveur n’envoie ni courriel ni SMS.'
                            : canaux.sms_simule
                                ? 'Ce serveur n’envoie pas de SMS.'
                                : 'Ce serveur n’envoie pas de courriel.'}
                    </p>
                    <p className="mt-1">
                        Ces messages sont écrits dans le journal technique et comptés comme « envoyés », mais personne ne les reçoit.
                        Pour remettre un mot de passe à un agent — pour un essai sur téléphone, par exemple — générez un bordereau.
                    </p>
                </div>
            )}

            <div className="grid gap-3 sm:grid-cols-3 lg:grid-cols-5">
                {Object.entries(etatsRemise).map(([valeur, etat]) => (
                    <Indicateur
                        key={valeur}
                        libelle={etat.libelle}
                        valeur={nombre(repartition[valeur]?.nombre ?? 0)}
                        ton={valeur === 'echec' && repartition[valeur]?.nombre > 0 ? 'alerte' : 'neutre'}
                    />
                ))}
            </div>

            <div className="flex flex-wrap items-center justify-between gap-3">
                <p className="max-w-prose text-sm text-ardoise-600">
                    L’état des accès s’imprime sans aucun mot de passe : c’est un document de suivi. Les mots de passe
                    se remettent par le bordereau nominatif, contre signature.
                </p>
                <Bouton
                    variante="secondaire"
                    disabled={telechargement.enCours}
                    onClick={() => telechargement.telecharger(
                        avecParametres('/comptes/remises/etat-acces', liste.filtres),
                        'etat-acces.pdf',
                    )}
                >
                    {telechargement.enCours ? 'Préparation…' : 'État des accès (PDF)'}
                </Bouton>
            </div>

            <BarreFiltres onReinitialiser={liste.reinitialiser}>
                <FiltreTexte
                    libelle="Recherche"
                    valeur={liste.filtres.recherche}
                    onChange={(v) => liste.changerFiltre('recherche', v)}
                    placeholder="Nom, téléphone ou matricule"
                />
                <FiltreListe
                    libelle="Remise"
                    valeur={liste.filtres.etat_remise}
                    onChange={(v) => liste.changerFiltre('etat_remise', v)}
                    options={Object.entries(etatsRemise).map(([valeur, etat]) => ({ valeur, libelle: etat.libelle }))}
                />
                <FiltreListe
                    libelle="Accès"
                    valeur={liste.filtres.statut_compte}
                    onChange={(v) => liste.changerFiltre('statut_compte', v)}
                    options={Object.entries(statutsCompte).map(([valeur, statut]) => ({ valeur, libelle: statut.libelle }))}
                />
                <FiltreListe
                    libelle="Catégorie"
                    valeur={liste.filtres.categorie}
                    onChange={(v) => liste.changerFiltre('categorie', v)}
                    tous="Toutes"
                    options={categories}
                />
                {/*
                  * La RÉGION DE DÉPLOIEMENT, pour préparer un bordereau région
                  * par région. Un agent jamais affecté n'appartient encore à
                  * aucune région : il ne sort dans aucun filtre régional.
                  */}
                {(regions.data ?? []).length > 1 && (
                    <FiltreListe
                        libelle="Région"
                        valeur={liste.filtres.region_id}
                        onChange={(v) => liste.changerFiltre('region_id', v)}
                        tous="Toutes"
                        options={(regions.data ?? []).map((r) => ({ valeur: r.id, libelle: r.nom }))}
                    />
                )}
                <FiltreListe
                    libelle="Lignes par page"
                    valeur={liste.filtres.par_page}
                    onChange={(v) => liste.changerFiltre('par_page', v)}
                    tous="50"
                    options={[
                        { valeur: 100, libelle: '100' },
                        { valeur: 200, libelle: '200' },
                    ]}
                />
                <label className="flex items-center gap-2 self-end pb-2 text-sm text-ardoise-800">
                    <input
                        type="checkbox"
                        checked={Boolean(liste.filtres.sans_courriel)}
                        onChange={(e) => liste.changerFiltre('sans_courriel', e.target.checked ? 1 : undefined)}
                        className="h-4 w-4"
                    />
                    Sans courriel
                </label>
            </BarreFiltres>

            {renvoi.message && <Succes message={renvoi.message} onFermer={renvoi.oublierMessage} />}
            {renvoi.erreur && <Echec erreur={renvoi.erreur} />}
            {bordereau.message && <Succes message={bordereau.message} onFermer={bordereau.oublierMessage} />}
            {bordereau.erreur && <Echec erreur={bordereau.erreur} />}
            {telechargement.erreur && <Echec erreur={telechargement.erreur} />}

            {peutAgir && choisis.size > 0 && (
                <Bloc
                    titre={`${nombre(choisis.size)} comptes choisis`}
                    precision="Chaque envoi et chaque bordereau crée un nouveau mot de passe : le précédent cesse de fonctionner."
                >
                    {sansAcces > 0 && (
                        <p className="mb-4 rounded border border-ocre-300 bg-ocre-50 px-3 py-2 text-sm text-ocre-900">
                            {nombre(sansAcces)} de ces comptes n’ont pas d’accès ouvert : ils ne pourront se connecter qu’une fois
                            affectés à une vague.
                        </p>
                    )}
                    <div className="flex flex-wrap items-end gap-6">
                        <div>
                            <Bouton variante="secondaire" disabled={renvoi.enCours} onClick={renvoyer}>
                                {renvoi.enCours ? 'Envoi…' : 'Renvoyer par courriel ou SMS'}
                            </Bouton>
                        </div>
                        <form onSubmit={genererBordereau} className="flex flex-wrap items-end gap-2" aria-label="Générer un bordereau">
                            <Champ nom="session" libelle="Session de formation" erreurs={bordereau.erreur?.erreurs}>
                                <Saisie
                                    id="session-bordereau"
                                    value={session}
                                    onChange={(e) => setSession(e.target.value)}
                                    placeholder="Formation Bagassi — 18 septembre"
                                    maxLength={80}
                                    required
                                />
                            </Champ>
                            <Bouton type="submit" disabled={!session.trim() || bordereau.enCours || telechargement.enCours}>
                                {bordereau.enCours || telechargement.enCours ? 'Préparation…' : 'Générer le bordereau PDF'}
                            </Bouton>
                        </form>
                    </div>
                </Bloc>
            )}

            {liste.isPending && <Chargement message="Chargement des comptes…" />}
            {liste.error && <Echec erreur={liste.error} onReessayer={liste.refetch} />}

            {comptes && (
                <>
                    <Tableau
                        cle={(c) => c.id}
                        lignes={lignes}
                        vide={<Vide titre="Aucun compte ne correspond" explication="Aucun volontaire de votre périmètre ne répond à ces filtres." />}
                        colonnes={[
                            ...(peutAgir
                                ? [{
                                    cle: 'choix',
                                    titre: (
                                        <input type="checkbox" aria-label="Cocher toute la page" checked={tousCoches} onChange={basculerPage} className="h-4 w-4" />
                                    ),
                                    compact: true,
                                    rendu: (c) => (
                                        <input
                                            type="checkbox"
                                            aria-label={`Choisir ${nomDe(c)}`}
                                            checked={choisis.has(c.id)}
                                            onChange={() => basculer(c)}
                                            className="h-4 w-4"
                                        />
                                    ),
                                }]
                                : []),
                            { cle: 'matricule', titre: 'Matricule', compact: true, rendu: (c) => <span className="font-mono">{c.volontaire?.matricule ?? '—'}</span> },
                            { cle: 'nom', titre: 'Nom et prénoms', rendu: (c) => nomDe(c) },
                            { cle: 'categorie', titre: 'Catégorie', compact: true, rendu: (c) => libelleCategorie(c.volontaire?.categorie) ?? 'à qualifier' },
                            { cle: 'telephone', titre: 'Téléphone', compact: true, rendu: (c) => c.telephone },
                            { cle: 'email', titre: 'Courriel', rendu: (c) => c.email ?? <span className="text-ardoise-500">aucun</span> },
                            {
                                cle: 'acces',
                                titre: 'Accès',
                                compact: true,
                                rendu: (c) => {
                                    const statut = statutsCompte[c.statut_compte];

                                    return statut ? <Pastille ton={statut.ton}>{statut.libelle}</Pastille> : c.statut_compte;
                                },
                            },
                            {
                                cle: 'remise',
                                titre: 'Remise',
                                compact: true,
                                rendu: (c) => {
                                    const etat = etatsRemise[c.etat_remise];

                                    return etat ? <Pastille ton={etat.ton}>{etat.libelle}</Pastille> : c.etat_remise;
                                },
                            },
                            { cle: 'dernier', titre: 'Dernière tentative', rendu: (c) => derniereTentative(c.remises_identifiants) },
                        ]}
                    />
                    <Pagination page={comptes} onPage={liste.setPage} />
                </>
            )}
        </>
    );
}

function derniereTentative(remises) {
    if (!remises || remises.length === 0) {
        return '—';
    }

    const derniere = [...remises].sort((a, b) => (a.id < b.id ? 1 : -1))[0];
    const quand = derniere.remis_le ?? derniere.envoye_le ?? derniere.created_at;
    const canal = canauxRemise[derniere.canal] ?? derniere.canal;
    const resultat = derniere.session_formation ? `session « ${derniere.session_formation} »` : derniere.statut?.replace(/_/g, ' ');

    return `${canal} · ${resultat} · ${dateHeure(quand)}`;
}
