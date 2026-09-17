<?php

namespace App\Http\Controllers\Api;

use App\Enums\NiveauPerimetre;
use App\Enums\RolePnvb;
use App\Http\Controllers\Controller;
use App\Http\Requests\Administration\CompteAdministrationRequest;
use App\Http\Requests\Administration\MotifRequest;
use App\Http\Responses\ReponseApi;
use App\Models\User;
use App\Services\Comptes\ServiceComptesAdministration;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * LES COMPTES D'ADMINISTRATION — réservés au super administrateur.
 *
 * Créer un compte, c'est attribuer des droits : seul roles.attribuer y donne
 * accès (séparation des pouvoirs, RolesEtPermissionsSeeder). L'administrateur
 * national ne peut donc ni se créer un collègue, ni s'élever lui-même.
 *
 * Les comptes de VOLONTAIRES n'apparaissent pas ici : ils naissent de l'import
 * et leur accès suit l'affectation.
 */
class ComptesAdministrationController extends Controller
{
    public function __construct(private readonly ServiceComptesAdministration $service)
    {
    }

    public function index(Request $requete): JsonResponse
    {
        $this->autoriser($requete);

        $roles = array_map(fn (RolePnvb $r) => $r->value, ServiceComptesAdministration::ROLES);

        $comptes = User::query()
            ->whereDoesntHave('volontaire')
            ->whereHas('roles', fn ($q) => $q->whereIn('name', $roles))
            ->with(['roles:id,name', 'region:id,code,nom'])
            ->when($requete->filled('role'),
                fn ($q) => $q->whereHas('roles', fn ($r) => $r->where('name', $requete->string('role'))))
            ->when($requete->filled('statut_compte'),
                fn ($q) => $q->where('statut_compte', $requete->string('statut_compte')))
            ->when($requete->filled('recherche'), function ($q) use ($requete) {
                $recherche = trim((string) $requete->string('recherche'));

                $q->where(fn ($r) => $r
                    ->where('nom', 'like', "%{$recherche}%")
                    ->orWhere('prenoms', 'like', "%{$recherche}%")
                    ->orWhere('telephone', 'like', "%{$recherche}%")
                    ->orWhere('email', 'like', "%{$recherche}%"));
            })
            ->orderBy('nom')
            ->paginate(min($requete->integer('par_page', 50), 200));

        $comptes->getCollection()->transform(fn (User $compte) => $this->presenter($compte));

        return ReponseApi::succes('Comptes d\'administration récupérés.', [
            'comptes' => $comptes,
            'roles' => array_map(fn (RolePnvb $r) => [
                'valeur' => $r->value,
                'libelle' => $r->libelle(),
                'regional' => $r->niveauPerimetre() === NiveauPerimetre::SaRegion,
            ], ServiceComptesAdministration::ROLES),
        ]);
    }

    public function store(CompteAdministrationRequest $requete): JsonResponse
    {
        $this->autoriser($requete);

        try {
            $resultat = $this->service->creer($requete->validated(), $requete->user());
        } catch (\DomainException $e) {
            return ReponseApi::echec($e->getMessage(), null, 422);
        }

        return ReponseApi::succes(
            'Compte créé. Notez le mot de passe provisoire maintenant : il ne sera plus jamais affiché. '
            .'Le titulaire devra le changer à sa première connexion.',
            [
                'compte' => $this->presenter($resultat['compte']),
                'mot_de_passe_provisoire' => $resultat['mot_de_passe'],
            ],
            201
        );
    }

    public function update(CompteAdministrationRequest $requete, User $compte): JsonResponse
    {
        $this->autoriser($requete);

        try {
            $compte = $this->service->modifier($compte, $requete->validated(), $requete->user());
        } catch (\DomainException $e) {
            return ReponseApi::echec($e->getMessage(), null, 422);
        }

        return ReponseApi::succes('Compte modifié. Ses sessions ouvertes ont été fermées.', [
            'compte' => $this->presenter($compte),
        ]);
    }

    public function fermer(MotifRequest $requete, User $compte): JsonResponse
    {
        $this->autoriser($requete);

        try {
            $compte = $this->service->fermer($compte, $requete->validated('motif'), $requete->user());
        } catch (\DomainException $e) {
            return ReponseApi::echec($e->getMessage(), null, 422);
        }

        return ReponseApi::succes(
            "Compte de {$compte->nomComplet()} fermé : il ne peut plus se connecter, et ses sessions ont été coupées.",
            ['compte' => $this->presenter($compte)]
        );
    }

    public function rouvrir(MotifRequest $requete, User $compte): JsonResponse
    {
        $this->autoriser($requete);

        try {
            $compte = $this->service->rouvrir($compte, $requete->validated('motif'), $requete->user());
        } catch (\DomainException $e) {
            return ReponseApi::echec($e->getMessage(), null, 422);
        }

        return ReponseApi::succes("Compte de {$compte->nomComplet()} rouvert.", [
            'compte' => $this->presenter($compte),
        ]);
    }

    public function reinitialiser(Request $requete, User $compte): JsonResponse
    {
        $this->autoriser($requete);

        try {
            $resultat = $this->service->reinitialiserMotDePasse($compte, $requete->user());
        } catch (\DomainException $e) {
            return ReponseApi::echec($e->getMessage(), null, 422);
        }

        return ReponseApi::succes(
            'Nouveau mot de passe provisoire créé : notez-le maintenant, il ne sera plus affiché. '
            .'L\'ancien ne fonctionne plus.',
            [
                'compte' => $this->presenter($resultat['compte']),
                'mot_de_passe_provisoire' => $resultat['mot_de_passe'],
            ]
        );
    }

    private function presenter(User $compte): array
    {
        $role = $this->service->roleDe($compte);

        return [
            'id' => $compte->id,
            'nom' => $compte->nom,
            'prenoms' => $compte->prenoms,
            'telephone' => $compte->telephone,
            'email' => $compte->email,
            'statut_compte' => $compte->statut_compte->value,
            'doit_changer_mot_de_passe' => (bool) $compte->doit_changer_mot_de_passe,
            'derniere_connexion_le' => $compte->derniere_connexion_le,
            'role' => $role?->value,
            'role_libelle' => $role?->libelle(),
            'region' => $compte->region ? [
                'id' => $compte->region->id,
                'code' => $compte->region->code,
                'nom' => $compte->region->nom,
            ] : null,
            'created_at' => $compte->created_at,
        ];
    }

    private function autoriser(Request $requete): void
    {
        abort_unless($requete->user()->can('roles.attribuer'), 403);
    }
}
