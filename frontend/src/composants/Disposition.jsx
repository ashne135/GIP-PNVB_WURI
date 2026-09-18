import { NavLink, Outlet, useNavigate } from 'react-router-dom';
import { useState } from 'react';
import { useAuth } from '../auth/ContexteAuth';
import { menuPour } from '../navigation/entrees';
import { Pastille } from './Tableau';
import { nomDe } from '../outils/format';
import { fichierPublic } from '../outils/chemins';

/**
 * LA MISE EN PAGE DU BACK-OFFICE.
 *
 * Barre latérale fixe aux couleurs du logo — le vert du Programme — et bandeau
 * clair au-dessus du contenu. La barre porte le logo : sur une plateforme
 * publique, savoir chez qui l'on est ne se déduit pas d'une adresse.
 *
 * Le bandeau du haut porte en permanence QUI est connecté et sur QUEL
 * PÉRIMÈTRE. Sur une plateforme où le même écran montre une région ou les
 * douze selon le compte, ne pas l'afficher revient à laisser le lecteur
 * deviner ce qu'il regarde.
 *
 * Les icônes sont écrites à la main, en SVG : aucune bibliothèque à télécharger
 * pour une vingtaine de pictogrammes.
 */
const ICONES = {
    '/': ['M3 3h7v7H3z', 'M14 3h7v7h-7z', 'M3 14h7v7H3z', 'M14 14h7v7h-7z'],
    '/alertes': ['M6 9a6 6 0 1112 0c0 4 1.5 5 1.5 5h-15S6 13 6 9z', 'M10 19a2 2 0 004 0'],
    '/exports': ['M12 3v12', 'M8 11l4 4 4-4', 'M4 19h16'],
    '/presences': ['M9 11a4 4 0 100-8 4 4 0 000 8z', 'M2 21a7 7 0 0114 0', 'M16 13l2 2 4-4'],
    '/rapports': ['M7 3h7l5 5v13H7z', 'M14 3v5h5', 'M10 13h6', 'M10 17h6'],
    '/incidents': ['M12 4l9 16H3z', 'M12 10v4', 'M12 17h.01'],
    '/vagues': ['M12 3l9 5-9 5-9-5z', 'M3 13l9 5 9-5'],
    '/equipes': [
        'M9 11a3 3 0 100-6 3 3 0 000 6z',
        'M3 20v-1a4 4 0 014-4h4a4 4 0 014 4v1',
        'M17 11a2.5 2.5 0 100-5',
        'M18 20v-1a3.5 3.5 0 00-2-3.2',
    ],
    '/tournees': [
        'M6 20a2.5 2.5 0 100-5 2.5 2.5 0 000 5z',
        'M18 9a2.5 2.5 0 100-5 2.5 2.5 0 000 5z',
        'M9 17.5h5a3.5 3.5 0 000-7h-4a3.5 3.5 0 010-7h5',
    ],
    '/volontaires': ['M9 11a4 4 0 100-8 4 4 0 000 8z', 'M2 21a7 7 0 0114 0', 'M17 11a3 3 0 100-6', 'M19 21a5 5 0 00-3-4.6'],
    '/kits': ['M3 8l9-5 9 5v8l-9 5-9-5z', 'M3 8l9 5 9-5', 'M12 13v8'],
    '/centres': ['M12 21s7-6.3 7-11a7 7 0 10-14 0c0 4.7 7 11 7 11z', 'M12 10a2 2 0 100-4 2 2 0 000 4z'],
    '/parametres': ['M4 7h16', 'M4 12h16', 'M4 17h16', 'M9 5v4', 'M16 10v4', 'M11 15v4'],
    '/journal': ['M12 21a9 9 0 110-18 9 9 0 010 18z', 'M12 7.5V12l3 2'],
    '/appreciations': ['M4 5h16v11H9l-5 4z', 'M8 9h8', 'M8 12h5'],
    '/territoire': ['M3 6l6-3 6 3 6-3v15l-6 3-6-3-6 3z', 'M9 3v15', 'M15 6v15'],
    '/administration/comptes': ['M10 11a4 4 0 100-8 4 4 0 000 8z', 'M3 21a7 7 0 0111-5.7', 'M18 14v6', 'M15 17h6'],
    '/administration/incidents': ['M9 6h11', 'M9 12h11', 'M9 18h11', 'M4 6h.01', 'M4 12h.01', 'M4 18h.01'],
    '/administration/synchronisations': ['M20 11a8 8 0 00-14.9-4', 'M4 4v4h4', 'M4 13a8 8 0 0014.9 4', 'M20 20v-4h-4'],
    defaut: ['M5 5h14v14H5z'],
};

function Icone({ chemin }) {
    return (
        <svg
            viewBox="0 0 24 24"
            className="h-5 w-5 shrink-0"
            fill="none"
            stroke="currentColor"
            strokeWidth="1.7"
            strokeLinecap="round"
            strokeLinejoin="round"
            aria-hidden="true"
        >
            {(ICONES[chemin] ?? ICONES.defaut).map((trace) => (
                <path key={trace} d={trace} />
            ))}
        </svg>
    );
}

