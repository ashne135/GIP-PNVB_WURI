<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Une photo du terrain, rattachée à SA fiche : un incident, ou un mouvement de
 * kit (photo de la partie qui remet, ou de celle qui reçoit).
 *
 * Le fichier vit sur le disque PRIVÉ. Il n'est jamais servi par une adresse
 * publique : le téléchargement repasse par les droits sur la fiche.
 */
class PieceJointe extends Model
{
    protected $table = 'pieces_jointes';

    public const ROLE_PREUVE_INCIDENT = 'preuve_incident';

    public const ROLE_CONSTAT_SOURCE = 'constat_source';

    public const ROLE_CONSTAT_DESTINATION = 'constat_destination';

    public const ROLES = [self::ROLE_PREUVE_INCIDENT, self::ROLE_CONSTAT_SOURCE, self::ROLE_CONSTAT_DESTINATION];

    protected $fillable = [
        'uuid_fichier', 'incident_id', 'kit_mouvement_id', 'role', 'chemin', 'type_mime',
        'taille_octets', 'largeur', 'hauteur', 'empreinte_sha256', 'deposee_par', 'deposee_le',
        'horodatage_telephone', 'est_fictif',
    ];

    /** Le chemin sur le disque est un détail interne : il ne sort pas de l'API. */
    protected $hidden = ['chemin', 'empreinte_sha256'];

    protected function casts(): array
    {
        return [
            'taille_octets' => 'integer',
            'largeur' => 'integer',
            'hauteur' => 'integer',
            'deposee_le' => 'datetime',
            'horodatage_telephone' => 'datetime',
            'est_fictif' => 'boolean',
        ];
    }

    public function incident(): BelongsTo
    {
        return $this->belongsTo(Incident::class);
    }

    public function kitMouvement(): BelongsTo
    {
        return $this->belongsTo(KitMouvement::class);
    }

    public function deposeePar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'deposee_par');
    }
}
