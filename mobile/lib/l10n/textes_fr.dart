// ignore: unused_import
import 'package:intl/intl.dart' as intl;
import 'textes.dart';

// ignore_for_file: type=lint

/// The translations for French (`fr`).
class TextesFr extends Textes {
  TextesFr([String locale = 'fr']) : super(locale);

  @override
  String get titreApplication => 'PNVB Volontaires';

  @override
  String get demarrageImpossibleTitre => 'L’application n’a pas pu démarrer';

  @override
  String get demarrageImpossibleTexte =>
      'Ses données sur ce téléphone n’ont pas pu être ouvertes. Réessayez ; si le problème continue, prévenez votre superviseur.';

  @override
  String get reseauEnLigne => 'Réseau disponible';

  @override
  String get reseauHorsLigne =>
      'Hors ligne — votre travail reste enregistré sur le téléphone';

  @override
  String get connexionTitre => 'Connexion';

  @override
  String get connexionReseauRequis =>
      'La première connexion demande du réseau. Ensuite, l’application fonctionne sans.';

  @override
  String get connexionTelephone => 'Numéro de téléphone';

  @override
  String get connexionTelephoneAide => 'Exemple : 70 12 34 56';

  @override
  String get connexionMotDePasse => 'Mot de passe';

  @override
  String get connexionBouton => 'Se connecter';

  @override
  String get enCours => 'Patientez…';

  @override
  String get motDePasseTitre => 'Votre mot de passe';

  @override
  String get motDePasseExplication =>
      'Le mot de passe qui vous a été remis est provisoire. Choisissez-en un que vous êtes seul à connaître.';

  @override
  String get motDePasseActuel => 'Mot de passe actuel';

  @override
  String get motDePasseNouveau => 'Nouveau mot de passe';

  @override
  String get motDePasseRegle =>
      'Au moins 8 caractères, dont au moins une lettre et un chiffre.';

  @override
  String get motDePasseConfirmation => 'Confirmez le nouveau mot de passe';

  @override
  String get motDePasseBouton => 'Enregistrer et continuer';

  @override
  String get obligationReseau => 'Cette étape demande du réseau.';

  @override
  String get charteTitre => 'Charte du volontaire';

  @override
  String get charteChargement => 'Chargement de la charte…';

  @override
  String get charteProvisoireTitre =>
      'Texte provisoire — sans valeur juridique';

  @override
  String get charteProvisoireTexte =>
      'Ce texte n’a pas été rédigé par un juriste. Il doit être remplacé par la charte officielle du GIP-PNVB avant toute mise en service sur le terrain.';

  @override
  String get charteAccepter => 'J’accepte la charte';

  @override
  String get charteRefuser => 'Refuser et quitter';

  @override
  String charteVersion(String version) {
    return 'Version $version. Votre acceptation est enregistrée avec sa date et la version du texte.';
  }

  @override
  String get reessayer => 'Réessayer';

  @override
  String accueilBonjour(String nom) {
    return 'Bonjour $nom';
  }

  @override
  String get accueilMission => 'Ma mission';

  @override
  String get accueilAucuneMission => 'Aucune mission en cours.';

  @override
  String accueilSiteDuJour(String site) {
    return 'Site du jour : $site';
  }

  @override
  String get accueilKitAbsent =>
      'Le kit ne passe pas sur votre site aujourd’hui.';

  @override
  String get accueilItineraire => 'Itinéraire vers le site';

  @override
  String get accueilItineraireImpossible =>
      'Ce site n’a pas de coordonnées : aucun itinéraire possible.';

  @override
  String get itineraireEchec =>
      'Aucune application de cartes n’a pu s’ouvrir sur ce téléphone.';

  @override
  String accueilInformationsDu(String date) {
    return 'Informations du $date';
  }

  @override
  String get fileTitre => 'Travail à envoyer';

