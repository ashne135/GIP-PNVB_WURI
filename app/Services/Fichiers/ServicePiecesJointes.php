<?php

namespace App\Services\Fichiers;

use App\Models\Incident;
use App\Models\KitMouvement;
use App\Models\PieceJointe;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;

/**
 * LE DÉPÔT D'UNE PHOTO DU TERRAIN (cadrage, section 11.8).
 *
 * La photo part APRÈS la fiche à laquelle elle appartient, et elle la désigne
 * par l'uuid que le téléphone a donné à cette fiche. Trois conséquences :
 *
 *   - IDEMPOTENCE : la photo porte son propre uuid. Renvoyée après une coupure
 *     réseau, elle est reconnue, jamais enregistrée deux fois ;
 *   - FICHE PAS ENCORE ARRIVÉE : ce n'est pas une erreur définitive, la photo
 *     repartira à la prochaine synchronisation ;
 *   - DROITS : déposer une photo sur une fiche, c'est y écrire. Seul qui peut
 *     voir l'incident, ou qui a fait le mouvement de kit, y dépose.
 */
class ServicePiecesJointes
{
    public const DISQUE = 'local';

    private const EXTENSIONS = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    /**
     * @param  array{uuid_fichier: string, element_uuid: string, role: string, horodatage_telephone?: string|null}  $donnees
     * @return array{piece: PieceJointe, action: string}
     *
     * @throws FicheIntrouvable
     * @throws AuthorizationException
     */
    public function deposer(User $auteur, array $donnees, UploadedFile $fichier): array
    {
        $existante = PieceJointe::query()->where('uuid_fichier', $donnees['uuid_fichier'])->first();

        if ($existante) {
            if ($existante->deposee_par !== $auteur->id) {
                throw new AuthorizationException("Cette photo a déjà été déposée par un autre compte.");
            }

            return ['piece' => $existante, 'action' => 'existant'];
        }

        [$incident, $mouvement] = $this->fiche($auteur, $donnees['role'], $donnees['element_uuid']);

        $typeMime = $fichier->getMimeType() ?? 'application/octet-stream';
        $extension = self::EXTENSIONS[$typeMime] ?? 'jpg';
        $dossier = 'pieces-jointes/'.now()->format('Y/m');
        $nom = $donnees['uuid_fichier'].'.'.$extension;
        $dimensions = @getimagesize($fichier->getRealPath()) ?: [null, null];

        $chemin = $fichier->storeAs($dossier, $nom, self::DISQUE);

        try {
            $piece = DB::transaction(function () use ($auteur, $donnees, $fichier, $incident, $mouvement, $chemin, $typeMime, $dimensions) {
                $piece = PieceJointe::query()->create([
                    'uuid_fichier' => $donnees['uuid_fichier'],
                    'incident_id' => $incident?->id,
                    'kit_mouvement_id' => $mouvement?->id,
                    'role' => $donnees['role'],
                    'chemin' => $chemin,
                    'type_mime' => $typeMime,
                    'taille_octets' => $fichier->getSize(),
                    'largeur' => $dimensions[0],
                    'hauteur' => $dimensions[1],
                    'empreinte_sha256' => hash_file('sha256', Storage::disk(self::DISQUE)->path($chemin)),
                    'deposee_par' => $auteur->id,
                    'deposee_le' => now(),
                    'horodatage_telephone' => filled($donnees['horodatage_telephone'] ?? null)
                        ? Carbon::parse($donnees['horodatage_telephone'])
                        : null,
                ]);

                // Le mouvement de kit garde le chemin de chaque photo de constat :
                // c'est ce qui éteint l'attente de l'alerte « photos manquantes ».
                if ($mouvement) {
                    $colonne = $donnees['role'] === PieceJointe::ROLE_CONSTAT_SOURCE
                        ? 'photo_source_chemin'
                        : 'photo_destination_chemin';

                    if (blank($mouvement->{$colonne})) {
                        $mouvement->update([$colonne => $chemin]);
                    }
                }

                return $piece;
            });
        } catch (\Throwable $e) {
            // Pas de fichier orphelin sur le disque si l'écriture en base échoue.
            Storage::disk(self::DISQUE)->delete($chemin);

            throw $e;
        }

        activity('piece_jointe')
            ->causedBy($auteur)
            ->performedOn($piece)
            ->withProperties(['role' => $piece->role, 'incident_id' => $piece->incident_id, 'kit_mouvement_id' => $piece->kit_mouvement_id])
            ->log('Photo déposée');

        return ['piece' => $piece, 'action' => 'cree'];
    }

    /** Qui peut voir la fiche peut voir sa photo. */
    public function peutVoir(User $utilisateur, PieceJointe $piece): bool
    {
        if ($piece->incident_id) {
            return Gate::forUser($utilisateur)->allows('view', $piece->incident);
        }

        $mouvement = $piece->kitMouvement;

        return $mouvement !== null
            && ($mouvement->effectue_par === $utilisateur->id
                || Gate::forUser($utilisateur)->allows('view', $mouvement->kit));
    }

    /**
     * @return array{0: Incident|null, 1: KitMouvement|null}
     */
    private function fiche(User $auteur, string $role, string $elementUuid): array
    {
        if ($role === PieceJointe::ROLE_PREUVE_INCIDENT) {
            $incident = Incident::query()->where('uuid_client', $elementUuid)->first();

            if (! $incident) {
                throw new FicheIntrouvable("L'incident de cette photo n'est pas encore arrivé sur le serveur.");
            }

            if (Gate::forUser($auteur)->denies('view', $incident)) {
                throw new AuthorizationException("Vous ne pouvez pas ajouter de photo à cet incident.");
            }

            return [$incident, null];
        }

        $mouvement = KitMouvement::query()->with('kit')->where('uuid_client', $elementUuid)->first();

        if (! $mouvement) {
            throw new FicheIntrouvable("Le mouvement de kit de cette photo n'est pas encore arrivé sur le serveur.");
        }

        $autorise = $mouvement->effectue_par === $auteur->id
            || Gate::forUser($auteur)->allows('declarerMouvement', $mouvement->kit);

        if (! $autorise) {
            throw new AuthorizationException("Vous ne pouvez pas ajouter de photo à ce mouvement de kit.");
        }

        return [null, $mouvement];
    }
}
