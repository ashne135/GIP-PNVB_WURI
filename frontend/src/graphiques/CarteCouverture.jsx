import { useEffect, useRef } from 'react';
import L from 'leaflet';
import 'leaflet/dist/leaflet.css';
import 'leaflet.markercluster/dist/MarkerCluster.css';
import 'leaflet.markercluster/dist/MarkerCluster.Default.css';
import { CLASSES_COUVERTURE, classeCouverture, viz } from './viz';
import { etatCarte } from './etatCarte';

/**
 * LA CARTE DE LA COUVERTURE.
 *
 * Deux couches, qui n'apparaissent que si leurs données existent :
 *
 *   - les RÉGIONS, colorées selon leur taux de couverture en cinq classes
 *     ordonnées d'une seule teinte ; une région sans population connue reste
 *     grise, jamais coloriée comme un zéro ;
 *   - les SITES, regroupés en amas — 12 294 marqueurs individuels rendraient la
 *     carte illisible et lente.
 *
 * AUCUNE POSITION N'EST INVENTÉE. Tant que les contours et les coordonnées ne
 * sont pas chargés, la carte le dit, en chiffres, par-dessus le fond de plan.
 * Le graphique en barres montre la même mesure : c'est la vue de secours, et
 * la vue accessible.
 *
 * Les libellés viennent des données : les infobulles sont construites en
 * nœuds texte, jamais en HTML concaténé.
 *
 * FOND DE PLAN : les tuiles publiques d'OpenStreetMap conviennent au
 * développement. Leur politique d'usage interdit un trafic de production
 * soutenu — un serveur de tuiles ou un fournisseur dédié est à prévoir au
 * déploiement.
 */
const BURKINA_FASO = [[9.39, -5.52], [15.09, 2.41]];

function infobulle(lignes) {
    const bloc = document.createElement('div');

    lignes.forEach((texte, rang) => {
        const ligne = document.createElement('p');
        ligne.textContent = texte;
        ligne.style.margin = '0';
        ligne.style.fontWeight = rang === 0 ? '600' : '400';
        bloc.appendChild(ligne);
    });

    return bloc;
}

function formatTaux(taux) {
    return `${Number(taux).toFixed(1).replace('.', ',')} %`;
}

