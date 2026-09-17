import { useAuth } from '../auth/ContexteAuth';
import { api } from '../api/client';
import { useAction, useListe, useTelechargement } from '../outils/crochets';
import { EnTetePage } from '../composants/Page';
import { Pagination, Pastille, Tableau } from '../composants/Tableau';
import { Bouton, Champ, Liste, Saisie } from '../composants/Champs';
import { Chargement } from '../composants/Chargement';
import { Echec, Succes, Vide } from '../composants/Etats';
import { date, dateHeure } from '../outils/format';
import { statutExport, tailleLisible, typeExport, typesExport } from '../domaine/exports';

/**
 * LES EXPORTS DÉPOSÉS.
 *
 * Les fichiers sont produits chaque nuit sur la journée écoulée, un par
 * périmètre. Un chef d'antenne ne voit que ceux de sa région : ce n'est pas un
 * filtre d'affichage, c'est un fichier différent.
 *
 * Rien n'est envoyé par courriel : le fichier reste ici, et c'est un geste
 * volontaire qui le fait sortir de la plateforme.
 */
export function Exports() {
    const auth = useAuth();
    const liste = useListe('exports', '/exports');
    const fichier = useTelechargement();
    const production = useAction(['exports']);

    return (
        <>
            <EnTetePage
                titre="Exports"
                sousTitre="Produits chaque nuit sur la journée écoulée. Le fichier reste sur la plateforme jusqu’à ce que vous le téléchargiez."
                actions={
                    auth.peut('exports.generer') && (
                        <Bouton
                            variante="secondaire"
                            disabled={production.enCours}
                            onClick={() => production.lancer(() => api.agir('/exports/produire', {}))}
                        >
                            {production.enCours ? 'Production…' : 'Reproduire la journée d’hier'}
                        </Bouton>
                    )
                }
            />

            {production.message && (
                <Succes message={production.message} onFermer={production.oublierMessage} />
            )}
            {production.erreur && <Echec erreur={production.erreur} />}
            {fichier.erreur && <Echec erreur={fichier.erreur} />}

            <div className="flex flex-wrap gap-3">
                <Champ nom="type" libelle="Contenu">
                    <Liste
                        value={liste.filtres.type ?? ''}
                        onChange={(e) => liste.changerFiltre('type', e.target.value)}
                    >
                        <option value="">Tous les contenus</option>
                        {Object.entries(typesExport).map(([valeur, libelle]) => (
                            <option key={valeur} value={valeur}>
                                {libelle}
                            </option>
                        ))}
                    </Liste>
                </Champ>
                <Champ nom="date" libelle="Journée">
                    <Saisie
                        type="date"
                        value={liste.filtres.date ?? ''}
                        onChange={(e) => liste.changerFiltre('date', e.target.value)}
                    />
                </Champ>
            </div>

            {liste.isPending && <Chargement message="Recherche des exports…" />}
            {liste.error && <Echec erreur={liste.error} onReessayer={liste.refetch} />}

            {!liste.isPending && !liste.error && (
                <>
                    <Tableau
                        cle={(e) => e.id}
                        lignes={liste.lignes}
                        vide={
                            <Vide
                                titre="Aucun export pour le moment"
                                explication="Le premier fichier sera produit cette nuit, sur la journée écoulée."
                            />
                        }
                        colonnes={[
                            {
                                cle: 'date_debut',
                                titre: 'Journée',
                                compact: true,
                                rendu: (e) => date(e.date_debut),
                            },
                            { cle: 'type', titre: 'Contenu', rendu: (e) => typeExport(e.type) },
                            {
                                cle: 'portee',
                                titre: 'Périmètre',
                                rendu: (e) => (e.portee === 'national' ? 'National' : (e.region?.nom ?? '—')),
                            },
                            {
                                cle: 'nb_lignes',
                                titre: 'Lignes',
                                alignement: 'droite',
                                rendu: (e) => e.nb_lignes,
                            },
                            {
                                cle: 'taille_octets',
                                titre: 'Taille',
                                alignement: 'droite',
                                rendu: (e) => tailleLisible(e.taille_octets),
                            },
                            {
                                cle: 'statut',
                                titre: 'État',
                                compact: true,
                                rendu: (e) => {
                                    const statut = statutExport(e.statut);

                                    return <Pastille ton={statut.ton}>{statut.libelle}</Pastille>;
                                },
                            },
                            {
                                cle: 'genere_le',
                                titre: 'Produit le',
                                compact: true,
                                rendu: (e) => dateHeure(e.genere_le),
                            },
                            {
                                cle: 'telecharger',
                                titre: '',
                                compact: true,
                                rendu: (e) =>
                                    e.statut === 'pret' ? (
                                        <button
                                            type="button"
                                            disabled={fichier.enCours}
                                            onClick={() =>
                                                fichier.telecharger(
                                                    `/exports/${e.id}/telecharger`,
                                                    e.nom_fichier,
                                                )
                                            }
                                            className="rounded border border-ardoise-300 bg-white px-3 py-1.5 text-sm hover:bg-ardoise-50"
                                        >
                                            Télécharger
                                        </button>
                                    ) : (
                                        <span className="text-xs text-ardoise-500">Rien à télécharger</span>
                                    ),
                            },
                        ]}
                    />
                    <Pagination page={liste.pagination} onPage={liste.setPage} />
                </>
            )}
        </>
    );
}
