import 'dart:async';

import 'package:flutter/foundation.dart';
import 'package:shared_preferences/shared_preferences.dart';

import 'models/course_app.dart';

/// Réveil de l'application avant la course.
///
/// ═════════════════════════════════════════════════════════════════════════════
/// CE QUE « RÉVEIL » VEUT DIRE — ET CE QU'IL NE VEUT PAS DIRE.
///
/// **Une application ne se lance pas toute seule.** Ni Android ni iOS ne
/// l'autorisent : depuis Android 10, démarrer une activité depuis l'arrière-plan
/// est bloqué ; iOS n'a jamais rien proposé de tel. Toute promesse contraire est
/// fausse, et le jour de la course n'est pas le moment de s'en apercevoir.
///
/// Ce que l'on peut faire, et qui répond au vrai besoin : programmer une
/// **notification locale** à l'heure dite. Le coureur la voit, la touche,
/// l'application s'ouvre, le suivi démarre. C'est un rappel, pas un démarrage.
///
/// ═════════════════════════════════════════════════════════════════════════════
/// POURQUOI CE FICHIER NE DÉPEND D'AUCUN GREFFON DE NOTIFICATION
///
/// Il expose une INTERFACE ([PoseurDeRappel]) et la logique de calcul. La coque
/// de chaque plateforme y branche son implémentation :
///
///   • Android / iOS : `flutter_local_notifications` ;
///   • Wear OS       : la même, mais sur la montre ;
///   • watchOS       : côté Swift, hors de Flutter.
///
/// Le cœur partagé reste ainsi compilable partout, et le jour où le greffon
/// change de nom ou d'API, un seul fichier de coque est à reprendre.
///
/// ⚠️ LES RAPPELS NE SURVIVENT PAS AU REDÉMARRAGE DU TÉLÉPHONE sur toutes les
/// versions d'Android. D'où [reprogrammer], appelé à CHAQUE lancement : on
/// repose systématiquement le rappel, plutôt que de supposer qu'il tient.
library;

/// Ce qu'une coque de plateforme doit savoir faire.
abstract class PoseurDeRappel {
  /// Demande l'autorisation d'afficher des notifications. Renvoie `false` si
  /// elle est refusée — l'appelant doit alors le dire, pas faire comme si.
  Future<bool> autoriser();

  /// Programme un rappel unique. Un même [id] remplace le précédent.
  Future<void> poser({
    required int id,
    required DateTime quand,
    required String titre,
    required String message,
  });

  /// Annule un rappel déjà posé.
  Future<void> annuler(int id);
}

/// Implémentation vide, utilisée quand la plateforme n'a pas de notifications
/// (tests, ou une coque qui ne les a pas encore branchées).
///
/// ⚠️ Elle ne prétend PAS avoir posé le rappel : [autoriser] renvoie `false`,
/// et l'interface affiche donc « rappel indisponible » au lieu d'un faux
/// « rappel programmé » sur lequel quelqu'un compterait.
class RappelIndisponible implements PoseurDeRappel {
  const RappelIndisponible();

  @override
  Future<bool> autoriser() async => false;

  @override
  Future<void> poser({
    required int id,
    required DateTime quand,
    required String titre,
    required String message,
  }) async {}

  @override
  Future<void> annuler(int id) async {}
}

class Reveil {
  Reveil({PoseurDeRappel poseur = const RappelIndisponible()})
      : _poseur = poseur;

  /// Identifiants fixes : reposer un rappel avec le même identifiant REMPLACE
  /// le précédent. Sans cela, changer l'heure de départ empilerait les rappels
  /// et le coureur serait réveillé deux fois, dont une à la mauvaise heure.
  static const int idAvantCourse = 1001;
  static const int idVeille = 1002;

  static const _cleDernier = 'fer_reveil_pose_pour';

  final PoseurDeRappel _poseur;

  bool _autorise = false;
  DateTime? _prochain;

  bool get autorise => _autorise;

  /// Heure à laquelle le rappel se déclenchera, ou `null` s'il n'y en a pas.
  DateTime? get prochain => _prochain;

  Future<bool> demanderAutorisation() async {
    _autorise = await _poseur.autoriser();
    return _autorise;
  }

