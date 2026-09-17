<?php

namespace App\Services\Exports;

use App\Models\FeuillePresence;
use App\Services\Presence\ExportPresences;

/**
 * Les listes des présents de la période.
 *
 * SEULES LES FEUILLES VALIDÉES — ou corrigées par le chef d'antenne — y
 * figurent : « seule la feuille validée fait foi » (cadrage, section 8.3). Une
 * feuille au brouillon n'engage personne et n'a pas à circuler.
 *
 * Une feuille ne porte pas de région : elle porte un centre, et c'est le centre
 * qui porte la région.
 */
class SourcePresences implements SourceExport
{
    public function __construct(private readonly ExportPresences $exports) {}

    public function cle(): string
    {
        return 'presences';
    }

    public function libelle(): string
    {
        return 'Listes des présents (feuilles validées)';
    }

    public function colonnes(): array
    {
        return $this->exports->colonnes();
    }

    public function lignes(?int $regionId, string $du, string $au): iterable
    {
        return FeuillePresence::query()
            ->whereIn('statut', ['validee', 'corrigee'])
            ->whereBetween('date_presence', [$du, $au])
            ->when($regionId, fn ($requete) => $requete->whereHas(
                'centre',
                fn ($centre) => $centre->where('region_id', $regionId)
            ))
            ->with([
                'centre:id,code,region_id', 'site:id,code',
                'superviseur:id,user_id,matricule', 'superviseur.user:id,nom,prenoms',
                'lignes.volontaire:id,user_id,matricule', 'lignes.volontaire.user:id,nom,prenoms',
            ])
            ->orderBy('date_presence')
            ->orderBy('centre_id')
            ->get()
            ->flatMap(fn (FeuillePresence $feuille) => $this->exports->lignesDe($feuille))
            ->all();
    }
}
