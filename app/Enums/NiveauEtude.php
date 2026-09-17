<?php

namespace App\Enums;

/**
 * NIVEAU D'ÉTUDE, l'échelle qui conditionne le profil (décision du client,
 * 17/09/2026) :
 *
 *   A-OPK ................ 4ème au minimum
 *   Opérateur de kit ..... BAC+1 au minimum
 *   Superviseur de centre  Licence (BAC+3) au minimum
 *
 * L'échelle est ORDONNÉE : c'est ce rang, et lui seul, qui permet de comparer.
 * L'intitulé exact du diplôme est conservé à côté, en texte, pour le dossier —
 * mais on ne compare jamais du texte libre.
 *
 * Les alias servent à lire le fichier des retenus, dont la colonne « Niveau »
 * n'est pas normalisée d'une campagne à l'autre.
 */
enum NiveauEtude: string
{
    case Aucun = 'aucun';
    case Cep = 'cep';
    case Quatrieme = 'quatrieme';
    case TroisiemeBepc = 'troisieme_bepc';
    case Bac = 'bac';
    case BacPlus1 = 'bac_plus_1';
    case BacPlus2 = 'bac_plus_2';
    case Licence = 'licence';
    case Master = 'master';

    public function libelle(): string
    {
        return match ($this) {
            self::Aucun => 'Aucun niveau scolaire',
            self::Cep => 'CEP (primaire)',
            self::Quatrieme => 'Classe de 4ème',
            self::TroisiemeBepc => '3ème ou BEPC',
            self::Bac => 'BAC',
            self::BacPlus1 => 'BAC+1',
            self::BacPlus2 => 'BAC+2',
            self::Licence => 'Licence (BAC+3)',
            self::Master => 'Master (BAC+5) ou plus',
        };
    }

    /** Le rang dans l'échelle : c'est lui qu'on compare, jamais le libellé. */
    public function rang(): int
    {
        return match ($this) {
            self::Aucun => 0,
            self::Cep => 1,
            self::Quatrieme => 2,
            self::TroisiemeBepc => 3,
            self::Bac => 4,
            self::BacPlus1 => 5,
            self::BacPlus2 => 6,
            self::Licence => 7,
            self::Master => 8,
        };
    }

    public function atteint(self $minimum): bool
    {
        return $this->rang() >= $minimum->rang();
    }

    /** Ce que le fichier des retenus peut écrire pour chaque niveau. */
    public function alias(): array
    {
        return match ($this) {
            self::Aucun => ['aucun', 'néant', 'neant', 'sans niveau', 'non scolarise', 'non scolarisé', 'rien'],
            self::Cep => ['cep', 'primaire', 'cepe', 'certificat d etudes primaires'],
            self::Quatrieme => ['4eme', '4ème', 'quatrieme', 'classe de 4eme', 'niveau 4eme', '4e'],
            self::TroisiemeBepc => ['3eme', '3ème', 'troisieme', 'bepc', 'niveau 3eme', '3e', 'college', 'collège'],
            self::Bac => ['bac', 'baccalaureat', 'baccalauréat', 'bac d', 'bac a', 'bac c', 'bac g', 'terminale'],
            self::BacPlus1 => ['bac+1', 'bac 1', 'bac plus 1', 'dut 1', 'l1', 'premiere annee'],
            self::BacPlus2 => ['bac+2', 'bac 2', 'bac plus 2', 'dut', 'bts', 'deug', 'l2', 'deuxieme annee'],
            self::Licence => ['licence', 'bac+3', 'bac 3', 'bac plus 3', 'l3', 'maitrise', 'maîtrise', 'bac+4'],
            self::Master => ['master', 'bac+5', 'bac 5', 'bac plus 5', 'dea', 'dess', 'ingenieur', 'ingénieur', 'doctorat', 'phd', 'm2'],
        };
    }

    /** Le niveau minimum exigé pour tenir un profil. */
    public static function minimumPour(CategorieVolontaire $categorie): self
    {
        return match ($categorie) {
            CategorieVolontaire::Assistant => self::Quatrieme,
            CategorieVolontaire::Operateur => self::BacPlus1,
            CategorieVolontaire::Superviseur => self::Licence,
        };
    }

    /**
     * Reconnaît une valeur du fichier. Rend null si la colonne est vide ou si
     * la valeur n'est pas dans l'échelle : on ne devine pas un niveau, on le
     * fait préciser.
     */
    public static function reconnaitre(?string $valeur, callable $normaliser): ?self
    {
        $normalise = $normaliser($valeur);

        if ($normalise === '') {
            return null;
        }

        foreach (self::cases() as $niveau) {
            foreach ($niveau->alias() as $alias) {
                if ($normalise === $normaliser($alias)) {
                    return $niveau;
                }
            }
        }

        return null;
    }
}
