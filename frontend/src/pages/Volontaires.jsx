import { useListe } from '../outils/crochets';
import { EnTetePage } from '../composants/Page';
import { Pagination, Pastille, Tableau } from '../composants/Tableau';
import { BarreFiltres, FiltreListe } from '../composants/Filtres';
import { Chargement } from '../composants/Chargement';
import { Echec, Vide } from '../composants/Etats';
import { humaniser, nomDe } from '../outils/format';

/**
 * LE REGISTRE DES VOLONTAIRES.
 *
 * LE NUMÉRO CNIB N'APPARAÎT PAS ICI, et ce n'est pas un oubli : le serveur ne
 * le sérialise jamais dans une liste — il est masqué côté modèle et réservé à
 * l'administration nationale sur une fiche individuelle. Le cadrage l'exclut
 * explicitement des listes opérationnelles.
 *
 * Le périmètre est appliqué côté serveur : un chef d'antenne voit sa région.
 */
export function Volontaires() {
    const liste = useListe('volontaires', '/volontaires');

    const tonStatut = { operationnel: 'bon', reserve: 'attention', retire: 'neutre' };

    return (
        <>
            <EnTetePage
                titre="Registre des volontaires"
                sousTitre="Les trois catégories sont étanches : un assistant ne devient jamais opérateur."
            />

            <BarreFiltres onReinitialiser={liste.reinitialiser}>
                <FiltreListe
                    libelle="Catégorie"
                    valeur={liste.filtres.categorie}
                    onChange={(v) => liste.changerFiltre('categorie', v)}
                    tous="Toutes"
                    options={[
                        { valeur: 'superviseur', libelle: 'Superviseur de centre' },
                        { valeur: 'operateur', libelle: 'Opérateur de kit' },
                        { valeur: 'assistant', libelle: 'Assistant (A-OPK)' },
                    ]}
                />
                <FiltreListe
                    libelle="Statut"
                    valeur={liste.filtres.statut}
                    onChange={(v) => liste.changerFiltre('statut', v)}
                    options={[
                        { valeur: 'operationnel', libelle: 'Opérationnel' },
                        { valeur: 'reserve', libelle: 'En réserve' },
                        { valeur: 'retire', libelle: 'Retiré' },
                    ]}
                />
            </BarreFiltres>

            {liste.isPending && <Chargement message="Chargement du registre…" />}
            {liste.error && <Echec erreur={liste.error} onReessayer={liste.refetch} />}

            {!liste.isPending && !liste.error && (
                <>
                    <Tableau
                        cle={(v) => v.id}
                        lignes={liste.lignes}
                        vide={<Vide titre="Aucun volontaire ne correspond" explication="Aucun volontaire de votre périmètre ne répond à ces filtres." />}
                        colonnes={[
                            { cle: 'matricule', titre: 'Matricule', compact: true, rendu: (v) => <span className="font-mono">{v.matricule}</span> },
                            { cle: 'nom', titre: 'Nom et prénoms', rendu: (v) => nomDe(v.user) },
                            {
                                cle: 'categorie',
                                titre: 'Catégorie',
                                compact: true,
                                rendu: (v) => (v.categorie ? humaniser(v.categorie) : <Pastille ton="attention">à qualifier</Pastille>),
                            },
                            { cle: 'statut', titre: 'Statut', compact: true, rendu: (v) => <Pastille ton={tonStatut[v.statut] ?? 'neutre'}>{humaniser(v.statut)}</Pastille> },
                            { cle: 'localite', titre: 'Localité', rendu: (v) => v.localite?.nom ?? '—' },
                            { cle: 'telephone', titre: 'Téléphone', compact: true, rendu: (v) => v.user?.telephone ?? '—' },
                            { cle: 'compte', titre: 'Accès', compact: true, rendu: (v) => humaniser(v.user?.statut_compte) },
                        ]}
                    />
                    <Pagination page={liste.pagination} onPage={liste.setPage} />
                </>
            )}
        </>
    );
}
