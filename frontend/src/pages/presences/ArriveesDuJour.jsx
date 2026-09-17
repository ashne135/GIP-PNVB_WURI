import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { api, avecParametres } from '../../api/client';
import { Indicateur } from '../../composants/Page';
import { Pastille, Tableau } from '../../composants/Tableau';
import { FiltreDate } from '../../composants/Filtres';
import { Chargement } from '../../composants/Chargement';
import { Echec, Vide } from '../../composants/Etats';
import { aujourdhui, nombre } from '../../outils/format';

/**
 * LES ARRIVÉES DU JOUR — la carte du superviseur, en tableau.
 *
 *   VERT   : arrivée signalée dans la zone du site
 *   ORANGE : arrivée signalée hors zone, avec la distance
 *   GRIS   : aucun signal — une absence d'INFORMATION, pas une absence
 *
 * La carte Leaflet arrive en tâche 15 ; les données, elles, sont déjà là. Le
 * tableau les montre dans l'ordre où elles appellent une action : les gris
 * d'abord, puis les orange.
 *
 * Aucune trace de déplacement n'est exposée : seul le DERNIER signal d'arrivée
 * de chaque agent, pour la journée.
 */
export function ArriveesDuJour() {
    const [jour, setJour] = useState(aujourdhui());

    const { data, isPending, error, refetch } = useQuery({
        queryKey: ['carte-presence', jour],
        queryFn: () => api.lire(avecParametres('/presence/carte', { date: jour })),
    });

    const ordre = { gris: 0, orange: 1, vert: 2 };
    const tons = { vert: 'bon', orange: 'attention', gris: 'neutre' };
    const libelles = { vert: 'dans la zone', orange: 'hors zone', gris: 'aucun signal' };

    const agents = [...(data?.agents ?? [])].sort((a, b) => ordre[a.couleur] - ordre[b.couleur]);

    return (
        <div className="space-y-4 pt-4">
            <div className="flex flex-wrap items-end justify-between gap-3">
                <FiltreDate libelle="Journée" valeur={jour} onChange={(v) => setJour(v || aujourdhui())} />
            </div>

            {isPending && <Chargement message="Chargement des arrivées…" />}
            {error && <Echec erreur={error} onReessayer={refetch} />}

            {data && (
                <>
                    <div className="grid gap-3 sm:grid-cols-3">
                        <Indicateur libelle="Dans la zone" valeur={nombre(data.resume?.vert)} ton="bon" />
                        <Indicateur libelle="Hors zone" valeur={nombre(data.resume?.orange)} ton={data.resume?.orange > 0 ? 'attention' : 'neutre'} precision="Arrivée signalée loin du site" />
                        <Indicateur libelle="Aucun signal" valeur={nombre(data.resume?.gris)} precision="Pas encore signalé — ce n’est pas une absence" />
                    </div>

                    <Tableau
                        cle={(a) => a.volontaire_id}
                        lignes={agents}
                        vide={<Vide titre="Aucun agent attendu ce jour-là" explication="Aucune tournée ne couvre les sites de vos centres à cette date." />}
                        colonnes={[
                            { cle: 'couleur', titre: 'Signal', compact: true, rendu: (a) => <Pastille ton={tons[a.couleur]}>{libelles[a.couleur]}</Pastille> },
                            { cle: 'matricule', titre: 'Matricule', compact: true, rendu: (a) => <span className="font-mono">{a.matricule}</span> },
                            { cle: 'nom_complet', titre: 'Nom et prénoms' },
                            { cle: 'site_code', titre: 'Site', compact: true },
                            { cle: 'heure_arrivee', titre: 'Arrivée', compact: true, rendu: (a) => a.heure_arrivee ?? '—' },
                            { cle: 'distance', titre: 'Distance', alignement: 'droite', rendu: (a) => (a.distance_metres == null ? '—' : `${nombre(a.distance_metres)} m`) },
                        ]}
                    />
                </>
            )}
        </div>
    );
}