export function CarteCouverture({ regions = [], sitesCarte = null }) {
    const noeud = useRef(null);
    const carte = useRef(null);
    const calques = useRef([]);
    const etat = etatCarte({ regions, sitesCarte });

    // La carte et son fond de plan, une seule fois.
    useEffect(() => {
        if (!noeud.current || carte.current) {
            return undefined;
        }

        const instance = L.map(noeud.current, { scrollWheelZoom: false });
        instance.fitBounds(BURKINA_FASO);

        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            maxZoom: 18,
            attribution: '© contributeurs OpenStreetMap',
        }).addTo(instance);

        carte.current = instance;

        return () => {
            instance.remove();
            carte.current = null;
        };
    }, []);

    // Les couches de données, redessinées quand elles changent.
    useEffect(() => {
        const instance = carte.current;

        if (!instance) {
            return undefined;
        }

        let annule = false;

        calques.current.forEach((calque) => calque.remove());
        calques.current = [];

        const limites = L.latLngBounds([]);

        regions
            .filter((region) => region.contour_geojson)
            .forEach((region) => {
                const classe = classeCouverture(region.taux_couverture);
                const calque = L.geoJSON(region.contour_geojson, {
                    // Liseré blanc de 2 px : les régions voisines se séparent
                    // par un blanc, pas par un trait sombre.
                    style: { fillColor: classe?.couleur ?? viz.grille, fillOpacity: 0.8, color: viz.surface, weight: 2 },
                });

                calque.bindTooltip(
                    infobulle([
                        region.nom,
                        region.taux_couverture === null || region.taux_couverture === undefined
                            ? 'Couverture non mesurable : population inconnue'
                            : `Couverture ${formatTaux(region.taux_couverture)}`,
                    ]),
                    { sticky: true },
                );

                calque.addTo(instance);
                calques.current.push(calque);
                limites.extend(calque.getBounds());
            });

        async function placerSites() {
            const sites = sitesCarte?.sites ?? [];

            if (sites.length === 0) {
                return;
            }

            // Le greffon d'amas s'accroche au L global : on le lui fournit avant
            // de le charger.
            window.L = L;
            await import('leaflet.markercluster');

            if (annule) {
                return;
            }

            const amas = L.markerClusterGroup({ showCoverageOnHover: false, chunkedLoading: true });

            sites.forEach((site) => {
                const marqueur = L.circleMarker([site.lat, site.lng], {
                    radius: 5,
                    weight: 2,
                    color: viz.surface,
                    fillColor: site.couvert ? viz.serie : viz.axe,
                    fillOpacity: 1,
                });

                marqueur.bindTooltip(
                    infobulle([`${site.code} — ${site.nom}`, site.couvert ? 'Enregistrements réalisés' : 'Aucun enregistrement']),
                );

                amas.addLayer(marqueur);
                limites.extend([site.lat, site.lng]);
            });

            amas.addTo(instance);
            calques.current.push(amas);

            if (limites.isValid()) {
                instance.fitBounds(limites, { padding: [16, 16] });
            }
        }

        if (limites.isValid()) {
            instance.fitBounds(limites, { padding: [16, 16] });
        }

        placerSites();

        return () => {
            annule = true;
        };
    }, [regions, sitesCarte]);

    return (
        <figure className="rounded border border-ardoise-200 bg-white">
            <figcaption className="border-b border-ardoise-200 px-4 py-3">
                <p className="text-sm font-semibold text-ardoise-900">Carte de la couverture</p>
                <p className="mt-0.5 text-xs text-ardoise-600">{etat.resume}</p>
            </figcaption>

            <div className="relative">
                <div ref={noeud} className="h-96 w-full" role="region" aria-label="Carte de la couverture par région" />

                {etat.vide && (
                    <div className="absolute inset-0 z-[800] flex items-center justify-center bg-white/70 p-6">
                        <div className="max-w-sm rounded border border-ardoise-300 bg-white px-4 py-4 text-center shadow-sm" role="status">
                            <p className="text-sm font-semibold text-ardoise-900">Données géographiques absentes</p>
                            <p className="mt-1.5 text-sm text-ardoise-700">{etat.message}</p>
                        </div>
                    </div>
                )}
            </div>

            <div className="flex flex-wrap items-center gap-x-4 gap-y-2 border-t border-ardoise-200 px-4 py-3 text-xs text-ardoise-700">
                <span className="font-medium">Taux de couverture</span>
                {CLASSES_COUVERTURE.map((classe) => (
                    <span key={classe.libelle} className="inline-flex items-center gap-1.5">
                        <span className="inline-block h-3 w-3 rounded-sm" style={{ background: classe.couleur }} aria-hidden="true" />
                        {classe.libelle}
                    </span>
                ))}
                <span className="inline-flex items-center gap-1.5">
                    <span className="inline-block h-3 w-3 rounded-sm" style={{ background: viz.grille }} aria-hidden="true" />
                    non mesurable
                </span>
                {etat.localises > 0 && (
                    <>
                        <span className="inline-flex items-center gap-1.5">
                            <span className="inline-block h-2.5 w-2.5 rounded-full" style={{ background: viz.serie }} aria-hidden="true" />
                            site avec enregistrements
                        </span>
                        <span className="inline-flex items-center gap-1.5">
                            <span className="inline-block h-2.5 w-2.5 rounded-full" style={{ background: viz.axe }} aria-hidden="true" />
                            site sans enregistrement
                        </span>
                    </>
                )}
            </div>
        </figure>
    );
}
