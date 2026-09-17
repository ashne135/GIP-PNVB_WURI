<?php

namespace App\Models;

use App\Enums\TypeMouvementKit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/** Journal des mouvements d'un kit, avec état constaté et photos. */
class KitMouvement extends Model
{
    use LogsActivity;

    protected $fillable = [
        'uuid_client', 'kit_id', 'type', 'volontaire_source_id', 'volontaire_destination_id',
        'vague_id', 'centre_id', 'site_id', 'etat_constate', 'commentaire',
        'photo_source_chemin', 'photo_destination_chemin', 'photos_attendues_jusqu_au', 'alerte_photos_le',
        'latitude', 'longitude', 'horodatage_telephone', 'effectue_par', 'effectue_le', 'est_fictif',
    ];

    protected function casts(): array
    {
        return [
            'type' => TypeMouvementKit::class,
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'photos_attendues_jusqu_au' => 'datetime',
            'alerte_photos_le' => 'datetime',
            'horodatage_telephone' => 'datetime',
            'effectue_le' => 'datetime',
            'est_fictif' => 'boolean',
        ];
    }

    public function kit(): BelongsTo
    {
        return $this->belongsTo(Kit::class);
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(Volontaire::class, 'volontaire_source_id');
    }

    public function destination(): BelongsTo
    {
        return $this->belongsTo(Volontaire::class, 'volontaire_destination_id');
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function centre(): BelongsTo
    {
        return $this->belongsTo(Centre::class);
    }

    public function effectuePar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'effectue_par');
    }

    /** Les photos de constat, arrivées après le mouvement. */
    public function piecesJointes(): HasMany
    {
        return $this->hasMany(PieceJointe::class)->orderBy('deposee_le');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['type', 'kit_id', 'volontaire_source_id', 'volontaire_destination_id', 'etat_constate'])
            ->useLogName('kit_mouvement');
    }
}
