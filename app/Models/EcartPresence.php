<?php

namespace App\Models;

use App\Enums\NiveauPerimetre;
use App\Models\Concerns\AppliquePerimetre;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Écart entre la feuille de présence et les relevés de position.
 *
 * C'est le SEUL objet exposé du mécanisme de rapprochement, et au seul CHEF
 * D'ANTENNE RÉGIONAL. La table ne porte qu'un COMPTE de relevés, jamais une
 * position : le chef d'antenne voit l'écart constaté, jamais l'historique des
 * déplacements de la personne.
 */
class EcartPresence extends Model
{
    use AppliquePerimetre;

    protected $table = 'ecarts_presence';

    protected $fillable = [
        'feuille_presence_id', 'ligne_presence_id', 'volontaire_id', 'region_id',
        'date_constat', 'type_ecart', 'nb_releves_zone', 'alerte_id',
        'statut', 'examine_par', 'examine_le', 'commentaire',
    ];

    protected function casts(): array
    {
        return [
            'date_constat' => 'date',
            'examine_le' => 'datetime',
        ];
    }

    public function feuille(): BelongsTo
    {
        return $this->belongsTo(FeuillePresence::class, 'feuille_presence_id');
    }

    public function ligne(): BelongsTo
    {
        return $this->belongsTo(LignePresence::class, 'ligne_presence_id');
    }

    public function volontaire(): BelongsTo
    {
        return $this->belongsTo(Volontaire::class);
    }

    public function alerte(): BelongsTo
    {
        return $this->belongsTo(Alerte::class);
    }

    public function libelleEcart(): string
    {
        return match ($this->type_ecart) {
            'present_sans_releve' => 'Déclaré présent, aucun relevé dans la zone du site',
            'releve_zone_declare_absent' => 'Déclaré absent, relevé dans la zone du site',
            default => 'Écart constaté',
        };
    }

    /**
     * Seuls le niveau régional et le niveau national voient les écarts.
     * Un superviseur ne voit pas les écarts de ses propres agents : c'est lui
     * qui a validé la feuille, il est partie prenante du constat.
     */
    protected function restreindrePerimetre(
        Builder $requete,
        User $utilisateur,
        NiveauPerimetre $niveau
    ): Builder {
        if ($niveau !== NiveauPerimetre::SaRegion) {
            return $requete->whereRaw('1 = 0');
        }

        $region = $utilisateur->idRegionAccessible();

        return $region ? $requete->where('region_id', $region) : $requete->whereRaw('1 = 0');
    }
}
