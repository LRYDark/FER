/// Client de l'API mobile `/api/mobile` de Forbach en Rose.
///
/// ═════════════════════════════════════════════════════════════════════════════
/// TOUTES LES RÉPONSES ONT LA MÊME FORME
///
///     { "ok": true,  "data": …,  "error": null }
///     { "ok": false, "data": null, "error": { "code": "…", "message": "…" } }
///
/// L'enveloppe est déballée ici, une fois pour toutes : les écrans reçoivent
/// des objets, ou une [ApiErreur]. Aucun `response.statusCode` ne remonte
/// au-delà de ce fichier.
///
/// ═════════════════════════════════════════════════════════════════════════════
/// TROIS RÈGLES IMPOSÉES PAR LE SERVEUR, ET NON NÉGOCIABLES
///
///   1. HTTPS obligatoire — le serveur refuse le HTTP en 403 `https_required`.
///      La seule exception admise est la boucle locale, pour le développement.
///   2. En-tête `X-App-Version` sur CHAQUE requête sauf /app/config. Absent, le
///      serveur répond 400. Trop ancienne, il répond 426 et l'application doit
///      afficher un écran bloquant : le refus vient du serveur, pas de la bonne
///      volonté du client.
///   3. Aucune donnée personnelle dans une URL. Tout passe par le corps JSON.
library;

import 'dart:convert';

import 'package:http/http.dart' as http;

import 'api_erreur.dart';
import 'jetons.dart';

/// Enveloppe le résultat d'un appel : soit la donnée, soit l'erreur.
typedef Reponse = Map<String, dynamic>;

class ApiClient {
  ApiClient({
    required this.baseUrl,
    required this.version,
    required this.jetons,
    http.Client? http_,
  }) : _http = http_ ?? http.Client();

  /// Racine de l'API, par exemple `https://exemple.fr/FER/api/mobile`.
  /// Sans barre oblique finale — [_uri] la pose.
  final String baseUrl;

  /// Version de l'application, lue du binaire par `package_info_plus`.
  /// ⚠️ Jamais une constante écrite à la main : elle finirait par mentir.
  final String version;

  final Jetons jetons;
  final http.Client _http;

  /// Appelé quand le serveur nous déclare déconnectés (`device_revoked`,
  /// `account_disabled`). La session s'y branche pour ramener à l'écran de
  /// connexion sans que chaque écran ait à y penser.
  void Function()? surDeconnexion;

  /// Appelé sur un 426. L'interface s'y branche pour afficher l'écran bloquant
  /// « mettez à jour », avec le lien du magasin.
  void Function(ApiErreur)? surVersionRefusee;

  Uri _uri(String chemin, [Map<String, String>? requete]) {
    final u = Uri.parse('$baseUrl/${chemin.replaceFirst(RegExp(r'^/+'), '')}');
    return requete == null ? u : u.replace(queryParameters: requete);
  }

  /* ══════════════════════════ Cœur des appels ══════════════════════════ */

