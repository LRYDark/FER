/// Erreur renvoyée par l'API mobile.
///
/// ═════════════════════════════════════════════════════════════════════════════
/// LE MESSAGE DU SERVEUR EST ÉCRIT POUR ÊTRE LU TEL QUEL.
///
/// Côté PHP, chaque `api_err()` porte une phrase rédigée en français pour la
/// personne, pas pour le développeur : « Ce code a expiré. », « Trop de
/// tentatives. Demandez un nouveau code. ». Les afficher directement évite
/// d'entretenir une seconde table de traductions qui divergera au premier
/// message ajouté côté serveur.
///
/// Le [code] sert à DÉCIDER, jamais à afficher : c'est lui qui dit s'il faut
/// rafraîchir un jeton, masquer un écran ou bloquer l'application.
library;

class ApiErreur implements Exception {
  ApiErreur({
    required this.statut,
    required this.code,
    required this.message,
    this.extra = const <String, dynamic>{},
  });

  /// Code HTTP.
  final int statut;

  /// Code métier : `invalid_code`, `chrono_disabled`, `app_outdated`…
  final String code;

  /// Phrase destinée au coureur, telle que le serveur l'a écrite.
  final String message;

  /// Champs supplémentaires portés par l'erreur. `app_outdated` transporte
  /// ainsi `version_minimale` et `config_url` — l'application n'a pas à faire
  /// un second appel pour savoir vers quoi diriger la personne.
  final Map<String, dynamic> extra;

  /// Réseau injoignable, DNS mort, serveur qui ne répond pas. Statut 0 : il n'y
  /// a pas eu de réponse HTTP du tout, la requête n'est pas partie ou n'est pas
  /// revenue. À distinguer d'un refus du serveur, qui, lui, est une réponse.
  bool get estReseau => statut == 0;

  /// Le jeton d'accès est absent, invalide ou expiré : un rafraîchissement peut
  /// sauver la requête. Ne comprend PAS `device_revoked`, qui est définitif.
  bool get estJetonExpire =>
      statut == 401 && (code == 'missing_token' || code == 'invalid_token');

  /// L'appareil a été révoqué depuis un autre appareil, ou le compte est
  /// désactivé. Il n'y a rien à réessayer : il faut se reconnecter.
  bool get estDeconnecte =>
      code == 'device_revoked' || code == 'account_disabled';

  /// Le serveur refuse cette version de l'application. ÉCRAN BLOQUANT : une
  /// version refusée ne peut plus rien faire, la contourner n'a aucun sens.
  bool get estTropAncienne => statut == 426 || code == 'app_outdated';

  /// L'API mobile est fermée (interrupteur de Réglages → API), ou la migration
  /// de base n'a pas été jouée. Réessayable plus tard.
  bool get estHorsService =>
      code == 'api_disabled' || code == 'not_installed';

  /// Le chronométrage n'est pas ouvert : les écrans de course doivent
  /// disparaître. Ce n'est PAS une panne — inutile de réessayer, et surtout
  /// inutile d'alarmer qui que ce soit.
  bool get estChronoFerme => code == 'chrono_disabled';

  /// Le suivi GPS n'a pas été autorisé. L'application doit demander l'accord
  /// avant de renvoyer des points.
  bool get estConsentementRequis => code == 'consent_required';

  /// Trop de demandes de code. NE PAS réessayer automatiquement : le débit est
  /// limité par adresse ET par IP, insister ne fait qu'allonger l'attente.
  bool get estDebitLimite => statut == 429 || code == 'rate_limited';

  /// Version minimale exigée, quand le serveur l'a jointe au refus.
  String? get versionMinimale => extra['version_minimale'] as String?;

  @override
  String toString() => 'ApiErreur($statut/$code) $message';
}