  @override
  String fileEnAttente(int nombre) {
    String _temp0 = intl.Intl.pluralLogic(
      nombre,
      locale: localeName,
      other: '$nombre éléments attendent d’être envoyés.',
      one: '1 élément attend d’être envoyé.',
      zero: 'Tout est envoyé.',
    );
    return '$_temp0';
  }

  @override
  String fileRejetes(int nombre) {
    String _temp0 = intl.Intl.pluralLogic(
      nombre,
      locale: localeName,
      other: '$nombre éléments refusés par le serveur',
      one: '1 élément refusé par le serveur',
    );
    return '$_temp0';
  }

  @override
  String fileDernierEnvoi(String date) {
    return 'Dernier envoi : $date';
  }

  @override
  String get fileJamaisEnvoye => 'Aucun envoi pour le moment.';

  @override
  String get fileEnvoyer => 'Envoyer maintenant';

  @override
  String envoiTermine(int nombre) {
    String _temp0 = intl.Intl.pluralLogic(
      nombre,
      locale: localeName,
      other: '$nombre éléments envoyés.',
      one: '1 élément envoyé.',
      zero: 'Rien à envoyer : tout est déjà sur le serveur.',
    );
    return '$_temp0';
  }

  @override
  String get envoiHorsLigne =>
      'Pas de réseau : votre travail reste sur le téléphone et partira tout seul.';

  @override
  String get envoiPartiel =>
      'Envoi incomplet : le reste partira à la prochaine tentative.';

  @override
  String get sessionExpiree =>
      'Votre session a expiré. Reconnectez-vous pour envoyer votre travail : il reste gardé sur le téléphone.';

  @override
  String get seReconnecter => 'Se reconnecter';

  @override
  String get accesFerme =>
      'Votre accès est fermé. Le travail fait pendant votre mission peut encore être envoyé pendant quelques jours.';

  @override
  String get deconnexion => 'Se déconnecter';

  @override
  String get deconnexionAvertissementTitre =>
      'Du travail n’est pas encore envoyé';

  @override
  String deconnexionNonEnvoyes(int nombre) {
    String _temp0 = intl.Intl.pluralLogic(
      nombre,
      locale: localeName,
      other: '$nombre éléments ne sont pas encore envoyés.',
      one: '1 élément n’est pas encore envoyé.',
    );
    return '$_temp0';
  }

  @override
  String get deconnexionGarde =>
      'Il reste gardé sur ce téléphone et partira à votre prochaine connexion.';

  @override
  String get annuler => 'Annuler';

  @override
  String get actionsTitre => 'Mon travail';

  @override
  String get actionSignal => 'Je suis arrivé / Je pars';

  @override
  String get actionRapport => 'Mon rapport du jour';

  @override
  String get actionVisas => 'Rapports à viser';

  @override
  String get actionFeuilles => 'Feuilles de présence';

  @override
  String get actionIncident => 'Déclarer un incident';

  @override
  String get actionKit => 'Mon kit';

  @override
  String get actionAlertes => 'Alertes';

  @override
  String get actionAppreciations => 'Mes appréciations';

  @override
  String get actualiserDonnees => 'Actualiser mes données';

  @override
  String donneesDu(String date) {
    return 'Données du $date';
  }

  @override
  String get donneesAbsentes =>
      'Ces informations ne sont pas encore sur le téléphone. Connectez-vous au réseau, puis appuyez sur « Actualiser mes données » depuis l’accueil.';

  @override
  String get preparationHorsLigne =>
      'Pas de réseau : les données gardées sur le téléphone restent utilisables.';

  @override
  String get preparationTerminee => 'Données du jour mises à jour.';

  @override
  String get enregistre =>
      'Enregistré sur le téléphone. Il partira dès que le réseau le permettra.';

  @override
  String get champObligatoire => 'Ce champ est obligatoire.';

  @override
  String get positionRecherche => 'Recherche de la position…';

  @override
  String get valider => 'Valider';

  @override
  String get facultatif => '(facultatif)';

  @override
  String get oui => 'Oui';

  @override
  String get non => 'Non';

