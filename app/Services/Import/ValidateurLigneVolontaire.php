<?php

namespace App\Services\Import;

use App\Models\Localite;
use App\Models\Region;
use App\Models\User;
use App\Models\Volontaire;
use App\Support\NormalisateurTelephone;

/**
 * Validation d'une ligne du fichier des retenus ou de la liste d'attente,
 * selon le canevas réel du cadrage v2 (section 6).
 *
 * Chaque motif d'erreur est une phrase en FRANÇAIS SIMPLE qui dit ce qui ne va
 * pas ET comment le corriger : l'administrateur doit pouvoir renvoyer le compte
 * rendu tel quel à la personne qui a produit le fichier.
 *
 * DÉDOUBLONNAGE SUR TROIS CLÉS, DANS CET ORDRE : N° CNIB, puis numéro de
 * téléphone, puis adresse de courriel. Une ligne en doublon est signalée à
 * l'aperçu et NON IMPORTÉE. Les doublons sont cherchés à la fois DANS LE
 * FICHIER et EN BASE — un fichier propre peut malgré tout rejouer un import
 * déjà passé.
 */
class ValidateurLigneVolontaire
{
    /** Clés déjà rencontrées dans ce fichier : valeur => numéro de ligne. */
    private array $cnibDuFichier = [];

    private array $telephonesDuFichier = [];

    private array $courrielsDuFichier = [];

    /** Caches de résolution, pour ne pas requêter à chaque ligne. */
    private ?array $localitesParCle = null;

    private ?array $regionsParNom = null;

    private ?array $telephonesExistants = null;

    private ?array $cnibExistants = null;

    private ?array $courrielsExistants = null;

