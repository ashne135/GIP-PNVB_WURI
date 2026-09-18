import { useState } from 'react';
import { Link } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';
import { api, avecParametres } from '../api/client';
import { EnTetePage, Indicateur } from '../composants/Page';
import { Tableau } from '../composants/Tableau';
import { BarreFiltres, FiltreListe } from '../composants/Filtres';
import { Chargement } from '../composants/Chargement';
import { Echec } from '../composants/Etats';
import { CarteCouverture } from '../graphiques/CarteCouverture';
import { nombre } from '../outils/format';

/**
 * LA CARTOGRAPHIE, en pleine page.
 *
 * Le tableau de bord porte une carte, mais petite et noyée au milieu du reste :
 * elle sert à repérer, pas à consulter. Cette page-ci fait l'inverse — la carte
 * occupe l'écran, et on choisit ce qu'elle montre : tout le pays, ou une région.
 *
 * CE QU'ELLE DIT DE CE QU'ELLE NE MONTRE PAS. Un site sans coordonnées ne peut
 * pas être placé ; le taire ferait lire une région à moitié saisie comme une
 * région à moitié vide. Le tableau donne donc trois nombres par région, et ils
 * ne se déduisent pas l'un de l'autre : les sites EXISTANTS, ceux qui sont
 * PLACÉS, et ceux qui ont ENREGISTRÉ.
 *
 * LE PÉRIMÈTRE reste celui du serveur : un chef d'antenne ne reçoit que sa
 * région, et demander une autre lui vaut un refus — pas une carte vide qu'il
 * lirait comme « aucun site ici ».
 */
export function Cartographie() {
    const [regionId, setRegionId] = useState('');

    const couverture = useQuery({
        queryKey: ['tableau-bord-couverture'],
        queryFn: () => api.lire('/tableau-bord/couverture'),
    });

    const carte = useQuery({
        queryKey: ['cartographie-sites', regionId],
        queryFn: () => api.lire(avecParametres('/tableau-bord/sites-carte', { region_id: regionId || undefined })),
    });

    const parRegion = carte.data?.par_region ?? [];
    const choisie = parRegion.find((r) => String(r.region_id) === regionId) ?? null;

    // Les aplats suivent le filtre : garder les douze contours pendant qu'on
    // regarde une seule région donnerait une carte qui parle d'autre chose.
    const contours = (couverture.data ?? []).filter(
        (region) => !regionId || String(region.region_id) === regionId,
    );

    const couverts = (carte.data?.sites ?? []).filter((site) => site.couvert).length;

    return (
        <>
            <EnTetePage
                titre="Cartographie"
                sousTitre={
                    choisie
                        ? `Les sites de la région ${choisie.nom}.`
                        : 'Tous les sites de votre périmètre, et leur répartition par région.'
                }
                actions={
                    <Link to="/centres" className="rounded border border-ardoise-300 bg-white px-3 py-2 text-sm hover:bg-ardoise-50">
                        Centres et sites
                    </Link>
                }
            />

            <BarreFiltres onReinitialiser={() => setRegionId('')}>
                <FiltreListe
                    libelle="Région"
                    tous="Toutes les régions"
                    valeur={regionId}
                    onChange={setRegionId}
                    options={parRegion.map((region) => ({
                        valeur: String(region.region_id),
                        libelle: `${region.nom} (${nombre(region.sites)} sites)`,
                    }))}
                />
            </BarreFiltres>

            {carte.isPending && <Chargement message="Chargement des sites…" />}
            {carte.error && <Echec erreur={carte.error} onReessayer={carte.refetch} />}

            {carte.data && (
                <>
                    <div className="grid gap-3 sm:grid-cols-3">
                        <Indicateur
                            libelle={choisie ? `Sites — ${choisie.nom}` : 'Sites du périmètre'}
                            valeur={nombre(carte.data.total_sites)}
                            precision={choisie ? 'Dans la région choisie' : 'Toutes régions confondues'}
                        />
                        <Indicateur
                            libelle="Placés sur la carte"
                            valeur={nombre(carte.data.localises)}
                            precision="Les autres n’ont pas encore de coordonnées"
                            ton={carte.data.localises < carte.data.total_sites ? 'alerte' : 'bon'}
                        />
                        <Indicateur
                            libelle="Ont enregistré"
                            valeur={nombre(couverts)}
                            precision="Parmi les sites placés"
                        />
                    </div>

                    <CarteCouverture regions={contours} sitesCarte={carte.data} hauteur="h-[36rem]" />

                    <Tableau
                        cle={(r) => r.region_id}
                        lignes={parRegion}
                        colonnes={[
                            {
                                cle: 'nom',
                                titre: 'Région',
                                rendu: (r) => (
                                    <button
                                        type="button"
                                        onClick={() => setRegionId(String(r.region_id))}
                                        className="text-left font-medium text-pnvb-800 underline"
                                    >
                                        {r.nom}
                                    </button>
                                ),
                            },
                            { cle: 'sites', titre: 'Sites', alignement: 'droite', rendu: (r) => nombre(r.sites) },
                            {
                                cle: 'localises',
                                titre: 'Placés sur la carte',
                                alignement: 'droite',
                                rendu: (r) => (
                                    <span className={r.localises < r.sites ? 'text-ocre-900' : undefined}>
                                        {nombre(r.localises)}
                                    </span>
                                ),
                            },
                            {
                                cle: 'couverts',
                                titre: 'Ont enregistré',
                                alignement: 'droite',
                                rendu: (r) => nombre(r.couverts),
                            },
                        ]}
                    />
                </>
            )}
        </>
    );
}
