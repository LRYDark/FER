/// Objets renvoyés par l'API mobile.
///
/// Un seul fichier : ces classes sont de simples enveloppes de lecture, les
/// éparpiller en douze fichiers d'une vingtaine de lignes rendrait le contrat
/// de l'API plus difficile à embrasser d'un coup d'œil — or c'est précisément
/// ce qu'on vient vérifier ici quand le serveur change.
///
/// ⚠️ AUCUNE DE CES CLASSES NE CALCULE UN TEMPS. Le chrono vient du serveur,
/// déjà arbitré entre balise et GPS. Recalculer ici donnerait deux vérités.
library;

/* ════════════════════════════ Configuration ════════════════════════════ */

class ConfigApp {
  const ConfigApp({
    required this.versionMinimale,
    required this.chronoActif,
    this.storeIos,
    this.storeAndroid,
    this.urlConfidentialite,
    this.urlFaq,
    this.codeTtlMinutes = 15,
    this.tracesConservationJours = 0,
    this.aideConnexion,
    this.reveilAvantMin = 0,
    this.notificationsActives = false,
  });

  factory ConfigApp.depuisJson(Map<String, dynamic> j) {
    final messages = (j['messages'] as Map<String, dynamic>?) ?? const {};
    return ConfigApp(
      versionMinimale: (j['version_minimale'] as String?) ?? '1.0.0',
      // ⚠️ DÉFAUT `false`. Un serveur trop ancien pour connaître ce champ n'a
      // pas de chronométrage à proposer : afficher les écrans de course
      // reviendrait à promettre ce qui n'existe pas. Fermé en cas de doute.
      chronoActif: j['chrono_actif'] == true,
      storeIos: j['store_ios'] as String?,
      storeAndroid: j['store_android'] as String?,
      urlConfidentialite: j['url_confidentialite'] as String?,
      urlFaq: j['url_faq'] as String?,
      codeTtlMinutes: (j['code_ttl_minutes'] as num?)?.toInt() ?? 15,
      tracesConservationJours:
          (j['traces_conservation_jours'] as num?)?.toInt() ?? 0,
      aideConnexion: messages['connexion_aide'] as String?,
      // ⚠️ DÉFAUT 0, pas 120. Un serveur trop ancien pour connaître ce réglage
      // n'a pas décidé d'un réveil : en inventer un poserait une notification
      // que personne n'a demandée, à une heure que personne n'a choisie.
      reveilAvantMin: (j['reveil_avant_min'] as num?)?.toInt() ?? 0,
      notificationsActives: j['notifications'] == true,
    );
  }

  final String versionMinimale;

  /// Le chronométrage est-il ouvert ? Quand il ne l'est pas, les écrans de
  /// course et le suivi GPS disparaissent — l'application ne sert alors qu'à
  /// consulter ses inscriptions, ce qui est le cas onze mois sur douze.
  final bool chronoActif;

  final String? storeIos;
  final String? storeAndroid;
  final String? urlConfidentialite;
  final String? urlFaq;
  final int codeTtlMinutes;

  /// 0 = conservation illimitée des traces. Le sens va vers la préservation :
  /// le but est de pouvoir revoir son parcours d'une année sur l'autre.
  final int tracesConservationJours;

  /// Texte d'aide de l'écran de connexion, modifiable côté serveur sans
  /// republier l'application.
  final String? aideConnexion;

  /// Combien de minutes avant le départ l'application doit rappeler sa
  /// présence. 0 = aucun rappel. Réglé dans l'écran Applications.
  final int reveilAvantMin;

  /// L'organisation diffuse-t-elle des messages ? Désactivé, l'application
  /// n'interroge même pas le point d'entrée.
  final bool notificationsActives;
}

/* ═════════════════════════════ Compte ══════════════════════════════════ */

class Profil {
  const Profil({
    required this.email,
    this.nom,
    this.prenom,
    this.rgpdAccepte = false,
    this.tracesConsent = false,
    this.derniereConnexion,
    this.compteCreeLe,
  });

