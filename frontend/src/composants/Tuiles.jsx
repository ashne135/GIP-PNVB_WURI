import { useEffect, useRef, useState } from 'react';

/**
 * LE MODÈLE DE TUILES ET DE PANNEAUX DU TABLEAU DE BORD.
 *
 * La mise en page reprend celle d'un modèle d'administration éprouvé — une
 * grille de tuiles chiffrées en tête, puis des panneaux titrés — mais RIEN de
 * ses couleurs : tout vient de la palette du Programme, le vert PNVB et
 * l'ardoise. Un modèle donne une structure, pas une identité.
 *
 * Aucune bibliothèque d'icônes n'est installée pour cela : les huit glyphes
 * nécessaires sont dessinés ici, en SVG, au trait. Cela évite une dépendance de
 * plusieurs mégaoctets pour huit dessins.
 */

/** Les glyphes du tableau de bord. Trait de 1.5, jamais de remplissage. */
const GLYPHES = {
    agents: 'M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2M9 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8M22 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75',
    valide: 'M22 11.08V12a10 10 0 1 1-5.93-9.14M22 4 12 14.01l-3-3',
    kit: 'M20 7h-3V5a2 2 0 0 0-2-2H9a2 2 0 0 0-2 2v2H4a2 2 0 0 0-2 2v9a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V9a2 2 0 0 0-2-2M9 5h6v2H9zM2 12h20',
    alerte: 'M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0M12 9v4M12 17h.01',
    rapport: 'M9 2h6a1 1 0 0 1 1 1v2H8V3a1 1 0 0 1 1-1M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2M9 12h6M9 16h4',
    site: 'M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0M12 12a2.5 2.5 0 1 0 0-5 2.5 2.5 0 0 0 0 5',
    horloge: 'M12 22a10 10 0 1 0 0-20 10 10 0 0 0 0 20M12 6v6l4 2',
    cible: 'M12 22a10 10 0 1 0 0-20 10 10 0 0 0 0 20M12 18a6 6 0 1 0 0-12 6 6 0 0 0 0 12M12 14a2 2 0 1 0 0-4 2 2 0 0 0 0 4',
};

export function Icone({ nom, className = 'h-5 w-5' }) {
    const trace = GLYPHES[nom];

    if (!trace) {
        return null;
    }

    return (
        <svg
            viewBox="0 0 24 24"
            fill="none"
            stroke="currentColor"
            strokeWidth="1.5"
            strokeLinecap="round"
            strokeLinejoin="round"
            className={className}
            aria-hidden="true"
        >
            <path d={trace} />
        </svg>
    );
}

/**
 * UN NOMBRE QUI MONTE — mais qui part de sa valeur finale si l'on a demandé
 * moins d'animations. Le chiffre affiché doit être juste à la première image
 * pour un lecteur d'écran comme pour une capture.
 */
export function NombreAnime({ valeur, format = (v) => String(v) }) {
    const cible = Number(valeur);
    const valide = Number.isFinite(cible);
    const [affiche, setAffiche] = useState(cible);
    const precedent = useRef(cible);

    useEffect(() => {
        if (!valide) {
            return undefined;
        }

        const reduit = window.matchMedia?.('(prefers-reduced-motion: reduce)')?.matches;
        const depart = precedent.current;
        precedent.current = cible;

        if (reduit || depart === cible) {
            setAffiche(cible);

            return undefined;
        }

        const debut = performance.now();
        const duree = 420;
        let image;

        const avancer = (maintenant) => {
            const part = Math.min(1, (maintenant - debut) / duree);
            // Sortie douce : le chiffre ralentit en approchant, il ne s'arrête pas net.
            const adouci = 1 - (1 - part) ** 3;

            setAffiche(Math.round(depart + (cible - depart) * adouci));

            if (part < 1) {
                image = requestAnimationFrame(avancer);
            }
        };

        image = requestAnimationFrame(avancer);

        return () => cancelAnimationFrame(image);
    }, [cible, valide]);

    if (!valide) {
        return <>—</>;
    }

    return <>{format(affiche)}</>;
}

