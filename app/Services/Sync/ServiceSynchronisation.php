<?php

namespace App\Services\Sync;

use App\Models\Parametre;
use App\Models\SyncLot;
use App\Models\User;
use App\Services\Sync\Types\SyncDroitRefuse;
use App\Services\Sync\Types\SyncIntrouvable;
use Carbon\CarbonInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

/**
 * POST /api/sync — LE POINT D'ENTRÉE DU TERRAIN (cadrage, section 11).
 *
 * Quatre propriétés, et le reste en découle.
 *
 * 1. IDEMPOTENCE À DEUX NIVEAUX.
 *    Le LOT porte un uuid : le rejouer rend la réponse déjà calculée, sans rien
 *    réappliquer. Chaque ÉLÉMENT porte le sien : un élément déjà intégré est
 *    rendu « existant », pas dupliqué. Sans les deux, un téléphone qui perd le
 *    réseau au moment de la réponse — le cas le plus banal — créerait des
 *    doublons à chaque tentative.
 *
 * 2. SUCCÈS PARTIEL. Un élément qui échoue ne fait jamais échouer le lot.
 *    Chacun est traité dans SA PROPRE TRANSACTION. Un rapport mal formé ne doit
 *    pas faire perdre les onze autres éléments de la journée d'un agent.
 *
 * 3. LES RÈGLES SONT CELLES D'EN LIGNE. Chaque type délègue au service métier
 *    qui sert déjà le contrôleur HTTP, et le droit est vérifié avant tout
 *    traitement. La synchronisation n'est pas une porte dérobée où les
 *    contrôles seraient plus faibles.
 *
 * 4. LE REFUS EST MOTIVÉ ET QUALIFIÉ. Chaque rejet porte un code stable qui dit
 *    au téléphone s'il doit réessayer ou abandonner.
 */
class ServiceSynchronisation
{
    public function __construct(private readonly RegistreSync $registre) {}

    /**
     * @param  array<int, array<string, mixed>>  $elements
     * @param  CarbonInterface|null  $fermetureAcces  Renseignée quand le lot arrive
     *         avec le jeton restreint d'un accès fermé : seules les actions
     *         faites avant cette date passent encore.
     */
    public function traiterLot(
        User $auteur,
        string $uuidLot,
        array $elements,
        ?CarbonInterface $fermetureAcces = null
    ): array {
        $debut = microtime(true);

        $dejaVu = SyncLot::query()->where('uuid_lot', $uuidLot)->first();

        if ($dejaVu) {
            return $this->reponseRejouee($dejaVu);
        }

        // La ligne est posée AVANT le traitement : elle réserve l'uuid du lot.
        // Deux téléphones — ou deux tentatives concurrentes du même — ne
        // peuvent pas traiter le même lot en parallèle.
        try {
            $lot = SyncLot::query()->create([
                'user_id' => $auteur->id,
                'uuid_lot' => $uuidLot,
                'recu_le' => now(),
                'nb_elements' => count($elements),
            ]);
        } catch (QueryException $e) {
            $concurrent = SyncLot::query()->where('uuid_lot', $uuidLot)->first();

            if ($concurrent) {
                return $this->reponseRejouee($concurrent);
            }

            throw $e;
        }

        $resultats = $this->traiterElements($auteur, $elements, $fermetureAcces);

        $acceptes = array_values(array_filter($resultats, fn (ResultatElement $r) => $r->accepte));
        $rejetes = array_values(array_filter($resultats, fn (ResultatElement $r) => ! $r->accepte));

        $detail = [
            'acceptes' => array_map(fn (ResultatElement $r) => $r->enTableau(), $acceptes),
            'rejetes' => array_map(fn (ResultatElement $r) => $r->enTableau(), $rejetes),
        ];

        $lot->update([
            'nb_acceptes' => count($acceptes),
            'nb_rejetes' => count($rejetes),
            'detail' => $detail,
            'duree_ms' => (int) round((microtime(true) - $debut) * 1000),
        ]);

        return $this->reponse($lot->fresh(), rejoue: false);
    }

