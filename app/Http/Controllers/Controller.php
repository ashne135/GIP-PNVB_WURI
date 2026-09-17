<?php

namespace App\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;

/**
 * Contrôleur de base.
 *
 * AuthorizesRequests donne accès à $this->authorize(), par lequel passe le
 * contrôle du DROIT sur chaque action (cadrage, section 5). Le contrôle du
 * PÉRIMÈTRE, lui, se fait par le scope perimetre() dans la requête Eloquent :
 * les deux sont distincts et tous les deux obligatoires.
 */
abstract class Controller
{
    use AuthorizesRequests;
}
