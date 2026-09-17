import { Link } from 'react-router-dom';

export function AccesRefuse() {
    return (
        <div className="mx-auto max-w-lg rounded border border-ocre-300 bg-ocre-50 px-6 py-10 text-center">
            <p className="text-lg font-semibold text-ocre-900">Cette page ne vous est pas ouverte</p>
            <p className="mt-2 text-sm text-ocre-800">
                Votre compte n’a pas les droits nécessaires, ou cette information est hors de votre
                périmètre. Si vous pensez que c’est une erreur, adressez-vous à votre chef
                d’antenne régional.
            </p>
            <Link
                to="/"
                className="mt-5 inline-block rounded bg-pnvb-700 px-4 py-2 text-sm font-medium text-white hover:bg-pnvb-800"
            >
                Retour au tableau de bord
            </Link>
        </div>
    );
}

export function Introuvable() {
    return (
        <div className="mx-auto max-w-lg rounded border border-ardoise-300 bg-white px-6 py-10 text-center">
            <p className="text-lg font-semibold text-ardoise-900">Cette page n’existe pas</p>
            <p className="mt-2 text-sm text-ardoise-600">
                L’adresse demandée ne correspond à aucun écran du back-office.
            </p>
            <Link
                to="/"
                className="mt-5 inline-block rounded bg-pnvb-700 px-4 py-2 text-sm font-medium text-white hover:bg-pnvb-800"
            >
                Retour au tableau de bord
            </Link>
        </div>
    );
}