  @override
  String get jeNeSaisPas => 'Je ne sais pas';

  @override
  String get signalTitre => 'Arrivée et départ';

  @override
  String get signalExplication =>
      'Appuyez en arrivant sur le site, puis en partant. Ce signal prévient votre superviseur : il ne remplace pas la feuille de présence.';

  @override
  String get signalArrivee => 'JE SUIS ARRIVÉ';

  @override
  String get signalDepart => 'JE PARS';

  @override
  String signalArriveeEnregistree(String heure) {
    return 'Arrivée enregistrée à $heure.';
  }

  @override
  String signalDepartEnregistre(String heure) {
    return 'Départ enregistré à $heure.';
  }

  @override
  String get siteDuJourInconnu =>
      'Le site du jour n’est pas connu sur le téléphone : le serveur le retrouvera.';

  @override
  String signalDansZone(int distance) {
    return 'Vous êtes dans la zone du site, à $distance m.';
  }

  @override
  String signalHorsZone(int distance, int rayon) {
    return 'Vous êtes à $distance m du site, au-delà de sa zone de $rayon m.';
  }

  @override
  String get signalHorsZoneRefus =>
      'Rapprochez-vous du site pour signaler votre arrivée. Si vous êtes bien sur place, prévenez votre superviseur : lui seul peut vous marquer présent sur la feuille.';

  @override
  String get signalHorsZoneToleree =>
      'Votre superviseur verra cet écart. Vous pouvez signaler quand même.';

  @override
  String get signalSiteNonLocalise =>
      'Ce site n’a pas encore de coordonnées : la distance ne peut pas être vérifiée.';

  @override
  String get incidentNature => 'Que s’est-il passé ?';

  @override
  String get incidentNatureObligatoire =>
      'Cochez au moins une nature d’incident.';

  @override
  String get incidentRecit => 'Racontez ce qui s’est passé';

  @override
  String get incidentRecitCourt =>
      'Quelques phrases au moins : le récit doit être exploitable.';

  @override
  String get incidentGravite => 'Gravité';

  @override
  String get incidentGraviteObligatoire => 'Choisissez le niveau de gravité.';

  @override
  String get incidentDanger => 'Danger grave ou immédiat';

  @override
  String get incidentEnCours => 'L’incident est-il toujours en cours ?';

  @override
  String get incidentLieu => 'Où ?';

  @override
  String get incidentLieuPrecision => 'Précisez le lieu (facultatif)';

  @override
  String get incidentImpacts => 'Conséquences (facultatif)';

  @override
  String get incidentPersonnesAffectees =>
      'Nombre de personnes touchées (facultatif)';

  @override
  String get incidentMesures => 'Mesures déjà prises (facultatif)';

  @override
  String get incidentMesuresPrecisions =>
      'Précisions sur les mesures (facultatif)';

  @override
  String get incidentInformes => 'Personnes déjà prévenues (facultatif)';

  @override
  String get incidentEnregistrer => 'Enregistrer la déclaration';

  @override
  String get lieuSite => 'Sur le site';

  @override
  String get lieuTrajetAller => 'Sur le trajet aller';

  @override
  String get lieuTrajetRetour => 'Sur le trajet retour';

  @override
  String get lieuAutre => 'Ailleurs';

  @override
  String get photosTitre => 'Photos (facultatif)';

  @override
  String get photoPrendre => 'Prendre une photo';

  @override
  String get photoRetirer => 'Retirer la photo';

  @override
  String photoImpossible(String message) {
    return 'La photo n’a pas pu être prise : $message';
  }

  @override
  String get kitAucun => 'Aucun kit ne vous est confié sur le téléphone.';

  @override
  String kitEtat(String etat) {
    return 'État : $etat';
  }

  @override
  String get kitPanne => 'Signaler une panne';

  @override
  String get kitPerteVol => 'Déclarer une perte ou un vol';

  @override
  String get kitRestitution => 'Restituer le kit';

