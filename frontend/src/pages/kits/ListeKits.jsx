import { Link } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';
import { api } from '../../api/client';
import { useListe } from '../../outils/crochets';
import { EnTetePage, Indicateur } from '../../composants/Page';
import { Pagination, Pastille, Tableau } from '../../composants/Tableau';
import { BarreFiltres, FiltreListe, FiltreTexte } from '../../composants/Filtres';
import { Chargement } from '../../composants/Chargement';
import { Echec, Vide } from '../../composants/Etats';
import { nombre, nomDe } from '../../outils/format';
import { etatKit, etatsKit } from '../../domaine/kits';

/**
 * LE PARC DE KITS.
 *
 * LE KIT SUIT LA PERSONNE, PAS LE SITE : la colonne qui compte est donc le
 * DÉTENTEUR. Le site courant est une information de localisation, pas de
 * rattachement.
 */
export function ListeKits() {
    const liste = useListe('kits', '/kits');
    const synthese = useQuery({ queryKey: ['kits-synthese'], queryFn: () => api.lire('/kits/synthese') });

    return (
        <>
            <EnTetePage
                titre="Parc de kits"
                sousTitre="Un kit est rattaché à un agent, pas à un site : il le suit d’une région à l’autre."
                actions={
                    <Link
                        to="/kits/non-restitues"
                        className="rounded border border-ocre-400 bg-ocre-50 px-3 py-2 text-sm font-medium text-ocre-900 hover:bg-ocre-100"
                    >
                        Kits à récupérer
                    </Link>
                }
            />

            {synthese.data && (
                <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                    <Indicateur libelle="Kits dans le périmètre" valeur={nombre(synthese.data.total)} />
                    <Indicateur libelle="Attribués" valeur={nombre(synthese.data.attribues)} />
                    <Indicateur libelle="Disponibles" valeur={nombre(synthese.data.disponibles)} />
                    <Indicateur
                        libelle="Non restitués"
                        valeur={nombre(synthese.data.non_restitues)}
                        precision="Mission terminée depuis plus que le délai de grâce"
                        ton={synthese.data.non_restitues > 0 ? 'alerte' : 'bon'}
                    />
                </div>
            )}

            <BarreFiltres onReinitialiser={liste.reinitialiser}>
                <FiltreTexte
                    libelle="Référence"
                    valeur={liste.filtres.reference}
                    onChange={(v) => liste.changerFiltre('reference', v)}
                    placeholder="KIT-…"
                />
                <FiltreListe
                    libelle="État"
                    valeur={liste.filtres.etat}
                    onChange={(v) => liste.changerFiltre('etat', v)}
                    options={Object.entries(etatsKit).map(([valeur, e]) => ({ valeur, libelle: e.libelle }))}
                />
                <FiltreListe
                    libelle="Attribution"
                    valeur={liste.filtres.disponibles}
                    onChange={(v) => liste.changerFiltre('disponibles', v)}
                    options={[{ valeur: '1', libelle: 'Disponibles seulement' }]}
                />
            </BarreFiltres>

            {liste.isPending && <Chargement message="Chargement du parc…" />}
            {liste.error && <Echec erreur={liste.error} onReessayer={liste.refetch} />}

            {!liste.isPending && !liste.error && (
                <>
                    <Tableau
                        cle={(k) => k.id}
                        lignes={liste.lignes}
                        vide={<Vide titre="Aucun kit ne correspond" explication="Aucun kit de votre périmètre ne répond à ces filtres." />}
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
                            {
                                cle: 'etat',
                                titre: 'État',
                                compact: true,
                                rendu: (k) => <Pastille ton={etatKit(k.etat).ton}>{etatKit(k.etat).libelle}</Pastille>,
                            },
                            {
                                cle: 'detenteur',
                                titre: 'Détenteur',
                                rendu: (k) =>
                                    k.detenteur ? `${k.detenteur.matricule} — ${nomDe(k.detenteur.user)}` : (
                                        <span className="text-ardoise-400">au parc</span>
                                    ),
                            },
                            { cle: 'centre', titre: 'Centre', rendu: (k) => k.centre_courant?.code ?? '—' },
                            { cle: 'site', titre: 'Site courant', rendu: (k) => k.site_courant?.nom ?? '—' },
                            {
                                cle: 'zone',
                                titre: '',
                                compact: true,
                                rendu: (k) => (k.est_permanent_zone_defis ? <Pastille ton="info">zone à défis</Pastille> : null),
                            },
                        ]}
                    />
                    <Pagination page={liste.pagination} onPage={liste.setPage} />
                </>
            )}
        </>
    );
}
