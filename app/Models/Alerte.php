<?php

namespace App\Models;

use App\Enums\NiveauPerimetre;
use App\Models\Concerns\AppliquePerimetre;
use App\Models\Concerns\MarquageFictif;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Alerte descendante, ou alerte émise par l'acteur SYSTÈME.
 *
 * emetteur_user_id nul signifie que l'alerte vient du planificateur : absence
 * détectée, écart de présence, kit non restitué, incident escaladé.
 */
class Alerte extends Model
{
    use AppliquePerimetre;
    use MarquageFictif;

    protected $fillable = [
        'code', 'type', 'titre', 'message', 'niveau', 'emetteur_user_id', 'portee',
        'region_id', 'centre_id', 'volontaire_id', 'role_cible', 'kit_id',
        'incident_id', 'ecart_presence_id', 'publiee_le', 'expire_le', 'est_fictif',
    ];

    protected function casts(): array
    {
        return [
            'publiee_le' => 'datetime',
            'expire_le' => 'datetime',
            'est_fictif' => 'boolean',
        ];
    }

    public function emetteur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'emetteur_user_id');
    }

    public function region(): BelongsTo
    {
        return $this->belongsTo(Region::class);
    }

    public function centre(): BelongsTo
    {
        return $this->belongsTo(Centre::class);
    }

    public function incident(): BelongsTo
    {
        return $this->belongsTo(Incident::class);
    }

    public function lecteurs(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'alerte_lectures')->withPivot('lu_le');
    }

    public function scopeEnCours(Builder $requete): Builder
    {
        return $requete->where('publiee_le', '<=', now())
            ->where(fn (Builder $q) => $q->whereNull('expire_le')->orWhere('expire_le', '>', now()));
    }

    /**
     * Les alertes destinées à un utilisateur, selon leur portée ET SON NIVEAU
     * DE PÉRIMÈTRE.
     *
     * LA HIÉRARCHIE COMPTE. Une alerte n'est pas visible que de l'échelon
     * exact qu'elle vise : un chef d'antenne régional n'est rattaché à aucun
     * centre, et ne verrait donc jamais une alerte de portée « centre » de sa
     * propre région — alors que la matrice de notification le prévient. La
     * trace dirait « prévenu », son écran dirait « aucune alerte ».
     *
     * L'alternative — élargir la portée à l'émission — serait pire : une
     * alerte « nationale » pour un incident local partirait aux douze régions,
     * et le cadrage l'interdit. C'est donc la LECTURE qui s'élargit vers le
     * haut, jamais l'émission vers le bas.
     */
    public function scopePour(Builder $requete, User $utilisateur): Builder
    {
        // Le périmètre national voit tout : c'est sa définition partout
        // ailleurs dans la plateforme, et les alertes n'y font pas exception.
        if ($utilisateur->niveauPerimetre() === NiveauPerimetre::National) {
            return $requete;
        }

        $volontaire = $utilisateur->volontaire;
        $region = $utilisateur->idRegionAccessible();
        $centres = $utilisateur->idsCentresAccessibles();

        return $requete->where(function (Builder $q) use ($utilisateur, $volontaire, $region, $centres) {
            $q->where('portee', 'nationale')
                ->orWhere(fn (Builder $r) => $r->where('portee', 'role')
                    ->whereIn('role_cible', $utilisateur->getRoleNames()->all()));

            if ($region !== null) {
                $q->orWhere(fn (Builder $r) => $r->where('portee', 'regionale')
                    ->where('region_id', $region));

                // Un périmètre régional couvre les centres de sa région, même
                // sans rattachement nominatif à aucun d'eux.
                if ($utilisateur->niveauPerimetre() === NiveauPerimetre::SaRegion) {
                    $q->orWhere(fn (Builder $r) => $r->where('portee', 'centre')
                        ->whereHas('centre', fn ($c) => $c->where('region_id', $region)));
                }
            }

            if ($centres !== []) {
                $q->orWhere(fn (Builder $r) => $r->where('portee', 'centre')
                    ->whereIn('centre_id', $centres));
            }

            if ($volontaire) {
                $q->orWhere(fn (Builder $r) => $r->where('portee', 'volontaire')
                    ->where('volontaire_id', $volontaire->id));
            }
        });
    }

    protected function restreindrePerimetre(
        Builder $requete,
        User $utilisateur,
        NiveauPerimetre $niveau
    ): Builder {
        return $this->scopePour($requete, $utilisateur);
    }
}
