import { Navigate, useLocation } from 'react-router-dom';
import { useAuth } from './ContexteAuth';
import { Chargement } from '../composants/Chargement';

/**
 * LE GARDE DE ROUTE.
 *
 * Trois barrières, dans l'ordre où le SERVEUR lui-même les applique :
 *
 *   1. être connecté ;
 *   2. avoir changé son mot de passe provisoire ;
 *   3. avoir accepté la charte du volontaire.
 *
 * Les middlewares refusent tout le reste tant que 2 et 3 ne sont pas levées.
 * Rediriger ici évite à l'agent de se heurter à des refus qu'il ne
 * comprendrait pas — mais ne remplace jamais ces middlewares.
 */
export function Garde({ permission, permissions, children }) {
    const auth = useAuth();
    const emplacement = useLocation();

    if (auth.chargement) {
        return <Chargement message="Vérification de votre session…" />;
    }

    if (!auth.connecte) {
        // On retient d'où venait l'agent : après connexion, il reprend là
        // plutôt que d'être renvoyé à l'accueil.
        return <Navigate to="/connexion" state={{ depuis: emplacement.pathname }} replace />;
    }

    if (auth.doitChangerMotDePasse) {
        return <Navigate to="/premiere-connexion/mot-de-passe" replace />;
    }

    if (auth.doitAccepterCharte) {
        return <Navigate to="/premiere-connexion/charte" replace />;
    }

    const requises = permissions ?? (permission ? [permission] : []);

    if (requises.length > 0 && !auth.peutAuMoins(...requises)) {
        return <Navigate to="/acces-refuse" replace />;
    }

    return children;
}

/**
 * L'inverse : une page réservée à qui n'est PAS encore entré (la connexion).
 * Sans cela, un agent déjà connecté pourrait revenir sur le formulaire et
 * croire que sa session est perdue.
 */
export function GardeVisiteur({ children }) {
    const auth = useAuth();

    if (auth.chargement) {
        return <Chargement message="Vérification de votre session…" />;
    }

    if (auth.connecte) {
        return <Navigate to="/" replace />;
    }

    return children;
}
