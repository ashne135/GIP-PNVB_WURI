<?php

namespace App\Services\Sync\Types;

/**
 * Droit ou périmètre refusé pendant le traitement d'un élément.
 *
 * Distincte d'une DomainException parce que le téléphone doit les traiter
 * différemment : une règle métier peut redevenir satisfaite demain, un droit
 * refusé ne se résout pas en réessayant.
 */
class SyncDroitRefuse extends \RuntimeException {}
