import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { api, avecParametres } from '../../api/client';
import { useAuth } from '../../auth/ContexteAuth';
import { useAction, useListe } from '../../outils/crochets';
import { Bloc } from '../../composants/Fiche';
import { Pagination, Pastille, Tableau } from '../../composants/Tableau';
import { BarreFiltres, FiltreListe, FiltreTexte } from '../../composants/Filtres';
import { Bouton, Champ, Liste, Saisie } from '../../composants/Champs';
import { Chargement } from '../../composants/Chargement';
import { Echec, Succes, Vide } from '../../composants/Etats';
import { date, humaniser, nombre, nomDe } from '../../outils/format';
import {
    categories,
    libelleCategorie,
    libelleNiveau,
    niveauSuffit,
    niveauxEtude,
    statutsCompte,
} from '../../domaine/volontaires';

/**
 * LE REGISTRE DES VOLONTAIRES.
 *
 * LE NUMÉRO CNIB N'APPARAÎT PAS DANS LA LISTE, et ce n'est pas un oubli : le
 * serveur ne le sérialise jamais là — il est réservé à l'administration
 * nationale, sur la fiche.
 *
 * CE QU'ON CORRIGE ET CE QU'ON NE CORRIGE PAS : l'identité, le niveau d'étude,
 * le diplôme et le territoire se corrigent. La CATÉGORIE et le MATRICULE, non :
 * les catégories sont étanches, et le matricule figure sur des documents déjà
 * remis.
 *
 * RETIRER n'efface rien : la fiche sort des listes et des tirages, son accès se
 * ferme, ses feuilles de présence et ses rapports restent. Le geste se défait.
 *
 * Le périmètre est appliqué côté serveur : un chef d'antenne voit sa région.
 */