  /// Exécute une requête et déballe l'enveloppe.
  ///
  /// [avecJeton] à false pour les routes publiques (/app/config, /auth/…).
  /// [reessaye] est un garde-fou interne : après un rafraîchissement de jeton
  /// on retente UNE fois, jamais deux — sinon un jeton durablement refusé
  /// bouclerait indéfiniment contre le serveur.
  Future<dynamic> _appel(
    String methode,
    String chemin, {
    Object? corps,
    bool avecJeton = true,
    bool reessaye = true,
  }) async {
    final entetes = <String, String>{
      'Accept': 'application/json',
      if (corps != null) 'Content-Type': 'application/json',
      // /app/config est la SEULE route dispensée : c'est là qu'une application
      // périmée apprend qu'elle doit se mettre à jour. Envoyer l'en-tête
      // quand même ne coûte rien et évite une exception dans la règle.
      'X-App-Version': version,
    };

    if (avecJeton) {
      final acces = jetons.acces ?? await _rafraichir();
      if (acces == null) {
        // Aucun jeton obtenable : l'appareil n'est pas (ou plus) connecté.
        surDeconnexion?.call();
        throw ApiErreur(
          statut: 401,
          code: 'missing_token',
          message: 'Vous devez vous reconnecter.',
        );
      }
      entetes['Authorization'] = 'Bearer $acces';
    }

    late http.Response reponse;
    try {
      final uri = _uri(chemin);
      final charge = corps == null ? null : jsonEncode(corps);
      reponse = await switch (methode) {
        'GET' => _http.get(uri, headers: entetes),
        'POST' => _http.post(uri, headers: entetes, body: charge),
        'PATCH' => _http.patch(uri, headers: entetes, body: charge),
        'DELETE' => _http.delete(uri, headers: entetes, body: charge),
        _ => throw ArgumentError('Méthode inconnue : $methode'),
      }
          .timeout(const Duration(seconds: 20));
    } catch (e) {
      // Aucune réponse HTTP : réseau coupé, DNS mort, serveur muet. Statut 0
      // pour que l'appelant distingue « pas de réseau » d'un refus du serveur —
      // le premier se réessaye, le second non.
      throw ApiErreur(
        statut: 0,
        code: 'network',
        message: 'Serveur injoignable. Vérifiez votre connexion.',
        extra: <String, dynamic>{'cause': e.toString()},
      );
    }

    Map<String, dynamic> enveloppe;
    try {
      enveloppe = jsonDecode(reponse.body) as Map<String, dynamic>;
    } catch (_) {
      // Réponse illisible : très souvent une page d'erreur HTML du serveur web
      // (503 de maintenance, page de connexion d'un portail wifi). Le dire
      // ainsi plutôt que « erreur de format », qui n'aide personne.
      throw ApiErreur(
        statut: reponse.statusCode,
        code: 'reponse_illisible',
        message: "Le serveur n'a pas répondu en JSON "
            '(code ${reponse.statusCode}).',
      );
    }

    if (enveloppe['ok'] == true) return enveloppe['data'];

    final err = (enveloppe['error'] as Map<String, dynamic>?) ??
        <String, dynamic>{};
    final erreur = ApiErreur(
      statut: reponse.statusCode,
      code: (err['code'] as String?) ?? 'inconnu',
      message: (err['message'] as String?) ?? 'Erreur inconnue.',
      // `version_minimale` et `config_url` voyagent au même niveau que code et
      // message dans le 426 : on garde tout le reste tel quel.
      extra: <String, dynamic>{...err}
        ..remove('code')
        ..remove('message'),
    );

    // Jeton d'accès périmé : on en rachète un et on retente UNE fois. C'est le
    // cas normal après une heure d'inactivité, il ne doit rien coûter à
    // l'utilisateur.
    if (erreur.estJetonExpire && avecJeton && reessaye) {
      final neuf = await _rafraichir();
      if (neuf != null) {
        return _appel(methode, chemin,
            corps: corps, avecJeton: avecJeton, reessaye: false);
      }
    }

    if (erreur.estDeconnecte) {
      await jetons.effacer();
      surDeconnexion?.call();
    }
    if (erreur.estTropAncienne) surVersionRefusee?.call(erreur);

    throw erreur;
  }

  /// Rachat en cours, s'il y en a un.
  ///
  /// ⚠️ SANS CE VERROU, UN DÉMARRAGE DEMANDE QUATRE JETONS AU LIEU D'UN.
  /// `Session.rafraichir()` lance ses appels ENSEMBLE — profil, inscriptions,
  /// éditions, résultats. Aucun n'a de jeton d'accès en mémoire au lancement :
  /// les quatre appelaient donc `_rafraichir()` en même temps. Le journal du
  /// serveur le montre noir sur blanc, quatre `POST /auth/refresh` à la même
  /// seconde pour une seule ouverture de l'application.
  ///
  /// Ce n'est pas qu'une question de politesse envers le serveur : les quatre
  /// réponses arrivaient dans un ordre quelconque et écrasaient tour à tour le
  /// jeton enregistré. Trois d'entre elles étaient au mieux inutiles.
  ///
  /// Le premier appelant lance la demande, les trois autres attendent la même.
  Future<String?>? _rachatEnCours;

  /// Rachète un jeton d'accès à partir du jeton d'appareil.
  /// Renvoie `null` si l'appareil n'est plus reconnu — le seul cas où il faut
  /// vraiment redemander une connexion.
  Future<String?> _rafraichir() {
    // Pas d'`await` avant cette ligne : le partage doit être décidé de façon
    // atomique. Un `await` ici rouvrirait la fenêtre qu'on vient de fermer.
    final enCours = _rachatEnCours;
    if (enCours != null) return enCours;

    final futur = _rafraichirVraiment();
    _rachatEnCours = futur;
    // `whenComplete` et non `then` : le verrou doit sauter même si la demande
    // échoue, sinon une coupure de réseau au lancement interdirait toute
    // nouvelle tentative jusqu'à la fermeture de l'application.
    return futur.whenComplete(() => _rachatEnCours = null);
  }

