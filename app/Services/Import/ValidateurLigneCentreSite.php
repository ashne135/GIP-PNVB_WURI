<?php

namespace App\Services\Import;

use App\Models\Commune;
use App\Models\Localite;
use App\Models\Site;

/**
 * Validation d'une ligne du fichier des centres et sites.
 *
 * Le point délicat est la RÉSOLUTION TERRITORIALE : le fichier nomme une
 * commune et une localité, la base travaille avec des identifiants. Un nom de
 * localité peut être porté par plusieurs communes du pays — la commune
 * départage, et en son absence, la ligne est refusée plutôt que rattachée au
 * hasard.
 */
class ValidateurLigneCentreSite
{
    private ?array $communesParCle = null;

    private ?array $localitesParCle = null;

    /** Sites déjà nommés dans ce fichier, pour repérer les doublons internes. */
    private array $sitesDuFichier = [];

    /**
     * @return array{valide: bool, motif: string|null, donnees: array}
     */
    public function valider(array $donnees, int $numeroLigne): array
    {
        $donnees = array_map(fn ($v) => is_string($v) ? trim($v) : $v, $donnees);
        $erreurs = [];

        $nomCentre = trim((string) ($donnees['nom_centre'] ?? ''));
        $nomSite = trim((string) ($donnees['nom_site'] ?? ''));

        if ($nomCentre === '') {
            $erreurs[] = 'le nom du centre est vide';
        }

        if ($nomSite === '') {
            $erreurs[] = 'le nom du site est vide';
        }

        // ---------- Commune ----------
        $commune = $this->resoudreCommune(
            (string) ($donnees['commune'] ?? ''),
            (string) ($donnees['region'] ?? '')
        );

        if ($commune['erreur'] !== null) {
            $erreurs[] = $commune['erreur'];
        }

        $donnees['commune_id'] = $commune['id'];

        // ---------- Localité ----------
        $localite = $this->resoudreLocalite(
            (string) ($donnees['localite'] ?? ''),
            $commune['id']
        );

        if ($localite['erreur'] !== null) {
            $erreurs[] = $localite['erreur'];
        }

        $donnees['localite_id'] = $localite['id'];

        // ---------- Doublon de site ----------
        $cleSite = CanevasCentresSites::normaliser($nomCentre).'|'.CanevasCentresSites::normaliser($nomSite);

        if (isset($this->sitesDuFichier[$cleSite])) {
            $erreurs[] = "ce site apparaît déjà à la ligne {$this->sitesDuFichier[$cleSite]} du fichier";
        } else {
            $this->sitesDuFichier[$cleSite] = $numeroLigne;
        }

        // ---------- Nombres ----------
        $kits = (int) ($donnees['nombre_kits'] ?? 0);

        if ($kits > 2) {
            $erreurs[] = "un centre dispose de 1 ou 2 kits, pas {$kits}";
        }

        $donnees['nombre_kits'] = $kits > 0 ? min($kits, 2) : 1;
        $donnees['ordre_tournee'] = (int) ($donnees['ordre_tournee'] ?? 0) ?: null;

        $donnees['latitude'] = $this->coordonnee($donnees['latitude'] ?? '', -90, 90);
        $donnees['longitude'] = $this->coordonnee($donnees['longitude'] ?? '', -180, 180);

        $donnees['nom_centre'] = $nomCentre;
        $donnees['nom_site'] = $nomSite;
        $donnees['code_centre'] = trim((string) ($donnees['code_centre'] ?? ''));
        $donnees['cle_centre'] = $commune['id'].'|'.CanevasCentresSites::normaliser($nomCentre);

        return [
            'valide' => $erreurs === [],
            'motif' => $erreurs === [] ? null : ucfirst(implode(' ; ', $erreurs)).'.',
            'donnees' => $donnees,
        ];
    }

    /** @return array{id: int|null, erreur: string|null} */
    private function resoudreCommune(string $nomCommune, string $nomRegion): array
    {
        if (trim($nomCommune) === '') {
            return ['id' => null, 'erreur' => 'la commune est vide'];
        }

        $candidats = $this->communesParCle()[CanevasCentresSites::normaliser($nomCommune)] ?? [];

        if ($candidats === []) {
            return [
                'id' => null,
                'erreur' => "la commune « {$nomCommune} » est introuvable dans le référentiel territorial",
            ];
        }

        if (count($candidats) === 1) {
            return ['id' => $candidats[0]['id'], 'erreur' => null];
        }

        if (trim($nomRegion) !== '') {
            $cleRegion = CanevasCentresSites::normaliser($nomRegion);

            foreach ($candidats as $candidat) {
                if (CanevasCentresSites::normaliser($candidat['region']) === $cleRegion) {
                    return ['id' => $candidat['id'], 'erreur' => null];
                }
            }

            return [
                'id' => null,
                'erreur' => "la commune « {$nomCommune} » n'existe pas dans la région « {$nomRegion} »",
            ];
        }

        return [
            'id' => null,
            'erreur' => count($candidats)." communes portent le nom « {$nomCommune} » : "
                .'renseignez la région pour lever l\'ambiguïté',
        ];
    }

    /** @return array{id: int|null, erreur: string|null} */
    private function resoudreLocalite(string $nomLocalite, ?int $communeId): array
    {
        if (trim($nomLocalite) === '') {
            return [
                'id' => null,
                'erreur' => 'la localité du site est vide : c\'est sa population qui sert '
                    .'de dénominateur au taux de couverture',
            ];
        }

        if ($communeId === null) {
            // Sans commune résolue, on ne peut pas lever l'ambiguïté : l'erreur
            // de commune a déjà été signalée, on n'en ajoute pas une seconde.
            return ['id' => null, 'erreur' => null];
        }

        $candidats = array_values(array_filter(
            $this->localitesParCle()[CanevasCentresSites::normaliser($nomLocalite)] ?? [],
            fn ($c) => $c['commune_id'] === $communeId
        ));

        if ($candidats === []) {
            return [
                'id' => null,
                'erreur' => "la localité « {$nomLocalite} » n'existe pas dans cette commune",
            ];
        }

        return ['id' => $candidats[0]['id'], 'erreur' => null];
    }

    private function coordonnee(string $valeur, float $min, float $max): ?float
    {
        $valeur = trim(str_replace(',', '.', $valeur));

        if ($valeur === '' || ! is_numeric($valeur)) {
            return null;
        }

        $nombre = (float) $valeur;

        return $nombre >= $min && $nombre <= $max ? $nombre : null;
    }

    private function communesParCle(): array
    {
        if ($this->communesParCle !== null) {
            return $this->communesParCle;
        }

        $this->communesParCle = [];

        Commune::query()
            ->join('regions', 'regions.id', '=', 'communes.region_id')
            ->select('communes.id', 'communes.nom', 'regions.nom as region')
            ->cursor()
            ->each(function ($ligne) {
                $this->communesParCle[CanevasCentresSites::normaliser($ligne->nom)][] = [
                    'id' => $ligne->id,
                    'region' => $ligne->region,
                ];
            });

        return $this->communesParCle;
    }

    private function localitesParCle(): array
    {
        if ($this->localitesParCle !== null) {
            return $this->localitesParCle;
        }

        $this->localitesParCle = [];

        Localite::query()
            ->select('id', 'nom', 'commune_id')
            ->cursor()
            ->each(function ($ligne) {
                $this->localitesParCle[CanevasCentresSites::normaliser($ligne->nom)][] = [
                    'id' => $ligne->id,
                    'commune_id' => $ligne->commune_id,
                ];
            });

        return $this->localitesParCle;
    }
}
