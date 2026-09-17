import { NavLink, Outlet } from 'react-router-dom';
import { useAuth } from '../../auth/ContexteAuth';
import { EnTetePage } from '../../composants/Page';

/**
 * LE RÉFÉRENTIEL : centres, sites, et leur import.
 *
 * Un centre est rattaché à une commune ; un site porte deux rattachements —
 * sa localité pour la population, son centre pour la supervision. Les codes
 * sont générés par le serveur et définitifs : ils apparaissent sur les
 * documents opposables.
 */
export function Referentiel() {
    const auth = useAuth();

    const onglet = ({ isActive }) =>
        `border-b-2 px-3 py-2 text-sm ${
            isActive ? 'border-pnvb-700 font-medium text-pnvb-900' : 'border-transparent text-ardoise-600 hover:text-ardoise-900'
        }`;

    return (
        <>
            <EnTetePage
                titre="Centres et sites"
                sousTitre="Les codes sont attribués par le serveur et ne changent jamais : ils figurent sur les documents."
            />
            <nav className="flex flex-wrap gap-1 border-b border-ardoise-200" aria-label="Référentiel">
                <NavLink to="/centres" end className={onglet}>Centres</NavLink>
                <NavLink to="/sites" end className={onglet}>Sites</NavLink>
                {auth.peut('referentiel.importer') && (
                    <NavLink to="/centres/import" className={onglet}>Importer</NavLink>
                )}
            </nav>
            <div className="space-y-4 pt-4">
                <Outlet />
            </div>
        </>
    );
}
