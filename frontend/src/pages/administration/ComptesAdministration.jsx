import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { api } from '../../api/client';
import { useAuth } from '../../auth/ContexteAuth';
import { useAction, useListe } from '../../outils/crochets';
import { EnTetePage } from '../../composants/Page';
import { Bloc } from '../../composants/Fiche';
import { Pagination, Pastille, Tableau } from '../../composants/Tableau';
import { BarreFiltres, FiltreListe, FiltreTexte } from '../../composants/Filtres';
import { Bouton, Champ, Liste, Saisie } from '../../composants/Champs';
import { Chargement } from '../../composants/Chargement';
import { Echec, Succes, Vide } from '../../composants/Etats';
import { dateHeure } from '../../outils/format';

/**
 * LES COMPTES D'ADMINISTRATION — chef d'antenne, contrôleur, observateur,
 * administrateurs. Réservé au super administrateur.
 *
 * LE MOT DE PASSE PROVISOIRE S'AFFICHE UNE FOIS, ici, juste après la création
 * ou la réinitialisation. Il n'est conservé nulle part en clair : l'écran le
 * dit, et ne le remontre pas après qu'on l'a fermé.
 *
 * Un compte ne se supprime pas : il se FERME, avec un motif, et se rouvre.
 * Son numéro de téléphone — l'identifiant de connexion — ne change pas.
 */
