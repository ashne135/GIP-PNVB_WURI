import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { api, avecParametres } from '../../api/client';
import { useAuth } from '../../auth/ContexteAuth';
import { useAction, useListe } from '../../outils/crochets';
import { EnTetePage } from '../../composants/Page';
import { Pagination, Pastille, Tableau } from '../../composants/Tableau';
import { BarreFiltres, FiltreListe } from '../../composants/Filtres';
import { Bloc } from '../../composants/Fiche';
import { Bouton, Champ, Liste, Saisie } from '../../composants/Champs';
import { Chargement } from '../../composants/Chargement';
import { Echec, Succes, Vide } from '../../composants/Etats';
import { nomDe } from '../../outils/format';
import { statut } from '../../domaine/referentiel';
import { statutsTournee } from '../../domaine/tournees';

/** Une date seule : les passages se comptent en jours, jamais en heures. */
function jour(valeur) {
    return valeur ? String(valeur).slice(0, 10).split('-').reverse().join('/') : '—';
}

const aujourdhui = () => new Date().toISOString().slice(0, 10);

/**
 * LES PASSAGES DU KIT SUR LES SITES.
 *
 * 12 294 sites pour 966 kits : un kit couvre les sites de SON centre en
 * séquence. C'est ce passage — et lui seul — qui dit où un opérateur travaille
 * un jour donné, à quel site se rattache la feuille de présence, et quels
 * agents y sont attendus.
 *
 * DEUX ACTES, SÉPARÉS À DESSEIN : le passage bouge (le kit et son porteur se
 * déplacent ensemble), ou l'agent change (le lieu ne bouge pas). Les confondre
 * dans un seul formulaire rendrait impossible de dire, plus tard, ce qui a
 * réellement été décidé.
 *
 * Ce que l'écran n'est PAS : un historique des déplacements d'un agent. On
 * regarde des passages de kits, jamais la trace d'une personne.
 */