  Future<String?> _rafraichirVraiment() async {
    final appareil = await jetons.appareil();
    if (appareil == null) return null;
    try {
      final d = await _appel('POST', 'auth/refresh',
          corps: <String, dynamic>{'device_token': appareil},
          avecJeton: false,
          reessaye: false) as Map<String, dynamic>;
      final expire = _dateOuNull(d['expires_at'] as String?);
      jetons.enregistrerAcces(d['access_token'] as String, expire);
      return d['access_token'] as String;
    } on ApiErreur catch (e) {
      // Réseau coupé : le jeton d'appareil reste valable, on ne déconnecte
      // surtout pas. Déconnecter quelqu'un parce qu'il est passé sous un
      // tunnel serait le pire des comportements pendant une course.
      if (e.estReseau) return null;
      if (e.estDeconnecte) {
        await jetons.effacer();
        surDeconnexion?.call();
      }
      return null;
    }
  }

  static DateTime? _dateOuNull(String? iso) =>
      (iso == null || iso.isEmpty) ? null : DateTime.tryParse(iso);

  /* ═══════════════════════════ /app/config ═════════════════════════════ */

  /// Configuration publique. Joignable SANS jeton et même par une application
  /// refusée pour cause de version — c'est là qu'elle apprend quoi faire.
  ///
  /// Porte `chrono_actif` : l'application masque ses écrans de course AVANT la
  /// connexion, plutôt que de découvrir un 403 sur la ligne d'arrivée.
  Future<Map<String, dynamic>> config() async =>
      await _appel('GET', 'app/config', avecJeton: false)
          as Map<String, dynamic>;

  /* ═════════════════════════════ /auth/… ═══════════════════════════════ */

  /// Demande l'envoi d'un code à 6 chiffres.
  ///
  /// ⚠️ La réponse est IDENTIQUE que l'adresse soit inscrite ou non : l'API ne
  /// révèle pas qui participe. Ne jamais afficher « adresse inconnue » ici —
  /// ce serait réintroduire côté application la fuite que le serveur évite.
  Future<Map<String, dynamic>> demanderCode(String email) async =>
      await _appel('POST', 'auth/request-code',
              corps: <String, dynamic>{'email': email}, avecJeton: false)
          as Map<String, dynamic>;

  /// Vérifie le code et ouvre la session. [infosAppareil] alimente la liste
  /// « Mes appareils » : sans libellé, on ne peut pas révoquer un téléphone
  /// perdu, faute de savoir lequel c'est.
  Future<Map<String, dynamic>> verifierCode({
    required String email,
    required String code,
    Map<String, String>? infosAppareil,
  }) async {
    final d = await _appel('POST', 'auth/verify-code',
        corps: <String, dynamic>{
          'email': email,
          'code': code,
          if (infosAppareil != null) 'device_info': infosAppareil,
        },
        avecJeton: false) as Map<String, dynamic>;

    await jetons.enregistrerAppareil(d['device_token'] as String);
    jetons.enregistrerAcces(
      d['access_token'] as String,
      _dateOuNull(d['expires_at'] as String?),
    );
    return d;
  }

  /// Révoque CET appareil côté serveur, puis efface les jetons locaux.
  ///
  /// L'effacement local a lieu même si l'appel échoue : rester « connecté » sur
  /// un appareil dont on vient de demander la déconnexion serait le contraire
  /// de ce qui a été demandé.
  Future<void> deconnexion() async {
    try {
      await _appel('POST', 'auth/logout');
    } on ApiErreur {
      // Sans importance : le jeton local part de toute façon.
    } finally {
      await jetons.effacer();
    }
  }

  /* ═══════════════════════════════ /me/… ═══════════════════════════════ */

  Future<Map<String, dynamic>> profil() async =>
      await _appel('GET', 'me') as Map<String, dynamic>;

  Future<String> majIdentite({String? nom, String? prenom}) async {
    final d = await _appel('PATCH', 'me', corps: <String, dynamic>{
      if (nom != null) 'nom': nom,
      if (prenom != null) 'prenom': prenom,
    }) as Map<String, dynamic>;
    return d['message'] as String;
  }

  /// Première moitié du changement d'adresse : un code part vers la NOUVELLE
  /// adresse. En un seul appel, une faute de frappe enfermerait le coureur
  /// dehors — l'adresse est son unique moyen de se reconnecter.
  Future<String> demanderChangementEmail(String nouvelEmail) async {
    final d = await _appel('POST', 'me/email/request-change',
        corps: <String, dynamic>{'email': nouvelEmail}) as Map<String, dynamic>;
    return d['message'] as String;
  }

  Future<String> confirmerChangementEmail(String email, String code) async {
    final d = await _appel('POST', 'me/email/confirm',
            corps: <String, dynamic>{'email': email, 'code': code})
        as Map<String, dynamic>;
    return d['message'] as String;
  }