  factory Profil.depuisJson(Map<String, dynamic> j) => Profil(
        email: (j['email'] as String?) ?? '',
        nom: j['nom'] as String?,
        prenom: j['prenom'] as String?,
        rgpdAccepte: j['rgpd_accepte'] == true,
        // ⚠️ DÉFAUT `false`, y compris face à un serveur trop ancien pour
        // connaître ce champ. Sur un consentement, le doute se tranche TOUJOURS
        // vers le non : supposer l'accord donné ferait afficher « autorisé » à
        // quelqu'un qui n'a jamais rien accepté.
        tracesConsent: j['traces_consent'] == true,
        derniereConnexion: _date(j['derniere_connexion']),
        compteCreeLe: _date(j['compte_cree_le']),
      );

  final String email;
  final String? nom;
  final String? prenom;
  final bool rgpdAccepte;

  /// Le serveur a-t-il le droit d'enregistrer le tracé de la course ?
  ///
  /// ⚠️ À NE PAS CONFONDRE AVEC L'AUTORISATION DE POSITION DU TÉLÉPHONE.
  /// iOS et Android décident si l'application peut LIRE la position ; ce
  /// consentement-ci décide si le serveur peut la CONSERVER. On peut très bien
  /// avoir accordé la première et refusé le second : le suivi fonctionne alors
  /// sur l'appareil — chrono, distance, allure — sans qu'aucun tracé ne parte.
  final bool tracesConsent;

  final DateTime? derniereConnexion;
  final DateTime? compteCreeLe;

  String get nomComplet {
    final n = '${prenom ?? ''} ${nom ?? ''}'.trim();
    return n.isEmpty ? email : n;
  }
}

/* ══════════════════════════ Inscription ════════════════════════════════ */

class Inscription {
  const Inscription({
    required this.annee,
    required this.inscriptionNo,
    this.nom,
    this.prenom,
    this.ville,
    this.sexe,
    this.age,
    this.tshirt,
    this.equipe,
    this.montantDu,
    this.paiementMode,
    this.groupId,
    this.inscritLe,
  });

  factory Inscription.depuisJson(Map<String, dynamic> j) => Inscription(
        annee: (j['annee'] as num).toInt(),
        inscriptionNo: j['inscription_no'] as String,
        nom: j['nom'] as String?,
        prenom: j['prenom'] as String?,
        ville: j['ville'] as String?,
        sexe: j['sexe'] as String?,
        // L'âge peut arriver en nombre ou en chaîne selon la source : on lit
        // les deux plutôt que de faire confiance à un seul.
        age: j['age'] == null ? null : int.tryParse('${j['age']}'),
        tshirt: j['tshirt'] as String?,
        equipe: j['equipe'] as String?,
        montantDu: (j['montant_du'] as num?)?.toDouble(),
        paiementMode: j['paiement_mode'] as String?,
        groupId: j['group_id'] as String?,
        inscritLe: _date(j['inscrit_le']),
      );

  final int annee;
  final String inscriptionNo;
  final String? nom;
  final String? prenom;
  final String? ville;
  final String? sexe;
  final int? age;
  final String? tshirt;
  final String? equipe;
  final double? montantDu;
  final String? paiementMode;

  /// Inscriptions faites d'un bloc — typiquement une famille sous l'adresse
  /// d'un parent. Elles partagent ce jeton.
  final String? groupId;
  final DateTime? inscritLe;

  /// Clé métier, telle que l'API l'attend dans ses chemins.
  String get cle => '$annee/$inscriptionNo';

  String get nomComplet {
    final n = '${prenom ?? ''} ${nom ?? ''}'.trim();
    return n.isEmpty ? inscriptionNo : n;
  }

  bool get estGratuite => (montantDu ?? 0) <= 0 || paiementMode == 'gratuit';
}

/* ═══════════════════════════ Résultat ══════════════════════════════════ */

/// Comment le temps a été obtenu. L'ordre reflète la fiabilité décroissante,
/// et cette information accompagne TOUJOURS le temps : un temps extrapolé
/// affiché nu passerait pour une mesure.
enum MethodeChrono {
  balise('beacon', 'Balise à la ligne', 'précision maximale'),
  gpsLigne('gps_ligne', 'GPS au passage de la ligne', 'précision courante'),
  gpsExtrapole('gps_extrapole', 'GPS extrapolé', 'temps approché'),
  gpsDistance('gps_distance', 'GPS par la distance', 'temps approché'),
  manuel('manuel', "Saisi par l'organisation", 'relevé à la main'),
  declaratif('declaratif', 'Déclaré par le coureur', 'non vérifié'),
  inconnue('', 'Méthode non précisée', '');

