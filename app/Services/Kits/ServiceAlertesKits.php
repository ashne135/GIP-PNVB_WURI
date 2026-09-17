<?php

namespace App\Services\Kits;

use App\Models\Alerte;
use App\Models\Kit;
use App\Models\KitMouvement;
use App\Models\Parametre;
use App\Models\VagueDeploiement;
use App\Services\Support\NumeroteurAlerte;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * LES ALERTES DU PARC (cadrage, section 13).
 *
 * 966 kits confiés à des agents dispersés sur douze régions, pour une mission
 * qui se termine. Le risque n'est pas le vol spectaculaire : c'est le kit qu'on
 * oublie de réclamer, et dont personne ne se souvient trois mois plus tard.
 *
 * D'où trois alertes, et une seule règle commune : elles nomment TOUJOURS le
 * kit, son détenteur et la date depuis laquelle il aurait dû être rendu. Une
 * alerte qui dit « des kits sont non restitués » n'est pas exploitable.
 */
class ServiceAlertesKits
{
    public function __construct(private readonly NumeroteurAlerte $numeroteur) {}

    /**
     * Les kits qu'un agent détient encore alors que son affectation est finie
     * depuis plus que le délai paramétré.
     *
     * @return Collection<int, Kit>
     */
    public function kitsNonRestitues(?VagueDeploiement $vague = null): Collection
    {
        $delai = Parametre::entier('kits.delai_alerte_non_restitue_jours', 3);
        $limite = now()->subDays($delai)->toDateString();

        return Kit::query()
            ->whereNotNull('volontaire_detenteur_id')
            ->with(['detenteur.user:id,nom,prenoms', 'centreCourant:id,code,nom,region_id'])
            ->whereHas('detenteur.affectations', function ($requete) use ($limite, $vague) {
                $requete->where('statut', 'terminee')
                    ->whereNotNull('date_fin')
                    ->whereDate('date_fin', '<=', $limite)
                    ->when($vague, fn ($q) => $q->where('vague_id', $vague->id));
            })
            // Un agent qui a repris du service ailleurs garde légitimement son
            // kit : le cadrage dit qu'il le suit d'une région à l'autre.
            ->whereDoesntHave('detenteur.affectations', fn ($requete) => $requete
                ->whereIn('statut', ['proposee', 'active']))
            ->get();
    }

    /**
     * Publie une alerte par kit non restitué, sans jamais en republier une
     * pour un kit déjà signalé et toujours non rendu : le job tourne chaque
     * jour, et réalerter quotidiennement sur le même kit finirait par rendre
     * toute la liste illisible.
     *
     * @return array{signales: int, deja_signales: int}
     */
    public function alerterNonRestitues(?VagueDeploiement $vague = null): array
    {
        $kits = $this->kitsNonRestitues($vague);

        $signales = 0;
        $dejaSignales = 0;

        foreach ($kits as $kit) {
            if ($this->dejaAlerte($kit)) {
                $dejaSignales++;

                continue;
            }

            $this->publierNonRestitue($kit);
            $signales++;
        }

        return ['signales' => $signales, 'deja_signales' => $dejaSignales];
    }

    private function dejaAlerte(Kit $kit): bool
    {
        return Alerte::query()
            ->where('type', 'kit_non_restitue')
            ->where('kit_id', $kit->id)
            ->whereNull('expire_le')
            ->exists();
    }