  Future<List<Map<String, dynamic>>> inscriptions() async =>
      (await _appel('GET', 'me/registrations') as List<dynamic>)
          .cast<Map<String, dynamic>>();

  /// Une inscription par sa CLÉ MÉTIER (année + numéro). Les identifiants
  /// techniques changent de table à chaque archivage annuel : les utiliser
  /// casserait la consultation des éditions passées.
  Future<Map<String, dynamic>> inscription(int annee, String no) async =>
      await _appel('GET', 'me/registrations/$annee/${Uri.encodeComponent(no)}')
          as Map<String, dynamic>;

  /// QR code en PNG, encodé base64. Le MÊME générateur que le mail de
  /// confirmation : il n'existe qu'un seul QR par inscription.
  Future<String> qrCode(int annee, String no) async {
    final d = await _appel(
      'GET',
      'me/registrations/$annee/${Uri.encodeComponent(no)}/qrcode',
    ) as Map<String, dynamic>;
    return d['png_base64'] as String;
  }

  /// Sexe et âge — les deux seuls champs corrigeables par le coureur. Le
  /// serveur refuse les éditions archivées et les corrections après le départ ;
  /// l'application ne rejoue pas ces règles, elle affiche le refus.
  Future<Map<String, dynamic>> majInscription(
    int annee,
    String no, {
    String? sexe,
    String? age,
  }) async =>
      await _appel(
        'PATCH',
        'me/registrations/$annee/${Uri.encodeComponent(no)}',
        corps: <String, dynamic>{
          if (sexe != null) 'sexe': sexe,
          if (age != null) 'age': age,
        },
      ) as Map<String, dynamic>;

  Future<List<Map<String, dynamic>>> appareils() async =>
      (await _appel('GET', 'me/devices') as List<dynamic>)
          .cast<Map<String, dynamic>>();

  Future<void> revoquerAppareil(int id) async =>
      await _appel('DELETE', 'me/devices/$id');

  Future<List<Map<String, dynamic>>> transferts() async =>
      (await _appel('GET', 'me/transfers') as List<dynamic>)
          .cast<Map<String, dynamic>>();

  Future<Map<String, dynamic>> creerTransfert({
    required int annee,
    required String inscriptionNo,
    required String emailCible,
  }) async =>
      await _appel('POST', 'me/transfers', corps: <String, dynamic>{
        'annee': annee,
        'inscription_no': inscriptionNo,
        'email_cible': emailCible,
      }) as Map<String, dynamic>;

  Future<void> annulerTransfert(int id) async =>
      await _appel('DELETE', 'me/transfers/$id');

  /* ════════════════════════ Données de course ══════════════════════════ */

  /// Envoie un lot de détections (balise ET franchissement GPS).
  ///
  /// ⚠️ L'APPLICATION N'ENVOIE JAMAIS UN TEMPS, seulement des observations
  /// horodatées. Le temps est calculé par le serveur. Une application qui
  /// enverrait « j'ai fait 42 minutes » ferait une déclaration, pas une mesure,
  /// et le premier classement contesté serait indéfendable.
  ///
  /// Le type `manuel` est refusé en 403 : il est réservé à l'organisation et
  /// prime sur tout le reste. 200 détections par appel au maximum.
  Future<Map<String, dynamic>> envoyerDetections({
    required int annee,
    required String inscriptionNo,
    required List<Map<String, dynamic>> detections,
  }) async =>
      await _appel('POST', 'me/detections', corps: <String, dynamic>{
        'annee': annee,
        'inscription_no': inscriptionNo,
        'detections': detections,
      }) as Map<String, dynamic>;

  /// Donne ou retire l'accord au suivi GPS. Une trace dit où quelqu'un se
  /// trouvait minute par minute : elle ne s'enregistre pas parce que
  /// l'application l'a décidé.
  /// Efface les tracés GPS du compte. Renvoie le nombre de tracés supprimés.
  ///
  /// ⚠️ NE TOUCHE NI AUX TEMPS NI AUX RÉSULTATS. Ce sont deux choses : le
  /// chrono est le fait sportif, publié et classé ; le tracé est le chemin
  /// suivi. Le serveur fait la distinction, le client ne doit pas la brouiller
  /// dans ce qu'il annonce.
  /// Retire un message de la boîte de CE coureur, sur tous ses appareils.
  ///
  /// ⚠️ CE N'EST PAS UNE SUPPRESSION : le message reste intact pour les autres.
  /// Le serveur refuse les messages épinglés (409 `message_epingle`).
  Future<void> masquerNotification(int id) =>
      _appel('DELETE', 'me/notifications/$id');