export function Tournees() {
    const auth = useAuth();
    const liste = useListe('tournees', '/tournees');
    const [choisi, setChoisi] = useState(null);
    const [programmation, setProgrammation] = useState(false);

    const peutAjuster = auth.peut('tournees.ajuster');

    const centres = useQuery({
        queryKey: ['centres-tournees'],
        queryFn: () => api.lire(avecParametres('/referentiel/centres', { par_page: 200 })),
    });

    // On ne garde que l'identifiant : la ligne elle-même est relue dans la
    // liste rafraîchie, sans quoi l'écran continuerait d'afficher l'état
    // d'avant la correction.
    const passage = liste.lignes.find((ligne) => ligne.id === choisi) ?? null;

    return (
        <>
            <EnTetePage
                titre="Passages des kits"
                sousTitre="Où chaque kit se trouve, et qui le porte. Un kit couvre les sites de son centre l’un après l’autre."
                actions={peutAjuster && (
                    <Bouton onClick={() => { setChoisi(null); setProgrammation((ouvert) => !ouvert); }}>
                        {programmation ? 'Fermer' : 'Programmer un passage'}
                    </Bouton>
                )}
            />

            {programmation && peutAjuster && (
                <ProgrammerPassage centres={centres.data?.data ?? []} onFini={() => setProgrammation(false)} />
            )}

            <BarreFiltres onReinitialiser={liste.reinitialiser}>
                <FiltreListe
                    libelle="Centre"
                    valeur={liste.filtres.centre_id}
                    onChange={(v) => liste.changerFiltre('centre_id', v)}
                    options={(centres.data?.data ?? []).map((centre) => ({
                        valeur: String(centre.id),
                        libelle: `${centre.code} — ${centre.nom}`,
                    }))}
                />
                <FiltreListe
                    libelle="Statut"
                    valeur={liste.filtres.statut}
                    onChange={(v) => liste.changerFiltre('statut', v)}
                    options={Object.entries(statutsTournee).map(([valeur, s]) => ({
                        valeur,
                        libelle: s.libelle,
                    }))}
                />
                <FiltreListe
                    libelle="Période"
                    valeur={liste.filtres.date}
                    onChange={(v) => liste.changerFiltre('date', v)}
                    tous="Tous les passages"
                    options={[{ valeur: aujourdhui(), libelle: 'En cours aujourd’hui' }]}
                />
            </BarreFiltres>

            {liste.isPending && <Chargement message="Chargement des passages…" />}
            {liste.error && <Echec erreur={liste.error} onReessayer={liste.refetch} />}

            {passage && peutAjuster && (
                <div className="grid gap-5 lg:grid-cols-2">
                    <CorrigerPassage key={`passage-${passage.id}`} passage={passage} onFermer={() => setChoisi(null)} />
                    <ChangerOperateur key={`operateur-${passage.id}`} passage={passage} />
                </div>
            )}

            {!liste.isPending && !liste.error && (
                <Tableau
                    cle={(ligne) => ligne.id}
                    lignes={liste.lignes}
                    vide={(
                        <Vide
                            titre="Aucun passage"
                            explication="Aucun passage de kit ne correspond à ces filtres."
                        />
                    )}
                    colonnes={[
                        {
                            cle: 'centre',
                            titre: 'Centre',
                            rendu: (t) => t.centre?.nom ?? '—',
                        },
                        {
                            cle: 'ordre',
                            titre: 'Rang',
                            alignement: 'droite',
                            compact: true,
                            rendu: (t) => t.ordre ?? '—',
                        },
                        {
                            cle: 'site',
                            titre: 'Site',
                            rendu: (t) => t.site?.nom ?? '—',
                        },
                        {
                            cle: 'kit',
                            titre: 'Kit',
                            compact: true,
                            rendu: (t) => t.kit?.reference ?? '—',
                        },
                        {
                            cle: 'operateur',
                            titre: 'Opérateur',
                            rendu: (t) => {
                                const volontaire = t.affectation_operateur?.volontaire;

                                if (!volontaire) {
                                    // Un kit sans porteur est un état réel : le
                                    // masquer derrière un tiret le rendrait invisible.
                                    return <span className="text-brique-700">Sans opérateur</span>;
                                }

                                return `${volontaire.matricule} — ${nomDe(volontaire.user)}`;
                            },
                        },
                        {
                            cle: 'periode',
                            titre: 'Du — au',
                            rendu: (t) => `${jour(t.date_debut)} — ${t.date_fin ? jour(t.date_fin) : 'sans fin'}`,
                        },
                        {
                            cle: 'statut',
                            titre: 'Statut',
                            compact: true,
                            rendu: (t) => (
                                <Pastille ton={statut(statutsTournee, t.statut).ton}>
                                    {statut(statutsTournee, t.statut).libelle}
                                </Pastille>
                            ),
                        },
                        ...(peutAjuster
                            ? [{
                                cle: 'action',
                                titre: '',
                                compact: true,
                                rendu: (t) => (
                                    <button
                                        type="button"
                                        onClick={() => setChoisi(t.id === choisi ? null : t.id)}
                                        className="rounded border border-ardoise-300 px-3 py-1.5 text-sm hover:bg-ardoise-50"
                                    >
                                        {t.id === choisi ? 'Fermer' : 'Corriger'}
                                    </button>
                                ),
                            }]
                            : []),
                    ]}
                />
            )}

            <Pagination page={liste.pagination} onPage={liste.setPage} />
        </>
    );
}

/**
 * LE PASSAGE BOUGE : site, dates, rang, statut.
 *
 * Le kit et l'opérateur qui le porte se déplacent ensemble — c'est le sens
 * même d'un passage. Les sites proposés sont ceux du centre du passage, et
 * eux seuls : un kit ne sort pas de son centre.
 */
