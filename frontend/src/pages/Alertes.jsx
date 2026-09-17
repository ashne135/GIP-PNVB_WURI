import { useState } from 'react';
import { Link } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';
import { api, avecParametres } from '../api/client';
import { useAuth } from '../auth/ContexteAuth';
import { useAction, useListe } from '../outils/crochets';
import { EnTetePage } from '../composants/Page';
import { Pagination, Pastille } from '../composants/Tableau';
import { BarreFiltres, FiltreListe } from '../composants/Filtres';
import { Bloc } from '../composants/Fiche';
import { Bouton, Champ, Liste, Saisie, Texte } from '../composants/Champs';
import { Chargement } from '../composants/Chargement';
import { Echec, Succes, Vide } from '../composants/Etats';
import { dateHeure, nomDe } from '../outils/format';
import { libellesRoles } from '../domaine/referentiel';
import { useRegions } from './referentiel/ListeCentres';

/**
 * LES ALERTES QUI ME CONCERNENT, ET CELLES QUE JE PUBLIE.
 *
 * C'est cet écran qui fait exister le moteur d'escalade : une escalade qui
 * n'aboutit à rien de lisible ne sert à rien. La portée de chaque alerte
 * décide seule de qui la voit, et le serveur l'applique — aucune alerte hors
 * périmètre n'arrive ici.
 *
 * Les alertes NON LUES sont mises en avant : sur un canal qui doit rester
 * crédible, ce qui n'a pas été vu compte plus que ce qui l'a été.
 */
export function Alertes() {
    const auth = useAuth();
    const liste = useListe('alertes', '/alertes');
    const action = useAction(['alertes', 'alertes-non-lues']);

    const tons = { info: 'info', important: 'attention', critique: 'alerte' };

    return (
        <>
            <EnTetePage
                titre="Alertes"
                sousTitre="Ce que la plateforme vous signale, dans votre périmètre."
            />

            {auth.peut('alertes.publier') && <PublierAlerte estNational={auth.estNational} />}

            <BarreFiltres onReinitialiser={liste.reinitialiser}>
                <FiltreListe
                    libelle="Niveau"
                    valeur={liste.filtres.niveau}
                    onChange={(v) => liste.changerFiltre('niveau', v)}
                    options={[
                        { valeur: 'critique', libelle: 'Critique' },
                        { valeur: 'important', libelle: 'Important' },
                        { valeur: 'info', libelle: 'Information' },
                    ]}
                />
                <FiltreListe
                    libelle="Type"
                    valeur={liste.filtres.type}
                    onChange={(v) => liste.changerFiltre('type', v)}
                    options={[
                        { valeur: 'escalade_incident', libelle: 'Escalade d’incident' },
                        { valeur: 'ecart_presence', libelle: 'Écart de présence' },
                        { valeur: 'kit_non_restitue', libelle: 'Kit non restitué' },
                        { valeur: 'systeme', libelle: 'Système' },
                        { valeur: 'descendante', libelle: 'Descendante' },
                    ]}
                />
                <FiltreListe
                    libelle="Période"
                    valeur={liste.filtres.toutes}
                    onChange={(v) => liste.changerFiltre('toutes', v)}
                    tous="En cours seulement"
                    options={[{ valeur: '1', libelle: 'Y compris les expirées' }]}
                />
            </BarreFiltres>

            {liste.isPending && <Chargement message="Chargement des alertes…" />}
            {liste.error && <Echec erreur={liste.error} onReessayer={liste.refetch} />}

            {!liste.isPending && !liste.error && liste.lignes.length === 0 && (
                <Vide
                    titre="Aucune alerte en cours"
                    explication="Rien ne vous est signalé pour le moment dans votre périmètre."
                />
            )}

            <ul className="space-y-2">
                {liste.lignes.map((alerte) => (
                    <li
                        key={alerte.id}
                        className={`rounded-lg border bg-white px-4 py-3 shadow-sm ${
                            alerte.lue ? 'border-ardoise-200' : 'border-l-4 border-l-pnvb-600 border-ardoise-200'
                        }`}
                    >
                        <div className="flex flex-wrap items-start justify-between gap-3">
                            <div className="min-w-0">
                                <div className="flex flex-wrap items-center gap-2">
                                    <Pastille ton={tons[alerte.niveau] ?? 'neutre'}>
                                        {alerte.niveau}
                                    </Pastille>
                                    {!alerte.lue && <Pastille ton="info">non lue</Pastille>}
                                    <span className="font-mono text-xs text-ardoise-400">
                                        {alerte.code}
                                    </span>
                                </div>
                                <p className="mt-1.5 text-sm font-semibold text-ardoise-900">
                                    {alerte.titre}
                                </p>
                                <p className="mt-1 text-sm text-ardoise-600">{alerte.message}</p>
                                <p className="mt-1.5 text-xs text-ardoise-500">
                                    {dateHeure(alerte.publiee_le)}
                                    {alerte.emetteur
                                        ? ` · ${nomDe(alerte.emetteur)}`
                                        : ' · acteur système'}
                                </p>
                            </div>

                            <div className="flex shrink-0 flex-col items-end gap-2">
                                {alerte.incident && (
                                    <Link
                                        to={`/incidents/${alerte.incident.id}`}
                                        className="text-sm text-pnvb-800 underline"
                                    >
                                        Voir l’incident {alerte.incident.numero}
                                    </Link>
                                )}
                                {!alerte.lue && (
                                    <button
                                        type="button"
                                        disabled={action.enCours}
                                        onClick={() =>
                                            action.lancer(() =>
                                                api.agir(`/alertes/${alerte.id}/lue`),
                                            )
                                        }
                                        className="rounded border border-ardoise-300 px-3 py-1.5 text-sm hover:bg-ardoise-50"
                                    >
                                        Marquer comme lue
                                    </button>
                                )}
                            </div>
                        </div>
                    </li>
                ))}
            </ul>

            <Pagination page={liste.pagination} onPage={liste.setPage} />
        </>
    );
}

