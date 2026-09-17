import { useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';
import { api, avecParametres } from '../../api/client';
import { useAuth } from '../../auth/ContexteAuth';
import { useAction } from '../../outils/crochets';
import { Bloc, Rubrique, Rubriques } from '../../composants/Fiche';
import { Pagination, Pastille, Tableau } from '../../composants/Tableau';
import { Bouton, Champ, Saisie } from '../../composants/Champs';
import { Chargement } from '../../composants/Chargement';
import { Echec, Succes, Vide } from '../../composants/Etats';
import { date, nombre, nomDe } from '../../outils/format';
import { categoriePourRole, roleTerrain, statutVague } from '../../domaine/vagues';
import { Remplacement } from './Remplacement';

/**
 * LA FICHE D'UNE VAGUE — et le seul chemin pour affecter les agents.
 *
 * Quatre temps, dans cet ordre, et l'écran refuse de les mélanger :
 *
 *   1. PLANIFIÉE   — la vague existe, aucun agent n'est encore affecté ;
 *   2. TIRÉE       — une PROPOSITION existe. Rien n'est notifié, aucun accès
 *                    n'est ouvert : on peut rejouer le tirage autant qu'on veut ;
 *   3. RELUE       — anomalies et contraintes non satisfaites se lisent AVANT
 *                    le bouton de validation, jamais après ;
 *   4. VALIDÉE     — la proposition devient la réalité : des centaines d'accès
 *                    s'ouvrent et les identifiants partent. D'où la confirmation.
 *
 * L'ajustement manuel n'est possible qu'AVANT la validation. Après, on ne
 * corrige plus une affectation : on la REMPLACE, avec un motif — c'est ce qui
 * garde l'historique lisible.
 */
export function FicheVague() {
    const { id } = useParams();
    const auth = useAuth();

    const vague = useQuery({ queryKey: ['vague', id], queryFn: () => api.lire(`/vagues/${id}`) });

    if (vague.isPending) {
        return <Chargement message="Chargement de la vague…" />;
    }

    if (vague.error) {
        return <Echec erreur={vague.error} onReessayer={vague.refetch} />;
    }

    const donnees = vague.data;
    const etat = statutVague(donnees.statut);
    const avantValidation = ['brouillon', 'proposee'].includes(donnees.statut);

    return (
        <>
            <div className="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h2 className="font-mono text-lg font-semibold text-ardoise-900">{donnees.code}</h2>
                    <p className="text-sm text-ardoise-600">{donnees.libelle}</p>
                </div>
                <Link to="/vagues" className="rounded border border-ardoise-300 bg-white px-3 py-2 text-sm hover:bg-ardoise-50">
                    Retour aux vagues
                </Link>
            </div>

            <div className="flex flex-wrap items-center gap-2">
                <Pastille ton={etat.ton}>{etat.libelle}</Pastille>
                <span className="text-sm text-ardoise-600">{etat.etape}</span>
            </div>

            <Bloc titre="Cadre">
                <Rubriques colonnes={3}>
                    <Rubrique libelle="Région">{donnees.region?.nom}</Rubrique>
                    <Rubrique libelle="Période prévue">
                        {date(donnees.date_debut_prevue)} → {date(donnees.date_fin_prevue)}
                    </Rubrique>
                    <Rubrique libelle="Objectif par kit et par jour">
                        {donnees.objectif_enregistrements_par_kit_jour
                            ? nombre(donnees.objectif_enregistrements_par_kit_jour)
                            : 'non fixé — l’écart des rapports restera vide'}
                    </Rubrique>
                    <Rubrique libelle="Centres ouverts">{nombre((donnees.centres ?? []).length)}</Rubrique>
                    <Rubrique libelle="Graine du tirage">
                        {donnees.graine_tirage ?? 'aucun tirage'}
                    </Rubrique>
                    <Rubrique libelle="Planifiée par">{nomDe(donnees.cree_par)}</Rubrique>
                </Rubriques>
            </Bloc>

            {auth.peut('vagues.tirer') && avantValidation && (
                <Tirage vague={donnees} onFait={vague.refetch} />
            )}

            {donnees.statut !== 'brouillon' && (
                <Proposition
                    vague={donnees}
                    // La Policy exige « affectations.ajuster » ET une affectation
                    // encore à l'état de proposition : l'écran suit exactement la
                    // même règle, plutôt que de proposer un bouton qui sera refusé.
                    peutAjuster={auth.peut('affectations.ajuster') && donnees.statut === 'proposee'}
                    // Une fois la vague validée, on n'ajuste plus : on remplace,
                    // avec un motif, et le kit suit son nouveau détenteur.
                    peutRemplacer={auth.peut('remplacements.decider')
                        && ['validee', 'active'].includes(donnees.statut)}
                />
            )}

            {auth.peut('vagues.valider') && donnees.statut === 'proposee' && (
                <Validation vague={donnees} onFait={vague.refetch} />
            )}

            {/* Le serveur n'autorise la clôture que sur une vague ACTIVE :
                l'offrir sur une vague seulement validée enverrait vers un refus. */}
            {auth.peut('vagues.cloturer') && donnees.statut === 'active' && (
                <Cloture vague={donnees} onFait={vague.refetch} />
            )}
        </>
    );
}

/**
 * LE TIRAGE.
 *
 * La graine peut être imposée : c'est ce qui permet de REJOUER un tirage à
 * l'identique et d'expliquer son résultat à qui le conteste. Laissée vide, le
 * serveur en choisit une et l'enregistre.
 */
function Tirage({ vague, onFait }) {
    const action = useAction(['vague', 'vagues', 'vague-proposition']);
    const [graine, setGraine] = useState('');
    const dejaTire = vague.statut === 'proposee';

    async function tirer() {
        if (await action.lancer(() => api.agir(`/vagues/${vague.id}/tirer`, graine ? { graine: Number(graine) } : {}))) {
            onFait();
        }
    }

    async function verifier() {
        await action.lancer(() => api.lire(`/vagues/${vague.id}/verifier-tirage`).then((donnees) => ({
            message: donnees?.identique
                ? 'Le tirage rejoué donne exactement le même résultat.'
                : 'Le tirage rejoué diffère du tirage enregistré.',
            donnees,
        })));
    }

    return (
        <Bloc
            titre={dejaTire ? 'Rejouer le tirage' : 'Lancer le tirage'}
            precision="Le tirage produit une PROPOSITION. Rien n’est notifié, aucun accès n’est ouvert : il peut être rejoué autant que nécessaire."
        >
            {action.message && <div className="mb-4"><Succes message={action.message} onFermer={action.oublierMessage} /></div>}
            {action.erreur && <div className="mb-4"><Echec erreur={action.erreur} /></div>}

            <div className="flex flex-wrap items-end gap-3">
                <Champ
                    nom="graine"
                    libelle="Graine (facultative)"
                    erreurs={action.erreur?.erreurs}
                    aide="Imposez-la pour reproduire un tirage à l’identique."
                >
                    <Saisie type="number" min="1" value={graine} onChange={(e) => setGraine(e.target.value)} />
                </Champ>
                <Bouton onClick={tirer} disabled={action.enCours} className="mb-0.5">
                    {action.enCours ? 'Tirage…' : dejaTire ? 'Rejouer le tirage' : 'Lancer le tirage'}
                </Bouton>
                {dejaTire && (
                    <Bouton variante="secondaire" onClick={verifier} disabled={action.enCours} className="mb-0.5">
                        Vérifier la reproductibilité
                    </Bouton>
                )}
            </div>
        </Bloc>
    );
}

/**
 * LA PROPOSITION, telle qu'elle doit être lue avant de valider.
 *
 * Les anomalies et les contraintes non satisfaites viennent EN PREMIER : les
 * mettre sous le tableau reviendrait à les faire manquer.
 */
function Proposition({ vague, peutAjuster, peutRemplacer }) {
    const [page, setPage] = useState(1);
    const [ajustee, setAjustee] = useState(null);

    const requete = useQuery({
        queryKey: ['vague-proposition', vague.id, page],
        queryFn: () => api.lire(avecParametres(`/vagues/${vague.id}/proposition`, { page, par_page: 50 })),
    });

    if (requete.isPending) {
        return <Chargement message="Chargement de la proposition…" />;
    }

    if (requete.error) {
        return <Echec erreur={requete.error} onReessayer={requete.refetch} />;
    }

    const affectations = requete.data?.affectations;
    const lignes = affectations?.data ?? [];
    const nonSatisfaites = requete.data?.contraintes_non_satisfaites ?? 0;

    return (
        <>
            {nonSatisfaites > 0 && (
                <div className="rounded-lg border border-ocre-300 bg-ocre-50 px-4 py-3 text-sm text-ocre-900">
                    <b>{nombre(nonSatisfaites)} contrainte{nonSatisfaites > 1 ? 's' : ''} non satisfaite{nonSatisfaites > 1 ? 's' : ''}</b> —
                    un superviseur a deux centres trop éloignés, ou hors de sa commune. Ce n’est pas bloquant,
                    mais cela se lit avant de valider, pas après.
                </div>
            )}

            <Bloc
                titre={`Proposition d’affectation (${nombre(affectations?.total ?? 0)})`}
                precision={
                    vague.statut === 'proposee'
                        ? 'Rien n’est notifié : ces affectations n’existent que comme proposition.'
                        : 'Affectations de la vague.'
                }
            >
                {ajustee && <div className="mb-4"><Succes message={ajustee} onFermer={() => setAjustee(null)} /></div>}

                <Tableau
                    cle={(a) => a.id}
                    lignes={lignes}
                    vide={<Vide titre="Aucune affectation" explication="Lancez le tirage pour obtenir une proposition." />}
                    colonnes={[
                        { cle: 'rang', titre: 'Rang', alignement: 'droite', rendu: (a) => a.rang_tirage ?? '—' },
                        { cle: 'role', titre: 'Rôle', rendu: (a) => roleTerrain(a.role_terrain) },
                        {
                            cle: 'volontaire',
                            titre: 'Agent',
                            rendu: (a) => (
                                <>
                                    <span className="block font-mono text-xs text-ardoise-600">{a.volontaire?.matricule}</span>
                                    <span className="block">{nomDe(a.volontaire?.user)}</span>
                                </>
                            ),
                        },
                        { cle: 'centre', titre: 'Centre', rendu: (a) => a.centre?.code ?? '—' },
                        { cle: 'kit', titre: 'Kit', rendu: (a) => a.kit?.reference ?? '—' },
                        ...(peutAjuster || peutRemplacer
                            ? [{
                                cle: 'action',
                                titre: '',
                                compact: true,
                                rendu: (a) => (peutAjuster ? (
                                    <Ajustement
                                        vague={vague}
                                        affectation={a}
                                        onFait={(message) => { setAjustee(message); requete.refetch(); }}
                                    />
                                ) : (
                                    <Remplacement
                                        affectation={a}
                                        onFait={(message) => { setAjustee(message); requete.refetch(); }}
                                    />
                                )),
                            }]
                            : []),
                    ]}
                />
                <Pagination page={affectations} onPage={setPage} />
            </Bloc>
        </>
    );
}

/** Remplacer l'agent d'une affectation, avant validation seulement. */
function Ajustement({ vague, affectation, onFait }) {
    const [ouvert, setOuvert] = useState(false);
    const [recherche, setRecherche] = useState('');
    const action = useAction([]);

    const candidats = useQuery({
        queryKey: ['volontaires-candidats', affectation.role_terrain, recherche],
        // La liste des volontaires vit hors du groupe « referentiel » : s'y
        // tromper rendait un 404, et la liste des remplaçants serait restée
        // vide sans le moindre message.
        queryFn: () => api.lire(avecParametres('/volontaires', {
            categorie: categoriePourRole[affectation.role_terrain],
            statut: 'operationnel',
            recherche,
            par_page: 20,
        })),
        enabled: ouvert,
    });

    async function choisir(volontaireId) {
        const resultat = await action.lancer(() =>
            api.modifier(`/vagues/${vague.id}/affectations/${affectation.id}`, { volontaire_id: volontaireId }),
        );

        if (resultat) {
            setOuvert(false);
            onFait(resultat.message);
        }
    }

    if (!ouvert) {
        return (
            <Bouton variante="secondaire" onClick={() => setOuvert(true)}>Ajuster</Bouton>
        );
    }

    return (
        <div className="min-w-64 rounded border border-ardoise-300 bg-ardoise-50 p-3">
            {action.erreur && <div className="mb-2"><Echec erreur={action.erreur} /></div>}

            <Champ nom="recherche" libelle="Remplacer par">
                <Saisie
                    type="search"
                    value={recherche}
                    placeholder="Matricule, nom ou téléphone"
                    onChange={(e) => setRecherche(e.target.value)}
                />
            </Champ>

            <ul className="mt-2 max-h-48 space-y-1 overflow-y-auto">
                {(candidats.data?.data ?? []).map((volontaire) => (
                    <li key={volontaire.id}>
                        <button
                            type="button"
                            disabled={action.enCours}
                            onClick={() => choisir(volontaire.id)}
                            className="w-full rounded px-2 py-1 text-left text-sm hover:bg-white"
                        >
                            <span className="font-mono text-xs text-ardoise-600">{volontaire.matricule}</span>
                            {' — '}
                            {nomDe(volontaire.user)}
                        </button>
                    </li>
                ))}
            </ul>

            <Bouton variante="secondaire" className="mt-2" onClick={() => setOuvert(false)}>Fermer</Bouton>
        </div>
    );
}

/**
 * LA VALIDATION — le seul geste irréversible de cet écran.
 *
 * Elle ouvre les accès de tous les agents affectés et déclenche l'envoi des
 * identifiants. On la confirme donc explicitement, en annonçant le nombre.
 */
function Validation({ vague, onFait }) {
    const action = useAction(['vague', 'vagues', 'vague-proposition']);
    const [confirme, setConfirme] = useState(false);

    async function valider() {
        if (await action.lancer(() => api.agir(`/vagues/${vague.id}/valider`, {}))) {
            onFait();
        }
    }

    return (
        <Bloc
            titre="Valider la proposition"
            precision="Irréversible : les accès des agents affectés s’ouvrent et leurs identifiants partent par courriel, SMS ou bordereau."
        >
            {action.message && <div className="mb-4"><Succes message={action.message} onFermer={action.oublierMessage} /></div>}
            {action.erreur && <div className="mb-4"><Echec erreur={action.erreur} /></div>}

            <label className="flex items-start gap-2 text-sm text-ardoise-800">
                <input
                    type="checkbox"
                    checked={confirme}
                    onChange={(e) => setConfirme(e.target.checked)}
                    className="mt-0.5 h-4 w-4 rounded border-ardoise-400"
                />
                J’ai relu la proposition, y compris les contraintes non satisfaites.
            </label>

            <Bouton className="mt-3" disabled={!confirme || action.enCours} onClick={valider}>
                {action.enCours ? 'Validation…' : 'Valider et ouvrir les accès'}
            </Bouton>
        </Bloc>
    );
}

/** La clôture : fin de mission, accès recalculés, kits réclamés. */
function Cloture({ vague, onFait }) {
    const action = useAction(['vague', 'vagues']);

    async function cloturer() {
        if (await action.lancer(() => api.agir(`/vagues/${vague.id}/cloturer`, {}))) {
            onFait();
        }
    }

    return (
        <Bloc
            titre="Clôturer la vague"
            precision="Les affectations se terminent, les accès sont recalculés selon la catégorie, et les kits non restitués sont signalés."
        >
            {action.message && <div className="mb-4"><Succes message={action.message} onFermer={action.oublierMessage} /></div>}
            {action.erreur && <div className="mb-4"><Echec erreur={action.erreur} /></div>}

            <Bouton variante="danger" disabled={action.enCours} onClick={cloturer}>
                {action.enCours ? 'Clôture…' : 'Clôturer la vague'}
            </Bouton>
        </Bloc>
    );
}