function CorrigerPassage({ passage, onFermer }) {
    const action = useAction(['tournees']);
    const [champs, setChamps] = useState({
        site_id: String(passage.site?.id ?? ''),
        date_debut: (passage.date_debut ?? '').slice(0, 10),
        date_fin: (passage.date_fin ?? '').slice(0, 10),
        ordre: String(passage.ordre ?? 1),
        statut: passage.statut ?? 'planifiee',
    });

    const sites = useQuery({
        queryKey: ['sites-du-centre', passage.centre?.id],
        queryFn: () => api.lire(avecParametres('/referentiel/sites', {
            centre_id: passage.centre?.id,
            par_page: 200,
        })),
        enabled: Boolean(passage.centre?.id),
    });

    const changer = (nom) => (e) => setChamps((c) => ({ ...c, [nom]: e.target.value }));

    async function envoyer(evenement) {
        evenement.preventDefault();

        await action.lancer(() => api.modifier(`/tournees/${passage.id}`, {
            site_id: Number(champs.site_id),
            date_debut: champs.date_debut,
            // Une chaîne vide n'est pas « pas de changement » : c'est un passage
            // sans date de fin, et le serveur distingue les deux.
            date_fin: champs.date_fin === '' ? null : champs.date_fin,
            ordre: Number(champs.ordre),
            statut: champs.statut,
        }));
    }

    return (
        <Bloc
            titre="Corriger le passage"
            precision="Le kit et son opérateur se déplacent ensemble."
            actions={<Bouton variante="secondaire" onClick={onFermer}>Fermer</Bouton>}
        >
            {action.message && (
                <div className="mb-4"><Succes message={action.message} onFermer={action.oublierMessage} /></div>
            )}
            {action.erreur && !action.erreur.estValidation && (
                <div className="mb-4"><Echec erreur={action.erreur} /></div>
            )}

            <form onSubmit={envoyer} className="grid gap-4 sm:grid-cols-2">
                <div className="sm:col-span-2">
                    <Champ nom="site_id" libelle="Site couvert" erreurs={action.erreur?.erreurs}>
                        <Liste value={champs.site_id} onChange={changer('site_id')} required>
                            {(sites.data?.data ?? []).map((site) => (
                                <option key={site.id} value={site.id}>{site.code} — {site.nom}</option>
                            ))}
                        </Liste>
                    </Champ>
                </div>

                <Champ nom="date_debut" libelle="Début du passage" erreurs={action.erreur?.erreurs}>
                    <Saisie type="date" value={champs.date_debut} onChange={changer('date_debut')} required />
                </Champ>

                <Champ
                    nom="date_fin"
                    libelle="Fin du passage"
                    erreurs={action.erreur?.erreurs}
                    aide="Laissée vide : le kit reste sur ce site sans date de fin."
                >
                    <Saisie type="date" value={champs.date_fin} onChange={changer('date_fin')} />
                </Champ>

                <Champ nom="ordre" libelle="Rang dans la tournée" erreurs={action.erreur?.erreurs}>
                    <Saisie type="number" min="1" value={champs.ordre} onChange={changer('ordre')} />
                </Champ>

                <Champ nom="statut" libelle="Statut du passage" erreurs={action.erreur?.erreurs}>
                    <Liste value={champs.statut} onChange={changer('statut')}>
                        {Object.entries(statutsTournee).map(([valeur, s]) => (
                            <option key={valeur} value={valeur}>{s.libelle}</option>
                        ))}
                    </Liste>
                </Champ>

                <div className="sm:col-span-2">
                    <Bouton type="submit" disabled={action.enCours}>
                        {action.enCours ? 'Enregistrement…' : 'Enregistrer le passage'}
                    </Bouton>
                </div>
            </form>
        </Bloc>
    );
}

/**
 * L'AGENT CHANGE, le passage reste où il est.
 *
 * Les opérateurs proposés sont ceux du centre du passage : un kit ne sort pas
 * de son centre, donc proposer les autres reviendrait à proposer des choix que
 * le serveur refusera.
 */
