/// Informations pratiques de la course et messages de l'organisation.
///
/// Ces deux objets viennent des points d'entrée ajoutés avec l'onglet
/// « Réglages → Course » et l'écran « Applications » : `GET /course` et
/// `GET /me/notifications`.
library;

/* ══════════════════════════ Infos de course ═══════════════════════════ */

class InfoCourse {
  const InfoCourse({
    required this.annee,
    this.libelle,
    this.dateCourse,
    this.heureDepart,
    this.departReel,
    this.distanceKm,
    this.latDepart,
    this.lonDepart,
    this.latArrivee,
    this.lonArrivee,
    this.adresse,
    this.lieuRdv,
    this.horaires,
    this.retraitTshirt,
    this.inscriptionSurPlace,
    this.chronoPret = false,
  });

  factory InfoCourse.depuisJson(Map<String, dynamic> j) {
    final dep = j['depart'] as Map<String, dynamic>?;
    final arr = j['arrivee'] as Map<String, dynamic>?;
    return InfoCourse(
      annee: (j['annee'] as num).toInt(),
      libelle: j['libelle'] as String?,
      dateCourse: _date(j['date_course']),
      // ⏱️ Servie en ISO-8601 avec décalage explicite parce qu'elle est
      // stockée en UTC. DateTime.parse respecte ce décalage ; la reconstruire
      // à la main dans le fuseau local décalerait le rappel de deux heures.
      heureDepart: _date(j['heure_depart']),
      departReel: _date(j['depart_reel']),
      distanceKm: (j['distance_km'] as num?)?.toDouble(),
      latDepart: (dep?['lat'] as num?)?.toDouble(),
      lonDepart: (dep?['lon'] as num?)?.toDouble(),
      latArrivee: (arr?['lat'] as num?)?.toDouble(),
      lonArrivee: (arr?['lon'] as num?)?.toDouble(),
      adresse: j['adresse'] as String?,
      lieuRdv: j['lieu_rdv'] as String?,
      horaires: j['horaires'] as String?,
      retraitTshirt: j['retrait_tshirt'] as String?,
      inscriptionSurPlace: j['inscription_sur_place'] as String?,
      chronoPret: j['chrono_pret'] == true,
    );
  }

  final int annee;
  final String? libelle;
  final DateTime? dateCourse;

  /// Heure de départ **prévue**. Sert au rappel, au compte à rebours, et de
  /// filet côté serveur si le départ n'est jamais donné.
  final DateTime? heureDepart;

  /// Instant où l'organisation a **donné le départ**. `null` tant que personne
  /// n'a appuyé.
  ///
  /// ⚠️ C'EST LUI QUI FAIT FOI. Une course part rarement à l'heure : afficher
  /// un chrono qui compte depuis l'heure prévue montrerait cinq minutes de trop
  /// sur tous les téléphones, et personne ne comprendrait pourquoi.
  final DateTime? departReel;

  /// L'origine du chrono à afficher : le top réel s'il existe, sinon la
  /// prévision. `null` si aucun des deux n'est publié — on n'invente pas une
  /// heure de départ pour faire tourner un compteur.
  DateTime? get departEffectif => departReel ?? heureDepart;

  /// La course est-elle réellement partie ?
  bool get partie => departReel != null;

  final double? distanceKm;
  final double? latDepart;
  final double? lonDepart;
  final double? latArrivee;
  final double? lonArrivee;
  final String? adresse;
  final String? lieuRdv;
  final String? horaires;
  final String? retraitTshirt;
  final String? inscriptionSurPlace;

  /// Le chronométrage est ouvert ET l'édition a tout ce qu'il lui faut : heure
  /// de départ et coordonnées des deux lignes.
  ///
  /// ⚠️ DISTINCT DE `chrono_actif`. L'interrupteur peut être ouvert sur une
  /// édition dont l'heure de départ n'est pas saisie — l'application
  /// proposerait alors un suivi qui ne produirait aucun temps. On ne promet
  /// que ce qui peut être tenu.
  final bool chronoPret;

  bool get aLigneDepart => latDepart != null && lonDepart != null;
  bool get aLigneArrivee => latArrivee != null && lonArrivee != null;

  /// Le lieu à afficher : l'adresse si elle existe, sinon le texte de
  /// rendez-vous. Les deux disent la même chose, l'une est structurée.
  String? get lieu {
    final a = adresse?.trim();
    if (a != null && a.isNotEmpty) return a;
    final r = lieuRdv?.trim();
    return (r != null && r.isNotEmpty) ? r : null;
  }

  /// Temps restant avant le départ, ou `null` si l'heure n'est pas publiée.
  /// Négatif une fois le départ passé — l'appelant décide quoi en faire.
  Duration? get avantDepart =>
      heureDepart == null ? null : heureDepart!.difference(DateTime.now());
}

/* ═══════════════════════════ Notifications ════════════════════════════ */

enum TypeNotification {
  info('info'),
  course('course'),
  urgent('urgent');

  const TypeNotification(this.code);

  final String code;

  static TypeNotification depuisCode(String? c) => values.firstWhere(
        (t) => t.code == c,
        orElse: () => TypeNotification.info,
      );
}

/// ⚠️ IL N'Y A PLUS DE « CANAL », ET C'EST VOLONTAIRE.
///
/// La première version portait un canal (app / système / les deux) sur le
/// message, comme si c'était une option d'affichage. C'était une erreur de
/// modèle : un message est du CONTENU qu'on relit, un push est un ÉVÉNEMENT qui
/// sonne une fois. Un push n'a pas de date de fin, un message ne sonne pas.
///
/// Désormais : le serveur ENVOIE un push quand l'organisation appuie sur le
/// bouton — l'application le reçoit par Firebase, comme n'importe quelle
/// notification. Ce que l'application reçoit ici, c'est uniquement la boîte de
/// réception : les messages à afficher.

class NotificationCourse {
  const NotificationCourse({
    required this.id,
    required this.type,
    required this.titre,
    required this.message,
    this.annee,
    this.epingle = false,
    this.publieLe,
    this.expireLe,
  });

  factory NotificationCourse.depuisJson(Map<String, dynamic> j) =>
      NotificationCourse(
        id: (j['id'] as num).toInt(),
        type: TypeNotification.depuisCode(j['type'] as String?),
        titre: j['titre'] as String,
        message: j['message'] as String,
        annee: (j['annee'] as num?)?.toInt(),
        epingle: j['epingle'] == true,
        publieLe: _date(j['publie_le']),
        expireLe: _date(j['expire_le']),
      );

  final int id;
  final TypeNotification type;
  final String titre;
  final String message;
  final int? annee;

  /// Reste en tête de liste au lieu de défiler. Porte les informations
  /// pratiques qu'on relit la veille : rendez-vous, parking, dossards.
  final bool epingle;

  final DateTime? publieLe;
  final DateTime? expireLe;
}

DateTime? _date(Object? v) {
  if (v is! String || v.isEmpty) return null;
  return DateTime.tryParse(v);
}