const TONS = {
    neutre: 'bg-ardoise-100 text-ardoise-700',
    bon: 'bg-vert-100 text-vert-800',
    attention: 'bg-ocre-100 text-ocre-900',
    alerte: 'bg-brique-100 text-brique-800',
    principal: 'bg-pnvb-100 text-pnvb-800',
};

/**
 * UNE TUILE CHIFFRÉE.
 *
 * L'icône situe la tuile d'un coup d'œil ; elle ne porte aucune information que
 * le libellé ne dise déjà. La PRÉCISION sous le chiffre n'est pas décorative :
 * « pic régional, jamais un cumul » change le sens du nombre au-dessus.
 */
export function Tuile({ icone, libelle, valeur, precision, ton = 'principal' }) {
    return (
        <div className="rounded-xl border border-ardoise-200 bg-white p-4 shadow-sm">
            {icone && (
                <span className={`mb-3 inline-flex h-9 w-9 items-center justify-center rounded-lg ${TONS[ton] ?? TONS.principal}`}>
                    <Icone nom={icone} />
                </span>
            )}
            <p className="text-xs font-medium uppercase tracking-wide text-ardoise-600">{libelle}</p>
            <p className="mt-1 text-2xl font-semibold tabular-nums text-ardoise-900">{valeur}</p>
            {precision && <p className="mt-1 text-xs leading-snug text-ardoise-600">{precision}</p>}
        </div>
    );
}

/** Un panneau titré : l'unité de composition du tableau de bord. */
export function Panneau({ titre, precision, icone, actions, children, className = '' }) {
    return (
        <section className={`rounded-xl border border-ardoise-200 bg-white shadow-sm ${className}`}>
            <header className="flex flex-wrap items-start justify-between gap-3 border-b border-ardoise-200 px-4 py-3">
                <div className="flex items-start gap-2.5">
                    {icone && (
                        <span className="mt-0.5 inline-flex h-7 w-7 items-center justify-center rounded-lg bg-pnvb-50 text-pnvb-700">
                            <Icone nom={icone} className="h-4 w-4" />
                        </span>
                    )}
                    <div>
                        <h3 className="text-sm font-semibold text-ardoise-900">{titre}</h3>
                        {precision && <p className="mt-0.5 max-w-prose text-xs text-ardoise-600">{precision}</p>}
                    </div>
                </div>
                {actions}
            </header>
            <div className="p-4">{children}</div>
        </section>
    );
}

/**
 * UNE JAUGE D'AVANCEMENT.
 *
 * Elle porte toujours ses deux nombres — la part ET le total — parce qu'une
 * barre à moitié pleine ne dit pas si elle vaut 3 sur 6 ou 3 000 sur 6 000.
 */
export function Jauge({ libelle, valeur, total, precision, ton = 'principal' }) {
    const part = total > 0 ? Math.min(100, (valeur / total) * 100) : 0;

    const couleurs = {
        principal: 'bg-pnvb-600',
        bon: 'bg-vert-600',
        attention: 'bg-ocre-600',
        alerte: 'bg-brique-600',
    };

    return (
        <div>
            <div className="flex items-baseline justify-between gap-3">
                <span className="text-sm font-medium text-ardoise-800">{libelle}</span>
                <span className="text-sm tabular-nums text-ardoise-900">
                    {total > 0 ? `${valeur} / ${total}` : valeur}
                </span>
            </div>
            <div className="mt-1.5 h-2 w-full overflow-hidden rounded-full bg-ardoise-100">
                <div
                    className={`h-full rounded-full ${couleurs[ton] ?? couleurs.principal}`}
                    style={{ width: `${part}%` }}
                    role="progressbar"
                    aria-valuenow={valeur}
                    aria-valuemin={0}
                    aria-valuemax={total || undefined}
                    aria-label={libelle}
                />
            </div>
            {precision && <p className="mt-1 text-xs text-ardoise-600">{precision}</p>}
        </div>
    );
}