export function ComptesAdministration() {
    const auth = useAuth();
    const moi = auth.utilisateur?.id;
    const liste = useListe('comptes-administration', '/administration/comptes');
    const [formulaire, setFormulaire] = useState(null);
    const [motDePasse, setMotDePasse] = useState(null);
    const [acte, setActe] = useState(null);
    const [message, setMessage] = useState(null);

    const regions = useQuery({
        queryKey: ['referentiel-regions'],
        queryFn: () => api.lire('/referentiel/regions'),
    });

    const comptes = liste.data?.comptes;
    const roles = liste.data?.roles ?? [];

    function montrerMotDePasse(resultat, titulaire) {
        setMotDePasse({ valeur: resultat.donnees.mot_de_passe_provisoire, titulaire, telephone: resultat.donnees.compte.telephone });
    }

    return (
        <>
            <EnTetePage
                titre="Comptes d’administration"
                sousTitre="Les comptes des volontaires naissent de l’import ; ceux-ci se créent un par un. Un compte ne se supprime pas : il se ferme."
                actions={
                    <Bouton onClick={() => { setMessage(null); setMotDePasse(null); setFormulaire({}); }}>
                        Nouveau compte
                    </Bouton>
                }
            />

            {motDePasse && (
                <div className="rounded-lg border-2 border-ocre-400 bg-ocre-50 px-4 py-4" role="alert">
                    <p className="font-semibold text-ocre-900">
                        Mot de passe provisoire de {motDePasse.titulaire} — notez-le maintenant, il ne sera plus affiché.
                    </p>
                    <p className="mt-2 text-sm text-ocre-900">
                        Identifiant : <span className="font-mono">{motDePasse.telephone}</span>
                    </p>
                    <p className="mt-1 select-all font-mono text-2xl tracking-wider text-ardoise-900">{motDePasse.valeur}</p>
                    <p className="mt-2 text-sm text-ocre-900">
                        Remettez-le à son titulaire de la main à la main : il devra le changer à sa première connexion.
                    </p>
                    <Bouton className="mt-3" variante="secondaire" onClick={() => setMotDePasse(null)}>
                        J’ai noté le mot de passe
                    </Bouton>
                </div>
            )}

            {message && <Succes message={message} onFermer={() => setMessage(null)} />}

            {formulaire && (
                <FormulaireCompte
                    key={formulaire.id ?? 'nouveau'}
                    compte={formulaire.id ? formulaire : null}
                    estMoi={formulaire.id === moi}
                    roles={roles}
                    regions={regions.data ?? []}
                    onAnnuler={() => setFormulaire(null)}
                    onEnregistre={(resultat, valeurs) => {
                        setFormulaire(null);
                        setMessage(resultat.message);

                        if (resultat.donnees?.mot_de_passe_provisoire) {
                            montrerMotDePasse(resultat, `${valeurs.prenoms} ${valeurs.nom}`);
                        }
                    }}
                />
            )}

            {acte && (
                <ActeSurCompte
                    key={`${acte.type}-${acte.compte.id}`}
                    acte={acte}
                    onAnnuler={() => setActe(null)}
                    onFait={(resultat) => {
                        setActe(null);
                        setMessage(resultat.message);

                        if (resultat.donnees?.mot_de_passe_provisoire) {
                            montrerMotDePasse(resultat, `${acte.compte.prenoms} ${acte.compte.nom}`);
                        }
                    }}
                />
            )}

            <BarreFiltres onReinitialiser={liste.reinitialiser}>
                <FiltreTexte
                    libelle="Recherche"
                    valeur={liste.filtres.recherche}
                    onChange={(v) => liste.changerFiltre('recherche', v)}
                    placeholder="Nom, téléphone ou courriel"
                />
                <FiltreListe
                    libelle="Rôle"
                    valeur={liste.filtres.role}
                    onChange={(v) => liste.changerFiltre('role', v)}
                    options={roles.map((r) => ({ valeur: r.valeur, libelle: r.libelle }))}
                />
                <FiltreListe
                    libelle="État"
                    valeur={liste.filtres.statut_compte}
                    onChange={(v) => liste.changerFiltre('statut_compte', v)}
                    options={[
                        { valeur: 'actif', libelle: 'Ouvert' },
                        { valeur: 'ferme', libelle: 'Fermé' },
                    ]}
                />
            </BarreFiltres>

            {liste.isPending && <Chargement message="Chargement des comptes…" />}
            {liste.error && <Echec erreur={liste.error} onReessayer={liste.refetch} />}

            {comptes && (
                <>
                    <Tableau
                        cle={(c) => c.id}
                        lignes={comptes.data}
                        vide={<Vide titre="Aucun compte ne correspond" />}
                        colonnes={[
                            {
                                cle: 'nom',
                                titre: 'Nom et prénoms',
                                rendu: (c) => (
                                    <>
                                        {c.prenoms} {c.nom}
                                        {c.id === moi && <span className="ml-2 text-xs text-ardoise-500">(vous)</span>}
                                    </>
                                ),
                            },
                            { cle: 'telephone', titre: 'Téléphone', compact: true, rendu: (c) => <span className="font-mono">{c.telephone}</span> },
                            { cle: 'email', titre: 'Courriel', rendu: (c) => c.email ?? '—' },
                            { cle: 'role', titre: 'Rôle', rendu: (c) => c.role_libelle ?? '—' },
                            { cle: 'region', titre: 'Région', compact: true, rendu: (c) => c.region?.nom ?? 'Nationale' },
                            {
                                cle: 'etat',
                                titre: 'État',
                                compact: true,
                                rendu: (c) => (c.statut_compte === 'ferme'
                                    ? <Pastille ton="alerte">fermé</Pastille>
                                    : <Pastille ton="bon">ouvert</Pastille>),
                            },
                            {
                                cle: 'connexion',
                                titre: 'Dernière connexion',
                                compact: true,
                                rendu: (c) => (c.derniere_connexion_le
                                    ? dateHeure(c.derniere_connexion_le)
                                    : <span className="text-ardoise-500">{c.doit_changer_mot_de_passe ? 'jamais — mot de passe provisoire' : 'jamais'}</span>),
                            },
                            {
                                cle: 'actions',
                                titre: '',
                                compact: true,
                                rendu: (c) => (
                                    <div className="flex flex-wrap gap-2">
                                        <Bouton variante="secondaire" onClick={() => { setMessage(null); setFormulaire(c); }}>Modifier</Bouton>
                                        {c.id !== moi && (
                                            <>
                                                {c.statut_compte === 'ferme'
                                                    ? <Bouton variante="secondaire" onClick={() => setActe({ type: 'rouvrir', compte: c })}>Rouvrir</Bouton>
                                                    : <Bouton variante="secondaire" onClick={() => setActe({ type: 'fermer', compte: c })}>Fermer</Bouton>}
                                                <Bouton variante="secondaire" onClick={() => setActe({ type: 'mot-de-passe', compte: c })}>
                                                    Nouveau mot de passe
                                                </Bouton>
                                            </>
                                        )}
                                    </div>
                                ),
                            },
                        ]}
                    />
                    <Pagination page={comptes} onPage={liste.setPage} />
                </>
            )}
        </>
    );
}