function ChangerOperateur({ passage }) {
    const action = useAction(['tournees']);
    const [choix, setChoix] = useState(String(passage.affectation_operateur?.id ?? ''));

    const candidats = useQuery({
        queryKey: ['operateurs-du-passage', passage.id],
        queryFn: () => api.lire(`/tournees/${passage.id}/operateurs`),
    });

    async function envoyer(evenement) {
        evenement.preventDefault();

        await action.lancer(() => api.modifier(`/tournees/${passage.id}/operateur`, {
            affectation_operateur_id: choix === '' ? null : Number(choix),
        }));
    }

    return (
        <Bloc titre="Changer l’opérateur" precision="Le site et les dates ne bougent pas.">
            {action.message && (
                <div className="mb-4"><Succes message={action.message} onFermer={action.oublierMessage} /></div>
            )}
            {action.erreur && !action.erreur.estValidation && (
                <div className="mb-4"><Echec erreur={action.erreur} /></div>
            )}

            <form onSubmit={envoyer} className="space-y-4">
                <Champ
                    nom="affectation_operateur_id"
                    libelle="Opérateur qui tient le passage"
                    erreurs={action.erreur?.erreurs}
                    aide="Seuls les opérateurs actifs du centre sont proposés."
                >
                    <Liste value={choix} onChange={(e) => setChoix(e.target.value)}>
                        <option value="">Aucun — le kit reste sans porteur</option>
                        {(candidats.data ?? []).map((candidat) => (
                            <option key={candidat.id} value={candidat.id}>
                                {candidat.volontaire?.matricule} — {nomDe(candidat.volontaire?.user)}
                            </option>
                        ))}
                    </Liste>
                </Champ>

                <Bouton type="submit" disabled={action.enCours}>
                    {action.enCours ? 'Enregistrement…' : 'Enregistrer l’opérateur'}
                </Bouton>
            </form>
        </Bloc>
    );
}

/**
 * PROGRAMMER UN PASSAGE (décision du client, 18/09/2026).
 *
 * Le tirage dit qui travaille dans quel centre ; il ne dit pas sur quel site le
 * kit se trouve tel jour. Sans passage, un opérateur n'a pas de site du jour —
 * et le téléphone n'a rien à quoi rattacher un signal d'arrivée ou une feuille.
 *
 * LE CENTRE NE S'ENVOIE PAS : il découle du site, et le serveur le déduit. Il
 * ne sert ici qu'à restreindre la liste des sites et celle des opérateurs.
 */
