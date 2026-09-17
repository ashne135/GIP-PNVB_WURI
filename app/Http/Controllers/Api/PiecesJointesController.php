<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Responses\ReponseApi;
use App\Models\PieceJointe;
use App\Services\Fichiers\FicheIntrouvable;
use App\Services\Fichiers\ServicePiecesJointes;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Les photos du terrain.
 *
 *   POST /sync/fichiers              dépôt d'une photo, après sa fiche
 *   GET  /pieces-jointes/{piece}     la photo, pour qui peut voir la fiche
 */
class PiecesJointesController extends Controller
{
    public function __construct(private readonly ServicePiecesJointes $service) {}

    public function deposer(Request $requete): JsonResponse
    {
        $valide = $requete->validate([
            'uuid_fichier' => ['required', 'uuid'],
            'element_uuid' => ['required', 'uuid'],
            'role' => ['required', Rule::in(PieceJointe::ROLES)],
            // Compressée sur le téléphone à 1 200 px et environ 200 Ko (cadrage,
            // section 11.8). Le plafond laisse de la marge sans accepter une
            // photo brute de plusieurs mégaoctets.
            'fichier' => ['required', 'file', 'mimes:jpeg,jpg,png,webp', 'max:1024'],
            'horodatage_telephone' => ['nullable', 'date'],
        ], [
            'fichier.required' => 'La photo est manquante.',
            'fichier.mimes' => 'Seules les photos JPEG, PNG ou WebP sont acceptées.',
            'fichier.max' => 'La photo dépasse 1 Mo : elle aurait dû être compressée sur le téléphone.',
            'role.in' => "Ce rôle de photo n'existe pas.",
        ]);

        try {
            $resultat = $this->service->deposer($requete->user(), $valide, $requete->file('fichier'));
        } catch (FicheIntrouvable $e) {
            // La fiche arrivera peut-être dans le lot suivant : on dit au
            // téléphone de réessayer, sans perdre la photo.
            return ReponseApi::echec(
                $e->getMessage().' La photo repartira à la prochaine synchronisation.',
                ['code' => 'introuvable_serveur', 'reessayer' => true],
                409
            );
        }

        $cree = $resultat['action'] === 'cree';

        return ReponseApi::succes(
            $cree ? 'Photo enregistrée.' : 'Cette photo avait déjà été reçue.',
            [...$resultat['piece']->toArray(), 'action' => $resultat['action']],
            $cree ? 201 : 200
        );
    }

    public function telecharger(Request $requete, PieceJointe $piece): StreamedResponse
    {
        abort_unless($this->service->peutVoir($requete->user(), $piece), 403);

        return Storage::disk(ServicePiecesJointes::DISQUE)->response(
            $piece->chemin,
            basename($piece->chemin),
            ['Content-Type' => $piece->type_mime]
        );
    }
}
