<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <style>
        @page { margin: 18mm 14mm; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 10pt; color: #1a1a1a; }
        .entete { border-bottom: 2px solid #0d5a62; padding-bottom: 8px; margin-bottom: 14px; }
        .entete h1 { font-size: 14pt; margin: 0 0 2px; color: #0d5a62; }
        .entete .projet { font-size: 9pt; color: #555; }
        .meta { font-size: 8.5pt; color: #555; margin-bottom: 12px; }
        .meta strong { color: #1a1a1a; }
        .consigne { background: #f2f6f6; border-left: 3px solid #0d5a62;
                    padding: 7px 10px; font-size: 8.5pt; margin-bottom: 14px; }
        .talon { border: 1px dashed #999; padding: 8px 10px; margin-bottom: 8px; }
        .talon table { width: 100%; border-collapse: collapse; }
        .talon td { padding: 2px 0; vertical-align: top; font-size: 9pt; }
        .talon .cle { color: #555; width: 30%; }
        .talon .identite { font-size: 10.5pt; font-weight: bold; }
        .talon .secret { font-family: DejaVu Sans Mono, monospace; font-size: 11pt;
                         letter-spacing: 1px; font-weight: bold; }
        .signature { margin-top: 6px; border-top: 1px solid #ccc; padding-top: 4px;
                     font-size: 8pt; color: #666; }
        .pied { position: fixed; bottom: -10mm; left: 0; right: 0;
                font-size: 7.5pt; color: #777; text-align: center; }
    </style>
</head>
<body>
    <div class="entete">
        <h1>Bordereau de remise des identifiants</h1>
        <div class="projet">GIP-PNVB — Projet WURI — Plateforme de gestion des volontaires</div>
    </div>

    <div class="meta">
        Session de formation : <strong>{{ $session }}</strong><br>
        Nombre de volontaires : <strong>{{ count($lignes) }}</strong><br>
        Document généré le <strong>{{ $genere_le->format('d/m/Y à H:i') }}</strong>
        par <strong>{{ $genere_par }}</strong>
    </div>

    <div class="consigne">
        Découpez chaque talon et remettez-le à son destinataire <strong>contre signature</strong>.
        Le mot de passe ne sert qu'une seule fois : à la première connexion, le volontaire
        devra en choisir un nouveau. Ce document contient des mots de passe en clair —
        détruisez-le une fois la distribution terminée.
    </div>

    @foreach ($lignes as $ligne)
        <div class="talon">
            <table>
                <tr>
                    <td colspan="2" class="identite">{{ $ligne['nom_complet'] }}</td>
                </tr>
                <tr>
                    <td class="cle">Matricule</td>
                    <td>{{ $ligne['matricule'] }} — {{ $ligne['categorie'] }}</td>
                </tr>
                <tr>
                    <td class="cle">Identifiant de connexion</td>
                    <td class="secret">{{ $ligne['identifiant'] }}</td>
                </tr>
                <tr>
                    <td class="cle">Mot de passe provisoire</td>
                    <td class="secret">{{ $ligne['mot_de_passe'] }}</td>
                </tr>
            </table>
            <div class="signature">
                Remis le ____ / ____ / ________ &nbsp;&nbsp;·&nbsp;&nbsp;
                Signature du volontaire : ______________________________
            </div>
        </div>
    @endforeach

    <div class="pied">
        GIP-PNVB — document interne — {{ $genere_le->format('d/m/Y H:i') }}
    </div>
</body>
</html>
