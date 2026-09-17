<?php

namespace App\Services\Alertes;

use App\Enums\NiveauPerimetre;
use App\Models\Alerte;
use App\Models\Centre;
use App\Models\User;
use App\Models\Volontaire;
use App\Services\Support\NumeroteurAlerte;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * LA PUBLICATION D'UNE ALERTE DESCENDANTE (cadrage, section 10).
 *
 * Jusqu'ici, seules les alertes du SYSTÈME existaient : escalade d'incident,
 * kit non restitué, écart de présence. Une consigne envoyée par un responsable
 * à ses équipes n'avait aucun chemin, alors que la permission existait.
 *
 * DEUX RÈGLES QUE CE SERVICE FAIT RESPECTER :
 *
 *  1. ON NE PUBLIE QUE DANS SON PÉRIMÈTRE. Le chef d'antenne régional porte le
 *     droit de publier, mais une alerte nationale partirait aux douze régions —
 *     ce que le cadrage interdit explicitement. Il publie donc dans SA région,
 *     ou vers un centre de SA région. Seul le niveau national publie au national
 *     et par rôle, une portée qui traverse par nature tous les territoires.
 *
 *  2. LE NUMÉRO EST PRIS DANS LA TRANSACTION. Le numéroteur pose un verrou :
 *     l'appeler hors transaction redonnerait le même code à deux alertes émises
 *     dans la même seconde — le défaut déjà corrigé une fois sur ce projet, et
 *     la colonne est unique, donc l'insertion échouerait.
 */
class ServicePublicationAlerte
{
    public function __construct(private readonly NumeroteurAlerte $numeroteur) {}

    /**
     * @param  array<string, mixed>  $donnees
     */
    public function publier(User $auteur, array $donnees): Alerte
    {
        $this->verifierPerimetre($auteur, $donnees);

        $alerte = DB::transaction(function () use ($auteur, $donnees) {
            return Alerte::query()->create([
                'code' => $this->numeroteur->suivant(),
                // « descendante » : une consigne d'un responsable. Les autres
                // types appartiennent au planificateur, jamais à un humain.
                'type' => 'descendante',
                'titre' => $donnees['titre'],
                'message' => $donnees['message'],
                'niveau' => $donnees['niveau'],
                'emetteur_user_id' => $auteur->id,
                'portee' => $donnees['portee'],
                'region_id' => $donnees['region_id'] ?? null,
                'centre_id' => $donnees['centre_id'] ?? null,
                'volontaire_id' => $donnees['volontaire_id'] ?? null,
                'role_cible' => $donnees['role_cible'] ?? null,
                'publiee_le' => now(),
                'expire_le' => filled($donnees['expire_le'] ?? null)
                    ? Carbon::parse($donnees['expire_le'])
                    : null,
            ]);
        });

        activity('alerte')
            ->causedBy($auteur)
            ->performedOn($alerte)
            ->withProperties([
                'portee' => $alerte->portee,
                'niveau' => $alerte->niveau,
                'region_id' => $alerte->region_id,
                'centre_id' => $alerte->centre_id,
            ])
            ->log('Alerte publiée');

        return $alerte;
    }

    /**
     * Le PÉRIMÈTRE de l'émetteur, vérifié avant toute écriture.
     *
     * Le droit dit qu'on peut publier ; le périmètre dit jusqu'où. Les deux sont
     * obligatoires, comme partout ailleurs dans la plateforme.
     */
    private function verifierPerimetre(User $auteur, array $donnees): void
    {
        if ($auteur->niveauPerimetre() === NiveauPerimetre::National) {
            return;
        }

        $region = $auteur->idRegionAccessible();
        $portee = $donnees['portee'];

        if (in_array($portee, ['nationale', 'role'], true)) {
            throw new \DomainException(
                'Une alerte nationale ou adressée à un rôle touche les douze régions : '
                ."seule l'administration nationale peut la publier. Choisissez votre région, "
                .'ou l’un de ses centres.'
            );
        }

        if ($region === null) {
            throw new \DomainException("Votre compte n'est rattaché à aucune région : vous ne pouvez pas publier d'alerte.");
        }

        if ($portee === 'regionale' && (int) ($donnees['region_id'] ?? 0) !== $region) {
            throw new \DomainException("Vous ne pouvez publier une alerte que dans votre propre région.");
        }

        if ($portee === 'centre') {
            $centre = Centre::query()->find($donnees['centre_id'] ?? null);

            if (! $centre || (int) $centre->region_id !== $region) {
                throw new \DomainException("Ce centre n'appartient pas à votre région.");
            }
        }

        if ($portee === 'volontaire') {
            $vise = Volontaire::query()->perimetre($auteur)->find($donnees['volontaire_id'] ?? null);

            if (! $vise) {
                throw new \DomainException("Cet agent n'est pas dans votre périmètre.");
            }
        }
    }
}
