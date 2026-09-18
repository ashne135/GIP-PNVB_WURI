import { Link } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';
import { api, avecParametres } from '../../api/client';
import { useListe } from '../../outils/crochets';
import { Pagination, Pastille, Tableau } from '../../composants/Tableau';
import { BarreFiltres, FiltreListe, FiltreTexte } from '../../composants/Filtres';
import { Chargement } from '../../composants/Chargement';
import { Echec, Vide } from '../../composants/Etats';
import { humaniser, nombre } from '../../outils/format';
import { statut, statutsSite } from '../../domaine/referentiel';
import { useRegions } from './ListeCentres';
import { useAuth } from '../../auth/ContexteAuth';
import { BarreSuppression, colonneChoix, useSelection } from '../../composants/Suppression';

/**
 * LES SITES.
 *
 * On les cherche par code ou par nom, et on les situe par région puis par
 * commune — la commune se choisit dans la région, sinon la liste des 336
 * communes du pays serait inutilisable dans un filtre.
 *
 * La colonne « Carte » dit si le site a des coordonnées : sans elles, il
 * n'apparaît pas sur la carte du tableau de bord, et aucune distance ne peut
 * être calculée pour un signal d'arrivée. On les renseigne depuis la fiche.
 */
export function ListeSites() {
    const liste = useListe('sites', '/referentiel/sites');
    const auth = useAuth();
    const selection = useSelection(liste.lignes);
    const peutSupprimer = auth.peut('donnees.supprimer');
    const regions = useRegions();

    const communes = useQuery({
        queryKey: ['referentiel-communes', liste.filtres.region_id],
        queryFn: () => api.lire(avecParametres('/referentiel/communes', { region_id: liste.filtres.region_id })),
        enabled: Boolean(liste.filtres.region_id),
    });

    return (
        <>
            <BarreFiltres onReinitialiser={liste.reinitialiser}>
                <FiltreTexte
                    libelle="Recherche"
                    valeur={liste.filtres.recherche}
                    onChange={(v) => liste.changerFiltre('recherche', v)}
                    placeholder="Code ou nom"
                />
                <FiltreListe
                    libelle="Région"
                    valeur={liste.filtres.region_id}
                    onChange={(v) => {
                        liste.changerFiltre('region_id', v);
                        // La commune choisie n'appartient plus à la région : la remettre
                        // à zéro évite un filtre qui ne rendrait jamais rien.
                        liste.changerFiltre('commune_id', '');
                    }}
                    tous="Toutes"
                    options={regions.map((r) => ({ valeur: String(r.id), libelle: r.nom }))}
                />
                <FiltreListe
                    libelle="Commune"
                    valeur={liste.filtres.commune_id}
                    onChange={(v) => liste.changerFiltre('commune_id', v)}
                    tous={liste.filtres.region_id ? 'Toutes' : 'Choisissez une région'}
                    options={(communes.data ?? []).map((c) => ({ valeur: String(c.id), libelle: c.nom }))}
                />
                <FiltreListe
                    libelle="Statut"
                    valeur={liste.filtres.statut}
                    onChange={(v) => liste.changerFiltre('statut', v)}
                    options={Object.entries(statutsSite).map(([valeur, s]) => ({ valeur, libelle: s.libelle }))}
                />
            </BarreFiltres>

            {liste.isPending && <Chargement message="Chargement des sites…" />}
            {liste.error && <Echec erreur={liste.error} onReessayer={liste.refetch} />}

            {!liste.isPending && !liste.error && (
                <>
                    <Tableau
                        cle={(s) => s.id}
                        lignes={liste.lignes}
                        vide={<Vide titre="Aucun site ne correspond" explication="Aucun site de votre périmètre ne répond à ces filtres." />}
                        colonnes={[
                            ...(peutSupprimer ? [colonneChoix(selection, (s) => s.code)] : []),
                            {
                                cle: 'code',
                                titre: 'Code',
                                compact: true,
                                rendu: (s) => <Link to={`/sites/${s.id}`} className="font-mono font-medium text-pnvb-800 underline">{s.code}</Link>,
                            },
                            { cle: 'nom', titre: 'Nom' },
                            { cle: 'centre', titre: 'Centre', compact: true, rendu: (s) => s.centre?.code ?? '—' },
                            {
                                cle: 'localite',
                                titre: 'Localité',
                                rendu: (s) => (s.localite ? `${s.localite.nom} (${humaniser(s.localite.type_localite)}) — ${nombre(s.localite.population_totale)} hab.` : '—'),
                            },
                            { cle: 'ordre', titre: 'Ordre', alignement: 'droite', rendu: (s) => s.ordre_tournee ?? '—' },
                            {
                                cle: 'statut',
                                titre: 'Statut',
                                compact: true,
                                rendu: (s) => <Pastille ton={statut(statutsSite, s.statut).ton}>{statut(statutsSite, s.statut).libelle}</Pastille>,
                            },
                            {
                                cle: 'carte',
                                titre: 'Carte',
                                compact: true,
                                rendu: (s) => (s.latitude != null && s.longitude != null
                                    ? <Pastille ton="bon">localisé</Pastille>
                                    : <Pastille>sans coordonnées</Pastille>),
                            },
                        ]}
                    />
                    <Pagination page={liste.pagination} onPage={liste.setPage} />

                    {peutSupprimer && (
                        <BarreSuppression
                            famille="site"
                            nom="site"
                            selection={selection}
                            nommer={(s) => s.code}
                            aRafraichir={['sites', 'centres', 'cartographie-sites', 'tableau-bord-sites-carte']}
                        />
                    )}
                </>
            )}
        </>
    );
}