/**
 * PUBLIER UNE CONSIGNE.
 *
 * La PORTÉE décide de qui la reçoit, et c'est le choix le plus lourd de ce
 * formulaire : une alerte nationale touche les douze régions. Le serveur la
 * refuse à un responsable régional ; l'écran ne la lui propose donc pas, pour
 * qu'il ne l'apprenne pas par un refus.
 *
 * L'échéance est facultative mais recommandée : une consigne qui reste
 * affichée indéfiniment finit par ne plus être lue.
 */
function PublierAlerte({ estNational }) {
    const [ouvert, setOuvert] = useState(false);
    const [champs, setChamps] = useState({
        titre: '',
        message: '',
        niveau: 'info',
        portee: estNational ? 'nationale' : 'regionale',
        region_id: '',
        centre_id: '',
        role_cible: '',
        expire_le: '',
    });

    const action = useAction(['alertes', 'alertes-non-lues']);
    const regions = useRegions();

    const centres = useQuery({
        queryKey: ['centres-alerte', champs.region_id],
        queryFn: () => api.lire(avecParametres('/referentiel/centres', { region_id: champs.region_id, par_page: 200 })),
        enabled: champs.portee === 'centre',
    });

    const changer = (nom) => (e) => setChamps((c) => ({ ...c, [nom]: e.target.value }));

    async function publier(evenement) {
        evenement.preventDefault();

        const resultat = await action.lancer(() =>
            api.creer('/alertes', {
                titre: champs.titre,
                message: champs.message,
                niveau: champs.niveau,
                portee: champs.portee,
                ...(champs.portee === 'regionale' ? { region_id: Number(champs.region_id) } : {}),
                ...(champs.portee === 'centre' ? { centre_id: Number(champs.centre_id) } : {}),
                ...(champs.portee === 'role' ? { role_cible: champs.role_cible } : {}),
                ...(champs.expire_le ? { expire_le: champs.expire_le } : {}),
            }),
        );

        if (resultat) {
            setChamps((c) => ({ ...c, titre: '', message: '', expire_le: '' }));
        }
    }

    if (!ouvert) {
        return (
            <div className="flex justify-end">
                <Bouton variante="secondaire" onClick={() => setOuvert(true)}>Publier une alerte</Bouton>
            </div>
        );
    }

    return (
        <Bloc
            titre="Publier une alerte"
            precision="Elle apparaîtra immédiatement chez ses destinataires, et chaque lecture sera horodatée."
            actions={<Bouton variante="secondaire" onClick={() => setOuvert(false)}>Fermer</Bouton>}
        >
            {action.message && <div className="mb-4"><Succes message={action.message} onFermer={action.oublierMessage} /></div>}
            {action.erreur && !action.erreur.estValidation && <div className="mb-4"><Echec erreur={action.erreur} /></div>}

            <form onSubmit={publier} className="grid gap-4 sm:grid-cols-2">
                <div className="sm:col-span-2">
                    <Champ nom="titre" libelle="Titre" erreurs={action.erreur?.erreurs} aide="C’est ce que les agents liront en premier.">
                        <Saisie value={champs.titre} onChange={changer('titre')} required maxLength={160} />
                    </Champ>
                </div>

                <div className="sm:col-span-2">
                    <Champ nom="message" libelle="Message" erreurs={action.erreur?.erreurs}>
                        <Texte value={champs.message} onChange={changer('message')} required />
                    </Champ>
                </div>

                <Champ nom="niveau" libelle="Niveau" erreurs={action.erreur?.erreurs}>
                    <Liste value={champs.niveau} onChange={changer('niveau')}>
                        <option value="info">Information</option>
                        <option value="important">Important</option>
                        <option value="critique">Critique</option>
                    </Liste>
                </Champ>

                <Champ
                    nom="portee"
                    libelle="Destinataires"
                    erreurs={action.erreur?.erreurs}
                    aide={estNational ? undefined : 'Votre périmètre : votre région, ou l’un de ses centres.'}
                >
                    <Liste value={champs.portee} onChange={changer('portee')}>
                        {/* Le national seul peut viser tout le pays ou un rôle :
                            les deux traversent les douze régions. */}
                        {estNational && <option value="nationale">Toutes les régions</option>}
                        <option value="regionale">Une région</option>
                        <option value="centre">Un centre</option>
                        {estNational && <option value="role">Tous les comptes d’un rôle</option>}
                    </Liste>
                </Champ>

                {(champs.portee === 'regionale' || champs.portee === 'centre') && (
                    <Champ nom="region_id" libelle="Région" erreurs={action.erreur?.erreurs}>
                        <Liste value={champs.region_id} onChange={(e) => { changer('region_id')(e); setChamps((c) => ({ ...c, centre_id: '' })); }} required>
                            <option value="">Choisir…</option>
                            {regions.map((r) => <option key={r.id} value={r.id}>{r.nom}</option>)}
                        </Liste>
                    </Champ>
                )}

                {champs.portee === 'centre' && (
                    <Champ nom="centre_id" libelle="Centre" erreurs={action.erreur?.erreurs}>
                        <Liste value={champs.centre_id} onChange={changer('centre_id')} disabled={!champs.region_id} required>
                            <option value="">{champs.region_id ? 'Choisir…' : 'Choisissez d’abord une région'}</option>
                            {(centres.data?.data ?? []).map((c) => (
                                <option key={c.id} value={c.id}>{c.code} — {c.nom}</option>
                            ))}
                        </Liste>
                    </Champ>
                )}

                {champs.portee === 'role' && (
                    <Champ nom="role_cible" libelle="Rôle destinataire" erreurs={action.erreur?.erreurs}>
                        <Liste value={champs.role_cible} onChange={changer('role_cible')} required>
                            <option value="">Choisir…</option>
                            {Object.entries(libellesRoles)
                                .filter(([valeur]) => valeur !== 'systeme')
                                .map(([valeur, libelle]) => (
                                    <option key={valeur} value={valeur}>{libelle}</option>
                                ))}
                        </Liste>
                    </Champ>
                )}

                <Champ
                    nom="expire_le"
                    libelle="Expire le (facultatif)"
                    erreurs={action.erreur?.erreurs}
                    aide="Sans échéance, l’alerte reste affichée indéfiniment."
                >
                    <Saisie type="datetime-local" value={champs.expire_le} onChange={changer('expire_le')} />
                </Champ>

                <div className="sm:col-span-2">
                    <Bouton type="submit" disabled={action.enCours}>
                        {action.enCours ? 'Publication…' : 'Publier l’alerte'}
                    </Bouton>
                </div>
            </form>
        </Bloc>
    );
}
