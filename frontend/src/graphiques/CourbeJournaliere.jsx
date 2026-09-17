import { useEffect, useId, useMemo, useRef, useState } from 'react';
import { echelleLineaire, graduations, indexLePlusProche } from './echelles';
import { viz } from './viz';

/**
 * UNE COURBE JOURNALIÈRE — une seule série, un seul axe.
 *
 * Choix de forme : l'évolution d'une mesure dans le temps, c'est une ligne.
 * Une seule série, donc pas de légende : le titre dit ce qui est tracé.
 *
 * JAMAIS DE SECOND AXE. Le cumul, qui n'a pas la même échelle, s'affiche en
 * tuile à côté : deux échelles sur un même tracé inventent une corrélation
 * qui n'est pas dans les données.
 *
 * Les jours sont espacés régulièrement, qu'ils se suivent ou non : les
 * week-ends sans activité ne creusent pas de faux trous dans la courbe.
 *
 * Le survol n'est jamais la seule porte : la vue en tableau montre chaque
 * valeur, et le clavier (flèches) donne le même réticule que la souris.
 */
const MARGES = { haut: 16, droite: 64, bas: 32, gauche: 52 };

function useLargeur(reference, repli = 640) {
    const [largeur, setLargeur] = useState(repli);

    useEffect(() => {
        const element = reference.current;

        if (!element || typeof ResizeObserver === 'undefined') {
            return undefined;
        }

        const observateur = new ResizeObserver(([entree]) => {
            setLargeur(Math.max(280, Math.round(entree.contentRect.width)));
        });

        observateur.observe(element);

        return () => observateur.disconnect();
    }, [reference]);

    return largeur;
}