    /**
     * Traite les éléments DANS L'ORDRE REÇU — la file du téléphone est
     * chronologique, et un rapport doit être saisi avant d'être visé.
     *
     * Puis UNE SECONDE PASSE sur les seuls éléments dont la cible était
     * introuvable : dans un lot où un visa précède le rapport qu'il vise — deux
     * appareils synchronisés dans le désordre, ou une file réordonnée — la
     * dépendance a pu arriver entre-temps. Une passe suffit : au-delà, c'est
     * que la cible n'est réellement pas là.
     *
     * @return array<int, ResultatElement>
     */
    private function traiterElements(User $auteur, array $elements, ?CarbonInterface $fermetureAcces): array
    {
        $resultats = [];

        foreach (array_values($elements) as $rang => $element) {
            $resultats[$rang] = $this->traiterUn($auteur, $element, $rang, $fermetureAcces);
        }

        foreach ($resultats as $rang => $resultat) {
            if ($resultat->code === CodeRejet::IntrouvableServeur) {
                $resultats[$rang] = $this->traiterUn(
                    $auteur, array_values($elements)[$rang], $rang, $fermetureAcces
                );
            }
        }

        return array_values($resultats);
    }

    private function traiterUn(
        User $auteur,
        mixed $element,
        int $rang,
        ?CarbonInterface $fermetureAcces = null
    ): ResultatElement {
        if (! is_array($element)) {
            return ResultatElement::rejete(
                null, 'inconnu', $rang, CodeRejet::DonneesInvalides,
                "Cet élément n'est pas un objet exploitable."
            );
        }

        $cle = is_string($element['type'] ?? null) ? $element['type'] : 'inconnu';
        $uuid = is_string($element['uuid_client'] ?? null) ? $element['uuid_client'] : null;

        $type = $this->registre->pour($cle);

        if (! $type) {
            return ResultatElement::rejete(
                $uuid, $cle, $rang, CodeRejet::TypeInconnu,
                "Le serveur ne connaît pas le type « {$cle} ». Mettez l'application à jour.",
                ['types_connus' => $this->registre->cles()]
            );
        }

        if ($fermetureAcces !== null && ($refus = $this->horsRattrapage($element, $fermetureAcces))) {
            return ResultatElement::rejete($uuid, $cle, $rang, CodeRejet::AccesFerme, $refus);
        }

        // Le droit d'abord : inutile de valider le contenu d'une action que ce
        // compte n'a pas le droit de faire.
        if ($type->permission() !== null && ! $auteur->can($type->permission())) {
            return ResultatElement::rejete(
                $uuid, $cle, $rang, CodeRejet::DroitRefuse,
                "Votre compte n'a pas le droit d'effectuer cette action."
            );
        }

        $regles = $type->regles();

        if ($type->exigeUuidClient()) {
            $regles['uuid_client'] = ['required', 'uuid'];
        }

        $validateur = Validator::make($element, $regles, $type->messages());

        if ($validateur->fails()) {
            return ResultatElement::rejete(
                $uuid, $cle, $rang, CodeRejet::DonneesInvalides,
                'Cet élément est incomplet ou mal formé.',
                $validateur->errors()->toArray()
            );
        }

        $donnees = $validateur->validated();
        $donnees['uuid_client'] = $uuid;

        try {
            // Chaque élément dans SA transaction : l'échec du suivant ne défait
            // pas celui-ci, et son propre échec ne laisse pas d'écriture partielle.
            $issue = DB::transaction(fn () => $type->traiter($auteur, $donnees));

            return ResultatElement::accepte($uuid, $cle, $rang, $issue['id'], $issue['action']);
        } catch (SyncDroitRefuse $e) {
            return ResultatElement::rejete($uuid, $cle, $rang, CodeRejet::DroitRefuse, $e->getMessage());
        } catch (SyncIntrouvable $e) {
            return ResultatElement::rejete($uuid, $cle, $rang, CodeRejet::IntrouvableServeur, $e->getMessage());
        } catch (\DomainException $e) {
            // Message métier, en français, destiné à l'agent.
            return ResultatElement::rejete($uuid, $cle, $rang, CodeRejet::RegleMetier, $e->getMessage());
        } catch (\Throwable $e) {
            // La seule catégorie où réessayer PEUT avoir un sens : on journalise
            // pour pouvoir corriger, et on ne fait pas tomber le reste du lot.
            Log::error('Synchronisation : échec technique sur un élément', [
                'type' => $cle,
                'uuid_client' => $uuid,
                'user_id' => $auteur->id,
                'exception' => $e->getMessage(),
            ]);

            /*
             * ON NE PROMET PLUS QUE « ce sera accepté à la prochaine tentative ».
             *
             * Cette phrase était fausse pour toute panne durable — un taux qui
             * ne tenait pas dans sa colonne l'a fait échouer quatre fois de
             * suite. L'agent lisait une promesse, le téléphone rejouait, et rien
             * ne changeait jamais.
             *
             * Le travail reste sur le téléphone, c'est vrai et c'est l'essentiel
             * à dire ; le reste est une consigne, pas une prédiction.
             */
            return ResultatElement::rejete(
                $uuid, $cle, $rang, CodeRejet::ErreurServeur,
                "Le serveur n'a pas pu traiter cet élément. Votre saisie reste sur le téléphone. "
                .'Si le renvoi échoue encore, signalez-le : la panne est côté serveur, '
                .'et elle est déjà enregistrée dans son journal.'
            );
        }
    }