  @override
  String get kitAutresMouvements =>
      'La remise, le transfert à un autre agent et le changement de site se déclarent au back-office.';

  @override
  String suiviReponseAgent(String date, String reponse) {
    return 'Réponse de l’agent, le $date : $reponse';
  }

  @override
  String get kitEtatConstate => 'État constaté du kit';

  @override
  String get kitEtatObligatoire =>
      'Constatez l’état du kit : c’est lui qui engage la responsabilité de chacun.';

  @override
  String get etatBon => 'Bon';

  @override
  String get etatUsage => 'Usagé';

  @override
  String get etatEndommage => 'Endommagé';

  @override
  String get etatIncomplet => 'Incomplet';

  @override
  String get kitCirconstance => 'Perte ou vol ?';

  @override
  String get circonstancePerte => 'Perte';

  @override
  String get circonstanceVol => 'Vol';

  @override
  String get kitCommentaire => 'Commentaire (facultatif)';

  @override
  String get kitPhotoSource => 'Photo de celui qui remet le kit';

  @override
  String get kitPhotoDestination => 'Photo de celui qui reçoit le kit';

  @override
  String get kitPhotosConseil =>
      'Les deux photos engagent la responsabilité de chacun en cas de perte ou de casse.';

  @override
  String get kitEnregistrer => 'Enregistrer le mouvement';

  @override
  String get alertesAucune => 'Aucune alerte.';

  @override
  String get alerteNonLue => 'Non lue';

  @override
  String get alerteMarquerLue => 'J’ai lu cette alerte';

  @override
  String get alerteLue => 'Lue';

  @override
  String get appreciationsAucune => 'Aucune appréciation ne vous concerne.';

  @override
  String appreciationDu(String date, String auteur) {
    return 'Rapport du $date, par $auteur';
  }

  @override
  String appreciationProduction(String valeur) {
    return 'Production : $valeur';
  }

  @override
  String appreciationAnomalies(String valeurs) {
    return 'Anomalies : $valeurs';
  }

  @override
  String appreciationObservation(String texte) {
    return 'Observation : $texte';
  }

  @override
  String get appreciationRepondre => 'Répondre';

  @override
  String get appreciationVotreReponse => 'Votre observation';

  @override
  String get appreciationReponseNonModifiable =>
      'Votre réponse sera horodatée et ne pourra plus être modifiée, ni par vous ni par votre supérieur.';

  @override
  String get appreciationEnvoyerReponse => 'Enregistrer ma réponse';

  @override
  String get appreciationReponseEnAttente =>
      'Réponse enregistrée, en attente d’envoi';

  @override
  String appreciationVotreReponseDu(String date) {
    return 'Votre réponse du $date';
  }

  @override
  String get productionPassable => 'Passable';

  @override
  String get productionPeuSatisfaisant => 'Peu satisfaisant';

  @override
  String get productionSatisfaisant => 'Satisfaisant';

  @override
  String get anomalieRetard => 'Retard';

  @override
  String get anomalieAbsenteisme => 'Absentéisme';

  @override
  String get anomalieProposDiscourtois => 'Propos discourtois';

  @override
  String get anomalieAutre => 'Autre';

  @override
  String alertesNonLues(int nombre) {
    String _temp0 = intl.Intl.pluralLogic(
      nombre,
      locale: localeName,
      other: '$nombre alertes non lues',
      one: '1 alerte non lue',
      zero: 'Aucune alerte non lue',
    );
    return '$_temp0';
  }

  @override
  String visasEnAttente(int nombre) {
    String _temp0 = intl.Intl.pluralLogic(
      nombre,
      locale: localeName,
      other: '$nombre rapports à viser',
      one: '1 rapport à viser',
      zero: 'Aucun rapport à viser',
    );
    return '$_temp0';
  }

  @override
  String feuillesAValider(int nombre) {
    String _temp0 = intl.Intl.pluralLogic(
      nombre,
      locale: localeName,
      other: '$nombre feuilles à valider',
      one: '1 feuille à valider',
      zero: 'Aucune feuille à valider',
    );
    return '$_temp0';
  }