function FormulaireCompte({ compte, estMoi, roles, regions, onAnnuler, onEnregistre }) {
    const [valeurs, setValeurs] = useState({
        nom: compte?.nom ?? '',
        prenoms: compte?.prenoms ?? '',
        telephone: compte?.telephone ?? '',
        email: compte?.email ?? '',
        role: compte?.role ?? '',
        region_id: compte?.region?.id ? String(compte.region.id) : '',
    });
    const action = useAction(['comptes-administration']);
    const erreurs = action.erreur?.erreurs;
    const regional = roles.find((r) => r.valeur === valeurs.role)?.regional ?? false;

    const changer = (champ) => (e) => setValeurs((v) => ({ ...v, [champ]: e.target.value }));

    async function enregistrer(evenement) {
        evenement.preventDefault();

        const corps = {
            nom: valeurs.nom,
            prenoms: valeurs.prenoms,
            email: valeurs.email || null,
            role: valeurs.role,
            region_id: regional && valeurs.region_id ? Number(valeurs.region_id) : null,
            ...(compte ? {} : { telephone: valeurs.telephone }),
        };

        const resultat = await action.lancer(() => (compte
            ? api.modifier(`/administration/comptes/${compte.id}`, corps)
            : api.creer('/administration/comptes', corps)));

        if (resultat) {
            onEnregistre(resultat, valeurs);
        }
    }

    return (
        <Bloc
            titre={compte ? `Modifier le compte de ${compte.prenoms} ${compte.nom}` : 'Nouveau compte d’administration'}
            precision={compte
                ? 'Changer le rôle ou la région ferme les sessions ouvertes du titulaire.'
                : 'Un mot de passe provisoire sera créé et affiché une seule fois.'}
        >
            <form onSubmit={enregistrer} aria-label="Compte d’administration" className="space-y-4">
                {action.erreur && !action.erreur.estValidation && <Echec erreur={action.erreur} />}
                <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                    <Champ nom="nom" libelle="Nom" erreurs={erreurs}>
                        <Saisie id="compte-nom" value={valeurs.nom} onChange={changer('nom')} maxLength={80} required />
                    </Champ>
                    <Champ nom="prenoms" libelle="Prénoms" erreurs={erreurs}>
                        <Saisie id="compte-prenoms" value={valeurs.prenoms} onChange={changer('prenoms')} maxLength={120} required />
                    </Champ>
                    <Champ
                        nom="telephone"
                        libelle="Téléphone (identifiant de connexion)"
                        aide={compte ? 'Il ne change pas.' : '8 chiffres, par exemple 70 12 34 56.'}
                        erreurs={erreurs}
                    >
                        <Saisie
                            id="compte-telephone"
                            value={valeurs.telephone}
                            onChange={changer('telephone')}
                            disabled={Boolean(compte)}
                            inputMode="tel"
                            required={!compte}
                        />
                    </Champ>
                    <Champ nom="email" libelle="Courriel (facultatif)" erreurs={erreurs}>
                        <Saisie id="compte-email" type="email" value={valeurs.email} onChange={changer('email')} maxLength={150} />
                    </Champ>
                    <Champ nom="role" libelle="Rôle" aide={estMoi ? 'Votre propre rôle ne se change pas ici.' : undefined} erreurs={erreurs}>
                        <Liste id="compte-role" value={valeurs.role} onChange={changer('role')} disabled={estMoi} required>
                            <option value="">Choisir…</option>
                            {roles.map((r) => <option key={r.valeur} value={r.valeur}>{r.libelle}</option>)}
                        </Liste>
                    </Champ>
                    {regional && (
                        <Champ nom="region_id" libelle="Région" erreurs={erreurs}>
                            <Liste id="compte-region" value={valeurs.region_id} onChange={changer('region_id')} required>
                                <option value="">Choisir…</option>
                                {regions.map((r) => <option key={r.id} value={r.id}>{r.nom}</option>)}
                            </Liste>
                        </Champ>
                    )}
                </div>
                <div className="flex gap-2">
                    <Bouton type="submit" disabled={action.enCours}>
                        {action.enCours ? 'Enregistrement…' : compte ? 'Enregistrer' : 'Créer le compte'}
                    </Bouton>
                    <Bouton type="button" variante="secondaire" onClick={onAnnuler}>Annuler</Bouton>
                </div>
            </form>
        </Bloc>
    );
}

