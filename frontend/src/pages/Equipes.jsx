import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { api, avecParametres } from '../api/client';
import { useAuth } from '../auth/ContexteAuth';
import { useAction, useListe } from '../outils/crochets';
import { EnTetePage } from '../composants/Page';
import { Pagination, Pastille, Tableau } from '../composants/Tableau';
import { BarreFiltres, FiltreListe, FiltreTexte } from '../composants/Filtres';
import { Bloc } from '../composants/Fiche';
import { Bouton, Champ, Liste, Texte } from '../composants/Champs';
import { Chargement } from '../composants/Chargement';
import { Echec, Succes, Vide } from '../composants/Etats';
import { aujourdhui, nomDe } from '../outils/format';

const roles = {
    superviseur: { libelle: 'Superviseur', ton: 'info' },
    operateur: { libelle: 'Opérateur de kit', ton: 'bon' },
    assistant: { libelle: 'Assistant (A-OPK)', ton: 'neutre' },
};

/**
 * LES ÉQUIPES DÉPLOYÉES — qui travaille où, et déplacements.
 *
 * Les affectations n'étaient visibles qu'avant validation, à l'état de
 * proposition : une fois la vague validée, elles devenaient actives et
 * disparaissaient de l'interface. Cet écran répond à la question la plus
 * simple du dispositif, et que personne ne pouvait poser.
 *
 * DEUX CHOSES QUE L'ÉCRAN NE MAQUILLE PAS :
 *
 *   - le SUPERVISEUR n'a pas de centre unique : il couvre deux centres, et
 *     c'est ce qu'on affiche, plutôt que d'en choisir un au hasard ;
 *   - le SITE dépend du jour. On montre celui de la date demandée, jamais un
 *     site « en général » qui n'existe pas.
 *
 * LE DÉPLACEMENT N'EST PROPOSÉ QUE SUR UN OPÉRATEUR. L'A-OPK est rattaché en
 * permanence à sa localité et ne se redéploie jamais ; le superviseur dépend
 * d'une unité couvrant deux centres. Le serveur refuse les deux — proposer le
 * bouton reviendrait à promettre une action qu'il rejettera.
 */
