import { useState } from 'react';
import { Link } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';
import { api, avecParametres } from '../../api/client';
import { useAction, useListe, useTelechargement } from '../../outils/crochets';
import { Bloc } from '../../composants/Fiche';
import { Indicateur } from '../../composants/Page';
import { Pagination, Pastille, Tableau } from '../../composants/Tableau';
import { Bouton } from '../../composants/Champs';
import { Chargement } from '../../composants/Chargement';
import { Echec, Succes, Vide } from '../../composants/Etats';
import { dateHeure, nombre, nomDe } from '../../outils/format';
import {
    colonnesCanevas,
    libelleCategorie,
    libelleNiveau,
    libellesColonnesServeur,
    localiteDuFichier,
    statutsImport,
    typesImport,
} from '../../domaine/volontaires';

/**
 * IMPORTER LES RETENUS — en trois temps (cadrage, sections 2 et 6).
 *
 *   1. ANALYSER : le fichier est lu ligne par ligne, RIEN n'est écrit.
 *   2. REGARDER l'aperçu : les lignes en erreur d'abord, avec leur motif.
 *   3. CONFIRMER, ou ANNULER.
 *
 * Les comptes naissent INACTIFS : c'est l'affectation qui ouvrira l'accès,
 * jamais l'import. L'écran le redit après la confirmation, pour qu'on ne
 * cherche pas à se connecter avec un compte tout juste importé.
 */
