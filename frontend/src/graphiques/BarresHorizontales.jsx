import { useState } from 'react';
import { graduations } from './echelles';
import { viz } from './viz';

/**
 * DES BARRES HORIZONTALES — une mesure comparée entre catégories nominales.
 *
 * Toutes les barres portent LA MÊME COULEUR. Les régions n'ont pas d'ordre
 * naturel : les foncer selon leur valeur coderait deux fois la longueur de la
 * barre et gaspillerait le seul canal libre.
 *
 * Une valeur NON MESURABLE — une région sans population connue — n'est pas
 * dessinée comme un zéro : elle est listée à part, en toutes lettres. Un zéro
 * la ferait passer pour un échec.
 *
 * Les libellés vont à la ligne plutôt que d'être tronqués : un nom de région
 * coupé en « Tannoun… » ne sert à personne.
 */
export function BarresHorizontales({
    titre,
    sousTitre,
    lignes,
    formatValeur = (valeur) => new Intl.NumberFormat('fr-FR').format(valeur),
    libelleNonMesurable = 'non mesurable',
    maximum,
}) {
    const [enTableau, setEnTableau] = useState(false);

    const mesurees = (lignes ?? [])
        .filter((ligne) => ligne.valeur !== null && ligne.valeur !== undefined)
        .sort((a, b) => b.valeur - a.valeur);
    const nonMesurees = (lignes ?? []).filter((ligne) => ligne.valeur === null || ligne.valeur === undefined);
    const plafond = maximum ?? graduations(Math.max(0, ...mesurees.map((ligne) => ligne.valeur))).max;

    const vide = mesurees.length === 0 && nonMesurees.length === 0;

    return (
        <figure className="rounded border border-ardoise-200 bg-white">
            <figcaption className="flex flex-wrap items-start justify-between gap-3 border-b border-ardoise-200 px-4 py-3">
                <div>
                    <p className="text-sm font-semibold text-ardoise-900">{titre}</p>
                    {sousTitre && <p className="mt-0.5 text-xs text-ardoise-600">{sousTitre}</p>}
                </div>
                {!vide && (
                    <button
                        type="button"
                        onClick={() => setEnTableau((valeur) => !valeur)}
                        className="rounded border border-ardoise-300 px-2.5 py-1 text-xs text-ardoise-700 hover:bg-ardoise-50"
                    >
                        {enTableau ? 'Afficher le graphique' : 'Afficher le tableau'}
                    </button>
                )}
            </figcaption>

            {vide && <p className="px-4 py-10 text-center text-sm text-ardoise-600">Aucune donnée à comparer.</p>}

            {!vide && enTableau && (
                <div className="overflow-x-auto px-4 py-3">
                    <table className="min-w-full text-sm">
                        <tbody className="divide-y divide-ardoise-100">
                            {[...mesurees, ...nonMesurees].map((ligne) => (
                                <tr key={ligne.cle}>
                                    <td className="py-1.5 pr-4 text-ardoise-800">{ligne.libelle}</td>
                                    <td className="py-1.5 text-right tabular-nums text-ardoise-900">
                                        {ligne.valeur === null || ligne.valeur === undefined ? libelleNonMesurable : formatValeur(ligne.valeur)}
                                    </td>
                                    {ligne.precision && <td className="py-1.5 pl-4 text-xs text-ardoise-600">{ligne.precision}</td>}
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}

            {!vide && !enTableau && (
                <ul className="space-y-2 px-4 py-3">
                    {mesurees.map((ligne) => {
                        const part = plafond > 0 ? Math.min(100, (ligne.valeur / plafond) * 100) : 0;
                        const description = `${ligne.libelle} : ${formatValeur(ligne.valeur)}${ligne.precision ? `, ${ligne.precision}` : ''}`;

                        return (
                            <li
                                key={ligne.cle}
                                tabIndex={0}
                                title={description}
                                aria-label={description}
                                className="grid grid-cols-[minmax(6rem,11rem)_1fr_4.5rem] items-center gap-3 rounded outline-none hover:bg-ardoise-50 focus-visible:ring-2 focus-visible:ring-pnvb-400"
                            >
                                <span className="break-words text-sm text-ardoise-800">{ligne.libelle}</span>
                                <span className="relative block h-5">
                                    <span
                                        data-barre={ligne.cle}
                                        className="absolute inset-y-0 left-0 rounded-r"
                                        style={{
                                            width: `${part}%`,
                                            // Une valeur non nulle reste visible, même minuscule.
                                            minWidth: ligne.valeur > 0 ? '2px' : 0,
                                            background: viz.serie,
                                        }}
                                    />
                                </span>
                                <span className="text-right text-sm tabular-nums" style={{ color: viz.texteFort }}>
                                    {formatValeur(ligne.valeur)}
                                </span>
                            </li>
                        );
                    })}

                    {nonMesurees.map((ligne) => (
                        <li
                            key={ligne.cle}
                            className="grid grid-cols-[minmax(6rem,11rem)_1fr] items-center gap-3"
                        >
                            <span className="break-words text-sm text-ardoise-800">{ligne.libelle}</span>
                            <span className="text-xs italic" style={{ color: viz.texte }}>{libelleNonMesurable}</span>
                        </li>
                    ))}
                </ul>
            )}
        </figure>
    );
}