export function Equipes() {
    const auth = useAuth();
    const liste = useListe('equipes', '/equipes', { date: aujourdhui() });
    const [choisi, setChoisi] = useState(null);

    const peutDeplacer = auth.peut('affectations.deplacer');

    const regions = useQuery({
        queryKey: ['referentiel-regions'],
        queryFn: () => api.lire('/referentiel/regions'),
    });

    const centres = useQuery({
        queryKey: ['centres-equipes', liste.filtres.region_id],
        queryFn: () => api.lire(avecParametres('/referentiel/centres', {
            region_id: liste.filtres.region_id,
            par_page: 200,
        })),
    });

    const listeRegions = Array.isArray(regions.data) ? regions.data : (regions.data?.data ?? []);
    const listeCentres = centres.data?.data ?? [];

    // On ne garde que l'identifiant : la ligne est relue dans la liste
    // rafraîchie, sans quoi l'écran montrerait l'état d'avant le déplacement.
    const agent = liste.lignes.find((ligne) => ligne.id === choisi) ?? null;

    return (
        <>
            <EnTetePage
                titre="Équipes déployées"
                sousTitre="Qui travaille où, et sur quel site ce jour-là. Seules les affectations actives figurent ici."
            />

            <BarreFiltres onReinitialiser={liste.reinitialiser}>
                <FiltreListe
                    libelle="Région"
                    valeur={liste.filtres.region_id}
                    onChange={(v) => liste.changerFiltre('region_id', v)}
                    options={listeRegions.map((r) => ({ valeur: String(r.id), libelle: r.nom }))}
                />
                <FiltreListe
                    libelle="Centre"
                    valeur={liste.filtres.centre_id}
                    onChange={(v) => liste.changerFiltre('centre_id', v)}
                    options={listeCentres.map((c) => ({
                        valeur: String(c.id),
                        libelle: `${c.code} — ${c.nom}`,
                    }))}
                />
                <FiltreListe
                    libelle="Rôle"
                    valeur={liste.filtres.role}
                    onChange={(v) => liste.changerFiltre('role', v)}
                    options={Object.entries(roles).map(([valeur, r]) => ({ valeur, libelle: r.libelle }))}
                />
                <label className="block">
                    <span className="text-sm font-medium text-ardoise-700">Journée</span>
                    <input
                        type="date"
                        value={liste.filtres.date ?? ''}
                        onChange={(e) => liste.changerFiltre('date', e.target.value)}
                        className="mt-1.5 w-full rounded border border-ardoise-300 bg-white px-3 py-2 text-sm
                            text-ardoise-900 focus:border-pnvb-500 focus:outline-none focus:ring-2 focus:ring-pnvb-200"
                    />
                </label>
                <FiltreTexte
                    libelle="Recherche"
                    valeur={liste.filtres.recherche}
                    onChange={(v) => liste.changerFiltre('recherche', v)}
                    placeholder="Nom, matricule ou téléphone"
                />
            </BarreFiltres>

            {liste.isPending && <Chargement message="Chargement des équipes…" />}
            {liste.error && <Echec erreur={liste.error} onReessayer={liste.refetch} />}

            {agent && peutDeplacer && (
                <DeplacerAgent
                    key={agent.id}
                    agent={agent}
                    centres={listeCentres}
                    onFermer={() => setChoisi(null)}
                />
            )}

            {peutDeplacer && liste.filtres.centre_id && (
                <DeplacerEquipe centreId={liste.filtres.centre_id} centres={listeCentres} />
            )}

            {!liste.isPending && !liste.error && (
                <>
                    <Tableau
                        cle={(a) => a.id}
                        lignes={liste.lignes}
                        vide={(
                            <Vide
                                titre="Aucun agent déployé"
                                explication="Aucune affectation active ne correspond à ces filtres."
                            />
                        )}
                        colonnes={[
                            {
                                cle: 'agent',
                                titre: 'Nom et prénoms',
                                rendu: (a) => nomDe(a.volontaire?.user),
                            },
                            {
                                cle: 'telephone',
                                titre: 'Téléphone',
                                compact: true,
                                rendu: (a) => a.volontaire?.user?.telephone ?? '—',
                            },
                            {
                                cle: 'role',
                                titre: 'Rôle',
                                compact: true,
                                rendu: (a) => {
                                    const role = roles[a.role_terrain] ?? { libelle: a.role_terrain, ton: 'neutre' };

                                    return <Pastille ton={role.ton}>{role.libelle}</Pastille>;
                                },
                            },
                            {
                                cle: 'commune',
                                titre: 'Commune',
                                rendu: (a) => a.centre?.commune?.nom
                                    ?? a.unite_supervision?.centre_principal?.commune?.nom
                                    ?? '—',
                            },
                            {
                                cle: 'centre',
                                titre: 'Centre',
                                rendu: (a) => <Centres affectation={a} />,
                            },
                            {
                                cle: 'site',
                                titre: 'Site du jour',
                                rendu: (a) => <SiteDuJour affectation={a} />,
                            },
                            ...(peutDeplacer
                                ? [{
                                    cle: 'action',
                                    titre: '',
                                    compact: true,
                                    rendu: (a) => (a.role_terrain === 'operateur' ? (
                                        <button
                                            type="button"
                                            onClick={() => setChoisi(a.id === choisi ? null : a.id)}
                                            className="rounded border border-ardoise-300 px-3 py-1.5 text-sm hover:bg-ardoise-50"
                                        >
                                            {a.id === choisi ? 'Fermer' : 'Déplacer'}
                                        </button>
                                    ) : null),
                                }]
                                : []),
                        ]}
                    />
                    <Pagination page={liste.pagination} onPage={liste.setPage} />
                </>
            )}
        </>
    );
}

/** Un superviseur couvre DEUX centres : on les montre tous les deux. */
function Centres({ affectation }) {
    if (affectation.centre) {
        return `${affectation.centre.code} — ${affectation.centre.nom}`;
    }

    const unite = affectation.unite_supervision;

    if (!unite) {
        return '—';
    }

    const codes = [unite.centre_principal?.code, unite.centre_secondaire?.code].filter(Boolean);

    return codes.length > 0 ? codes.join(' + ') : '—';
}

/**
 * Le site du jour, ou la raison honnête de son absence.
 *
 * Un tiret seul laisserait croire à une donnée manquante. Un superviseur n'a
 * pas de site parce qu'il couvre deux centres ; un opérateur sans site ce
 * jour-là, c'est un kit qui ne passe nulle part — deux situations différentes
 * qu'il vaut mieux nommer.
 */
function SiteDuJour({ affectation }) {
    if (affectation.site_du_jour) {
        return affectation.site_du_jour.nom;
    }

    if (affectation.role_terrain === 'superviseur') {
        return <span className="text-ardoise-500">Couvre ses deux centres</span>;
    }

    return <span className="text-brique-700">Aucun passage ce jour</span>;
}

/**
 * DÉPLACER UN AGENT vers un autre centre de sa vague.
 *
 * Le motif est exigé, comme pour un remplacement : six mois après, personne ne
 * saura si c'était un renfort, une correction de tirage ou une sanction.
 */
