import { NavLink, Outlet } from 'react-router-dom';
import { useAuth } from '../../auth/ContexteAuth';
import { EnTetePage } from '../../composants/Page';

/**
 * PRÉSENCES : deux mécanismes, et une règle qu'on ne doit jamais brouiller.
 *
 *   LA CARTE DU JOUR montre les SIGNAUX d'arrivée — ce que l'agent déclare.
 *   LES FEUILLES montrent ce que le superviseur a VALIDÉ — la seule pièce qui
 *   fait foi.
 *
 * Les deux vivent sur des onglets distincts, et le bandeau le rappelle : un
 * point vert sur la carte n'est pas une présence.
 */
export function Presences() {
    const auth = useAuth();

    const onglet = ({ isActive }) =>
        `rounded-t border-b-2 px-3 py-2 text-sm ${
            isActive ? 'border-pnvb-700 font-medium text-pnvb-900' : 'border-transparent text-ardoise-600 hover:text-ardoise-900'
        }`;

    return (
        <>
            <EnTetePage
                titre="Présences"
                sousTitre="Le signal d’arrivée n’est pas un pointage : seule la feuille validée par le superviseur fait foi."
            />
            <nav className="flex gap-1 border-b border-ardoise-200" aria-label="Présences">
                {auth.peut('presence.consulter_feuille') && (
                    <NavLink to="/presences" end className={onglet}>
                        Feuilles de présence
                    </NavLink>
                )}
                {auth.peut('presence.consulter_carte') && (
                    <NavLink to="/presences/carte" className={onglet}>
                        Arrivées du jour
                    </NavLink>
                )}
            </nav>
            <Outlet />
        </>
    );
}

