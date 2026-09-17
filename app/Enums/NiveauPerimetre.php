<?php

namespace App\Enums;

use App\Models\User;

/**
 * Les quatre niveaux de périmètre.
 *
 * RÈGLE D'IMPLÉMENTATION NON NÉGOCIABLE (cadrage, section 5) : le DROIT (ce qu'on
 * peut faire) et le PÉRIMÈTRE (sur quelles données) sont deux mécanismes distincts
 * et tous les deux obligatoires. Le droit passe par les rôles et les Policies ;
 * le périmètre passe par le scope Eloquent, appliqué dans chaque requête.
 */
enum NiveauPerimetre: string
{
    case LuiMeme = 'lui_meme';
    case SesCentres = 'ses_centres';
    case SaRegion = 'sa_region';
    case National = 'national';

    public function libelle(): string
    {
        return match ($this) {
            self::LuiMeme => 'Lui-même',
            self::SesCentres => 'Ses centres',
            self::SaRegion => 'Sa région',
            self::National => 'National',
        };
    }

    /**
     * Le niveau du périmètre d'un utilisateur, déduit de ses rôles.
     * Le niveau le plus large l'emporte si l'utilisateur en cumule plusieurs.
     */
    public static function pour(User $utilisateur): self
    {
        $niveaux = collect(RolePnvb::cases())
            ->filter(fn (RolePnvb $role) => $utilisateur->hasRole($role->value))
            ->map(fn (RolePnvb $role) => $role->niveauPerimetre());

        if ($niveaux->isEmpty()) {
            return self::LuiMeme;
        }

        return $niveaux->sortByDesc(fn (self $niveau) => $niveau->rang())->first();
    }

    public function rang(): int
    {
        return match ($this) {
            self::LuiMeme => 0,
            self::SesCentres => 1,
            self::SaRegion => 2,
            self::National => 3,
        };
    }
}