  @override
  String preparationEchecs(String details) {
    return 'Certaines données n’ont pas pu être mises à jour : $details';
  }

  @override
  String get confirmer => 'Confirmer';

  @override
  String get sansPositionTitre => 'Position introuvable';

  @override
  String get sansPositionContinuer => 'Continuer sans position';

  @override
  String kitMouvementEnAttente(String mouvement) {
    return 'Déclaration enregistrée sur le téléphone, en attente d’envoi : $mouvement';
  }

  @override
  String get etatKitPanne => 'En panne';

  @override
  String get etatKitPerdu => 'Perdu';

  @override
  String get etatKitVole => 'Volé';

  @override
  String get etatKitReforme => 'Réformé';

  @override
  String get rapportTypeAopk => 'Rapport d’accueil (A-OPK)';

  @override
  String get rapportTypeOpk => 'Rapport de production (opérateur)';

  @override
  String get rapportTypeSuperviseur => 'Rapport de centre (superviseur)';

  @override
  String get rapportStatutBrouillon => 'Brouillon';

  @override
  String get rapportStatutSoumis => 'Signé, en attente de visa';

  @override
  String get rapportStatutVise => 'Visé';

  @override
  String get rapportStatutRejete => 'Renvoyé pour correction';

  @override
  String get rapportStatutClos => 'Clos';

  @override
  String rapportJournee(String date) {
    return 'Journée du $date';
  }

  @override
  String rapportMotifRejet(String motif) {
    return 'Motif du renvoi : $motif';
  }

  @override
  String get rapportNonModifiable =>
      'Ce rapport est signé : il n’est plus modifiable. Il ne le redevient que s’il vous est renvoyé pour correction.';

  @override
  String get rapportSigneEnAttente =>
      'Signé sur le téléphone, en attente d’envoi.';

  @override
  String get rapportIdentification => 'Identification';

  @override
  String get rapportIdentificationAide =>
      'Pré-remplie depuis votre affectation : vérifiez-la, vous ne la saisissez pas.';

  @override
  String rapportLigne(String libelle, String valeur) {
    return '$libelle : $valeur';
  }

  @override
  String get rapportCentre => 'Centre';

  @override
  String get rapportSite => 'Site';

  @override
  String get rapportSuperieur => 'Supérieur désigné';

  @override
  String get rapportHeureArrivee => 'Heure d’arrivée';

  @override
  String get rapportHeureDepart => 'Heure de départ';

  @override
  String get rapportHeureNonSaisie => 'non saisie';

  @override
  String get rapportActivites => 'Activités d’accueil';

  @override
  String get rapportPrevu => 'Prévu';

  @override
  String get rapportRealise => 'Réalisé';

  @override
  String rapportPrevuRealise(String libelle, String prevu, String realise) {
    return '$libelle — prévu : $prevu, réalisé : $realise';
  }

  @override
  String get activiteAffluence => 'Affluence';

  @override
  String get activiteJustificatifsRecus => 'Justificatifs reçus';

  @override
  String get activiteJustificatifsTransmis => 'Justificatifs transmis';

  @override
  String get activitePlaintesEnregistrees => 'Plaintes enregistrées';

  @override
  String get activitePlaintesReversees => 'Plaintes reversées';

  @override
  String get affluenceFaible => 'Faible (1 à 25)';

  @override
  String get affluenceMoyen => 'Moyenne (25 à 50)';

  @override
  String get affluenceEleve => 'Élevée (50 à 100)';

  @override
  String get rapportProduction => 'Production du kit';

  @override
  String get productionObjectif => 'Objectif du jour';

  @override
  String get productionObjectifAide =>
      'Figé à l’ouverture du rapport. L’écart et le taux de réalisation sont calculés par le serveur.';

  @override
  String get productionEnregistrements => 'Enregistrements réalisés';

