<?php

namespace App\Services\Fichiers;

/**
 * La fiche d'une photo n'est pas (encore) sur le serveur.
 *
 * Ce n'est pas un refus définitif : la photo part après sa fiche, et la fiche
 * peut arriver dans le lot suivant. Le téléphone réessaiera.
 */
class FicheIntrouvable extends \RuntimeException {}