    /**
     * EN RATTRAPAGE, seule une action faite AVANT la fermeture de l'accès passe.
     *
     * L'heure retenue est celle du téléphone au moment du geste (cadrage,
     * section 11.7), portée par « horodatage_action » sur chaque élément. Sans
     * elle, rien ne prouve que l'action précède la fermeture : l'élément est
     * refusé plutôt que présumé légitime.
     */
    private function horsRattrapage(array $element, CarbonInterface $fermeture): ?string
    {
        $brut = $element['horodatage_action'] ?? null;

        try {
            $moment = is_string($brut) && $brut !== '' ? Carbon::parse($brut) : null;
        } catch (\Throwable) {
            $moment = null;
        }

        if ($moment === null) {
            return "Votre accès est fermé et l'heure de cette action manque : "
                .'impossible de vérifier qu\'elle a été faite pendant votre mission.';
        }

        if ($moment->greaterThan($fermeture)) {
            return 'Votre accès était déjà fermé au moment de cette action : elle ne peut pas être enregistrée.';
        }

        return null;
    }

    /** La taille maximale d'un lot est un PARAMÈTRE, jamais une constante. */
    public function tailleMaximale(): int
    {
        return Parametre::entier('sync.max_elements_par_lot', 200);
    }

    private function reponseRejouee(SyncLot $lot): array
    {
        // Le lot existe déjà : sa réponse est rendue telle quelle, sans rien
        // réappliquer. C'est tout l'intérêt de l'uuid de lot.
        return $this->reponse($lot, rejoue: true);
    }

    private function reponse(SyncLot $lot, bool $rejoue): array
    {
        $detail = $lot->detail ?? ['acceptes' => [], 'rejetes' => []];

        return [
            'uuid_lot' => $lot->uuid_lot,
            'rejoue' => $rejoue,
            'recu_le' => $lot->recu_le?->toIso8601String(),
            // L'heure du serveur, pour que le téléphone mesure sa dérive sans
            // avoir à la deviner.
            'serveur_le' => now()->toIso8601String(),
            'nb_elements' => (int) $lot->nb_elements,
            'nb_acceptes' => (int) $lot->nb_acceptes,
            'nb_rejetes' => (int) $lot->nb_rejetes,
            'acceptes' => $detail['acceptes'] ?? [],
            'rejetes' => $detail['rejetes'] ?? [],
            'duree_ms' => $lot->duree_ms,
        ];
    }
}