  /// Remet un message écarté, sur tous les appareils du coureur.
  ///
  /// ⚠️ INDISPENSABLE AU BOUTON « ANNULER ». Sans elle, le bandeau rendait le
  /// message à l'écran mais le serveur le gardait masqué : il restait invisible
  /// sur le navigateur, et réapparaissait au prochain rechargement.
  Future<void> restaurerNotification(int id) =>
      _appel('POST', 'me/notifications/$id/restaurer');

  Future<int> supprimerTraces() async {
    final d = await _appel('DELETE', 'me/traces') as Map<String, dynamic>;
    return (d['supprimes'] as num?)?.toInt() ?? 0;
  }

  Future<bool> consentementGps(bool accord) async {
    final d = await _appel('POST', 'me/traces/consent',
        corps: <String, dynamic>{'consent': accord}) as Map<String, dynamic>;
    return d['consent'] as bool;
  }

  /// Envoie un lot de points GPS. Idempotent côté serveur : seuls les points
  /// postérieurs au dernier connu sont retenus, renvoyer un lot déjà reçu
  /// n'ajoute rien. 5000 points par appel au maximum.
  Future<Map<String, dynamic>> envoyerTrace({
    required int annee,
    required String inscriptionNo,
    required List<Map<String, dynamic>> points,
  }) async =>
      await _appel('POST', 'me/traces', corps: <String, dynamic>{
        'annee': annee,
        'inscription_no': inscriptionNo,
        'points': points,
      }) as Map<String, dynamic>;

  /// Résultats calculés. `methode` et `precision_s` sont TOUJOURS présents :
  /// un temps extrapolé au GPS ne doit jamais être présenté comme équivalent à
  /// un temps mesuré par la balise.
  Future<List<Map<String, dynamic>>> resultats() async =>
      (await _appel('GET', 'me/results') as List<dynamic>)
          .cast<Map<String, dynamic>>();

  /* ══════════════════════ /course et notifications ═════════════════════ */

  /// Informations pratiques de l'édition : date, heure, lieu, horaires,
  /// retrait des dossards, inscriptions sur place.
  ///
  /// SANS JETON : tout ce qui est ici figure déjà sur l'affiche de la course.
  /// Exiger une connexion empêcherait d'afficher l'essentiel à quelqu'un qui
  /// vient d'installer l'application, sans rien protéger de plus.
  Future<Map<String, dynamic>> course([int? annee]) async => await _appel(
        'GET',
        annee == null ? 'course' : 'course/$annee',
        avecJeton: false,
      ) as Map<String, dynamic>;

  /// Déclare le jeton Firebase de cet appareil, ou le retire avec `null`.
  ///
  /// Le jeton est rangé sur la ligne de l'appareil : révoquer un téléphone
  /// depuis « Mes appareils » coupe ses notifications sans autre geste.
  Future<void> declarerJetonPush(String? token) async =>
      await _appel('POST', 'me/push-token',
          corps: <String, dynamic>{'token': token});

  /// Messages de l'organisation.
  ///
  /// ⚠️ CE N'EST PAS DU PUSH. On interroge le serveur à l'ouverture et au
  /// réveil, et on affiche localement ce qui est nouveau. Aucun appareil n'est
  /// déclaré chez Google ou Apple — donc aucune liste de porteurs de
  /// l'application n'est exportée.
  ///
  /// [depuis] : dernier identifiant déjà vu. Les notifications ÉPINGLÉES
  /// reviennent quand même — elles portent les informations pratiques qu'on
  /// relit la veille.
  Future<List<Map<String, dynamic>>> notifications({int? depuis}) async {
    final chemin = depuis == null || depuis <= 0
        ? 'me/notifications'
        : 'me/notifications?depuis=$depuis';
    return (await _appel('GET', chemin) as List<dynamic>)
        .cast<Map<String, dynamic>>();
  }

  /* ═════════════════════════════ /editions ═════════════════════════════ */

  /// Éditions : date, distance, heure de départ, points de départ et d'arrivée.
  ///
  /// ⏱️ `heure_depart` arrive en ISO-8601 avec décalage explicite parce qu'elle
  /// est stockée en UTC. La lire comme une heure locale décalerait tous les
  /// chronos de deux heures, sans le moindre message d'erreur.
  Future<List<Map<String, dynamic>>> editions() async =>
      (await _appel('GET', 'editions') as List<dynamic>)
          .cast<Map<String, dynamic>>();

  void fermer() => _http.close();
}