function DeplacerAgent({ agent, centres, onFermer }) {
    const action = useAction(['equipes']);
    const [champs, setChamps] = useState({ centre_destination_id: '', motif: '' });

    const changer = (nom) => (e) => setChamps((c) => ({ ...c, [nom]: e.target.value }));

    async function envoyer(evenement) {
        evenement.preventDefault();

        await action.lancer(() => api.modifier(`/equipes/affectations/${agent.id}/centre`, {
            centre_destination_id: Number(champs.centre_destination_id),
            motif: champs.motif,
        }));
    }

    return (
        <Bloc
            titre={`Déplacer ${nomDe(agent.volontaire?.user)}`}
            precision="Son kit le suivra, et ses passages à venir sur l’ancien centre seront libérés."
            actions={<Bouton variante="secondaire" onClick={onFermer}>Fermer</Bouton>}
        >
            {action.message && (
                <div className="mb-4"><Succes message={action.message} onFermer={action.oublierMessage} /></div>
            )}
            {action.erreur && !action.erreur.estValidation && (
                <div className="mb-4"><Echec erreur={action.erreur} /></div>
            )}

            <form onSubmit={envoyer} className="grid gap-4 sm:grid-cols-2">
                <Champ nom="centre_destination_id" libelle="Centre d’accueil" erreurs={action.erreur?.erreurs}>
                    <Liste value={champs.centre_destination_id} onChange={changer('centre_destination_id')} required>
                        <option value="">Choisir…</option>
                        {centres
                            .filter((c) => c.id !== agent.centre?.id)
                            .map((c) => <option key={c.id} value={c.id}>{c.code} — {c.nom}</option>)}
                    </Liste>
                </Champ>

                <div className="sm:col-span-2">
                    <Champ
                        nom="motif"
                        libelle="Motif du déplacement"
                        erreurs={action.erreur?.erreurs}
                        aide="Il sera lu bien après votre décision."
                    >
                        <Texte value={champs.motif} onChange={changer('motif')} required />
                    </Champ>
                </div>

                <div className="sm:col-span-2">
                    <Bouton type="submit" disabled={action.enCours}>
                        {action.enCours ? 'Déplacement…' : 'Déplacer l’agent'}
                    </Bouton>
                </div>
            </form>
        </Bloc>
    );
}

/**
 * DÉPLACER L'ÉQUIPE d'un centre — c'est-à-dire ses OPÉRATEURS.
 *
 * La précision n'est pas un détail d'affichage : l'A-OPK reste sur sa
 * localité, et le superviseur dépend de son unité. Le dire ici évite de
 * croire qu'un centre a été vidé.
 */
function DeplacerEquipe({ centreId, centres }) {
    const action = useAction(['equipes']);
    const [champs, setChamps] = useState({ centre_destination_id: '', motif: '' });

    const changer = (nom) => (e) => setChamps((c) => ({ ...c, [nom]: e.target.value }));

    async function envoyer(evenement) {
        evenement.preventDefault();

        await action.lancer(() => api.modifier(`/equipes/centres/${centreId}/deplacer`, {
            centre_destination_id: Number(champs.centre_destination_id),
            motif: champs.motif,
        }));
    }

    return (
        <Bloc
            titre="Déplacer l’équipe de ce centre"
            precision="Seuls les opérateurs se déplacent : l’A-OPK reste rattaché à sa localité, et le superviseur à son unité."
        >
            {action.message && (
                <div className="mb-4"><Succes message={action.message} onFermer={action.oublierMessage} /></div>
            )}
            {action.erreur && !action.erreur.estValidation && (
                <div className="mb-4"><Echec erreur={action.erreur} /></div>
            )}

            <form onSubmit={envoyer} className="grid gap-4 sm:grid-cols-2">
                <Champ nom="centre_destination_id" libelle="Centre d’accueil" erreurs={action.erreur?.erreurs}>
                    <Liste value={champs.centre_destination_id} onChange={changer('centre_destination_id')} required>
                        <option value="">Choisir…</option>
                        {centres
                            .filter((c) => String(c.id) !== String(centreId))
                            .map((c) => <option key={c.id} value={c.id}>{c.code} — {c.nom}</option>)}
                    </Liste>
                </Champ>

                <div className="sm:col-span-2">
                    <Champ nom="motif" libelle="Motif du déplacement" erreurs={action.erreur?.erreurs}>
                        <Texte value={champs.motif} onChange={changer('motif')} required />
                    </Champ>
                </div>

                <div className="sm:col-span-2">
                    <Bouton type="submit" disabled={action.enCours}>
                        {action.enCours ? 'Déplacement…' : 'Déplacer les opérateurs'}
                    </Bouton>
                </div>
            </form>
        </Bloc>
    );
}
