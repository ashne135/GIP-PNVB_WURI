import { useEffect, useState } from 'react';
import { api } from '../api/client';
import { dateHeure } from '../outils/format';

/**
 * LES PHOTOS REMONTÉES DU TERRAIN.
 *
 * Une pièce jointe n'est jamais servie par une adresse publique : le fichier
 * est hors du dossier public, et le serveur vérifie le droit de le voir à
 * chaque requête. Le navigateur doit donc la demander AVEC son jeton, puis
 * l'afficher depuis la mémoire — d'où le passage par un blob plutôt qu'un
 * `src` direct.
 */
const roles = {
    preuve_incident: 'Preuve de l’incident',
    constat_source: 'Constat — celui qui remet le kit',
    constat_destination: 'Constat — celui qui reçoit le kit',
};

export function PhotosJointes({ pieces, vide = 'Aucune photo.' }) {
    if (!pieces || pieces.length === 0) {
        return <p className="text-sm text-ardoise-500">{vide}</p>;
    }

    return (
        <div className="mt-3 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
            {pieces.map((piece) => (
                <Photo key={piece.id} piece={piece} />
            ))}
        </div>
    );
}

function Photo({ piece }) {
    const [adresse, setAdresse] = useState(null);
    const [erreur, setErreur] = useState(null);

    useEffect(() => {
        let vivant = true;
        let objet = null;

        api.telecharger(`/pieces-jointes/${piece.id}`)
            .then(({ fichier }) => {
                if (!vivant) {
                    return;
                }

                objet = URL.createObjectURL(fichier);
                setAdresse(objet);
            })
            .catch((echec) => {
                if (vivant) {
                    setErreur(echec);
                }
            });

        // La mémoire est rendue quand la fiche se ferme : sans cela, chaque
        // consultation en retiendrait un peu plus.
        return () => {
            vivant = false;

            if (objet) {
                URL.revokeObjectURL(objet);
            }
        };
    }, [piece.id]);

    const libelle = roles[piece.role] ?? piece.role;

    return (
        <figure className="m-0 border border-ardoise-200 bg-white">
            <div className="flex aspect-[4/3] items-center justify-center bg-ardoise-50">
                {adresse && (
                    <a href={adresse} target="_blank" rel="noreferrer" className="block h-full w-full">
                        <img src={adresse} alt={libelle} className="h-full w-full object-cover" />
                    </a>
                )}
                {!adresse && !erreur && <span className="text-xs text-ardoise-500">Chargement…</span>}
                {erreur && (
                    <span className="px-3 text-center text-xs text-ardoise-600">
                        Cette photo n’a pas pu être affichée. {erreur.message}
                    </span>
                )}
            </div>
            <figcaption className="px-3 py-2 text-xs text-ardoise-600">
                <span className="block font-medium text-ardoise-800">{libelle}</span>
                {piece.horodatage_telephone && <span>Prise le {dateHeure(piece.horodatage_telephone)}</span>}
                {!piece.horodatage_telephone && piece.deposee_le && <span>Reçue le {dateHeure(piece.deposee_le)}</span>}
                {piece.taille_octets != null && <span> · {Math.round(piece.taille_octets / 1024)} Ko</span>}
            </figcaption>
        </figure>
    );
}