    /**
     * @return array{valide: bool, motif: string|null, donnees: array, a_qualifier: bool}
     */
    public function valider(array $donnees, int $numeroLigne): array
    {
        $donnees = array_map(fn ($v) => is_string($v) ? trim($v) : $v, $donnees);
        $erreurs = [];

        // ---------- Identité ----------
        $nom = CanevasVolontaires::normaliserNom($donnees['nom'] ?? '');
        $prenoms = CanevasVolontaires::normaliserPrenoms($donnees['prenoms'] ?? '');

        if ($nom === '') {
            $erreurs[] = 'le nom est vide';
        }

        if ($prenoms === '') {
            $erreurs[] = 'les prénoms sont vides';
        }

        $donnees['nom'] = $nom;
        $donnees['prenoms'] = $prenoms;

        // ---------- Téléphone : identifiant de connexion ----------
        $telephone = NormalisateurTelephone::normaliser($donnees['telephone'] ?? null);

        if ($telephone === null) {
            $erreurs[] = ($donnees['telephone'] ?? '') === ''
                ? 'le numéro de téléphone est vide'
                : "le numéro de téléphone « {$donnees['telephone']} » n'est pas valide "
                    .'(8 chiffres attendus, exemple : 70 12 34 56)';
        }

        $donnees['telephone'] = $telephone;

        // ---------- Courriel : FACULTATIF ----------
        // Une adresse absente est acceptée — la cascade SMS puis bordereau
        // prendra le relais. Une adresse MAL FORMÉE est en revanche signalée :
        // elle ferait échouer l'envoi sans qu'on sache pourquoi.
        $email = mb_strtolower((string) ($donnees['email'] ?? ''));

        if ($email !== '' && ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $erreurs[] = "l'adresse de courriel « {$email} » n'est pas valide";
            $email = '';
        }

        $donnees['email'] = $email !== '' ? $email : null;

        // ---------- Pièce d'identité ----------
        $cnib = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) ($donnees['numero_cnib'] ?? '')) ?? '');
        $donnees['numero_cnib'] = $cnib !== '' ? $cnib : null;

        $donnees['date_etablissement_cnib'] = $this->normaliserDate($donnees['date_etablissement_cnib'] ?? '');

        // Une CNIB établie dans le futur est une erreur de saisie, pas un cas limite.
        if ($donnees['date_etablissement_cnib'] !== null
            && $donnees['date_etablissement_cnib'] > now()->toDateString()) {
            $erreurs[] = "la date d'établissement de la pièce d'identité est dans le futur "
                ."({$donnees['date_etablissement_cnib']})";
            $donnees['date_etablissement_cnib'] = null;
        }

        // ---------- Dédoublonnage, dans l'ordre imposé ----------
        if ($doublon = $this->chercherDoublon($cnib, $telephone, $email, $numeroLigne)) {
            $erreurs[] = $doublon;
        }

        // ---------- Profil : peut manquer, la ligne part « à qualifier » ----------
        $profil = CanevasVolontaires::reconnaitreProfil($donnees['profil'] ?? null);
        $profilFourni = trim((string) ($donnees['profil'] ?? '')) !== '';

        if ($profilFourni && $profil === null) {
            $erreurs[] = "le profil « {$donnees['profil']} » n'est pas reconnu "
                .'(valeurs attendues : superviseur de centre, opérateur de kit, A-OPK)';
        }

        $donnees['categorie'] = $profil;
        $aQualifier = $profil === null;

        // ---------- Territoire ----------
        // OBLIGATOIRE POUR LES A-OPK, qui sont rattachés en permanence à leur
        // localité. FACULTATIF pour les OPK et les superviseurs, qui reçoivent
        // leur territoire à l'affectation.
        $donnees['localite_id'] = null;
        $donnees['region_origine_id'] = $this->resoudreRegion($donnees['region'] ?? '');

        if ($profil === 'assistant') {
            $resolution = $this->resoudreLocalite($donnees);

            if ($resolution['erreur'] !== null) {
                $erreurs[] = $resolution['erreur'];
            }

            $donnees['localite_id'] = $resolution['id'];
        } elseif ($profil === null) {
            // Profil encore inconnu : si la fiche devient un A-OPK à la
            // qualification, sa localité sera exigée. On la retient donc dès
            // maintenant quand le fichier la nomme sans ambiguïté — sans en
            // faire un motif de rejet, puisque rien n'oblige encore à l'avoir.
            // Pour un autre profil, la qualification l'effacera.
            $donnees['localite_id'] = $this->resoudreLocalite($donnees)['id'];
        }

        // ---------- Champs facultatifs ----------
        $donnees['sexe'] = CanevasVolontaires::reconnaitreSexe($donnees['sexe'] ?? null);
        $donnees['date_naissance'] = $this->normaliserDate($donnees['date_naissance'] ?? '');

        // Texte libre, parfois à l'étranger : stocké tel quel après nettoyage,
        // JAMAIS rattaché à une commune.
        $lieu = trim(preg_replace('/\s+/', ' ', (string) ($donnees['lieu_naissance'] ?? '')) ?? '');
        $donnees['lieu_naissance'] = $lieu !== '' ? $lieu : null;

        return [
            'valide' => $erreurs === [],
            'motif' => $erreurs === [] ? null : ucfirst(implode(' ; ', $erreurs)).'.',
            'donnees' => $donnees,
            'a_qualifier' => $aQualifier && $erreurs === [],
        ];
    }

    /**
     * Cherche un doublon sur les trois clés, dans l'ordre du cadrage : la
     * première qui correspond arrête la recherche et motive le rejet.
     */
    private function chercherDoublon(?string $cnib, ?string $telephone, ?string $email, int $ligne): ?string
    {
        if ($cnib) {
            if (isset($this->cnibDuFichier[$cnib])) {
                return "ce numéro de pièce d'identité apparaît déjà à la ligne "
                    .$this->cnibDuFichier[$cnib].' du fichier';
            }

            if (in_array($cnib, $this->cnibExistants(), true)) {
                return "ce numéro de pièce d'identité est déjà enregistré pour un autre volontaire";
            }

            $this->cnibDuFichier[$cnib] = $ligne;
        }

        if ($telephone) {
            if (isset($this->telephonesDuFichier[$telephone])) {
                return 'ce numéro de téléphone apparaît déjà à la ligne '
                    .$this->telephonesDuFichier[$telephone].' du fichier';
            }

            if (in_array($telephone, $this->telephonesExistants(), true)) {
                return 'ce numéro de téléphone est déjà utilisé par un compte existant';
            }

            $this->telephonesDuFichier[$telephone] = $ligne;
        }

        if ($email) {
            if (isset($this->courrielsDuFichier[$email])) {
                return 'cette adresse de courriel apparaît déjà à la ligne '
                    .$this->courrielsDuFichier[$email].' du fichier';
            }

            if (in_array($email, $this->courrielsExistants(), true)) {
                return 'cette adresse de courriel est déjà utilisée par un compte existant';
            }

            $this->courrielsDuFichier[$email] = $ligne;
        }

        return null;
    }

    /**
     * Résout la localité d'un A-OPK depuis le bloc territorial : village,
     * secteur ou quartier — les trois colonnes du fichier correspondent aux
     * trois valeurs de type_localite, et une seule est renseignée.
     *
     * @return array{id: int|null, erreur: string|null}
     */
    private function resoudreLocalite(array $donnees): array
    {
        $nomLocalite = '';

        foreach (CanevasVolontaires::COLONNES_LOCALITE as $colonne) {
            if (trim((string) ($donnees[$colonne] ?? '')) !== '') {
                $nomLocalite = trim((string) $donnees[$colonne]);
                break;
            }
        }

        if ($nomLocalite === '') {
            return [
                'id' => null,
                'erreur' => 'la localité est vide : village, secteur ou quartier est obligatoire '
                    ."pour un A-OPK, qui est rattaché en permanence à sa localité",
            ];
        }

        $candidats = $this->localitesParCle()[CanevasVolontaires::normaliser($nomLocalite)] ?? [];

        if ($candidats === []) {
            return [
                'id' => null,
                'erreur' => "la localité « {$nomLocalite} » est introuvable dans le référentiel territorial",
            ];
        }

        if (count($candidats) === 1) {
            return ['id' => $candidats[0]['id'], 'erreur' => null];
        }

        // Plusieurs homonymes : la commune départage. Choisir au hasard
        // affecterait un agent à des centaines de kilomètres de chez lui.
        $nomCommune = trim((string) ($donnees['commune'] ?? ''));

        if ($nomCommune !== '') {
            $cleCommune = CanevasVolontaires::normaliser($nomCommune);

            foreach ($candidats as $candidat) {
                if (str_starts_with(CanevasVolontaires::normaliser($candidat['commune']), $cleCommune)) {
                    return ['id' => $candidat['id'], 'erreur' => null];
                }
            }

            return [
                'id' => null,
                'erreur' => "la localité « {$nomLocalite} » n'existe pas dans la commune « {$nomCommune} »",
            ];
        }

        $communes = implode(', ', array_slice(array_column($candidats, 'commune'), 0, 4));

        return [
            'id' => null,
            'erreur' => count($candidats)." localités portent le nom « {$nomLocalite} » "
                ."({$communes}…) : renseignez la commune pour lever l'ambiguïté",
        ];
    }

    private function resoudreRegion(string $nom): ?int
    {
        if (trim($nom) === '') {
            return null;
        }

        return $this->regionsParNom()[CanevasVolontaires::normaliser($nom)] ?? null;
    }

    /** Accepte les formats courants : 1998-07-21, 21/07/1998, 21-07-1998. */
    private function normaliserDate(string $valeur): ?string
    {
        $valeur = trim($valeur);

        if ($valeur === '') {
            return null;
        }

        foreach (['Y-m-d', 'd/m/Y', 'd-m-Y', 'd.m.Y', 'Y/m/d'] as $format) {
            $date = \DateTimeImmutable::createFromFormat($format, $valeur);

            if ($date !== false && $date->format($format) === $valeur) {
                return $date->format('Y-m-d');
            }
        }

        return null;
    }

    // ------------------------------------------------------------------
    // Caches de résolution
    // ------------------------------------------------------------------

    private function localitesParCle(): array
    {
        if ($this->localitesParCle !== null) {
            return $this->localitesParCle;
        }

        $this->localitesParCle = [];

        Localite::query()
            ->join('communes', 'communes.id', '=', 'localites.commune_id')
            ->select('localites.id', 'localites.nom', 'communes.nom as commune')
            ->cursor()
            ->each(function ($ligne) {
                $cle = CanevasVolontaires::normaliser($ligne->nom);
                $this->localitesParCle[$cle][] = ['id' => $ligne->id, 'commune' => $ligne->commune];
            });

        return $this->localitesParCle;
    }

    private function regionsParNom(): array
    {
        return $this->regionsParNom ??= Region::query()
            ->get(['id', 'nom', 'code'])
            ->flatMap(fn ($region) => [
                CanevasVolontaires::normaliser($region->nom) => $region->id,
                CanevasVolontaires::normaliser($region->code) => $region->id,
            ])
            ->all();
    }

    private function telephonesExistants(): array
    {
        return $this->telephonesExistants ??= User::query()->withTrashed()->pluck('telephone')->all();
    }

    private function courrielsExistants(): array
    {
        return $this->courrielsExistants ??= User::query()->withTrashed()
            ->whereNotNull('email')
            ->pluck('email')
            ->map(fn ($e) => mb_strtolower($e))
            ->all();
    }

    private function cnibExistants(): array
    {
        return $this->cnibExistants ??= Volontaire::query()->withTrashed()
            ->whereNotNull('numero_cnib')
            ->pluck('numero_cnib')
            ->all();
    }
}