export function Registre() {
    const auth = useAuth();
    const peutModifier = auth.peut('volontaires.modifier');
    const liste = useListe('volontaires', '/volontaires');
    const [fiche, setFiche] = useState(null);
    const [retrait, setRetrait] = useState(null);
    const [choisis, setChoisis] = useState(() => new Map());
    const [message, setMessage] = useState(null);

    const tonStatut = { operationnel: 'bon', reserve: 'attention', retire: 'neutre' };
    const lignes = liste.lignes ?? [];
    const tousCoches = lignes.length > 0 && lignes.every((v) => choisis.has(v.id));

    function basculer(volontaire) {
        setChoisis((actuels) => {
            const suivants = new Map(actuels);
            suivants.has(volontaire.id) ? suivants.delete(volontaire.id) : suivants.set(volontaire.id, volontaire);

            return suivants;
        });
    }

    function basculerPage() {
        setChoisis((actuels) => {
            const suivants = new Map(actuels);
            lignes.forEach((v) => (tousCoches ? suivants.delete(v.id) : suivants.set(v.id, v)));

            return suivants;
        });
    }

    function ouvrir(reglage) {
        setMessage(null);
        setRetrait(null);
        setFiche(null);
        reglage();
    }

    return (
        <>
            <BarreFiltres onReinitialiser={liste.reinitialiser}>
                <FiltreTexte
                    libelle="Recherche"
                    valeur={liste.filtres.recherche}
                    onChange={(v) => liste.changerFiltre('recherche', v)}
                    placeholder="Nom, téléphone ou matricule"
                />
                <FiltreListe
                    libelle="Catégorie"
                    valeur={liste.filtres.categorie}
                    onChange={(v) => liste.changerFiltre('categorie', v)}
                    tous="Toutes"
                    options={categories}
                />
                <FiltreListe
                    libelle="Statut"
                    valeur={liste.filtres.statut}
                    onChange={(v) => liste.changerFiltre('statut', v)}
                    options={[
                        { valeur: 'operationnel', libelle: 'Opérationnel' },
                        { valeur: 'reserve', libelle: 'En réserve' },
                        { valeur: 'retire', libelle: 'Retiré' },
                    ]}
                />
            </BarreFiltres>

            {message && <Succes message={message} onFermer={() => setMessage(null)} />}

            {fiche && (
                <FicheVolontaire
                    key={fiche.id}
                    volontaire={fiche}
                    onAnnuler={() => setFiche(null)}
                    onEnregistre={(texte) => { setFiche(null); setMessage(texte); }}
                />
            )}

            {retrait && (
                <Retrait
                    key={`${retrait.type}-${retrait.volontaires.length}`}
                    retrait={retrait}
                    onAnnuler={() => setRetrait(null)}
                    onFait={(texte) => { setRetrait(null); setChoisis(new Map()); setMessage(texte); }}
                />
            )}

            {peutModifier && choisis.size > 0 && (
                <div className="flex flex-wrap items-center gap-3 rounded border border-ardoise-200 bg-white px-4 py-3 text-sm">
                    <span className="font-medium">{nombre(choisis.size)} fiches choisies</span>
                    <Bouton
                        variante="danger"
                        onClick={() => ouvrir(() => setRetrait({ type: 'lot', volontaires: [...choisis.values()] }))}
                    >
                        Retirer du dispositif
                    </Bouton>
                    <Bouton variante="secondaire" onClick={() => setChoisis(new Map())}>Tout décocher</Bouton>
                </div>
            )}

            {liste.isPending && <Chargement message="Chargement du registre…" />}
            {liste.error && <Echec erreur={liste.error} onReessayer={liste.refetch} />}

            {!liste.isPending && !liste.error && (
                <>
                    <Tableau
                        cle={(v) => v.id}
                        lignes={lignes}
                        vide={<Vide titre="Aucun volontaire ne correspond" explication="Aucun volontaire de votre périmètre ne répond à ces filtres." />}
                        colonnes={[
                            ...(peutModifier
                                ? [{
                                    cle: 'choix',
                                    titre: (
                                        <input type="checkbox" aria-label="Cocher toute la page" checked={tousCoches} onChange={basculerPage} className="h-4 w-4" />
                                    ),
                                    compact: true,
                                    rendu: (v) => (
                                        <input
                                            type="checkbox"
                                            aria-label={`Choisir ${nomDe(v.user)}`}
                                            checked={choisis.has(v.id)}
                                            onChange={() => basculer(v)}
                                            className="h-4 w-4"
                                        />
                                    ),
                                }]
                                : []),
                            { cle: 'matricule', titre: 'Matricule', compact: true, rendu: (v) => <span className="font-mono">{v.matricule}</span> },
                            { cle: 'nom', titre: 'Nom et prénoms', rendu: (v) => nomDe(v.user) },
                            {
                                cle: 'categorie',
                                titre: 'Catégorie',
                                compact: true,
                                rendu: (v) => (v.categorie ? libelleCategorie(v.categorie) : <Pastille ton="attention">à qualifier</Pastille>),
                            },
                            {
                                cle: 'niveau',
                                titre: 'Niveau d’étude',
                                rendu: (v) => {
                                    const libelle = libelleNiveau(v.niveau_etude);

                                    if (!libelle) {
                                        return <Pastille ton="attention">à renseigner</Pastille>;
                                    }

                                    // Un niveau sous le minimum n'est possible que par dérogation :
                                    // on le montre, avec son motif en infobulle.
                                    return v.categorie && !niveauSuffit(v.niveau_etude, v.categorie)
                                        ? (
                                            <span title={v.derogation_niveau_motif ?? undefined}>
                                                <Pastille ton="alerte">{libelle} — dérogation</Pastille>
                                            </span>
                                        )
                                        : libelle;
                                },
                            },
                            { cle: 'diplome', titre: 'Diplôme', rendu: (v) => v.diplome ?? '—' },
                            {
                                cle: 'statut',
                                titre: 'Statut',
                                compact: true,
                                rendu: (v) => (
                                    <span title={v.statut === 'retire' ? (v.motif_retrait ?? undefined) : undefined}>
                                        <Pastille ton={tonStatut[v.statut] ?? 'neutre'}>{humaniser(v.statut)}</Pastille>
                                    </span>
                                ),
                            },
                            { cle: 'localite', titre: 'Localité', rendu: (v) => v.localite?.nom ?? '—' },
                            { cle: 'telephone', titre: 'Téléphone', compact: true, rendu: (v) => v.user?.telephone ?? '—' },
                            {
                                cle: 'compte',
                                titre: 'Accès',
                                compact: true,
                                rendu: (v) => statutsCompte[v.user?.statut_compte]?.libelle ?? humaniser(v.user?.statut_compte),
                            },
                            ...(peutModifier
                                ? [{
                                    cle: 'actions',
                                    titre: '',
                                    compact: true,
                                    rendu: (v) => (
                                        <div className="flex flex-wrap gap-2">
                                            <Bouton variante="secondaire" onClick={() => ouvrir(() => setFiche(v))}>Modifier</Bouton>
                                            {v.statut === 'retire'
                                                ? <Bouton variante="secondaire" onClick={() => ouvrir(() => setRetrait({ type: 'reintegrer', volontaires: [v] }))}>Réintégrer</Bouton>
                                                : <Bouton variante="secondaire" onClick={() => ouvrir(() => setRetrait({ type: 'retirer', volontaires: [v] }))}>Retirer</Bouton>}
                                        </div>
                                    ),
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

/** La fiche : identité, dossier, territoire. Ni catégorie, ni matricule. */
function FicheVolontaire({ volontaire, onAnnuler, onEnregistre }) {
    const [valeurs, setValeurs] = useState({
        nom: volontaire.user?.nom ?? '',
        prenoms: volontaire.user?.prenoms ?? '',
        telephone: volontaire.user?.telephone ?? '',
        email: volontaire.user?.email ?? '',
        sexe: volontaire.sexe ?? '',
        date_naissance: volontaire.date_naissance?.slice(0, 10) ?? '',
        lieu_naissance: volontaire.lieu_naissance ?? '',
        niveau_etude: volontaire.niveau_etude ?? '',
        diplome: volontaire.diplome ?? '',
    });
    const action = useAction(['volontaires', 'volontaires-a-qualifier', 'remises']);
    const erreurs = action.erreur?.erreurs;

    const detail = useQuery({
        queryKey: ['volontaire', volontaire.id],
        queryFn: () => api.lire(`/volontaires/${volontaire.id}`),
    });

    const changer = (champ) => (e) => setValeurs((v) => ({ ...v, [champ]: e.target.value }));

    async function enregistrer(evenement) {
        evenement.preventDefault();

        const corps = Object.fromEntries(
            Object.entries(valeurs).map(([cle, valeur]) => [cle, valeur === '' ? null : valeur]),
        );

        const resultat = await action.lancer(() => api.modifier(`/volontaires/${volontaire.id}`, corps));

        if (resultat) {
            onEnregistre(resultat.message);
        }
    }

    return (
        <Bloc
            titre={`Fiche de ${nomDe(volontaire.user)}`}
            precision={`${volontaire.matricule} · ${libelleCategorie(volontaire.categorie) ?? 'à qualifier'} — la catégorie et le matricule ne se modifient pas.`}
        >
            <form onSubmit={enregistrer} aria-label="Fiche du volontaire" className="space-y-4">
                {action.erreur && !action.erreur.estValidation && <Echec erreur={action.erreur} />}

                <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                    <Champ nom="nom" libelle="Nom" erreurs={erreurs}>
                        <Saisie id="fiche-nom" value={valeurs.nom} onChange={changer('nom')} maxLength={80} required />
                    </Champ>
                    <Champ nom="prenoms" libelle="Prénoms" erreurs={erreurs}>
                        <Saisie id="fiche-prenoms" value={valeurs.prenoms} onChange={changer('prenoms')} maxLength={120} required />
                    </Champ>
                    <Champ nom="telephone" libelle="Téléphone" aide="Identifiant de connexion : le changer ferme ses sessions." erreurs={erreurs}>
                        <Saisie id="fiche-telephone" value={valeurs.telephone} onChange={changer('telephone')} inputMode="tel" required />
                    </Champ>
                    <Champ nom="email" libelle="Courriel" erreurs={erreurs}>
                        <Saisie id="fiche-email" type="email" value={valeurs.email} onChange={changer('email')} maxLength={150} />
                    </Champ>
                    <Champ nom="sexe" libelle="Sexe" erreurs={erreurs}>
                        <Liste id="fiche-sexe" value={valeurs.sexe} onChange={changer('sexe')}>
                            <option value="">Non renseigné</option>
                            <option value="M">Masculin</option>
                            <option value="F">Féminin</option>
                        </Liste>
                    </Champ>
                    <Champ nom="date_naissance" libelle="Date de naissance" erreurs={erreurs}>
                        <Saisie id="fiche-naissance" type="date" value={valeurs.date_naissance} onChange={changer('date_naissance')} />
                    </Champ>
                    <Champ nom="lieu_naissance" libelle="Lieu de naissance" erreurs={erreurs}>
                        <Saisie id="fiche-lieu" value={valeurs.lieu_naissance} onChange={changer('lieu_naissance')} maxLength={120} />
                    </Champ>
                    <Champ
                        nom="niveau_etude"
                        libelle="Niveau d’étude"
                        aide="Il commande le profil : 4ème pour un A-OPK, BAC pour un opérateur, Licence pour un superviseur."
                        erreurs={erreurs}
                    >
                        <Liste id="fiche-niveau" value={valeurs.niveau_etude} onChange={changer('niveau_etude')}>
                            <option value="">Non renseigné</option>
                            {niveauxEtude.map((n) => <option key={n.valeur} value={n.valeur}>{n.libelle}</option>)}
                        </Liste>
                    </Champ>
                    <Champ nom="diplome" libelle="Diplôme (intitulé exact)" erreurs={erreurs}>
                        <Saisie id="fiche-diplome" value={valeurs.diplome} onChange={changer('diplome')} maxLength={150} />
                    </Champ>
                </div>

                {detail.data && (
                    <p className="text-sm text-ardoise-600">
                        N° CNIB : <span className="font-mono">{detail.data.numero_cnib ?? '—'}</span>
                        {detail.data.volontaire?.derogation_niveau_motif && (
                            <>
                                {' · '}
                                <span className="text-ocre-800">
                                    Profil accordé par dérogation : {detail.data.volontaire.derogation_niveau_motif}
                                </span>
                            </>
                        )}
                        {volontaire.statut === 'retire' && volontaire.retire_le && (
                            <> · Retiré le {date(volontaire.retire_le)}</>
                        )}
                    </p>
                )}

                <div className="flex gap-2">
                    <Bouton type="submit" disabled={action.enCours}>
                        {action.enCours ? 'Enregistrement…' : 'Enregistrer'}
                    </Bouton>
                    <Bouton type="button" variante="secondaire" onClick={onAnnuler}>Annuler</Bouton>
                </div>
            </form>
        </Bloc>
    );
}

/** Retirer ou réintégrer : un motif, et ce que le geste fait vraiment. */
function Retrait({ retrait, onAnnuler, onFait }) {
    const [motif, setMotif] = useState('');
    const action = useAction(['volontaires', 'volontaires-a-qualifier', 'remises', 'equipes']);
    const { type, volontaires } = retrait;
    const lot = type === 'lot';

    const textes = {
        retirer: {
            titre: `Retirer ${nomDe(volontaires[0]?.user)} du dispositif`,
            precision: 'La fiche sort des listes et des tirages, et son accès se ferme. Ses feuilles de présence et ses rapports restent. Le geste se défait.',
            bouton: 'Retirer',
        },
        lot: {
            titre: `Retirer ${nombre(volontaires.length)} fiches du dispositif`,
            precision: 'Une fiche engagée dans une vague en cours ne sera pas retirée : elle demande d’abord un remplacement.',
            bouton: 'Retirer les fiches choisies',
        },
        reintegrer: {
            titre: `Réintégrer ${nomDe(volontaires[0]?.user)}`,
            precision: 'La fiche revient en réserve : elle redevient mobilisable pour une prochaine vague.',
            bouton: 'Réintégrer',
        },
    }[type];

    async function confirmer(evenement) {
        evenement.preventDefault();

        const appel = lot
            ? () => api.agir('/volontaires/retrait-en-lot', { volontaire_ids: volontaires.map((v) => v.id), motif })
            : () => api.agir(`/volontaires/${volontaires[0].id}/${type === 'retirer' ? 'retirer' : 'reintegrer'}`, { motif });

        const resultat = await action.lancer(appel);

        if (resultat) {
            const refusees = resultat.donnees?.refusees ?? [];

            onFait(refusees.length > 0
                ? `${resultat.message} ${refusees.map((r) => `${r.matricule} : ${r.motif}`).join(' ')}`
                : resultat.message);
        }
    }

    return (
        <Bloc titre={textes.titre} precision={textes.precision}>
            <form onSubmit={confirmer} aria-label={textes.titre} className="space-y-3">
                {action.erreur && !action.erreur.estValidation && <Echec erreur={action.erreur} />}
                <Champ nom="motif" libelle="Motif (inscrit sur la fiche et au journal)" erreurs={action.erreur?.erreurs}>
                    <Saisie id="motif-retrait" value={motif} onChange={(e) => setMotif(e.target.value)} maxLength={500} required />
                </Champ>
                <div className="flex gap-2">
                    <Bouton
                        type="submit"
                        variante={type === 'reintegrer' ? 'principal' : 'danger'}
                        disabled={action.enCours || motif.trim().length < 5}
                    >
                        {textes.bouton}
                    </Bouton>
                    <Bouton type="button" variante="secondaire" onClick={onAnnuler}>Annuler</Bouton>
                </div>
            </form>
        </Bloc>
    );
}
