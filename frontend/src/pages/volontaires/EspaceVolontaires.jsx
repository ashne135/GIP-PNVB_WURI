import { NavLink, Outlet } from 'react-router-dom';
import { useAuth } from '../../auth/ContexteAuth';
import { EnTetePage } from '../../composants/Page';

/**
 * LES VOLONTAIRES : le registre, et le chemin qui y mène.
 *
 * L'ordre des onglets est celui du travail : on IMPORTE les retenus, on
 * attribue un PROFIL aux fiches arrivées sans, on planifie une vague — et
 * l'affectation ouvre l'accès, dont on suit ensuite la REMISE.
 *
 * Un onglet sans droit disparaît. Ce n'est qu'une politesse : le serveur
 * revérifie chaque droit, et c'est lui qui refuse.
 */
export function EspaceVolontaires() {
    const auth = useAuth();

    const onglet = ({ isActive }) =>
        `border-b-2 px-3 py-2 text-sm ${
            isActive ? 'border-pnvb-700 font-medium text-pnvb-900' : 'border-transparent text-ardoise-600 hover:text-ardoise-900'
        }`;

    return (
        <>
            <EnTetePage
                titre="Volontaires"
                sousTitre="Les trois catégories sont étanches : un assistant ne devient jamais opérateur."
            />
            <nav className="flex flex-wrap gap-1 border-b border-ardoise-200" aria-label="Volontaires">
                <NavLink to="/volontaires" end className={onglet}>Registre</NavLink>
                {auth.peut('volontaires.importer') && (
                    <NavLink to="/volontaires/import" className={onglet}>Importer les retenus</NavLink>
                )}
                {auth.peut('volontaires.qualifier') && (
                    <NavLink to="/volontaires/a-qualifier" className={onglet}>Profils à attribuer</NavLink>
                )}
                {auth.peut('comptes.consulter') && (
                    <NavLink to="/volontaires/identifiants" className={onglet}>Identifiants</NavLink>
                )}
            </nav>
            <div className="space-y-4 pt-4">
                <Outlet />
            </div>
        </>
    );
}