/** Fermer, rouvrir, ou recréer un mot de passe : chaque acte dit ce qu'il fait avant qu'on le confirme. */
function ActeSurCompte({ acte, onAnnuler, onFait }) {
    const [motif, setMotif] = useState('');
    const action = useAction(['comptes-administration']);
    const { compte, type } = acte;
    const nom = `${compte.prenoms} ${compte.nom}`;

    const textes = {
        fermer: {
            titre: `Fermer le compte de ${nom}`,
            precision: 'Il ne pourra plus se connecter, et ses sessions ouvertes seront coupées.',
            bouton: 'Fermer le compte',
        },
        rouvrir: {
            titre: `Rouvrir le compte de ${nom}`,
            precision: 'Il pourra de nouveau se connecter avec son mot de passe actuel.',
            bouton: 'Rouvrir le compte',
        },
        'mot-de-passe': {
            titre: `Nouveau mot de passe pour ${nom}`,
            precision: 'L’ancien cessera de fonctionner et ses sessions seront coupées. Le nouveau s’affichera une seule fois.',
            bouton: 'Créer un nouveau mot de passe',
        },
    }[type];

    const avecMotif = type !== 'mot-de-passe';

    async function confirmer(evenement) {
        evenement.preventDefault();

        const resultat = await action.lancer(() => api.agir(
            `/administration/comptes/${compte.id}/${type}`,
            avecMotif ? { motif } : undefined,
        ));

        if (resultat) {
            onFait(resultat);
        }
    }

    return (
        <Bloc titre={textes.titre} precision={textes.precision}>
            <form onSubmit={confirmer} aria-label={textes.titre} className="space-y-3">
                {action.erreur && !action.erreur.estValidation && <Echec erreur={action.erreur} />}
                {avecMotif && (
                    <Champ nom="motif" libelle="Motif (inscrit au journal)" erreurs={action.erreur?.erreurs}>
                        <Saisie id="acte-motif" value={motif} onChange={(e) => setMotif(e.target.value)} maxLength={500} required />
                    </Champ>
                )}
                <div className="flex gap-2">
                    <Bouton
                        type="submit"
                        variante={type === 'fermer' ? 'danger' : 'principal'}
                        disabled={action.enCours || (avecMotif && motif.trim().length < 5)}
                    >
                        {textes.bouton}
                    </Bouton>
                    <Bouton type="button" variante="secondaire" onClick={onAnnuler}>Annuler</Bouton>
                </div>
            </form>
        </Bloc>
    );
}
