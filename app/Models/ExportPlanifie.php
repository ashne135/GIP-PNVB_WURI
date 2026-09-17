<?php

namespace App\Models;

use App\Enums\NiveauPerimetre;
use App\Models\Concerns\AppliquePerimetre;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un fichier d'export produit et déposé, prêt à être téléchargé.
 *
 * LE CHEMIN NE SORT JAMAIS DE L'API : le fichier vit hors du dossier public et
 * n'est servi que par le contrôleur, qui revérifie le droit et le périmètre.
 * Exposer le chemin donnerait une adresse à deviner.
 */
class ExportPlanifie extends Model
{
    use AppliquePerimetre;

    protected $table = 'exports_planifies';

    protected $fillable = [
        'type', 'portee', 'region_id', 'date_debut', 'date_fin',
        'nom_fichier', 'chemin', 'format', 'taille_octets', 'nb_lignes',
        'statut', 'message', 'genere_le', 'genere_par',
    ];

    protected $hidden = ['chemin'];

    /** Les contenus produits, et le nom que l'écran leur donne. */
    public const TYPES = [
        'rapports' => 'Rapports journaliers visés',
        'presences' => 'Listes des présents',
        'incidents' => 'Incidents déclarés',
        'tableau_bord' => 'Tableau de bord — journées agrégées',
        'couverture' => 'Couverture par localité',
        'kits' => 'Parc de kits',
    ];

    protected function casts(): array
    {
        return [
            'date_debut' => 'date',
            'date_fin' => 'date',
            'genere_le' => 'datetime',
        ];
    }

    public function region(): BelongsTo
    {
        return $this->belongsTo(Region::class);
    }

    public function auteur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'genere_par');
    }

    public function estTelechargeable(): bool
    {
        return $this->statut === 'pret' && filled($this->chemin);
    }

    public function libelleType(): string
    {
        return self::TYPES[$this->type] ?? $this->type;
    }

    /**
     * Un chef d'antenne régional ne voit QUE le fichier de sa région.
     *
     * Lui rendre le fichier national reviendrait à lui ouvrir les onze autres
     * régions : c'est précisément ce que la production d'un fichier par région
     * évite. Les niveaux inférieurs — superviseur, agent — n'ont pas d'export
     * planifié, et ne portent d'ailleurs pas la permission.
     */
    protected function restreindrePerimetre(
        Builder $requete,
        User $utilisateur,
        NiveauPerimetre $niveau
    ): Builder {
        if ($niveau === NiveauPerimetre::SaRegion && $utilisateur->region_id) {
            return $requete->where('portee', 'region')->where('region_id', $utilisateur->region_id);
        }

        return $requete->whereRaw('1 = 0');
    }
}