  const MethodeChrono(this.code, this.libelle, this.precision);

  final String code;
  final String libelle;
  final String precision;

  static MethodeChrono depuisCode(String? c) => values.firstWhere(
        (m) => m.code == c,
        orElse: () => MethodeChrono.inconnue,
      );

  /// Vrai pour tout ce qui n'est pas une mesure à la ligne. Sert à afficher la
  /// nuance, jamais à masquer le temps.
  bool get estApproche =>
      this == gpsExtrapole || this == gpsDistance || this == declaratif;
}

enum StatutCourse {
  enCourse('en_course', 'En course'),
  termine('termine', 'Terminé'),
  abandon('abandon', 'Abandon'),
  nonPartant('non_partant', 'Non partant'),
  invalide('invalide', 'À vérifier'),
  inconnu('', '—');

  const StatutCourse(this.code, this.libelle);

  final String code;
  final String libelle;

  static StatutCourse depuisCode(String? c) => values.firstWhere(
        (s) => s.code == c,
        orElse: () => StatutCourse.inconnu,
      );
}

class Resultat {
  const Resultat({
    required this.annee,
    required this.inscriptionNo,
    required this.statut,
    required this.methode,
    this.departAt,
    this.arriveeAt,
    this.tempsS,
    this.precisionS,
    this.distanceM,
    this.denivelePositifM,
  });

  factory Resultat.depuisJson(Map<String, dynamic> j) => Resultat(
        annee: (j['annee'] as num).toInt(),
        inscriptionNo: j['inscription_no'] as String,
        statut: StatutCourse.depuisCode(j['statut'] as String?),
        methode: MethodeChrono.depuisCode(j['methode'] as String?),
        departAt: _date(j['depart_at']),
        arriveeAt: _date(j['arrivee_at']),
        tempsS: (j['temps_s'] as num?)?.toDouble(),
        precisionS: (j['precision_s'] as num?)?.toInt(),
        distanceM: (j['distance_m'] as num?)?.toInt(),
        denivelePositifM: (j['denivele_positif_m'] as num?)?.toInt(),
      );

  final int annee;
  final String inscriptionNo;
  final StatutCourse statut;
  final MethodeChrono methode;
  final DateTime? departAt;
  final DateTime? arriveeAt;
  final double? tempsS;
  final int? precisionS;
  final int? distanceM;
  final int? denivelePositifM;

  /// ⚠️ UN TEMPS MARQUÉ « invalide » N'EST PAS UN TEMPS.
  /// Arrivée sans départ, durée sous le minimum plausible, horodatages
  /// incohérents. Le masquer sans rien dire laisserait croire à un oubli ; le
  /// publier ferait passer une anomalie pour un résultat. On l'annonce
  /// « à vérifier », et l'écran ne montre pas de chrono.
  bool get chronoAffichable =>
      tempsS != null && statut != StatutCourse.invalide;

  /// `h:mm:ss`, ou `—` tant qu'il n'y a rien à montrer.
  String get chrono {
    if (!chronoAffichable) return '—';
    final s = tempsS!.round();
    final h = s ~/ 3600;
    final m = (s % 3600) ~/ 60;
    final r = s % 60;
    return '$h:${m.toString().padLeft(2, '0')}:${r.toString().padLeft(2, '0')}';
  }
}

/* ═══════════════════════════ Appareil ══════════════════════════════════ */

class Appareil {
  const Appareil({
    required this.id,
    required this.courant,
    this.type,
    this.libelle,
    this.plateforme,
    this.modele,
    this.derniereUtilisation,
    this.creeLe,
  });

  factory Appareil.depuisJson(Map<String, dynamic> j) => Appareil(
        id: (j['id'] as num).toInt(),
        courant: j['courant'] == true,
        type: j['type'] as String?,
        libelle: j['libelle'] as String?,
        plateforme: j['plateforme'] as String?,
        modele: j['modele'] as String?,
        derniereUtilisation: _date(j['derniere_utilisation']),
        creeLe: _date(j['cree_le']),
      );