  @override
  String get productionRecepisses => 'Récépissés transmis';

  @override
  String get productionNonValides => 'Enregistrements non validés';

  @override
  String get productionMotifNonValides =>
      'Pourquoi ces enregistrements ne sont-ils pas validés ?';

  @override
  String get productionMotifObligatoire =>
      'Indiquez pourquoi ces enregistrements ne sont pas validés : le chiffre seul ne suffit pas.';

  @override
  String get productionEcart => 'Écart';

  @override
  String get productionTaux => 'Taux de réalisation';

  @override
  String get productionEtatKit => 'État du kit';

  @override
  String get etatKitFonctionnel => 'Fonctionnel';

  @override
  String get etatKitPannePartielle => 'Panne partielle';

  @override
  String get etatKitPanneTotale => 'Panne totale';

  @override
  String get rapportEvolution => 'Évolution des enregistrements';

  @override
  String get rapportEvolutionAide =>
      'Consolidée depuis les rapports d’opérateur déjà visés : elle ne se ressaisit pas. Une correction se fait au back-office, avec son motif.';

  @override
  String get evolutionPersonnes => 'Personnes enregistrées';

  @override
  String get evolutionValides => 'Dossiers validés';

  @override
  String get evolutionAReprendre => 'Dossiers à reprendre';

  @override
  String get rapportQualite => 'Contrôle qualité';

  @override
  String get rapportQualiteAide =>
      'Le taux de conformité est calculé par le serveur.';

  @override
  String get qualiteControles => 'Dossiers contrôlés';

  @override
  String get qualiteConformes => 'Dossiers conformes';

  @override
  String get qualiteNonConformes => 'Dossiers non conformes';

  @override
  String get qualiteDoublons => 'Doublons détectés';

  @override
  String get qualiteErreurs => 'Erreurs de saisie';

  @override
  String get qualiteCorrections => 'Corrections effectuées';

  @override
  String get qualiteIncidents => 'Incidents majeurs';

  @override
  String get rapportLogistique => 'Situation logistique';

  @override
  String get logistiqueRessource => 'Ressource';

  @override
  String get logistiqueDisponible => 'Disponible';

  @override
  String get logistiqueFonctionnelle => 'Fonctionnelle';

  @override
  String get logistiqueBesoin => 'Besoin';

  @override
  String get logistiqueObservation => 'Observation (facultatif)';

  @override
  String get logistiqueAjouter => 'Ajouter une ressource';

  @override
  String get rapportDifficultes => 'Difficultés rencontrées et solutions';

  @override
  String get rapportDifficulte => 'Difficulté';

  @override
  String get rapportSolution => 'Solution apportée ou proposée (facultatif)';

  @override
  String get rapportAjouterDifficulte => 'Ajouter une difficulté';

  @override
  String get rapportPoints => 'Points à améliorer';

  @override
  String get rapportPoint => 'Point à améliorer';

  @override
  String get rapportAjouterPoint => 'Ajouter un point';

  @override
  String get retirerLigne => 'Retirer cette ligne';

  @override
  String get rapportSuiviAgents => 'Suivi des agents';

  @override
  String get rapportSuiviAide =>
      'La présence vient de la feuille de présence validée : elle ne se saisit pas ici.';

  @override
  String suiviPresence(String valeur) {
    return 'Présence : $valeur';
  }

  @override
  String get suiviProduction => 'Production';

  @override
  String get suiviAnomalies => 'Anomalies (facultatif)';

  @override
  String get suiviObservation => 'Observation (facultatif)';

  @override
  String get presencePresent => 'Présent';

  @override
  String get presenceAbsent => 'Absent';

  @override
  String get presenceAbsentJustifie => 'Absent justifié';

  @override
  String get presenceInconnue => 'pas encore connue';

  @override
  String get categorieSuperviseur => 'Superviseur';

  @override
  String get categorieOperateur => 'Opérateur de kit';

  @override
  String get categorieAssistant => 'A-OPK';