  /// (Re)pose les rappels pour une course.
  ///
  /// Appelé à chaque lancement de l'application, et après chaque lecture de
  /// `/app/config` : c'est le seul moyen fiable de survivre à un redémarrage du
  /// téléphone, qui efface les alarmes programmées sur plusieurs versions
  /// d'Android.
  ///
  /// [minutesAvant] vient du réglage `app_reveil_avant_min` de
  /// l'administration. 0 = pas de rappel.
  Future<bool> reprogrammer({
    required InfoCourse? course,
    required int minutesAvant,
  }) async {
    final depart = course?.heureDepart;

    // Pas d'heure de départ, ou réveil désactivé : on ANNULE ce qui traînait.
    // Sans cette annulation, désactiver le réveil dans l'administration
    // laisserait sonner le rappel déjà posé sur chaque téléphone.
    if (depart == null || minutesAvant <= 0) {
      await _poseur.annuler(idAvantCourse);
      await _poseur.annuler(idVeille);
      _prochain = null;
      await _oublier();
      return false;
    }

    if (!_autorise) _autorise = await _poseur.autoriser();
    if (!_autorise) {
      _prochain = null;
      return false;
    }

    final quand = depart.subtract(Duration(minutes: minutesAvant));

    // Un rappel dans le passé ne se déclencherait jamais. Le poser quand même
    // donnerait une interface affichant « rappel programmé » pour un rappel qui
    // ne sonnera pas — exactement le genre de fausse promesse à éviter.
    if (quand.isBefore(DateTime.now())) {
      await _poseur.annuler(idAvantCourse);
      _prochain = null;
      return false;
    }

    final heure = _hhmm(depart);
    await _poseur.poser(
      id: idAvantCourse,
      quand: quand,
      titre: 'Forbach en Rose — départ à $heure',
      message: course?.lieu != null
          ? 'Rendez-vous : ${course!.lieu}. Ouvrez l\'application pour activer '
              'le suivi de votre course.'
          : "Ouvrez l'application pour activer le suivi de votre course.",
    );

    // Un second rappel la veille au soir, s'il y a la place. C'est celui qui
    // sert vraiment : préparer ses affaires, prévoir le trajet.
    final veille = DateTime(depart.year, depart.month, depart.day - 1, 19);
    if (veille.isAfter(DateTime.now()) && veille.isBefore(quand)) {
      await _poseur.poser(
        id: idVeille,
        quand: veille,
        titre: 'Forbach en Rose, c\'est demain',
        message: 'Départ à $heure'
            '${course?.lieu != null ? ' — ${course!.lieu}' : ''}.',
      );
    } else {
      await _poseur.annuler(idVeille);
    }

    _prochain = quand;
    await _memoriser(depart);
    return true;
  }

  static String _hhmm(DateTime d) =>
      '${d.hour.toString().padLeft(2, '0')} h ${d.minute.toString().padLeft(2, '0')}';

  Future<void> _memoriser(DateTime depart) async {
    final p = await SharedPreferences.getInstance();
    await p.setString(_cleDernier, depart.toIso8601String());
  }

  Future<void> _oublier() async {
    final p = await SharedPreferences.getInstance();
    await p.remove(_cleDernier);
  }

  /// Heure de départ pour laquelle un rappel a été posé la dernière fois.
  /// Sert à repérer qu'elle a changé côté organisation.
  static Future<DateTime?> departMemorise() async {
    final p = await SharedPreferences.getInstance();
    final s = p.getString(_cleDernier);
    return s == null ? null : DateTime.tryParse(s);
  }

  /* ⚠️ IL N'Y A PLUS DE « ANNONCER » ICI, ET C'EST VOULU.
   *
   * Les messages de l'organisation partent désormais en vrai push, envoyé par le
   * serveur au moment où quelqu'un appuie sur « Envoyer sur les téléphones ».
   * Poser en plus une notification locale à la lecture ferait sonner une seconde
   * fois pour le même message.
   *
   * Ce fichier ne s'occupe donc plus que du RAPPEL AVANT LA COURSE, qui est
   * l'affaire de l'application seule : elle le programme à partir de l'heure de
   * départ, sans que le serveur ait à envoyer quoi que ce soit. */

  Future<void> annulerTout() async {
    await _poseur.annuler(idAvantCourse);
    await _poseur.annuler(idVeille);
    _prochain = null;
    await _oublier();
    debugPrint('[FER] rappels annulés');
  }
}