export function Disposition() {
    const auth = useAuth();
    const naviguer = useNavigate();
    const [menuOuvert, setMenuOuvert] = useState(false);

    const rubriques = menuPour(auth.peutAuMoins);

    async function seDeconnecter() {
        await auth.deconnecter();
        naviguer('/connexion', { replace: true });
    }

    return (
        <div className="min-h-screen bg-ardoise-50 font-sans text-ardoise-900">
            {menuOuvert && (
                <button
                    type="button"
                    aria-label="Fermer le menu"
                    onClick={() => setMenuOuvert(false)}
                    className="fixed inset-0 z-30 bg-ardoise-900/50 lg:hidden"
                />
            )}

            <aside
                className={`fixed inset-y-0 left-0 z-40 flex w-64 flex-col bg-pnvb-900 text-pnvb-100
                    transition-transform duration-200 lg:translate-x-0 ${
                        menuOuvert ? 'translate-x-0' : '-translate-x-full'
                    }`}
            >
                <div className="flex items-center gap-3 border-b border-pnvb-800 px-4 py-4">
                    <img
                        src={fichierPublic('logo-pnvb.jpg')}
                        alt="Programme national de volontariat au Burkina Faso"
                        className="h-12 w-12 rounded-md bg-white object-contain p-1"
                    />
                    <div className="min-w-0">
                        <p className="truncate text-sm font-semibold text-white">GIP-PNVB</p>
                        <p className="truncate text-xs text-pnvb-200">Projet WURI</p>
                    </div>
                </div>

                <nav className="flex-1 overflow-y-auto px-3 py-4" aria-label="Navigation principale">
                    {rubriques.map((rubrique) => (
                        <div key={rubrique.titre} className="mb-5">
                            <p className="mb-1.5 px-3 text-[11px] font-semibold uppercase tracking-wider text-pnvb-300">
                                {rubrique.titre}
                            </p>
                            <ul className="space-y-0.5">
                                {rubrique.liens.map((lien) => (
                                    <li key={lien.chemin}>
                                        {lien.aVenir ? (
                                            <span
                                                className="flex items-center gap-3 rounded border-l-4 border-transparent px-3 py-2 text-sm text-pnvb-400"
                                                title="Écran prévu, pas encore construit"
                                            >
                                                <Icone chemin={lien.chemin} />
                                                <span className="flex-1">{lien.libelle}</span>
                                                <span className="text-[10px] uppercase tracking-wide">à venir</span>
                                            </span>
                                        ) : (
                                            <NavLink
                                                to={lien.chemin}
                                                end={lien.chemin === '/'}
                                                onClick={() => setMenuOuvert(false)}
                                                className={({ isActive }) =>
                                                    `flex items-center gap-3 rounded border-l-4 px-3 py-2 text-sm transition ${
                                                        isActive
                                                            ? 'border-or-400 bg-pnvb-700 font-medium text-white'
                                                            : 'border-transparent text-pnvb-100 hover:bg-pnvb-800 hover:text-white'
                                                    }`
                                                }
                                            >
                                                <Icone chemin={lien.chemin} />
                                                <span>{lien.libelle}</span>
                                            </NavLink>
                                        )}
                                    </li>
                                ))}
                            </ul>
                        </div>
                    ))}
                </nav>

                <p className="border-t border-pnvb-800 px-4 py-3 text-[11px] leading-snug text-pnvb-300">
                    Plateforme de gestion des volontaires
                </p>
            </aside>

            <div className="lg:pl-64">
                <header className="sticky top-0 z-20 border-b border-ardoise-200 bg-white">
                    <div className="flex flex-wrap items-center gap-x-5 gap-y-3 px-4 py-3">
                        <button
                            type="button"
                            onClick={() => setMenuOuvert((ouvert) => !ouvert)}
                            className="rounded border border-ardoise-300 px-2.5 py-1.5 text-sm lg:hidden"
                            aria-expanded={menuOuvert}
                        >
                            Menu
                        </button>

                        <BandeauPerimetre auth={auth} />

                        <div className="ml-auto flex items-center gap-3 border-l border-ardoise-200 pl-4">
                            <div className="text-right">
                                <p className="text-sm font-medium">{nomDe(auth.utilisateur)}</p>
                                <p className="text-xs text-ardoise-500">
                                    {auth.volontaire?.matricule ?? auth.utilisateur?.telephone}
                                </p>
                            </div>
                            <button
                                type="button"
                                onClick={seDeconnecter}
                                className="rounded border border-ardoise-300 px-3 py-1.5 text-sm hover:bg-ardoise-50"
                            >
                                Quitter
                            </button>
                        </div>
                    </div>
                </header>

                <main className="mx-auto max-w-7xl space-y-5 px-4 py-6">
                    <Outlet />
                </main>
            </div>
        </div>
    );
}

/**
 * LE PÉRIMÈTRE, affiché en permanence.
 *
 * « National » ou « Région du Nakambé » change complètement le sens des
 * chiffres à l'écran. Un observateur voit en plus qu'il est en lecture seule,
 * pour qu'il ne cherche pas un bouton d'action qui n'existera jamais pour lui.
 */
function BandeauPerimetre({ auth }) {
    const perimetre = auth.perimetre;

    if (!perimetre) {
        return null;
    }

    const regions = perimetre.regions ?? [];
    const intitule = auth.estNational
        ? 'National — 12 régions'
        : regions.length === 1
          ? regions[0].nom
          : perimetre.libelle;

    return (
        <div className="flex items-center gap-2 text-sm">
            <Pastille ton={auth.estNational ? 'info' : 'neutre'}>{intitule}</Pastille>
            {auth.estLectureSeule && <Pastille ton="attention">Lecture seule</Pastille>}
            {!auth.utilisateur?.peut_saisir && !auth.estLectureSeule && (
                <Pastille ton="attention">Saisie fermée</Pastille>
            )}
        </div>
    );
}