function ProgrammerPassage({ centres, onFini }) {
    const action = useAction(['tournees']);
    const [champs, setChamps] = useState({
        centre_id: '',
        vague_id: '',
        site_id: '',
        affectation_operateur_id: '',
        date_debut: aujourdhui(),
        date_fin: '',
        statut: 'planifiee',
    });

    const changer = (nom) => (e) => setChamps((c) => ({
        ...c,
        [nom]: e.target.value,
        // Changer de centre invalide le site et l'opérateur : ils en dépendent.
        ...(nom === 'centre_id' ? { site_id: '', affectation_operateur_id: '' } : {}),
    }));

    const vagues = useQuery({
        queryKey: ['vagues-programmation'],
        queryFn: () => api.lire(avecParametres('/vagues', { par_page: 100 })),
    });

    const sites = useQuery({
        queryKey: ['sites-du-centre', champs.centre_id],
        queryFn: () => api.lire(avecParametres('/referentiel/sites', { centre_id: champs.centre_id, par_page: 200 })),
        enabled: Boolean(champs.centre_id),
    });

    const operateurs = useQuery({
        queryKey: ['operateurs-du-centre', champs.centre_id],
        queryFn: () => api.lire(avecParametres('/equipes', { centre_id: champs.centre_id, role: 'operateur', par_page: 100 })),
        enabled: Boolean(champs.centre_id),
    });

    // Une vague clôturée ne reçoit plus de passage : ne pas la proposer évite
    // un refus que l'écran aurait pu éviter.
    const vaguesOuvertes = (vagues.data?.data ?? []).filter((v) => v.statut !== 'cloturee' && v.statut !== 'annulee');

    async function enregistrer(evenement) {
        evenement.preventDefault();

        const resultat = await action.lancer(() => api.creer('/tournees', {
            vague_id: Number(champs.vague_id),
            site_id: Number(champs.site_id),
            affectation_operateur_id: champs.affectation_operateur_id
                ? Number(champs.affectation_operateur_id)
                : null,
            date_debut: champs.date_debut,
            date_fin: champs.date_fin || null,
            statut: champs.statut,
        }));

        if (resultat) {
            onFini();
        }
    }

    return (
        <Bloc
            titre="Programmer un passage"
            precision="Le kit couvre un site pendant une période. Le centre se déduit du site ; l’opérateur apporte son kit."
        >
            <form onSubmit={enregistrer} aria-label="Programmer un passage" className="space-y-4">
                {action.erreur && !action.erreur.estValidation && <Echec erreur={action.erreur} />}

                <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                    <Champ nom="vague_id" libelle="Vague" erreurs={action.erreur?.erreurs}>
                        <Liste id="passage-vague" value={champs.vague_id} onChange={changer('vague_id')} required>
                            <option value="">Choisir…</option>
                            {vaguesOuvertes.map((v) => (
                                <option key={v.id} value={v.id}>{v.code} — {v.libelle}</option>
                            ))}
                        </Liste>
                    </Champ>

                    <Champ nom="centre_id" libelle="Centre" aide="Il ne s’envoie pas : il restreint les sites et les opérateurs.">
                        <Liste id="passage-centre" value={champs.centre_id} onChange={changer('centre_id')} required>
                            <option value="">Choisir…</option>
                            {centres.map((centre) => (
                                <option key={centre.id} value={centre.id}>{centre.code} — {centre.nom}</option>
                            ))}
                        </Liste>
                    </Champ>

                    <Champ nom="site_id" libelle="Site" erreurs={action.erreur?.erreurs}>
                        <Liste id="passage-site" value={champs.site_id} onChange={changer('site_id')} disabled={!champs.centre_id} required>
                            <option value="">Choisir…</option>
                            {(sites.data?.data ?? []).map((site) => (
                                <option key={site.id} value={site.id}>{site.code} — {site.nom}</option>
                            ))}
                        </Liste>
                    </Champ>

                    <Champ
                        nom="affectation_operateur_id"
                        libelle="Opérateur"
                        aide="Facultatif : un passage sans porteur est un état réel, et visible."
                        erreurs={action.erreur?.erreurs}
                    >
                        <Liste
                            id="passage-operateur"
                            value={champs.affectation_operateur_id}
                            onChange={changer('affectation_operateur_id')}
                            disabled={!champs.centre_id}
                        >
                            <option value="">Sans opérateur</option>
                            {(operateurs.data?.data ?? []).map((affectation) => (
                                <option key={affectation.id} value={affectation.id}>
                                    {affectation.volontaire?.matricule} — {nomDe(affectation.volontaire?.user)}
                                </option>
                            ))}
                        </Liste>
                    </Champ>

                    <Champ nom="date_debut" libelle="Premier jour" erreurs={action.erreur?.erreurs}>
                        <Saisie id="passage-debut" type="date" value={champs.date_debut} onChange={changer('date_debut')} required />
                    </Champ>

                    <Champ nom="date_fin" libelle="Dernier jour" aide="Vide : passage sans date de fin." erreurs={action.erreur?.erreurs}>
                        <Saisie id="passage-fin" type="date" value={champs.date_fin} onChange={changer('date_fin')} />
                    </Champ>

                    <Champ nom="statut" libelle="État" erreurs={action.erreur?.erreurs}>
                        <Liste id="passage-statut" value={champs.statut} onChange={changer('statut')}>
                            {Object.entries(statutsTournee).map(([valeur, s]) => (
                                <option key={valeur} value={valeur}>{s.libelle}</option>
                            ))}
                        </Liste>
                    </Champ>
                </div>

                <div className="flex gap-2">
                    <Bouton type="submit" disabled={action.enCours || !champs.vague_id || !champs.site_id}>
                        {action.enCours ? 'Enregistrement…' : 'Programmer le passage'}
                    </Bouton>
                    <Bouton type="button" variante="secondaire" onClick={onFini}>Annuler</Bouton>
                </div>
            </form>
        </Bloc>
    );
}
