import { useState } from 'react';
import { Link } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';
import { api, avecParametres } from '../../api/client';
import { useAction, useListe } from '../../outils/crochets';
import { useAuth } from '../../auth/ContexteAuth';
import { EnTetePage, Indicateur } from '../../composants/Page';
import { Bloc } from '../../composants/Fiche';
import { Bouton, Champ, Liste, Saisie, Texte } from '../../composants/Champs';
import { Pagination, Pastille, Tableau } from '../../composants/Tableau';
import { BarreFiltres, FiltreListe, FiltreTexte } from '../../composants/Filtres';
import { Chargement } from '../../composants/Chargement';
import { Echec, Succes, Vide } from '../../composants/Etats';
import { nombre, nomDe } from '../../outils/format';
import { etatKit, etatsKit } from '../../domaine/kits';

/**
 * LE PARC DE KITS.
 *
 * LE KIT SUIT LA PERSONNE, PAS LE SITE : la colonne qui compte est donc le
 * DÉTENTEUR. Le site courant est une information de localisation, pas de
 * rattachement.
 */
export function ListeKits() {
    const auth = useAuth();
    const liste = useListe('kits', '/kits');
    const synthese = useQuery({ queryKey: ['kits-synthese'], queryFn: () => api.lire('/kits/synthese') });

    return (
        <>
            <EnTetePage
                titre="Parc de kits"
                sousTitre="Un kit est rattaché à un agent, pas à un site : il le suit d’une région à l’autre."
                actions={
                    <Link
                        to="/kits/non-restitues"
                        className="rounded border border-ocre-400 bg-ocre-50 px-3 py-2 text-sm font-medium text-ocre-900 hover:bg-ocre-100"
                    >
                        Kits à récupérer
                    </Link>
                }
            />

            {synthese.data && (
                <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                    <Indicateur libelle="Kits dans le périmètre" valeur={nombre(synthese.data.total)} />
                    <Indicateur libelle="Attribués" valeur={nombre(synthese.data.attribues)} />
                    <Indicateur libelle="Disponibles" valeur={nombre(synthese.data.disponibles)} />
                    <Indicateur
                        libelle="Non restitués"
                        valeur={nombre(synthese.data.non_restitues)}
                        precision="Mission terminée depuis plus que le délai de grâce"
                        ton={synthese.data.non_restitues > 0 ? 'alerte' : 'bon'}
                    />
                </div>
            )}

            {auth.peut('kits.gerer') && <AjouterKit />}

            <BarreFiltres onReinitialiser={liste.reinitialiser}>
                <FiltreTexte
                    libelle="Référence"
                    valeur={liste.filtres.reference}
                    onChange={(v) => liste.changerFiltre('reference', v)}
                    placeholder="KIT-…"
                />
                <FiltreListe
                    libelle="État"
                    valeur={liste.filtres.etat}
                    onChange={(v) => liste.changerFiltre('etat', v)}
                    options={Object.entries(etatsKit).map(([valeur, e]) => ({ valeur, libelle: e.libelle }))}
                />
                <FiltreListe
                    libelle="Attribution"
                    valeur={liste.filtres.disponibles}
                    onChange={(v) => liste.changerFiltre('disponibles', v)}
                    options={[{ valeur: '1', libelle: 'Disponibles seulement' }]}
                />
            </BarreFiltres>

            {liste.isPending && <Chargement message="Chargement du parc…" />}
            {liste.error && <Echec erreur={liste.error} onReessayer={liste.refetch} />}

            {!liste.isPending && !liste.error && (
                <>
                    <Tableau
                        cle={(k) => k.id}
                        lignes={liste.lignes}
                        vide={<Vide titre="Aucun kit ne correspond" explication="Aucun kit de votre périmètre ne répond à ces filtres." />}
                        colonnes={[
                            {
                                cle: 'reference',
                                titre: 'Référence',
                                compact: true,
                                rendu: (k) => (
                                    <Link to={`/kits/${k.id}`} className="font-mono font-medium text-pnvb-800 underline">
                                        {k.reference}
                                    </Link>
                                ),
                            },
                            {
                                cle: 'etat',
                                titre: 'État',
                                compact: true,
                                rendu: (k) => <Pastille ton={etatKit(k.etat).ton}>{etatKit(k.etat).libelle}</Pastille>,
                            },
                            {
                                cle: 'detenteur',
                                titre: 'Détenteur',
                                rendu: (k) =>
                                    k.detenteur ? `${k.detenteur.matricule} — ${nomDe(k.detenteur.user)}` : (
                                        <span className="text-ardoise-400">au parc</span>
                                    ),
                            },
                            { cle: 'centre', titre: 'Centre', rendu: (k) => k.centre_courant?.code ?? '—' },
                            { cle: 'site', titre: 'Site courant', rendu: (k) => k.site_courant?.nom ?? '—' },
                            {
                                cle: 'zone',
                                titre: '',
                                compact: true,
                                rendu: (k) => (k.est_permanent_zone_defis ? <Pastille ton="info">zone à défis</Pastille> : null),
                            },
                        ]}
                    />
                    <Pagination page={liste.pagination} onPage={liste.setPage} />
                </>
            )}
        </>
    );
}