  final int id;

  /// Celui sur lequel on se trouve. L'interface doit l'empêcher d'être révoqué
  /// par inadvertance — sinon on se déconnecte soi-même sans le vouloir.
  final bool courant;
  final String? type;
  final String? libelle;
  final String? plateforme;
  final String? modele;
  final DateTime? derniereUtilisation;
  final DateTime? creeLe;

  String get nom => (libelle?.isNotEmpty ?? false)
      ? libelle!
      : (modele ?? plateforme ?? 'Appareil $id');
}

/* ═══════════════════════════ Transfert ═════════════════════════════════ */

class Transfert {
  const Transfert({
    required this.id,
    required this.annee,
    required this.inscriptionNo,
    required this.statut,
    this.emailCible,
    this.expireLe,
    this.demandeLe,
    this.accepteLe,
  });

  factory Transfert.depuisJson(Map<String, dynamic> j) => Transfert(
        id: (j['id'] as num).toInt(),
        annee: (j['annee'] as num).toInt(),
        inscriptionNo: j['inscription_no'] as String,
        statut: (j['statut'] as String?) ?? 'en_attente',
        emailCible: j['email_cible'] as String?,
        expireLe: _date(j['expire_le']),
        demandeLe: _date(j['demande_le']),
        accepteLe: _date(j['accepte_le']),
      );

  final int id;
  final int annee;
  final String inscriptionNo;
  final String statut;
  final String? emailCible;
  final DateTime? expireLe;
  final DateTime? demandeLe;
  final DateTime? accepteLe;

  bool get enAttente => statut == 'en_attente';
}

/* ════════════════════════════ Édition ══════════════════════════════════ */

class Edition {
  const Edition({
    required this.id,
    required this.annee,
    required this.active,
    this.libelle,
    this.dateCourse,
    this.distanceKm,
    this.heureDepart,
    this.latDepart,
    this.lonDepart,
    this.latArrivee,
    this.lonArrivee,
    this.tempsMinPlausibleS,
  });

  factory Edition.depuisJson(Map<String, dynamic> j) {
    final dep = j['depart'] as Map<String, dynamic>?;
    final arr = j['arrivee'] as Map<String, dynamic>?;
    return Edition(
      id: (j['id'] as num).toInt(),
      annee: (j['annee'] as num).toInt(),
      active: j['active'] == true,
      libelle: j['libelle'] as String?,
      dateCourse: _date(j['date_course']),
      distanceKm: (j['distance_km'] as num?)?.toDouble(),
      // ⏱️ Stockée en UTC côté serveur et servie avec son décalage. DateTime
      // .parse respecte ce décalage ; construire la date à la main dans le
      // fuseau local décalerait tous les chronos de deux heures en été.
      heureDepart: _date(j['heure_depart']),
      latDepart: (dep?['lat'] as num?)?.toDouble(),
      lonDepart: (dep?['lon'] as num?)?.toDouble(),
      latArrivee: (arr?['lat'] as num?)?.toDouble(),
      lonArrivee: (arr?['lon'] as num?)?.toDouble(),
      tempsMinPlausibleS: (j['temps_min_plausible_s'] as num?)?.toInt(),
    );
  }

  final int id;
  final int annee;
  final bool active;
  final String? libelle;
  final DateTime? dateCourse;
  final double? distanceKm;
  final DateTime? heureDepart;
  final double? latDepart;
  final double? lonDepart;
  final double? latArrivee;
  final double? lonArrivee;
  final int? tempsMinPlausibleS;

  bool get aLigneDepart => latDepart != null && lonDepart != null;
  bool get aLigneArrivee => latArrivee != null && lonArrivee != null;
}

/* ══════════════════════════════════════════════════════════════════════ */

/// Date ISO-8601 tolérante : l'API renvoie `null` pour toute date absente ou
/// nulle en base, et une chaîne illisible ne doit jamais faire tomber un écran.
DateTime? _date(Object? v) {
  if (v is! String || v.isEmpty) return null;
  return DateTime.tryParse(v);
}
