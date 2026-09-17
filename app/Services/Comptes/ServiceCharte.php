<?php

namespace App\Services\Comptes;

use App\Models\Parametre;

/**
 * LE TEXTE DE LA CHARTE, CÔTÉ SERVEUR.
 *
 * Le consentement enregistre une VERSION (consentements.version_charte). Tant
 * que le texte vivait dans le paquet du back-office, rien ne garantissait que
 * deux agents ayant accepté « 2026.1 » avaient lu la même chose — et le mobile
 * en aurait porté une troisième copie. Il n'y a désormais qu'un texte par
 * version, servi à tous les clients : resources/chartes/<version>.json.
 *
 * Aucun texte de repli : si la version courante n'a pas de texte, on le dit.
 * Montrer une autre version ferait accepter à l'agent ce qu'il n'a pas lu.
 */
class ServiceCharte
{
    public function versionCourante(): string
    {
        return (string) Parametre::valeur('comptes.charte_version_courante', '2026.1');
    }

    public function existe(string $version): bool
    {
        return $this->chemin($version) !== null;
    }

    /**
     * @return array{version: string, provisoire: bool, titre: string, preambule: string,
     *               articles: array<int, array{titre: string, texte: string}>, engagement: string}
     *
     * @throws \RuntimeException si le texte de cette version est absent ou incohérent
     */
    public function texte(string $version): array
    {
        $chemin = $this->chemin($version);

        if ($chemin === null) {
            throw new \RuntimeException("Aucun texte de charte pour la version « {$version} ».");
        }

        $texte = json_decode((string) file_get_contents($chemin), true, flags: JSON_THROW_ON_ERROR);

        // Le fichier doit dire lui-même quelle version il porte : un fichier
        // copié sans être corrigé ne passe pas pour la version suivante.
        if (($texte['version'] ?? null) !== $version) {
            throw new \RuntimeException("Le fichier de la charte « {$version} » porte une autre version.");
        }

        return $texte;
    }

    private function chemin(string $version): ?string
    {
        // La version sert de nom de fichier : rien d'autre qu'un identifiant.
        if (! preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,39}$/', $version)) {
            return null;
        }

        $chemin = resource_path("chartes/{$version}.json");

        return is_file($chemin) ? $chemin : null;
    }
}
