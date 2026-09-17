<?php

namespace App\Services\Referentiel;

use App\Models\Centre;
use App\Models\Commune;
use App\Models\Localite;
use App\Models\Parametre;
use App\Models\Site;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Création et codification des centres et des sites.
 *
 * CODIFICATION AUTOMATIQUE (cadrage, section 7) :
 *     Centre : <CODE_RÉGION>-<CODE_COMMUNE>-C<numéro sur 3 chiffres>
 *     Site   : <CODE_CENTRE>-S<numéro sur 2 chiffres>
 *
 * « Les codes sont générés une seule fois et ne changent jamais : ils
 * apparaîtront sur des documents papier. » Aucune méthode de cette classe ne
 * régénère le code d'un objet existant — le code est posé à la création, et
 * plus jamais touché, même si la commune est renommée.
 *
 * Le numéro repart du PLUS GRAND déjà attribué, et non d'un COUNT : un centre
 * ne se supprime pas, mais si cela arrivait un jour, compter les lignes
 * réattribuerait un code déjà imprimé.
 */
class ServiceCentresEtSites
{
    public function __construct(
        private readonly CodificateurTerritorial $codificateur = new CodificateurTerritorial,
    ) {
    }

    /** Crée un centre dans une commune, avec son code définitif. */
    public function creerCentre(Commune $commune, array $donnees, ?User $auteur = null): Centre
    {
        return DB::transaction(function () use ($commune, $donnees, $auteur) {
            $code = $donnees['code'] ?? $this->prochainCodeCentre($commune);

            $centre = Centre::query()->create([
                'commune_id' => $commune->id,
                'region_id' => $commune->region_id,
                'code' => $code,
                'nom' => $donnees['nom'] ?? "Centre {$code}",
                'nombre_kits' => $donnees['nombre_kits'] ?? 1,
                'est_permanent' => $donnees['est_permanent'] ?? false,
                'latitude' => $donnees['latitude'] ?? null,
                'longitude' => $donnees['longitude'] ?? null,
                'statut' => $donnees['statut'] ?? 'planifie',
                'est_fictif' => $donnees['est_fictif'] ?? false,
            ]);

            activity('referentiel')
                // Un code de centre est définitif et finit sur des documents
                // papier : savoir qui l'a créé fait partie de ce qu'on doit
                // pouvoir établir après coup.
                ->causedBy($auteur)
                ->performedOn($centre)
                ->withProperties(['code' => $code, 'commune' => $commune->nom])
                ->log('Centre créé');

            return $centre;
        });
    }

    /** Crée un site dans un centre, rattaché à sa localité. */
    public function creerSite(
        Centre $centre,
        Localite $localite,
        array $donnees,
        ?User $auteur = null
    ): Site {
        return DB::transaction(function () use ($centre, $localite, $donnees, $auteur) {
            $ordre = $donnees['ordre_tournee'] ?? $this->prochainOrdreTournee($centre);
            $code = $donnees['code'] ?? $this->codificateur->codeSite($centre->code, $ordre);

            $site = Site::query()->create([
                'centre_id' => $centre->id,
                'localite_id' => $localite->id,
                'region_id' => $centre->region_id,
                'code' => $code,
                'nom' => $donnees['nom'] ?? "Site {$ordre}",
                'latitude' => $donnees['latitude'] ?? null,
                'longitude' => $donnees['longitude'] ?? null,
                'rayon_zone_metres' => $donnees['rayon_zone_metres']
                    ?? Parametre::entier('presence.rayon_zone_site_metres', 500),
                'ordre_tournee' => $ordre,
                'statut' => $donnees['statut'] ?? 'planifie',
                'est_fictif' => $donnees['est_fictif'] ?? false,
            ]);

            activity('referentiel')
                ->causedBy($auteur)
                ->performedOn($site)
                ->withProperties(['code' => $code, 'centre' => $centre->code])
                ->log('Site créé');

            return $site;
        });
    }

    /**
     * Le prochain code de centre libre dans une commune.
     * Le code de commune est celui déjà figé en base, jamais recalculé.
     */
    public function prochainCodeCentre(Commune $commune): string
    {
        $codeRegion = $commune->region->code;
        $numero = $this->prochainNumero($commune, 'centres', "{$codeRegion}-{$commune->code}-C", 3);

        return $this->codificateur->codeCentre($codeRegion, $commune->code, $numero);
    }

    /** Le prochain rang de tournée libre dans un centre. */
    public function prochainOrdreTournee(Centre $centre): int
    {
        return 1 + (int) Site::query()->where('centre_id', $centre->id)->max('ordre_tournee');
    }

    /**
     * Ferme un centre. UN CENTRE NE SE SUPPRIME PAS : il porte l'historique des
     * rapports, des présences et des affectations. Il se ferme.
     */
    public function fermerCentre(Centre $centre, string $motif, ?User $auteur = null): Centre
    {
        $centre->update(['statut' => 'ferme']);
        Site::query()->where('centre_id', $centre->id)->update(['statut' => 'ferme']);

        activity('referentiel')
            // Fermer un centre ferme tous ses sites : l'acte est lourd, et le
            // motif seul ne dit pas qui en a décidé.
            ->causedBy($auteur)
            ->performedOn($centre)
            ->withProperties(['motif' => $motif])
            ->log('Centre fermé');

        return $centre->fresh();
    }

    /**
     * Le plus grand numéro déjà attribué derrière un préfixe de code, plus un.
     * On lit le code, pas le nombre de lignes : un code imprimé ne se réattribue
     * jamais, même si la ligne qui le portait a disparu.
     */
    private function prochainNumero(Commune $commune, string $table, string $prefixe, int $longueur): int
    {
        $dernier = DB::table($table)
            ->where('commune_id', $commune->id)
            ->where('code', 'like', $prefixe.'%')
            ->orderByDesc('code')
            ->value('code');

        return $dernier ? ((int) substr($dernier, -$longueur)) + 1 : 1;
    }
}