    private function publierNonRestitue(Kit $kit): void
    {
        $detenteur = $kit->detenteur;
        $finMission = $detenteur?->affectations()
            ->where('statut', 'terminee')
            ->orderByDesc('date_fin')
            ->value('date_fin');

        DB::transaction(fn () => Alerte::query()->create([
            'code' => $this->numeroteur->suivant(),
            'type' => 'kit_non_restitue',
            'titre' => "Kit non restitué : {$kit->reference}",
            'message' => sprintf(
                'Le kit %s est toujours détenu par %s (%s), dont la mission est terminée depuis '
                .'le %s. Réclamez-le ou faites constater sa perte : ce matériel appartient au projet.',
                $kit->reference,
                $detenteur?->user?->nomComplet() ?? 'un agent',
                $detenteur?->matricule ?? 'matricule inconnu',
                $finMission ? Carbon::parse($finMission)->format('d/m/Y') : 'une date inconnue'
            ),
            'niveau' => 'important',
            'emetteur_user_id' => null,
            // Régionale : c'est le chef d'antenne qui va réclamer le kit sur
            // place. Le national la voit aussi, par la hiérarchie des périmètres.
            'portee' => $kit->centreCourant?->region_id ? 'regionale' : 'nationale',
            'region_id' => $kit->centreCourant?->region_id,
            'kit_id' => $kit->id,
            'publiee_le' => now(),
        ]));
    }

    /** Perte ou vol : alerte immédiate, et de niveau critique. */
    public function perteOuVol(Kit $kit, KitMouvement $mouvement): void
    {
        $perdu = $kit->etat === 'perdu';

        DB::transaction(fn () => Alerte::query()->create([
            'code' => $this->numeroteur->suivant(),
            'type' => 'systeme',
            'titre' => ($perdu ? 'Kit déclaré perdu : ' : 'Kit déclaré volé : ').$kit->reference,
            'message' => sprintf(
                'Le kit %s a été déclaré %s par %s le %s. %s',
                $kit->reference,
                $perdu ? 'perdu' : 'volé',
                $mouvement->source?->matricule ?? 'son détenteur',
                $mouvement->effectue_le->format('d/m/Y à H:i'),
                $mouvement->commentaire ?: 'Aucune circonstance précisée.'
            ),
            'niveau' => 'critique',
            'emetteur_user_id' => null,
            'portee' => $kit->centreCourant?->region_id ? 'regionale' : 'nationale',
            'region_id' => $kit->centreCourant?->region_id,
            'kit_id' => $kit->id,
            'publiee_le' => now(),
        ]));
    }

    /**
     * LES PHOTOS ANNONCÉES QUI NE SONT JAMAIS ARRIVÉES.
     *
     * Quand le téléphone annonce ses photos, l'alerte attend leur échéance : un
     * agent sans réseau ne doit pas être signalé pour une photo déjà prise et
     * simplement pas encore partie. Passée l'échéance, l'alerte est levée une
     * seule fois par mouvement.
     *
     * @return array{signales: int}
     */
    public function alerterPhotosNonRecues(): array
    {
        $signales = 0;

        KitMouvement::query()
            ->with('kit')
            ->whereNotNull('photos_attendues_jusqu_au')
            ->where('photos_attendues_jusqu_au', '<=', now())
            ->whereNull('alerte_photos_le')
            ->where(fn ($q) => $q->whereNull('photo_source_chemin')->orWhereNull('photo_destination_chemin'))
            ->orderBy('id')
            ->each(function (KitMouvement $mouvement) use (&$signales) {
                $this->photosManquantes($mouvement->kit, $mouvement);
                $mouvement->update(['alerte_photos_le' => now()]);
                $signales++;
            });

        return ['signales' => $signales];
    }

    /** Constat sans photo des deux parties : signalé, jamais bloquant. */
    public function photosManquantes(Kit $kit, KitMouvement $mouvement): void
    {
        DB::transaction(fn () => Alerte::query()->create([
            'code' => $this->numeroteur->suivant(),
            'type' => 'systeme',
            'titre' => "Mouvement de kit sans photo : {$kit->reference}",
            'message' => sprintf(
                'Le mouvement « %s » du kit %s a été enregistré sans les deux photos de constat. '
                .'Réclamez-les aux agents concernés : elles engagent la responsabilité en cas de '
                .'perte ou de casse.',
                $mouvement->type->libelle(),
                $kit->reference
            ),
            'niveau' => 'important',
            'emetteur_user_id' => null,
            'portee' => 'role',
            'role_cible' => 'administrateur_national',
            'kit_id' => $kit->id,
            'publiee_le' => now(),
        ]));
    }
}
