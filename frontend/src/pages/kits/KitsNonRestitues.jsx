import { Link } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';
import { api } from '../../api/client';
import { EnTetePage } from '../../composants/Page';
import { Tableau } from '../../composants/Tableau';
import { Chargement } from '../../composants/Chargement';
import { Echec, Vide } from '../../composants/Etats';
import { nomDe } from '../../outils/format';

/**
 * LES KITS À ALLER RÉCUPÉRER — nominatifs.
 *
 * « 14 kits non restitués » n'aide personne à les chercher. Cette liste donne
 * pour chacun le détenteur, son matricule et son centre : de quoi décrocher le
 * téléphone.
 */
export function KitsNonRestitues() {
    const { data, isPending, error, refetch } = useQuery({
        queryKey: ['kits-non-restitues'],
        queryFn: () => api.lire('/kits/non-restitues'),
    });

    return (
        <>
            <EnTetePage
                titre="Kits à récupérer"
                sousTitre="Détenus par des agents dont la mission est terminée depuis plus que le délai de grâce."
                actions={
                    <Link to="/kits" className="rounded border border-ardoise-300 bg-white px-3 py-2 text-sm hover:bg-ardoise-50">
                        Tout le parc
                    </Link>
                }
            />

            {isPending && <Chargement message="Recherche des kits non restitués…" />}
            {error && <Echec erreur={error} onReessayer={refetch} />}

            {!isPending && !error && (
                <Tableau
                    cle={(k) => k.id}
                    lignes={data ?? []}
                    vide={<Vide titre="Aucun kit à récupérer" explication="Tous les kits de votre périmètre sont rendus ou encore en mission." />}
                    colonnes={[
                        {
                            cle: 'reference',
                            titre: 'Référence',
                            compact: true,
                            rendu: (k) => (
                                <Link to={`/kits/${k.id}`} className="font-mono font-medium text-pnvb-800 underline">
                                    {k.reference}
                                </Link>
                            ),
                        },
                        { cle: 'matricule', titre: 'Matricule', compact: true, rendu: (k) => k.detenteur?.matricule ?? '—' },
                        { cle: 'nom', titre: 'Détenteur', rendu: (k) => nomDe(k.detenteur?.user) },
                        { cle: 'centre', titre: 'Centre', rendu: (k) => (k.centre_courant ? `${k.centre_courant.code} — ${k.centre_courant.nom}` : '—') },
                    ]}
                />
            )}
        </>
    );
}
