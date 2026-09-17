<?php

namespace App\Services\Sync\Types;

/**
 * L'objet visé par l'élément n'existe pas sur le serveur.
 *
 * Cas typique : un visa remonté avant le rapport qu'il vise, parce que deux
 * téléphones se sont synchronisés dans le désordre.
 */
class SyncIntrouvable extends \RuntimeException {}
