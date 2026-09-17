<?php

namespace App\Policies;

use App\Enums\RolePnvb;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Socle de toutes les Policies.
 *
 * RÈGLE D'IMPLÉMENTATION NON NÉGOCIABLE (cadrage, section 5) : le DROIT et le
 * PÉRIMÈTRE sont deux mécanismes DISTINCTS et tous les deux OBLIGATOIRES.
 *
 *   - le droit   : la permission Spatie ($this->peut())
 *   - le périmètre : le scope Eloquent perimetre() ($this->dansLePerimetre())
 *
 * Une Policy qui ne vérifierait que la permission laisserait un chef d'antenne
 * de Bankui valider un rapport du Kadiogo : il a bien le droit « valider un
 * rapport de centre », mais pas sur ces données-là. C'est exactement le piège
 * que ces deux méthodes évitent, et pourquoi autoriser() les combine toujours.
 */
abstract class PolitiqueDeBase
{
    /** La permission requise pour consulter ce type d'objet. */
    abstract protected function permissionConsulter(): string;

    /** Le modèle concerné, pour appliquer son scope de périmètre. */
    abstract protected function modele(): string;

    /**
     * Le super administrateur (DSI) n'est PAS un passe-droit universel : il ne
     * court-circuite que la vérification de périmètre, jamais celle du droit.
     * Un before() qui renverrait true à tout coup rendrait les Policies
     * décoratives — et le cadrage sépare justement les pouvoirs.
     */
    public function before(User $utilisateur, string $capacite): ?bool
    {
        // L'observateur est en lecture seule stricte : toute capacité autre que
        // la consultation lui est refusée, en plus du middleware qui bloque déjà
        // les méthodes HTTP d'écriture.
        if ($utilisateur->hasRole(RolePnvb::Observateur->value)
            && ! in_array($capacite, ['viewAny', 'view'], true)) {
            return false;
        }

        return null;
    }

    // ------------------------------------------------------------------
    // Capacités standard
    // ------------------------------------------------------------------

    public function viewAny(User $utilisateur): bool
    {
        return $this->peut($utilisateur, $this->permissionConsulter());
    }

    public function view(User $utilisateur, Model $modele): bool
    {
        return $this->peut($utilisateur, $this->permissionConsulter())
            && $this->dansLePerimetre($utilisateur, $modele);
    }

    // ------------------------------------------------------------------
    // Briques réutilisables
    // ------------------------------------------------------------------

    /** Le DROIT : la permission est-elle accordée à cet utilisateur ? */
    protected function peut(User $utilisateur, string $permission): bool
    {
        return $utilisateur->can($permission);
    }

    /**
     * Le PÉRIMÈTRE : cet objet précis tombe-t-il dans le périmètre de
     * l'utilisateur ? La question est posée à la base via le scope du modèle —
     * jamais réimplémentée ici, pour qu'il n'existe qu'UNE définition du
     * périmètre par modèle.
     */
    protected function dansLePerimetre(User $utilisateur, Model $modele): bool
    {
        $classe = $this->modele();

        return $classe::query()
            ->perimetre($utilisateur)
            ->whereKey($modele->getKey())
            ->exists();
    }

    /** Droit ET périmètre, la combinaison attendue partout. */
    protected function autoriser(User $utilisateur, string $permission, Model $modele): bool
    {
        return $this->peut($utilisateur, $permission)
            && $this->dansLePerimetre($utilisateur, $modele);
    }
}
