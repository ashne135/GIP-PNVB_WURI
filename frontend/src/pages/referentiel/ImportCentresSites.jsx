import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { api, avecParametres } from '../../api/client';
import { useAction, useTelechargement } from '../../outils/crochets';
import { Bloc } from '../../composants/Fiche';
import { Pagination, Pastille, Tableau } from '../../composants/Tableau';
import { Bouton } from '../../composants/Champs';
import { Chargement } from '../../composants/Chargement';
import { Echec, Succes, Vide } from '../../composants/Etats';

/**
 * IMPORTER CENTRES ET SITES — en deux temps.
 *
 *   1. ANALYSER : le fichier est lu, rien n'est écrit. Le serveur dit ce qui
 *      passerait et ce qui est en erreur, ligne par ligne.
 *   2. CONFIRMER, ou ANNULER.
 *
 * Le mode « remplacer » supprime le jeu de démonstration. Il est signalé en
 * rouge, et le serveur refuse la purge si des données réelles en dépendent.
 */
export function ImportCentresSites() {
    const [fichier, setFichier] = useState(null);
    const [mode, setMode] = useState('completer');
    const [importEnCours, setImportEnCours] = useState(null);
    const [termine, setTermine] = useState(false);
    const [erreursSeulement, setErreursSeulement] = useState(false);
    const [page, setPage] = useState(1);

    const analyse = useAction(['imports-centres']);
    const decision = useAction(['imports-centres', 'centres', 'sites']);
    const modele = useTelechargement();
    const compteRendu = useTelechargement();

    const apercu = useQuery({
        queryKey: ['import-centres-apercu', importEnCours?.id, erreursSeulement, page],
        queryFn: () => api.lire(avecParametres(`/imports/centres-sites/${importEnCours.id}/apercu`, {
            erreurs_seulement: erreursSeulement ? 1 : undefined,
            page,
        })),
        enabled: Boolean(importEnCours?.id),
        placeholderData: (precedent) => precedent,
    });

    async function analyser(evenement) {
        evenement.preventDefault();
        setTermine(false);
        decision.oublierMessage();

        const formulaire = new FormData();
        formulaire.append('fichier', fichier);
        formulaire.append('mode', mode);

        // Un fichier illisible répond 422 avec l'import en échec : on garde
        // son identifiant pour pouvoir télécharger le compte rendu. L'erreur est
        // captée ici — l'état `analyse.erreur` lu juste après l'appel serait
        // encore celui du rendu précédent.
        let refus = null;
        const resultat = await analyse.lancer(() =>
            api.creer('/imports/centres-sites', formulaire).catch((echec) => {
                refus = echec;
                throw echec;
            }),
        );

        setImportEnCours(resultat?.donnees?.import ?? refus?.donnees?.import ?? null);
        setPage(1);
    }

    async function confirmer() {
        if (await decision.lancer(() => api.agir(`/imports/centres-sites/${importEnCours.id}/confirmer`))) {
            setTermine(true);
        }
    }

    async function annuler() {
        if (await decision.lancer(() => api.supprimer(`/imports/centres-sites/${importEnCours.id}`))) {
            setTermine(true);
            setImportEnCours(null);
        }
    }

    return (
        <>
            <Bloc
                titre="Importer un fichier de centres et de sites"
                precision="Une ligne par site ; le centre est créé au passage. Les codes ne sont pas demandés : ils sont attribués."
                actions={
                    <Bouton
                        variante="secondaire"
                        disabled={modele.enCours}
                        onClick={() => modele.telecharger('/imports/centres-sites/modele', 'modele-import-centres-sites.csv')}
                    >
                        Télécharger le modèle
                    </Bouton>
                }
            >
                {modele.erreur && <div className="mb-4"><Echec erreur={modele.erreur} /></div>}

                <form onSubmit={analyser} className="space-y-4">
                    <label className="block">
                        <span className="text-sm font-medium text-ardoise-700">Fichier (.xlsx, .xls ou .csv, 20 Mo au plus)</span>
                        <input
                            type="file"
                            accept=".xlsx,.xls,.csv,.txt"
                            onChange={(e) => setFichier(e.target.files?.[0] ?? null)}
                            className="mt-1.5 block w-full text-sm text-ardoise-800 file:mr-3 file:rounded file:border file:border-ardoise-300 file:bg-white file:px-3 file:py-1.5"
                            required
                        />
                    </label>

                    <fieldset className="space-y-2">
                        <legend className="text-sm font-medium text-ardoise-700">Mode</legend>
                        <label className="flex items-start gap-2 text-sm text-ardoise-800">
                            <input type="radio" name="mode" value="completer" checked={mode === 'completer'} onChange={() => setMode('completer')} className="mt-0.5" />
                            <span><span className="font-medium">Compléter</span> — ajoute les centres et sites du fichier au référentiel existant.</span>
                        </label>
                        <label className="flex items-start gap-2 text-sm text-ardoise-800">
                            <input type="radio" name="mode" value="remplacer" checked={mode === 'remplacer'} onChange={() => setMode('remplacer')} className="mt-0.5" />
                            <span><span className="font-medium">Remplacer le jeu de démonstration</span> — supprime les centres et sites fictifs avant d’importer.</span>
                        </label>
                        {mode === 'remplacer' && (
                            <p className="rounded border border-brique-300 bg-brique-50 px-3 py-2 text-sm text-brique-900" role="alert">
                                Les centres, sites et affectations de démonstration seront supprimés à la confirmation. Le serveur
                                refuse cette purge si des données réelles en dépendent, et l’analyse vous le dira avant.
                            </p>
                        )}
                    </fieldset>

                    <Bouton type="submit" disabled={!fichier || analyse.enCours}>
                        {analyse.enCours ? 'Analyse…' : 'Analyser le fichier'}
                    </Bouton>
                    <p className="text-xs text-ardoise-600">L’analyse n’écrit rien : vous confirmez ensuite, après avoir vu le résultat.</p>
                </form>
            </Bloc>

            {analyse.message && <Succes message={analyse.message} />}
            {analyse.erreur && <Echec erreur={analyse.erreur} />}
            {decision.message && <Succes message={decision.message} onFermer={decision.oublierMessage} />}
            {decision.erreur && <Echec erreur={decision.erreur} />}

            {importEnCours && (
                <Bloc
                    titre="Résultat de l’analyse"
                    precision={`${importEnCours.lignes_valides ?? 0} lignes valides, ${importEnCours.lignes_erreur ?? 0} en erreur`}
                    actions={
                        <>
                            <Bouton
                                variante="secondaire"
                                disabled={compteRendu.enCours}
                                onClick={() => compteRendu.telecharger(`/imports/centres-sites/${importEnCours.id}/compte-rendu`, 'compte-rendu-centres-sites.csv')}
                            >
                                Compte rendu
                            </Bouton>
                            {!termine && (
                                <>
                                    <Bouton variante="secondaire" disabled={decision.enCours} onClick={annuler}>Annuler</Bouton>
                                    <Bouton disabled={decision.enCours || !(importEnCours.lignes_valides > 0)} onClick={confirmer}>
                                        Confirmer l’import
                                    </Bouton>
                                </>
                            )}
                        </>
                    }
                >
                    {compteRendu.erreur && <div className="mb-3"><Echec erreur={compteRendu.erreur} /></div>}

                    <label className="mb-3 flex items-center gap-2 text-sm text-ardoise-800">
                        <input type="checkbox" checked={erreursSeulement} onChange={(e) => { setErreursSeulement(e.target.checked); setPage(1); }} className="h-4 w-4" />
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
                                    { cle: 'motif', titre: 'Motif', rendu: (l) => l.motif_erreur ?? '—' },
                                    { cle: 'region', titre: 'Région', rendu: (l) => l.donnees?.region ?? '—' },
                                    { cle: 'commune', titre: 'Commune', rendu: (l) => l.donnees?.commune ?? '—' },
                                    { cle: 'localite', titre: 'Localité', rendu: (l) => l.donnees?.localite ?? '—' },
                                    { cle: 'centre', titre: 'Centre', rendu: (l) => l.donnees?.nom_centre ?? '—' },
                                    { cle: 'site', titre: 'Site', rendu: (l) => l.donnees?.nom_site ?? '—' },
                                ]}
                            />
                            <Pagination page={apercu.data.lignes} onPage={setPage} />
                        </>
                    )}
                </Bloc>
            )}
        </>
    );
}
