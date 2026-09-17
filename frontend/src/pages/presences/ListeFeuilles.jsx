import { Link } from 'react-router-dom';
import { useAuth } from '../../auth/ContexteAuth';
import { avecParametres } from '../../api/client';
import { useListe, useTelechargement } from '../../outils/crochets';
import { Pagination, Pastille, Tableau } from '../../composants/Tableau';
import { BarreFiltres, FiltreDate, FiltreListe } from '../../composants/Filtres';
import { Chargement } from '../../composants/Chargement';
import { Echec, Vide } from '../../composants/Etats';
import { date, dateHeure, nombre, nomDe } from '../../outils/format';

/**
 * LES FEUILLES DE PRÉSENCE — une par site et par jour.
 *
 * Les exports sont des PIÈCES JUSTIFICATIVES : le PDF porte l'en-tête du
 * projet, le signataire et l'heure de génération, et chaque génération est
 * journalisée côté serveur.
 */
export function ListeFeuilles() {
    const auth = useAuth();
    const liste = useListe('feuilles', '/feuilles');
    const export_ = useTelechargement();

    const tons = { brouillon: 'attention', validee: 'bon', corrigee: 'info' };
    const libelles = { brouillon: 'Brouillon', validee: 'Validée', corrigee: 'Corrigée après validation' };

    return (
        <div className="space-y-4 pt-4">
            {auth.peut('presence.exporter') && (
                <div className="flex flex-wrap justify-end gap-2">
                    <button
                        type="button"
                        disabled={export_.enCours}
                        onClick={() => export_.telecharger(avecParametres('/feuilles/export/pdf', liste.filtres), 'liste-des-presents.pdf')}
                        className="rounded border border-ardoise-300 bg-white px-3 py-2 text-sm hover:bg-ardoise-50"
                    >
                        Exporter en PDF
                    </button>
                    <button
                        type="button"
                        disabled={export_.enCours}
                        onClick={() => export_.telecharger(avecParametres('/feuilles/export/csv', liste.filtres), 'presences.csv')}
                        className="rounded border border-ardoise-300 bg-white px-3 py-2 text-sm hover:bg-ardoise-50"
                    >
                        Exporter (tableur)
                    </button>
                </div>
            )}
            {export_.erreur && <Echec erreur={export_.erreur} />}

            <BarreFiltres onReinitialiser={liste.reinitialiser}>
                <FiltreDate libelle="Journée" valeur={liste.filtres.date} onChange={(v) => liste.changerFiltre('date', v)} />
                <FiltreListe
                    libelle="Statut"
                    valeur={liste.filtres.statut}
                    onChange={(v) => liste.changerFiltre('statut', v)}
                    options={Object.entries(libelles).map(([valeur, libelle]) => ({ valeur, libelle }))}
                />
            </BarreFiltres>

            {liste.isPending && <Chargement message="Chargement des feuilles…" />}
            {liste.error && <Echec erreur={liste.error} onReessayer={liste.refetch} />}

            {!liste.isPending && !liste.error && (
                <>
                    <Tableau
                        cle={(f) => f.id}
                        lignes={liste.lignes}
                        vide={<Vide titre="Aucune feuille ne correspond" explication="Aucune feuille de présence de votre périmètre ne répond à ces filtres." />}
                        colonnes={[
                            {
                                cle: 'date',
                                titre: 'Journée',
                                compact: true,
                                rendu: (f) => (
                                    <Link to={`/presences/feuilles/${f.id}`} className="font-medium text-pnvb-800 underline">
                                        {date(f.date_presence)}
                                    </Link>
                                ),
                            },
                            { cle: 'site', titre: 'Site', rendu: (f) => (f.site ? `${f.site.code} — ${f.site.nom}` : '—') },
                            { cle: 'centre', titre: 'Centre', compact: true, rendu: (f) => f.centre?.code ?? '—' },
                            { cle: 'agents', titre: 'Agents', alignement: 'droite', rendu: (f) => nombre(f.lignes_count) },
                            { cle: 'statut', titre: 'Statut', compact: true, rendu: (f) => <Pastille ton={tons[f.statut] ?? 'neutre'}>{libelles[f.statut] ?? f.statut}</Pastille> },
                            { cle: 'superviseur', titre: 'Validée par', rendu: (f) => (f.valide_le ? nomDe(f.superviseur?.user) : '—') },
                            { cle: 'valide_le', titre: 'Le', compact: true, rendu: (f) => dateHeure(f.valide_le) },
                        ]}
                    />
                    <Pagination page={liste.pagination} onPage={liste.setPage} />
                </>
            )}
        </div>
    );
}
