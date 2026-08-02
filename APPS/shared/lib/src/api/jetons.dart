import 'package:flutter_secure_storage/flutter_secure_storage.dart';

/// Rangement des deux jetons de l'API mobile.
///
/// ═════════════════════════════════════════════════════════════════════════════
/// POURQUOI DEUX JETONS, ET POURQUOI ILS NE SE RANGENT PAS AU MÊME ENDROIT
///
///   • `device_token` — 64 caractères hexadécimaux, SANS EXPIRATION. C'est lui
///     qui fait qu'on reste connecté d'une course à l'autre. Il ne circule
///     qu'au renouvellement. Un vol de ce jeton donne un accès durable au
///     compte : il va dans le trousseau sécurisé du système (Keychain iOS,
///     EncryptedSharedPreferences Android), jamais ailleurs.
///
///   • `access_token` — signé, valable une heure par défaut. Il accompagne
///     chaque requête. Il n'est gardé QU'EN MÉMOIRE : l'écrire sur le disque
///     l'exposerait sans rien gagner, puisqu'il se rachète en un appel à
///     /auth/refresh.
///
/// ⚠️ NE JAMAIS écrire le device_token dans SharedPreferences, ni le
/// journaliser, ni l'envoyer dans une URL. Le corps de requête, et rien d'autre.
class Jetons {
  Jetons({FlutterSecureStorage? coffre})
      : _coffre = coffre ??
            const FlutterSecureStorage(
              aOptions: AndroidOptions(encryptedSharedPreferences: true),
              iOptions: IOSOptions(
                // Le jeton doit survivre au redémarrage sans exiger le
                // déverrouillage : la montre envoie des détections écran
                // éteint, poignet baissé.
                accessibility: KeychainAccessibility.first_unlock,
              ),
            );

  static const _cleAppareil = 'fer_device_token';

  final FlutterSecureStorage _coffre;

  String? _acces;
  DateTime? _accesExpireLe;
  String? _appareil;
  bool _charge = false;

  /// Jeton d'accès courant, ou `null` s'il manque ou s'il est périmé.
  ///
  /// La marge de 30 secondes évite le cas classique : un jeton jugé valide à
  /// l'instant de l'envoi, expiré à l'instant où le serveur le lit.
  String? get acces {
    if (_acces == null || _accesExpireLe == null) return null;
    if (DateTime.now().isAfter(
      _accesExpireLe!.subtract(const Duration(seconds: 30)),
    )) {
      return null;
    }
    return _acces;
  }

  /// Jeton d'appareil, lu du trousseau au premier appel.
  Future<String?> appareil() async {
    if (!_charge) {
      _appareil = await _coffre.read(key: _cleAppareil);
      _charge = true;
    }
    return _appareil;
  }

  /// Vrai dès qu'un jeton d'appareil existe — c'est la définition de
  /// « connecté » pour cette application.
  Future<bool> connecte() async => (await appareil()) != null;

  Future<void> enregistrerAppareil(String jeton) async {
    _appareil = jeton;
    _charge = true;
    await _coffre.write(key: _cleAppareil, value: jeton);
  }

  void enregistrerAcces(String jeton, DateTime? expireLe) {
    _acces = jeton;
    // Sans date d'expiration annoncée, on se donne une heure — la valeur par
    // défaut de `app_access_token_ttl_min` côté serveur. Le pire cas est un
    // rafraîchissement de trop, jamais une requête qui échoue en silence.
    _accesExpireLe = expireLe ?? DateTime.now().add(const Duration(hours: 1));
  }

  /// Efface tout. Appelé à la déconnexion ET sur `device_revoked` : garder un
  /// jeton mort ferait échouer chaque requête sans que personne comprenne
  /// pourquoi.
  Future<void> effacer() async {
    _acces = null;
    _accesExpireLe = null;
    _appareil = null;
    _charge = true;
    await _coffre.delete(key: _cleAppareil);
  }
}