/**
 * AJOUTER UN KIT AU PARC.
 *
 * Un kit entre au parc SANS DÉTENTEUR : c'est un mouvement de remise, et lui
 * seul, qui le confie à un agent. Le formulaire ne propose donc aucun champ
 * « détenteur » — l'attribuer ici contournerait la traçabilité que le parc
 * existe pour tenir.
 *
 * L'état initial est « fonctionnel », décidé par le serveur : un kit qu'on
 * enregistre en panne se déclare par un mouvement, avec sa constatation.
 *
 * LA COMPOSITION est saisie librement, une ligne par élément. Je ne pré-remplis
 * aucune liste type : le canevas de composition du Programme n'est pas dans la
 * base, et l'inventer ici ferait signer à l'agent un contenu que personne n'a
 * validé.
 */
function AjouterKit() {
    const [ouvert, setOuvert] = useState(false);
    const [champs, setChamps] = useState({ reference: '', composition: '', centre_courant_id: '', zone_defis: false });
    const action = useAction(['kits', 'kits-synthese']);

    const centres = useQuery({
        queryKey: ['centres-parc-kits'],
        queryFn: () => api.lire(avecParametres('/referentiel/centres', { par_page: 200 })),
        enabled: ouvert,
    });

    async function ajouter(evenement) {
        evenement.preventDefault();

        // Une ligne vide dans la zone de saisie n'est pas un élément du kit.
        const composition = champs.composition
            .split('\n')
            .map((ligne) => ligne.trim())
            .filter((ligne) => ligne !== '');

        const resultat = await action.lancer(() =>
            api.creer('/kits', {
                reference: champs.reference.trim(),
                composition: composition.length > 0 ? composition : undefined,
                centre_courant_id: champs.centre_courant_id ? Number(champs.centre_courant_id) : undefined,
                est_permanent_zone_defis: champs.zone_defis,
            }),
        );

        if (resultat) {
            // La référence seule est vidée : on en enregistre rarement un seul,
            // et la composition comme le centre se répètent d'un kit à l'autre.
            setChamps((c) => ({ ...c, reference: '' }));
        }
    }

    if (!ouvert) {
        return (
            <div className="flex justify-end">
                <Bouton variante="secondaire" onClick={() => setOuvert(true)}>Ajouter un kit au parc</Bouton>
            </div>
        );
    }

    return (
        <Bloc
            titre="Ajouter un kit au parc"
            precision="Le kit entre disponible, sans détenteur : c’est une remise qui le confie à un agent."
            actions={<Bouton variante="secondaire" onClick={() => setOuvert(false)}>Fermer</Bouton>}
        >
            {action.message && <div className="mb-4"><Succes message={action.message} onFermer={action.oublierMessage} /></div>}
            {action.erreur && !action.erreur.estValidation && <div className="mb-4"><Echec erreur={action.erreur} /></div>}

            <form onSubmit={ajouter} aria-label="Ajouter un kit au parc" className="grid gap-4 sm:grid-cols-2">
                <Champ
                    nom="reference"
                    libelle="Référence"
                    erreurs={action.erreur?.erreurs}
                    aide="Celle qui est gravée ou collée sur la mallette. Elle identifie le kit pour toute sa vie."
                >
                    <Saisie
                        value={champs.reference}
                        onChange={(e) => setChamps((c) => ({ ...c, reference: e.target.value }))}
                        required
                        maxLength={30}
                        placeholder="KIT-BAN-BAGA-C001"
                    />
                </Champ>
                <Champ
                    nom="centre_courant_id"
                    libelle="Centre de rattachement"
                    erreurs={action.erreur?.erreurs}
                    aide="Facultatif : où le kit est entreposé aujourd’hui. Il suivra ensuite son détenteur."
                >
                    <Liste
                        value={champs.centre_courant_id}
                        onChange={(e) => setChamps((c) => ({ ...c, centre_courant_id: e.target.value }))}
                        disabled={centres.isPending}
                    >
                        <option value="">Aucun</option>
                        {(centres.data?.data ?? []).map((centre) => (
                            <option key={centre.id} value={centre.id}>{centre.code} — {centre.nom}</option>
                        ))}
                    </Liste>
                </Champ>
                <div className="sm:col-span-2">
                    <Champ
                        nom="composition"
                        libelle="Composition"
                        erreurs={action.erreur?.erreurs}
                        aide="Un élément par ligne. C’est cette liste que l’agent constate à chaque remise et à chaque restitution."
                    >
                        <Texte
                            value={champs.composition}
                            onChange={(e) => setChamps((c) => ({ ...c, composition: e.target.value }))}
                            rows={5}
                            placeholder={'tablette de saisie\nscanner d’empreintes\nimprimante portable'}
                        />
                    </Champ>
                </div>
                <label className="flex items-center gap-2 text-sm text-ardoise-800 sm:col-span-2">
                    <input
                        type="checkbox"
                        checked={champs.zone_defis}
                        onChange={(e) => setChamps((c) => ({ ...c, zone_defis: e.target.checked }))}
                        className="h-4 w-4 rounded border-ardoise-400"
                    />
                    Kit d’un centre permanent en zone à défis sécuritaires
                </label>
                <div className="sm:col-span-2">
                    <Bouton type="submit" disabled={action.enCours}>
                        {action.enCours ? 'Ajout…' : 'Ajouter au parc'}
                    </Bouton>
                </div>
            </form>
        </Bloc>
    );
}
