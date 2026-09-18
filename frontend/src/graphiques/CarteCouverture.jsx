import { useEffect, useRef } from 'react';
import L from 'leaflet';
import 'leaflet/dist/leaflet.css';
import 'leaflet.markercluster/dist/MarkerCluster.css';
import 'leaflet.markercluster/dist/MarkerCluster.Default.css';
import { CLASSES_COUVERTURE, classeCouverture, viz } from './viz';
import { etatCarte } from './etatCarte';
import { lienItineraire } from '../outils/itineraire';

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

/**
 * LES DEUX TEINTES DES SITES.
 *
 * Reprises des classes de couverture — c'est la même mesure, donc la même
 * teinte. Le bleu le plus foncé de la rampe tient sur un fond de carte
 * beige-gris, là où le gris clair des filets disparaissait purement et
 * simplement.
 */
export const BLEU_SITE = CLASSES_COUVERTURE[CLASSES_COUVERTURE.length - 1].couleur;

/**
 * UNE ÉPINGLE, ET NON UN POINT.
 *
 * Un disque de 5 px se confondait avec le fond de plan : sur un écran lu en
 * réunion, personne ne voyait les sites. Une épingle porte une ombre, une
 * pointe qui désigne l'endroit exact, et une taille qu'on peut viser au doigt.
 *
 * LES DEUX ÉTATS SE DISTINGUENT PAR LA FORME AUTANT QUE PAR LA COULEUR :
 * pleine quand le site a produit des enregistrements, creuse quand il n'en a
 * pas. La distinction reste lisible en noir et blanc, et pour un lecteur qui
 * confond les teintes.
 */
export function svgEpingle(couvert) {
    const corps = couvert ? BLEU_SITE : viz.surface;
    const trait = couvert ? viz.surface : BLEU_SITE;
    const pastille = couvert ? viz.surface : BLEU_SITE;

    // Aucune donnée ne passe dans ce balisage : ni nom, ni code, ni libellé.
    return `
        <svg width="26" height="34" viewBox="0 0 26 34" xmlns="http://www.w3.org/2000/svg"
             style="filter: drop-shadow(0 1px 1.5px rgba(0,0,0,.45))" aria-hidden="true">
            <path d="M13 1.5c-6 0-10.5 4.6-10.5 10.4C2.5 20.4 13 32.5 13 32.5S23.5 20.4 23.5 11.9C23.5 6.1 19 1.5 13 1.5z"
                  fill="${corps}" stroke="${trait}" stroke-width="2.5" stroke-linejoin="round" />
            <circle cx="13" cy="11.8" r="3.4" fill="${pastille}" />
        </svg>`;
}

function epingle(couvert) {
    return L.divIcon({
        html: svgEpingle(couvert),
        // La classe par défaut de Leaflet dessine un carré blanc bordé :
        // la remplacer laisse l'épingle seule à l'écran.
        className: 'pnvb-epingle',
        iconSize: [26, 34],
        iconAnchor: [13, 33],
        popupAnchor: [0, -30],
        tooltipAnchor: [0, -28],
    });
}

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
                const marqueur = L.marker([site.lat, site.lng], {
                    icon: epingle(site.couvert),
                    title: `${site.code} — ${site.nom}`,
                    keyboard: true,
                });

                marqueur.bindTooltip(
                    infobulle([
                        `${site.code} — ${site.nom}`,
                        site.couvert ? 'Enregistrements réalisés' : 'Aucun enregistrement',
                        'Cliquez pour l’itinéraire',
                    ]),
                );

                /*
                 * AU CLIC, L'ITINÉRAIRE : ces sites sont dans des villages sans
                 * adresse, et c'est depuis la carte qu'on prépare une visite de
                 * suivi. L'infobulle du survol, elle, ne change pas.
                 */
                const itineraire = lienItineraire(site.lat, site.lng);

                if (itineraire) {
                    // La bulle se construit en éléments du document, comme
                    // l'infobulle : aucun texte de la base ne devient du HTML.
                    const bulle = infobulle([site.nom, site.code]);
                    const lien = document.createElement('a');

                    lien.href = itineraire;
                    lien.target = '_blank';
                    lien.rel = 'noreferrer noopener';
                    lien.textContent = 'Itinéraire (Google Maps)';
                    lien.style.display = 'inline-block';
                    lien.style.marginTop = '6px';
                    lien.style.textDecoration = 'underline';
                    bulle.appendChild(lien);

                    marqueur.bindPopup(bulle);
                }

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
                        {/*
                          * La légende montre les DEUX FORMES, pleine et creuse,
                          * et non deux ronds de couleurs différentes : c'est la
                          * forme qui reste lisible en noir et blanc.
                          */}
                        <span className="inline-flex items-center gap-1.5">
                            <span
                                className="inline-block h-3 w-3 rounded-full border-2"
                                style={{ background: BLEU_SITE, borderColor: viz.surface }}
                                aria-hidden="true"
                            />
                            site avec enregistrements
                        </span>
                        <span className="inline-flex items-center gap-1.5">
                            <span
                                className="inline-block h-3 w-3 rounded-full border-2"
                                style={{ background: viz.surface, borderColor: BLEU_SITE }}
                                aria-hidden="true"
                            />
                            site sans enregistrement
                        </span>
                        <span className="text-ardoise-500">— cliquez un site pour son itinéraire</span>
                    </>
                )}
            </div>
        </figure>
    );
}