  @override
  String get rapportEnregistrer => 'Enregistrer sans signer';

  @override
  String get rapportSigner => 'Signer et envoyer';

  @override
  String get rapportSignerTitre => 'Signer le rapport ?';

  @override
  String get rapportSignerTexte =>
      'Une fois signé, le rapport part à votre supérieur et vous ne pouvez plus le modifier.';

  @override
  String get rapportSigne =>
      'Rapport signé. Il partira dès que le réseau le permettra.';

  @override
  String get visasAide =>
      'Vos agents ont signé : leurs chiffres ne remonteront qu’après votre visa.';

  @override
  String get visasAucun =>
      'Aucun rapport n’attend votre visa sur le téléphone.';

  @override
  String visaSigneLe(String date) {
    return 'Signé le $date';
  }

  @override
  String get visaOuvrir => 'Lire et viser';

  @override
  String get visaContenuPartiel =>
      'Les difficultés et le suivi des agents se lisent au back-office.';

  @override
  String get visaCommentaire => 'Commentaire (facultatif)';

  @override
  String get visaViser => 'Viser le rapport';

  @override
  String get visaRenvoyerTitre => 'Renvoyer pour correction';

  @override
  String get visaMotif => 'Ce qui doit être corrigé';

  @override
  String get visaMotifObligatoire =>
      'Dites ce qui doit être corrigé : un renvoi sans motif est inexploitable.';

  @override
  String get visaRenvoyer => 'Renvoyer à l’auteur';

  @override
  String get visaFait =>
      'Visa enregistré. Il partira dès que le réseau le permettra.';

  @override
  String get renvoiFait =>
      'Renvoi enregistré. Il partira dès que le réseau le permettra.';

  @override
  String get feuillesAucune =>
      'Aucune feuille de présence du jour sur le téléphone.';

  @override
  String get feuilleAValider => 'À valider';

  @override
  String get feuilleValidee => 'Validée';

  @override
  String get feuilleValideeEnAttente =>
      'Validée sur le téléphone, en attente d’envoi';

  @override
  String feuilleAgents(int nombre) {
    String _temp0 = intl.Intl.pluralLogic(
      nombre,
      locale: localeName,
      other: '$nombre agents attendus',
      one: '1 agent attendu',
      zero: 'Aucun agent attendu',
    );
    return '$_temp0';
  }

  @override
  String get feuilleOuvrir => 'Ouvrir la feuille';

  @override
  String get feuilleAide =>
      'Marquez chaque agent. Le signal d’arrivée vous aide, mais seule votre validation fait foi.';

  @override
  String get feuilleDejaValidee =>
      'Cette feuille est validée : seul le chef d’antenne régional peut la corriger.';

  @override
  String feuilleSignal(String heure, String distance, String zone) {
    return 'Signal d’arrivée à $heure, à $distance m du site $zone';
  }

  @override
  String get feuilleSignalDansZone => '(dans la zone)';

  @override
  String get feuilleSignalHorsZone => '(hors de la zone)';

  @override
  String get feuilleAucunSignal => 'Aucun signal d’arrivée';

  @override
  String get feuilleMotif => 'Motif de l’absence';

  @override
  String get feuilleMotifObligatoire => 'Une absence justifiée exige un motif.';

  @override
  String get feuilleNonMarque =>
      'Marquez la présence de chaque agent avant de valider.';

  @override
  String get feuilleValider => 'Valider la feuille';

  @override
  String get feuilleConfirmationTitre => 'Valider la feuille ?';

  @override
  String get feuilleConfirmationTexte =>
      'Votre position est enregistrée avec la validation. Une feuille validée ne se modifie plus : seul le chef d’antenne régional peut la corriger.';

  @override
  String get feuilleValideeEnregistree =>
      'Feuille validée. Elle partira dès que le réseau le permettra.';

  @override
  String get sansPositionFeuille =>
      'Sans position, la distance entre vous et le site ne pourra pas être vérifiée.';
}
