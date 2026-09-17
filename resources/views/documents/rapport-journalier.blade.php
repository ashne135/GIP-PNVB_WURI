@php
    use App\Enums\TypeRapport;

    $aopk = $rapport->type === TypeRapport::Aopk;
    $opk = $rapport->type === TypeRapport::Opk;
    $superviseur = $rapport->type === TypeRapport::Superviseur;
@endphp
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <style>
        @page { margin: 16mm 12mm 20mm; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 9pt; color: #1a1a1a; }
        .entete { border-bottom: 2px solid #0d5a62; padding-bottom: 7px; margin-bottom: 12px; }
        .entete h1 { font-size: 13pt; margin: 0 0 2px; color: #0d5a62; }
        .entete .projet { font-size: 8.5pt; color: #555; }
        .meta { font-size: 8pt; color: #555; margin-bottom: 10px; }
        .meta strong { color: #1a1a1a; }
        .avertissement { background: #f8e6e2; border: 1px solid #9c3323;
                         padding: 5px 8px; font-size: 8pt; color: #9c3323; margin-bottom: 10px; }
        h2 { font-size: 10pt; margin: 14px 0 5px; color: #0d5a62;
             border-bottom: 1px solid #ccc; padding-bottom: 3px; }
        table { width: 100%; border-collapse: collapse; font-size: 8.5pt;
                margin-bottom: 4px; page-break-inside: avoid; }
        th { background: #edf1f1; text-align: left; padding: 4px 6px;
             border-bottom: 1px solid #bbb; font-size: 7.5pt;
             text-transform: uppercase; letter-spacing: .04em; color: #555; }
        td { padding: 4px 6px; border-bottom: 1px solid #e5e5e5; vertical-align: top; }
        td.n { text-align: right; }
        .identification td { border-bottom: none; padding: 2px 6px; }
        .identification .cle { color: #555; width: 26%; }
        .positif { color: #2d6a4a; font-weight: bold; }
        .negatif { color: #9c3323; font-weight: bold; }
        .gris { color: #888; font-style: italic; }
        .visa { background: #f2f6f6; border-left: 3px solid #0d5a62;
                padding: 5px 8px; font-size: 8pt; margin-bottom: 5px; }
        .reponse { background: #fdf7ec; border-left: 3px solid #8a5313;
                   padding: 4px 8px; font-size: 7.5pt; margin: 3px 0 3px 12px; color: #5a3a0d; }
        .pied { position: fixed; bottom: -12mm; left: 0; right: 0;
                font-size: 7pt; color: #777; text-align: center; }
    </style>
</head>
<body>
    <div class="entete">
        <h1>{{ $rapport->type?->libelle() }}</h1>
        <div class="projet">GIP-PNVB — Projet WURI — Rapport journalier d'activités</div>
    </div>

    <div class="meta">
        Journée du <strong>{{ $rapport->date_rapport?->format('d/m/Y') }}</strong><br>
        Document généré le <strong>{{ $genere_le->format('d/m/Y à H:i') }}</strong>
        par <strong>{{ $genere_par }}</strong>
    </div>

    @if (! $rapport->statut?->alimenteLeNiveauSuperieur())
        {{-- Un rapport non visé ne doit jamais circuler comme s'il l'était. --}}
        <div class="avertissement">
            Ce rapport est « {{ $rapport->statut?->libelle() }} » : il n'a pas encore été visé
            par le supérieur hiérarchique. Ses chiffres ne sont pas consolidés.
            @if ($rapport->motif_rejet)
                <br>Motif du renvoi : {{ $rapport->motif_rejet }}
            @endif
        </div>
    @endif

    {{-- ------------------------------------------------------------------
         Bloc d'identification — pré-rempli depuis l'affectation, jamais saisi
         ------------------------------------------------------------------ --}}
    <h2>Identification</h2>
    <table class="identification">
        <tr>
            <td class="cle">Région</td><td>{{ $rapport->region?->nom ?? '—' }}</td>
            <td class="cle">Province</td><td>{{ $rapport->centre?->commune?->province?->nom ?? '—' }}</td>
        </tr>
        <tr>
            <td class="cle">Commune</td><td>{{ $rapport->centre?->commune?->nom ?? '—' }}</td>
            <td class="cle">Centre</td>
            <td>{{ $rapport->centre?->code ?? '—' }} — {{ $rapport->centre?->nom }}</td>
        </tr>
        <tr>
            <td class="cle">Site</td>
            <td>{{ $rapport->site ? $rapport->site->code.' — '.$rapport->site->nom : '—' }}</td>
            <td class="cle">CARV de rattachement</td><td>{{ $rapport->carv_rattachement ?? '—' }}</td>
        </tr>
        <tr>
            <td class="cle">Agent</td>
            <td>{{ $rapport->auteur?->matricule }} — {{ $rapport->auteur?->user?->nomComplet() }}</td>
            <td class="cle">Supérieur</td>
            <td>{{ $rapport->superieur?->user?->nomComplet() ?? '—' }}</td>
        </tr>
        <tr>
            <td class="cle">Heure d'arrivée</td>
            <td>{{ $rapport->heure_arrivee ?? '—' }}</td>
            <td class="cle">Heure de départ</td>
            <td>{{ $rapport->heure_depart ?? '—' }}</td>
        </tr>
    </table>

    {{-- ------------------------------------------------------------------
         9.1 — A-OPK : activités d'accueil, en PRÉVU et RÉALISÉ
         ------------------------------------------------------------------ --}}
    @if ($aopk && $rapport->activitesAopk)
        @php $a = $rapport->activitesAopk; @endphp
        <h2>Activités du jour</h2>
        <table>
            <tr><th>Activité</th><th style="width:18%">Prévu</th><th style="width:18%">Réalisé</th></tr>
            <tr>
                <td>Affluence au site</td>
                <td>{{ $a->affluence_prevue ?? '—' }}</td>
                <td>{{ $a->affluence_realisee ?? '—' }}</td>
            </tr>
            <tr>
                <td>Pièces justificatives reçues</td>
                <td class="n">{{ $a->justificatifs_recus_prevu ?? '—' }}</td>
                <td class="n">{{ $a->justificatifs_recus_realise ?? '—' }}</td>
            </tr>
            <tr>
                <td>Pièces justificatives transmises à l'opérateur</td>
                <td class="n">{{ $a->justificatifs_transmis_prevu ?? '—' }}</td>
                <td class="n">{{ $a->justificatifs_transmis_realise ?? '—' }}</td>
            </tr>
            <tr>
                <td>Plaintes enregistrées</td>
                <td class="n">{{ $a->plaintes_enregistrees_prevu ?? '—' }}</td>
                <td class="n">{{ $a->plaintes_enregistrees_realise ?? '—' }}</td>
            </tr>
            <tr>
                <td>Plaintes reversées</td>
                <td class="n">{{ $a->plaintes_reversees_prevu ?? '—' }}</td>
                <td class="n">{{ $a->plaintes_reversees_realise ?? '—' }}</td>
            </tr>
        </table>
        <div class="gris" style="font-size:7.5pt">
            L'assistant à l'opérateur de kit n'enregistre aucune personne : il accueille,
            oriente et prépare les dossiers.
        </div>
    @endif

    {{-- ------------------------------------------------------------------
         9.2 — Opérateur de kit : la production
         ------------------------------------------------------------------ --}}
    @if ($opk && $rapport->productionOpk)
        @php $p = $rapport->productionOpk; @endphp
        <h2>Production du kit</h2>
        <table>
            <tr>
                <th>Objectif</th><th>Réalisé</th><th>Écart</th><th>Taux de réalisation</th>
                <th>Récépissés transmis</th><th>État du kit</th>
            </tr>
            <tr>
                <td class="n">{{ $p->objectif_enregistrements ?? '—' }}</td>
                <td class="n">{{ $p->enregistrements_realises }}</td>
                <td class="n {{ ($p->ecart_enregistrements ?? 0) < 0 ? 'negatif' : 'positif' }}">
                    {{ $p->ecart_enregistrements ?? '—' }}
                </td>
                <td class="n">{{ $p->taux_realisation !== null ? $p->taux_realisation.' %' : '—' }}</td>
                <td class="n">{{ $p->recepisses_transmis }}</td>
                <td>{{ $p->etat_kit }}</td>
            </tr>
        </table>

        @if ($p->enregistrements_non_valides > 0)
            <table>
                <tr><th>Enregistrements non validés</th><th style="width:70%">Motif</th></tr>
                <tr>
                    <td class="n">{{ $p->enregistrements_non_valides }}</td>
                    <td>{{ $p->motif_non_valides }}</td>
                </tr>
            </table>
        @endif
    @endif

    {{-- ------------------------------------------------------------------
         9.3 — Superviseur de centre
         ------------------------------------------------------------------ --}}
    @if ($superviseur)
        @if ($rapport->evolution)
            @php $e = $rapport->evolution; @endphp
            <h2>Évolution des enregistrements</h2>
            <table>
                <tr>
                    <th>Personnes enregistrées</th><th>Dossiers validés</th><th>Dossiers à reprendre</th>
                </tr>
                <tr>
                    <td class="n">{{ $e->personnes_enregistrees }}</td>
                    <td class="n">{{ $e->dossiers_valides }}</td>
                    <td class="n">{{ $e->dossiers_a_reprendre }}</td>
                </tr>
            </table>
            <div class="gris" style="font-size:7.5pt">
                Chiffres consolidés depuis les rapports déjà visés des opérateurs du centre.
            </div>
        @endif

        @if ($rapport->qualite)
            @php $q = $rapport->qualite; @endphp
            <h2>Contrôle qualité</h2>
            <table>
                <tr>
                    <th>Contrôlés</th><th>Conformes</th><th>Non conformes</th><th>Taux</th>
                    <th>Doublons</th><th>Erreurs de saisie</th><th>Corrections</th><th>Incidents majeurs</th>
                </tr>
                <tr>
                    <td class="n">{{ $q->dossiers_controles }}</td>
                    <td class="n">{{ $q->dossiers_conformes }}</td>
                    <td class="n">{{ $q->dossiers_non_conformes }}</td>
                    <td class="n">{{ $q->taux_conformite !== null ? $q->taux_conformite.' %' : '—' }}</td>
                    <td class="n">{{ $q->doublons_detectes }}</td>
                    <td class="n">{{ $q->erreurs_saisie }}</td>
                    <td class="n">{{ $q->corrections_effectuees }}</td>
                    <td class="n">{{ $q->incidents_majeurs }}</td>
                </tr>
            </table>
        @endif

        @if ($rapport->logistique->isNotEmpty())
            <h2>Situation logistique</h2>
            <table>
                <tr>
                    <th>Ressource</th><th>Disponible</th><th>Fonctionnelle</th>
                    <th>Besoin</th><th style="width:34%">Observation</th>
                </tr>
                @foreach ($rapport->logistique as $ligne)
                    <tr>
                        <td>{{ $ligne->ressource }}</td>
                        <td class="n">{{ $ligne->disponible }}</td>
                        <td class="n">{{ $ligne->fonctionnelle }}</td>
                        <td class="n">{{ $ligne->besoin }}</td>
                        <td>{{ $ligne->observation ?? '' }}</td>
                    </tr>
                @endforeach
            </table>
        @endif
    @endif

    {{-- ------------------------------------------------------------------
         Suivi des équipes — commun aux niveaux OPK et superviseur
         ------------------------------------------------------------------ --}}
    @if ($rapport->suiviAgents->isNotEmpty())
        <h2>Suivi des agents</h2>
        <table>
            <tr>
                <th>Matricule</th><th>Nom et prénoms</th><th>Niveau</th>
                <th>Présence</th><th>Production</th><th>Anomalies</th><th style="width:28%">Observation</th>
            </tr>
            @foreach ($rapport->suiviAgents as $suivi)
                <tr>
                    <td>{{ $suivi->volontaire?->matricule }}</td>
                    <td>{{ $suivi->volontaire?->user?->nomComplet() }}</td>
                    <td>{{ $suivi->categorie_agent }}</td>
                    <td>{{ $suivi->presence?->libelle() ?? '—' }}</td>
                    <td>{{ $suivi->production ?? '—' }}</td>
                    <td>{{ $suivi->anomalies ? implode(', ', $suivi->anomalies) : '—' }}</td>
                    <td>{{ $suivi->observation ?? '' }}</td>
                </tr>
                {{-- Droit de réponse : une appréciation ne circule jamais sans
                     l'observation que l'agent y a opposée. --}}
                @foreach ($suivi->reponses as $reponse)
                    <tr>
                        <td colspan="7" style="border-bottom:none; padding:0">
                            <div class="reponse">
                                Réponse de l'agent, le {{ $reponse->repondu_le?->format('d/m/Y à H:i') }} :
                                {{ $reponse->reponse }}
                            </div>
                        </td>
                    </tr>
                @endforeach
            @endforeach
        </table>
        <div class="gris" style="font-size:7.5pt">
            La présence provient de la feuille de présence validée du jour : elle n'est pas
            ressaisie dans le rapport.
        </div>
    @endif

    {{-- ------------------------------------------------------------------
         Blocs libres
         ------------------------------------------------------------------ --}}
    @if ($rapport->difficultes->isNotEmpty())
        <h2>Difficultés rencontrées et solutions</h2>
        <table>
            <tr><th style="width:50%">Difficulté</th><th>Solution apportée ou proposée</th></tr>
            @foreach ($rapport->difficultes as $ligne)
                <tr>
                    <td>{{ $ligne->difficulte }}</td>
                    <td>{{ $ligne->solution ?? '' }}</td>
                </tr>
            @endforeach
        </table>
    @endif

    @if ($rapport->pointsAmelioration->isNotEmpty())
        <h2>Points à améliorer</h2>
        <table>
            @foreach ($rapport->pointsAmelioration as $point)
                <tr><td>{{ $point->point }}</td></tr>
            @endforeach
        </table>
    @endif

    {{-- ------------------------------------------------------------------
         Corrections motivées et chaîne de visas
         ------------------------------------------------------------------ --}}
    @if ($rapport->corrections->isNotEmpty())
        <h2>Corrections apportées aux chiffres pré-remplis</h2>
        <table>
            <tr><th>Champ</th><th>Valeur d'origine</th><th>Valeur retenue</th>
                <th style="width:40%">Motif</th><th>Le</th></tr>
            @foreach ($rapport->corrections as $correction)
                <tr>
                    <td>{{ $correction->champ }}</td>
                    <td>{{ $correction->valeur_origine ?? '—' }}</td>
                    <td>{{ $correction->valeur_corrigee ?? '—' }}</td>
                    <td>{{ $correction->motif }}</td>
                    <td>{{ $correction->corrige_le?->format('d/m/Y H:i') }}</td>
                </tr>
            @endforeach
        </table>
    @endif

    <h2>Chaîne de visas</h2>
    @forelse ($rapport->visas as $visa)
        <div class="visa">
            <strong>{{ $visa->acte?->libelle() }}</strong>
            — {{ $visa->user?->nomComplet() }},
            le {{ $visa->effectue_le?->format('d/m/Y à H:i') }}
            @if ($visa->commentaire)
                <br>{{ $visa->commentaire }}
            @endif
        </div>
    @empty
        <div class="gris">Aucun visa enregistré à ce jour.</div>
    @endforelse

    <div class="pied">
        GIP-PNVB — Projet WURI · Document produit par la plateforme de gestion des volontaires ·
        {{ $genere_le->format('d/m/Y H:i') }}
    </div>
</body>
</html>
