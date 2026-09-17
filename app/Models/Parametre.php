<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/**
 * Seuils et réglages du dispositif.
 *
 * Le cadrage (section 15) interdit de coder en dur les seuils : 966 kits,
 * 2 centres par superviseur, délai d'escalade de 2 heures, distance maximale
 * entre 2 centres. Tout passe par cette table.
 *
 * La lecture est mise en cache : ces valeurs sont lues à chaque tirage, à chaque
 * signal d'arrivée et à chaque calcul d'escalade.
 */
class Parametre extends Model
{
    protected $table = 'parametres';

    protected $fillable = [
        'cle', 'valeur', 'type_valeur', 'groupe', 'libelle', 'description', 'modifiable_par',
    ];

    private const PREFIXE_CACHE = 'parametre:';

    protected static function booted(): void
    {
        static::saved(fn (self $parametre) => Cache::forget(self::PREFIXE_CACHE.$parametre->cle));
        static::deleted(fn (self $parametre) => Cache::forget(self::PREFIXE_CACHE.$parametre->cle));
    }

    /** Valeur typée d'un paramètre, ou la valeur de repli si la clé n'existe pas. */
    public static function valeur(string $cle, mixed $repli = null): mixed
    {
        $parametre = Cache::remember(
            self::PREFIXE_CACHE.$cle,
            now()->addHour(),
            fn () => static::query()->where('cle', $cle)->first()
        );

        return $parametre?->valeurTypee() ?? $repli;
    }

    public static function entier(string $cle, int $repli = 0): int
    {
        return (int) static::valeur($cle, $repli);
    }

    public static function decimal(string $cle, float $repli = 0.0): float
    {
        return (float) static::valeur($cle, $repli);
    }

    public static function booleen(string $cle, bool $repli = false): bool
    {
        return (bool) static::valeur($cle, $repli);
    }

    public static function tableau(string $cle, array $repli = []): array
    {
        $valeur = static::valeur($cle, $repli);

        return is_array($valeur) ? $valeur : $repli;
    }

    public function valeurTypee(): mixed
    {
        return match ($this->type_valeur) {
            'entier' => (int) $this->valeur,
            'decimal' => (float) $this->valeur,
            'booleen' => filter_var($this->valeur, FILTER_VALIDATE_BOOLEAN),
            'json' => json_decode((string) $this->valeur, true),
            default => $this->valeur,
        };
    }
}
