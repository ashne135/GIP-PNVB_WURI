import { Link } from 'react-router-dom';

/**
 * LES TROIS ÉTATS QU'UNE PAGE DOIT SAVOIR AFFICHER, et qu'on oublie toujours :
 * il n'y a rien, ça a échoué, ou c'est refusé.
 *
 * Chacun dit quoi faire ensuite. Un écran vide sans explication est le plus sûr
 * moyen de faire croire à une panne là où il n'y a simplement rien.
 */

export function Vide({ titre, explication, action }) {
    return (
        <div className="rounded border border-dashed border-ardoise-300 bg-white px-6 py-12 text-center">
            <p className="text-sm font-semibold text-ardoise-700">{titre}</p>
            {explication && (
                <p className="mx-auto mt-2 max-w-prose text-sm text-ardoise-500">{explication}</p>
            )}
            {action && <div className="mt-4">{action}</div>}
        </div>
    );
}

export function Echec({ erreur, onReessayer }) {
    // Un refus de droit n'est pas une panne : on ne propose pas de réessayer,
    // ce serait promettre que ça marchera la prochaine fois.
    const refus = erreur?.estRefus;

    return (
        <div
            className={`rounded border px-4 py-4 text-sm ${
                refus
                    ? 'border-ocre-300 bg-ocre-50 text-ocre-900'
                    : 'border-brique-300 bg-brique-50 text-brique-900'
            }`}
            role="alert"
        >
            <p className="font-semibold">
                {refus ? 'Action non autorisée' : 'Cette information n’a pas pu être chargée'}
            </p>
            <p className="mt-1">{erreur?.message ?? 'Une erreur est survenue.'}</p>
            {!refus && onReessayer && (
                <button
                    type="button"
                    onClick={onReessayer}
                    className="mt-3 rounded border border-brique-400 px-3 py-1.5 font-medium hover:bg-brique-100"
                >
                    Réessayer
                </button>
            )}
        </div>
    );
}

/** Le message de succès du serveur, affiché tel quel : il est déjà précis. */
export function Succes({ message, onFermer }) {
    if (!message) {
        return null;
    }

    return (
        <div
            className="flex items-start justify-between gap-4 rounded border border-vert-100 bg-vert-50 px-4 py-3 text-sm text-vert-800"
            role="status"
        >
            <p>{message}</p>
            {onFermer && (
                <button
                    type="button"
                    onClick={onFermer}
                    className="shrink-0 text-vert-700 hover:text-vert-800"
                    aria-label="Fermer le message"
                >
                    ×
                </button>
            )}
        </div>
    );
}