export function CourbeJournaliere({
    titre,
    sousTitre,
    jours,
    libelleValeur,
    formatValeur = (valeur) => new Intl.NumberFormat('fr-FR').format(valeur),
    formatDate = (date) => date,
    hauteur = 240,
}) {
    const conteneur = useRef(null);
    const largeur = useLargeur(conteneur);
    const idTitre = useId();
    const [actif, setActif] = useState(null);
    const [enTableau, setEnTableau] = useState(false);

    const donnees = jours ?? [];

    const geometrie = useMemo(() => {
        if (donnees.length === 0) {
            return null;
        }

        const basTrace = hauteur - MARGES.bas;
        const largeurTrace = Math.max(40, largeur - MARGES.gauche - MARGES.droite);
        const echelleY = graduations(Math.max(...donnees.map((jour) => jour.valeur)));
        const y = echelleLineaire([0, echelleY.max], [basTrace, MARGES.haut]);
        const x = donnees.length === 1
            ? () => MARGES.gauche + largeurTrace / 2
            : echelleLineaire([0, donnees.length - 1], [MARGES.gauche, MARGES.gauche + largeurTrace]);

        const points = donnees.map((jour, rang) => ({ ...jour, x: x(rang), y: y(jour.valeur) }));
        const trace = points.map((p, rang) => `${rang === 0 ? 'M' : 'L'}${p.x.toFixed(1)},${p.y.toFixed(1)}`).join(' ');
        const dernier = points[points.length - 1];

        return {
            points,
            trace,
            aire: `${trace} L${dernier.x.toFixed(1)},${basTrace} L${points[0].x.toFixed(1)},${basTrace} Z`,
            basTrace,
            graduationsY: echelleY.valeurs.map((valeur) => ({ valeur, y: y(valeur) })),
            // Trois dates seulement — première, milieu, dernière : assez pour
            // situer la période, trop peu pour que les libellés se chevauchent.
            reperesX: [...new Set([0, Math.floor((points.length - 1) / 2), points.length - 1])].map((rang) => points[rang]),
        };
    }, [donnees, largeur, hauteur]);

    function survoler(evenement) {
        if (!geometrie) {
            return;
        }

        const cadre = evenement.currentTarget.getBoundingClientRect();
        const xLocal = ((evenement.clientX - cadre.left) / (cadre.width || largeur)) * largeur;

        setActif(indexLePlusProche(xLocal, geometrie.points.map((p) => p.x)));
    }

    function clavier(evenement) {
        if (!geometrie) {
            return;
        }

        const dernier = geometrie.points.length - 1;
        const mouvements = {
            ArrowRight: () => Math.min(dernier, (actif ?? -1) + 1),
            ArrowLeft: () => Math.max(0, (actif ?? dernier + 1) - 1),
            Home: () => 0,
            End: () => dernier,
            Escape: () => null,
        };

        if (mouvements[evenement.key]) {
            evenement.preventDefault();
            setActif(mouvements[evenement.key]());
        }
    }

    const point = geometrie && actif !== null ? geometrie.points[actif] : null;

    return (
        <figure className="rounded border border-ardoise-200 bg-white">
            <figcaption className="flex flex-wrap items-start justify-between gap-3 border-b border-ardoise-200 px-4 py-3">
                <div>
                    <p id={idTitre} className="text-sm font-semibold text-ardoise-900">{titre}</p>
                    {sousTitre && <p className="mt-0.5 text-xs text-ardoise-600">{sousTitre}</p>}
                </div>
                {donnees.length > 0 && (
                    <button
                        type="button"
                        onClick={() => setEnTableau((valeur) => !valeur)}
                        className="rounded border border-ardoise-300 px-2.5 py-1 text-xs text-ardoise-700 hover:bg-ardoise-50"
                    >
                        {enTableau ? 'Afficher le graphique' : 'Afficher le tableau'}
                    </button>
                )}
            </figcaption>

            {donnees.length === 0 && (
                <p className="px-4 py-10 text-center text-sm text-ardoise-600">
                    Aucune donnée sur cette période.
                </p>
            )}

            {donnees.length > 0 && enTableau && (
                <div className="max-h-80 overflow-auto px-4 py-3">
                    <table className="min-w-full text-sm">
                        <thead>
                            <tr className="text-left text-xs uppercase tracking-wide text-ardoise-600">
                                <th className="py-1.5 pr-4 font-semibold">Jour</th>
                                <th className="py-1.5 text-right font-semibold">{libelleValeur}</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-ardoise-100">
                            {donnees.map((jour) => (
                                <tr key={jour.date}>
                                    <td className="py-1.5 pr-4 text-ardoise-800">{formatDate(jour.date)}</td>
                                    <td className="py-1.5 text-right tabular-nums text-ardoise-900">{formatValeur(jour.valeur)}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}

            {geometrie && !enTableau && (
                <div
                    ref={conteneur}
                    className="relative px-2 py-2 outline-none focus-visible:ring-2 focus-visible:ring-pnvb-400"
                    tabIndex={0}
                    onKeyDown={clavier}
                    onFocus={() => setActif((valeur) => valeur ?? geometrie.points.length - 1)}
                    onBlur={() => setActif(null)}
                    aria-describedby={idTitre}
                >
                    <svg
                        viewBox={`0 0 ${largeur} ${hauteur}`}
                        width="100%"
                        height={hauteur}
                        role="img"
                        aria-labelledby={idTitre}
                        onPointerMove={survoler}
                        onPointerLeave={() => setActif(null)}
                    >
                        <g data-axe="y">
                            {geometrie.graduationsY.map((g) => (
                                <g key={g.valeur}>
                                    <line x1={MARGES.gauche} x2={largeur - MARGES.droite} y1={g.y} y2={g.y} stroke={viz.grille} strokeWidth="1" />
                                    <text x={MARGES.gauche - 8} y={g.y + 4} textAnchor="end" fontSize="11" fill={viz.texte} style={{ fontVariantNumeric: 'tabular-nums' }}>
                                        {formatValeur(g.valeur)}
                                    </text>
                                </g>
                            ))}
                        </g>

                        <g data-axe="x">
                            <line x1={MARGES.gauche} x2={largeur - MARGES.droite} y1={geometrie.basTrace} y2={geometrie.basTrace} stroke={viz.axe} strokeWidth="1" />
                            {geometrie.reperesX.map((p) => (
                                <text key={p.date} x={p.x} y={hauteur - 10} textAnchor="middle" fontSize="11" fill={viz.texte}>
                                    {formatDate(p.date)}
                                </text>
                            ))}
                        </g>

                        <path d={geometrie.aire} fill={viz.lavis} />
                        <path data-serie="valeur" d={geometrie.trace} fill="none" stroke={viz.serie} strokeWidth="2" strokeLinejoin="round" strokeLinecap="round" />

                        {/* Libellé direct sélectif : la dernière valeur seulement. */}
                        {(() => {
                            const dernier = geometrie.points[geometrie.points.length - 1];

                            return (
                                <g>
                                    <circle cx={dernier.x} cy={dernier.y} r="4" fill={viz.serie} stroke={viz.surface} strokeWidth="2" />
                                    <text x={dernier.x + 8} y={dernier.y + 4} fontSize="12" fontWeight="600" fill={viz.texteFort}>
                                        {formatValeur(dernier.valeur)}
                                    </text>
                                </g>
                            );
                        })()}

                        {point && (
                            <g aria-hidden="true">
                                <line x1={point.x} x2={point.x} y1={MARGES.haut} y2={geometrie.basTrace} stroke={viz.axe} strokeWidth="1" />
                                <circle cx={point.x} cy={point.y} r="4" fill={viz.serie} stroke={viz.surface} strokeWidth="2" />
                            </g>
                        )}
                    </svg>

                    {point && (
                        <div
                            role="status"
                            className="pointer-events-none absolute top-2 rounded border border-ardoise-200 bg-white px-2.5 py-1.5 shadow-sm"
                            style={{ left: `${Math.min(Math.max((point.x / largeur) * 100, 8), 78)}%` }}
                        >
                            <p className="flex items-center gap-1.5 text-sm font-semibold text-ardoise-900">
                                <span className="inline-block h-0.5 w-3" style={{ background: viz.serie }} aria-hidden="true" />
                                {formatValeur(point.valeur)} {libelleValeur}
                            </p>
                            <p className="text-xs text-ardoise-600">{formatDate(point.date)}</p>
                        </div>
                    )}
                </div>
            )}
        </figure>
    );
}