export function ImportVolontaires() {
    const [fichier, setFichier] = useState(null);
    const [type, setType] = useState('volontaires_retenus');
    const [mode, setMode] = useState('completer');
    const [importEnCours, setImportEnCours] = useState(null);
    const [colonnesManquantes, setColonnesManquantes] = useState([]);
    const [erreursSeulement, setErreursSeulement] = useState(false);
    const [page, setPage] = useState(1);

    const analyse = useAction(['imports-volontaires']);
    const decision = useAction(['imports-volontaires', 'volontaires', 'volontaires-a-qualifier', 'remises']);
    const modele = useTelechargement();
    const compteRendu = useTelechargement();

    const apercu = useQuery({
        queryKey: ['import-volontaires-apercu', importEnCours?.id, erreursSeulement, page],
        queryFn: () => api.lire(avecParametres(`/imports/volontaires/${importEnCours.id}/apercu`, {
            erreurs_seulement: erreursSeulement ? 1 : undefined,
            page,
        })),
        enabled: Boolean(importEnCours?.id),
        placeholderData: (precedent) => precedent,
    });

    const aConfirmer = importEnCours?.statut === 'apercu_pret';

    async function analyser(evenement) {
        evenement.preventDefault();
        decision.oublierMessage();
        decision.oublierErreur();
        setColonnesManquantes([]);

        const formulaire = new FormData();
        formulaire.append('fichier', fichier);
        formulaire.append('type', type);
        formulaire.append('mode', mode);

        // Un fichier illisible répond 422 avec l'import en échec : on en tire
        // les colonnes manquantes, pour les nommer comme dans le fichier.
        let refus = null;
        const resultat = await analyse.lancer(() =>
            api.creer('/imports/volontaires', formulaire).catch((echec) => {
                refus = echec;
                throw echec;
            }),
        );

        setImportEnCours(resultat?.donnees?.import ?? null);
        setColonnesManquantes(refus?.donnees?.import?.resume?.colonnes_manquantes ?? []);
        setErreursSeulement(false);
        setPage(1);
    }

    async function confirmer() {
        const resultat = await decision.lancer(() => api.agir(`/imports/volontaires/${importEnCours.id}/confirmer`));

        if (resultat?.donnees?.import) {
            setImportEnCours(resultat.donnees.import);
        }
    }

    async function annuler() {
        if (await decision.lancer(() => api.supprimer(`/imports/volontaires/${importEnCours.id}`))) {
            setImportEnCours(null);
        }
    }

    function reprendre(ancien) {
        analyse.oublierMessage();
        decision.oublierMessage();
        decision.oublierErreur();
        setColonnesManquantes([]);
        setImportEnCours(ancien);
        setErreursSeulement(false);
        setPage(1);
    }

    return (
        <>
            <Bloc
                titre="Importer la liste des retenus"
                precision="Excel (.xlsx, .xls) ou CSV, 10 Mo au plus. Une ligne par volontaire, l’en-tête peut être précédé d’un titre."
                actions={
                    <Bouton
                        variante="secondaire"
                        disabled={modele.enCours}
                        onClick={() => modele.telecharger('/imports/volontaires/modele', 'modele-import-volontaires.csv')}
                    >
                        Télécharger le modèle
                    </Bouton>
                }
            >
                {modele.erreur && <div className="mb-4"><Echec erreur={modele.erreur} /></div>}

                <form onSubmit={analyser} className="space-y-4" aria-label="Importer un fichier de volontaires">
                    <label className="block">
                        <span className="text-sm font-medium text-ardoise-700">Fichier</span>
                        <input
                            id="fichier-volontaires"
                            type="file"
                            accept=".xlsx,.xls,.csv,.txt"
                            onChange={(e) => setFichier(e.target.files?.[0] ?? null)}
                            className="mt-1.5 block w-full text-sm text-ardoise-800 file:mr-3 file:rounded file:border file:border-ardoise-300 file:bg-white file:px-3 file:py-1.5"
                            required
                        />
                    </label>

                    <fieldset className="space-y-2">
                        <legend className="text-sm font-medium text-ardoise-700">Liste</legend>
                        <label className="flex items-start gap-2 text-sm text-ardoise-800">
                            <input type="radio" name="type" value="volontaires_retenus" checked={type === 'volontaires_retenus'} onChange={() => setType('volontaires_retenus')} className="mt-0.5" />
                            <span><span className="font-medium">Retenus</span> — les volontaires à déployer, en statut opérationnel.</span>
                        </label>
                        <label className="flex items-start gap-2 text-sm text-ardoise-800">
                            <input type="radio" name="type" value="volontaires_reserve" checked={type === 'volontaires_reserve'} onChange={() => setType('volontaires_reserve')} className="mt-0.5" />
                            <span><span className="font-medium">Liste d’attente</span> — placés en réserve, mobilisés seulement pour un remplacement.</span>
                        </label>
                    </fieldset>

                    <fieldset className="space-y-2">
                        <legend className="text-sm font-medium text-ardoise-700">Mode</legend>
                        <label className="flex items-start gap-2 text-sm text-ardoise-800">
                            <input type="radio" name="mode" value="completer" checked={mode === 'completer'} onChange={() => setMode('completer')} className="mt-0.5" />
                            <span><span className="font-medium">Compléter</span> — ajoute les volontaires du fichier au registre existant.</span>
                        </label>
                        <label className="flex items-start gap-2 text-sm text-ardoise-800">
                            <input type="radio" name="mode" value="remplacer" checked={mode === 'remplacer'} onChange={() => setMode('remplacer')} className="mt-0.5" />
                            <span><span className="font-medium">Remplacer le jeu de démonstration</span> — supprime les volontaires fictifs avant d’importer.</span>
                        </label>
                        {mode === 'remplacer' && (
                            <p className="rounded border border-brique-300 bg-brique-50 px-3 py-2 text-sm text-brique-900" role="alert">
                                Les volontaires de démonstration et leurs affectations seront supprimés à la confirmation. Le serveur
                                refuse cette purge si des feuilles de présence ou des rapports réels en dépendent.
                            </p>
                        )}
                    </fieldset>

                    <Bouton type="submit" disabled={!fichier || analyse.enCours}>
                        {analyse.enCours ? 'Analyse…' : 'Analyser le fichier'}
                    </Bouton>
                    <p className="text-xs text-ardoise-600">L’analyse n’écrit rien : vous confirmez ensuite, après avoir vu le résultat.</p>
                </form>

                <details className="mt-5 border-t border-ardoise-200 pt-4">
                    <summary className="cursor-pointer text-sm font-medium text-pnvb-800">Le canevas à respecter</summary>
                    <p className="mt-3 max-w-prose text-sm text-ardoise-600">
                        Les intitulés sont reconnus sans tenir compte des majuscules, des accents ni de la ponctuation.
                        Seuls le numéro, le nom et les prénoms sont exigés sur chaque ligne.
                    </p>
                    <div className="mt-3 overflow-x-auto">
                        <table className="min-w-full text-sm">
                            <thead>
                                <tr className="text-left text-xs uppercase tracking-wide text-ardoise-500">
                                    <th scope="col" className="py-1.5 pr-4">Colonne</th>
                                    <th scope="col" className="py-1.5 pr-4">Exigée</th>
                                    <th scope="col" className="py-1.5">Valeurs acceptées</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-ardoise-100">
                                {colonnesCanevas.map((colonne) => (
                                    <tr key={colonne.intitule}>
                                        <td className="whitespace-nowrap py-1.5 pr-4 font-mono text-ardoise-900">{colonne.intitule}</td>
                                        <td className="whitespace-nowrap py-1.5 pr-4">
                                            {colonne.exigence === 'obligatoire' && <Pastille ton="alerte">oui</Pastille>}
                                            {colonne.exigence === 'A-OPK' && <Pastille ton="attention">pour un A-OPK</Pastille>}
                                            {colonne.exigence === 'pour le profil' && <Pastille ton="attention">pour le profil</Pastille>}
                                            {colonne.exigence === 'facultative' && <span className="text-ardoise-500">non</span>}
                                        </td>
                                        <td className="py-1.5 text-ardoise-700">{colonne.valeurs}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </details>
            </Bloc>

            {analyse.message && <Succes message={analyse.message} />}
            {analyse.erreur && <Echec erreur={analyse.erreur} />}
            {colonnesManquantes.length > 0 && (
                <p className="text-sm text-ardoise-700">
                    Colonnes introuvables dans le fichier :{' '}
                    <span className="font-mono">{colonnesManquantes.map((c) => libellesColonnesServeur[c] ?? c).join(', ')}</span>.
                </p>
            )}
            {decision.message && <Succes message={decision.message} onFermer={decision.oublierMessage} />}
            {decision.erreur && <Echec erreur={decision.erreur} />}

            {importEnCours && (
                <ResultatImport
                    importEnCours={importEnCours}
                    aConfirmer={aConfirmer}
                    decision={decision}
                    onConfirmer={confirmer}
                    onAnnuler={annuler}
                    compteRendu={compteRendu}
                    apercu={apercu}
                    erreursSeulement={erreursSeulement}
                    onErreursSeulement={(valeur) => { setErreursSeulement(valeur); setPage(1); }}
                    onPage={setPage}
                />
            )}

            <HistoriqueImports onOuvrir={reprendre} />
        </>
    );
}

function ResultatImport({
    importEnCours, aConfirmer, decision, onConfirmer, onAnnuler, compteRendu,
    apercu, erreursSeulement, onErreursSeulement, onPage,
}) {
    const resume = importEnCours.resume ?? {};
    const statut = statutsImport[importEnCours.statut];
    const motifs = Object.entries(resume.motifs_frequents ?? {});
    const obstacles = resume.obstacles_purge ?? [];

    return (
        <Bloc
            titre={`Fichier « ${importEnCours.fichier_nom} »`}
            precision={`${typesImport[importEnCours.type_referentiel] ?? ''} · ${statut?.libelle ?? importEnCours.statut}`}
            actions={
                <>
                    <Bouton
                        variante="secondaire"
                        disabled={compteRendu.enCours}
                        onClick={() => compteRendu.telecharger(`/imports/volontaires/${importEnCours.id}/compte-rendu`, 'compte-rendu-import.csv')}
                    >
                        Compte rendu
                    </Bouton>
                    {aConfirmer && (
                        <>
                            <Bouton variante="secondaire" disabled={decision.enCours} onClick={onAnnuler}>Annuler</Bouton>
                            <Bouton disabled={decision.enCours || !(importEnCours.lignes_valides > 0) || obstacles.length > 0} onClick={onConfirmer}>
                                {decision.enCours ? 'Création des comptes…' : `Confirmer : créer ${nombre(importEnCours.lignes_valides ?? 0)} comptes`}
                            </Bouton>
                        </>
                    )}
                </>
            }
        >
            {compteRendu.erreur && <div className="mb-3"><Echec erreur={compteRendu.erreur} /></div>}

            <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <Indicateur libelle="Lignes valides" valeur={nombre(importEnCours.lignes_valides ?? 0)} ton="bon" precision={`sur ${nombre(importEnCours.lignes_total ?? 0)} lues`} />
                <Indicateur
                    libelle="Lignes en erreur"
                    valeur={nombre(importEnCours.lignes_erreur ?? 0)}
                    ton={importEnCours.lignes_erreur > 0 ? 'alerte' : 'neutre'}
                    precision="ignorées à la confirmation"
                />
                <Indicateur libelle="Sans courriel" valeur={nombre(resume.sans_courriel ?? 0)} precision="identifiants par SMS ou bordereau" />
                <Indicateur
                    libelle="Sans profil"
                    valeur={nombre(resume.a_qualifier ?? 0)}
                    ton={resume.a_qualifier > 0 ? 'attention' : 'neutre'}
                    precision={resume.profils_ecartes > 0
                        ? `dont ${nombre(resume.profils_ecartes)} écartés faute du niveau exigé`
                        : 'à qualifier avant toute vague'}
                />
            </div>

            {importEnCours.statut === 'applique' && (
                <p className="mt-4 rounded border border-pnvb-200 bg-pnvb-50 px-3 py-2 text-sm text-pnvb-900">
                    {nombre(resume.comptes_crees ?? 0)} comptes créés, <span className="font-medium">inactifs</span> : personne ne peut encore se
                    connecter. L’accès s’ouvre à l’affectation, quand une vague est tirée.{' '}
                    {resume.a_qualifier > 0 && (
                        <Link to="/volontaires/a-qualifier" className="font-medium underline">
                            Attribuer les {nombre(resume.a_qualifier)} profils manquants
                        </Link>
                    )}
                </p>
            )}

            {obstacles.length > 0 && (
                <div className="mt-4 rounded border border-brique-300 bg-brique-50 px-3 py-2 text-sm text-brique-900" role="alert">
                    <p className="font-medium">La purge du jeu de démonstration est impossible :</p>
                    <ul className="mt-1 list-disc pl-5">
                        {obstacles.map((obstacle) => <li key={obstacle}>{obstacle}</li>)}
                    </ul>
                </div>
            )}

            {resume.purge_prevue > 0 && obstacles.length === 0 && aConfirmer && (
                <p className="mt-4 text-sm text-brique-800">
                    La confirmation supprimera {nombre(resume.purge_prevue)} volontaires de démonstration.
                </p>
            )}

            {motifs.length > 0 && (
                <div className="mt-4">
                    <p className="text-sm font-medium text-ardoise-800">Erreurs les plus fréquentes</p>
                    <ul className="mt-1 space-y-1 text-sm text-ardoise-700">
                        {motifs.map(([motif, fois]) => (
                            <li key={motif}><span className="tabular-nums font-medium">{fois} ×</span> {motif}</li>
                        ))}
                    </ul>
                </div>
            )}

            <label className="mb-3 mt-5 flex items-center gap-2 text-sm text-ardoise-800">
                <input type="checkbox" checked={erreursSeulement} onChange={(e) => onErreursSeulement(e.target.checked)} className="h-4 w-4" />
                Afficher seulement les lignes en erreur
            </label>

            {apercu.isPending && <Chargement message="Chargement des lignes…" />}
            {apercu.error && <Echec erreur={apercu.error} onReessayer={apercu.refetch} />}

            {apercu.data && (
                <>
                    <Tableau
                        cle={(l) => l.id ?? l.numero_ligne}
                        lignes={apercu.data.lignes?.data ?? []}
                        vide={<Vide titre="Aucune ligne à afficher" />}
                        colonnes={[
                            { cle: 'numero_ligne', titre: 'Ligne', alignement: 'droite' },
                            {
                                cle: 'valide',
                                titre: 'État',
                                compact: true,
                                rendu: (l) => (l.valide ? <Pastille ton="bon">valide</Pastille> : <Pastille ton="alerte">en erreur</Pastille>),
                            },
                            {
                                cle: 'motif',
                                titre: 'Motif',
                                // Une ligne valide peut porter un avertissement : son profil
                                // n'a pas été appliqué faute du niveau d'étude exigé.
                                rendu: (l) => l.motif_erreur
                                    ?? (l.donnees?.profil_ecarte
                                        ? <span className="text-ocre-800">{l.donnees.profil_ecarte}</span>
                                        : '—'),
                            },
                            { cle: 'nom', titre: 'Nom et prénoms', rendu: (l) => nomDe(l.donnees) },
                            { cle: 'telephone', titre: 'Téléphone', compact: true, rendu: (l) => l.donnees?.telephone || '—' },
                            { cle: 'email', titre: 'Courriel', rendu: (l) => l.donnees?.email || '—' },
                            {
                                cle: 'profil',
                                titre: 'Profil',
                                compact: true,
                                rendu: (l) => libelleCategorie(l.donnees?.categorie)
                                    ?? (l.valide ? <Pastille ton="attention">à qualifier</Pastille> : '—'),
                            },
                            {
                                cle: 'niveau',
                                titre: 'Niveau',
                                compact: true,
                                rendu: (l) => libelleNiveau(l.donnees?.niveau_etude) ?? '—',
                            },
                            { cle: 'localite', titre: 'Localité', rendu: (l) => localiteDuFichier(l.donnees) ?? '—' },
                        ]}
                    />
                    <Pagination page={apercu.data.lignes} onPage={onPage} />
                </>
            )}
        </Bloc>
    );
}

/** Les imports passés : on y reprend un aperçu laissé en attente. */
function HistoriqueImports({ onOuvrir }) {
    const liste = useListe('imports-volontaires', '/imports/volontaires');

    return (
        <Bloc titre="Imports précédents" precision="Cliquez sur une ligne pour revoir son aperçu ou son compte rendu.">
            {liste.isPending && <Chargement message="Chargement de l’historique…" />}
            {liste.error && <Echec erreur={liste.error} onReessayer={liste.refetch} />}
            {!liste.isPending && !liste.error && (
                <>
                    <Tableau
                        cle={(i) => i.id}
                        lignes={liste.lignes}
                        onLigne={(i) => (i.statut === 'apercu_pret' || i.statut === 'applique') && onOuvrir(i)}
                        vide={<Vide titre="Aucun import pour l’instant" explication="Le premier fichier analysé apparaîtra ici." />}
                        colonnes={[
                            { cle: 'date', titre: 'Date', compact: true, rendu: (i) => dateHeure(i.created_at) },
                            { cle: 'fichier', titre: 'Fichier', rendu: (i) => i.fichier_nom },
                            { cle: 'type', titre: 'Liste', compact: true, rendu: (i) => typesImport[i.type_referentiel] ?? '—' },
                            {
                                cle: 'statut',
                                titre: 'État',
                                compact: true,
                                rendu: (i) => <Pastille ton={statutsImport[i.statut]?.ton ?? 'neutre'}>{statutsImport[i.statut]?.libelle ?? i.statut}</Pastille>,
                            },
                            {
                                cle: 'lignes',
                                titre: 'Valides / erreurs',
                                alignement: 'droite',
                                rendu: (i) => `${nombre(i.lignes_valides ?? 0)} / ${nombre(i.lignes_erreur ?? 0)}`,
                            },
                            { cle: 'par', titre: 'Par', rendu: (i) => nomDe(i.televerse_par) },
                        ]}
                    />
                    <Pagination page={liste.pagination} onPage={liste.setPage} />
                </>
            )}
        </Bloc>
    );
}
