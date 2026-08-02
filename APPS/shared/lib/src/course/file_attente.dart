import 'dart:convert';

import 'package:shared_preferences/shared_preferences.dart';

import '../api/api_client.dart';
import '../api/api_erreur.dart';

/// File d'attente des données de course, persistée sur le téléphone.
///
/// ═════════════════════════════════════════════════════════════════════════════
/// LE RÉSEAU TOMBERA PENDANT LA COURSE — CE N'EST PAS UNE HYPOTHÈSE.
///
/// Un parcours en périphérie, trois mille personnes sur la même antenne, un
/// téléphone au fond d'une poche : la couverture disparaît. Si une détection
/// d'arrivée n'existe que dans la mémoire d'une requête HTTP qui échoue, elle
/// est perdue — et quelqu'un franchit la ligne sans chrono.
///
/// Tout ce qui doit partir est donc D'ABORD écrit sur le disque, puis envoyé.
/// Ce qui n'est pas parti reste. L'application peut être tuée par le système,
/// le téléphone redémarrer : la file est retrouvée au lancement suivant.
///
/// ═════════════════════════════════════════════════════════════════════════════
/// POURQUOI ON PEUT RENVOYER SANS CRAINTE
///
/// Les deux points d'entrée sont idempotents CÔTÉ SERVEUR :
///   • `/me/detections` — un index unique (année, n°, type, point, detecte_at)
///     fait qu'une même détection renvoyée dix fois n'en crée qu'une.
///   • `/me/traces` — seuls les points postérieurs au dernier point connu sont
///     retenus.
/// On efface donc la file APRÈS confirmation, jamais avant. Dans le doute, on
/// renvoie : le pire est un doublon, que le serveur ignore.
library;

class _Lot {
  _Lot(this.annee, this.inscriptionNo, this.elements);

  factory _Lot.depuisJson(Map<String, dynamic> j) => _Lot(
        (j['annee'] as num).toInt(),
        j['no'] as String,
        (j['elements'] as List<dynamic>).cast<Map<String, dynamic>>(),
      );

  final int annee;
  final String inscriptionNo;
  final List<Map<String, dynamic>> elements;

  Map<String, dynamic> versJson() => <String, dynamic>{
        'annee': annee,
        'no': inscriptionNo,
        'elements': elements,
      };

  String get cle => '$annee|$inscriptionNo';
}

class FileAttente {
  FileAttente(this._api);

  static const _cleDetections = 'fer_file_detections';
  static const _clePoints = 'fer_file_points';

  /// Le serveur accepte 200 détections et 5000 points par appel. On envoie par
  /// paquets plus petits : sur un réseau chancelant, un gros envoi qui échoue
  /// fait tout recommencer, alors qu'un petit paquet passé est acquis.
  static const _maxDetectionsParEnvoi = 100;
  static const _maxPointsParEnvoi = 500;

  final ApiClient _api;

  final List<_Lot> _detections = <_Lot>[];
  final List<_Lot> _points = <_Lot>[];
  bool _charge = false;
  bool _envoiEnCours = false;

  int get detectionsEnAttente =>
      _detections.fold(0, (n, l) => n + l.elements.length);
  int get pointsEnAttente => _points.fold(0, (n, l) => n + l.elements.length);
  bool get vide => detectionsEnAttente == 0 && pointsEnAttente == 0;

  Future<void> charger() async {
    if (_charge) return;
    final prefs = await SharedPreferences.getInstance();
    _lire(prefs.getString(_cleDetections), _detections);
    _lire(prefs.getString(_clePoints), _points);
    _charge = true;
  }

  static void _lire(String? brut, List<_Lot> cible) {
    if (brut == null || brut.isEmpty) return;
    try {
      cible
        ..clear()
        ..addAll((jsonDecode(brut) as List<dynamic>)
            .cast<Map<String, dynamic>>()
            .map(_Lot.depuisJson));
    } catch (_) {
      // Fichier corrompu (arrêt brutal en pleine écriture). On repart d'une
      // file vide plutôt que d'empêcher l'application de démarrer : perdre
      // quelques points est ennuyeux, une application qui ne s'ouvre plus le
      // jour de la course est bien pire.
      cible.clear();
    }
  }

  Future<void> _sauver() async {
    final prefs = await SharedPreferences.getInstance();
    await prefs.setString(_cleDetections,
        jsonEncode(_detections.map((l) => l.versJson()).toList()));
    await prefs.setString(
        _clePoints, jsonEncode(_points.map((l) => l.versJson()).toList()));
  }

  /// Range une détection et tente de l'envoyer tout de suite.
  ///
  /// ⚠️ L'ÉCRITURE SUR DISQUE PRÉCÈDE L'ENVOI, toujours. Envoyer d'abord et
  /// ranger en cas d'échec laisserait une fenêtre — l'application tuée par le
  /// système entre les deux — où la détection n'existe nulle part.
  Future<void> ajouterDetection(
    int annee,
    String inscriptionNo,
    Map<String, dynamic> detection,
  ) async {
    await charger();
    _empiler(_detections, annee, inscriptionNo, <Map<String, dynamic>>[detection]);
    await _sauver();
    await vidange();
  }

  Future<void> ajouterPoints(
    int annee,
    String inscriptionNo,
    List<Map<String, dynamic>> points,
  ) async {
    if (points.isEmpty) return;
    await charger();
    _empiler(_points, annee, inscriptionNo, points);
    await _sauver();
  }

  static void _empiler(
    List<_Lot> file,
    int annee,
    String no,
    List<Map<String, dynamic>> elements,
  ) {
    final cle = '$annee|$no';
    final existant = file.where((l) => l.cle == cle).firstOrNull;
    if (existant != null) {
      existant.elements.addAll(elements);
    } else {
      file.add(_Lot(annee, no, List<Map<String, dynamic>>.from(elements)));
    }
  }

  /// Envoie ce qui attend. Sans effet s'il n'y a rien, ou si un envoi tourne
  /// déjà — deux vidanges simultanées enverraient les mêmes données deux fois.
  ///
  /// Renvoie le nombre d'éléments effectivement acceptés par le serveur.
  Future<int> vidange() async {
    if (_envoiEnCours) return 0;
    await charger();
    if (vide) return 0;

    _envoiEnCours = true;
    var partis = 0;
    try {
      partis += await _vider(
        _detections,
        _maxDetectionsParEnvoi,
        (lot, paquet) => _api.envoyerDetections(
          annee: lot.annee,
          inscriptionNo: lot.inscriptionNo,
          detections: paquet,
        ),
      );
      partis += await _vider(
        _points,
        _maxPointsParEnvoi,
        (lot, paquet) => _api.envoyerTrace(
          annee: lot.annee,
          inscriptionNo: lot.inscriptionNo,
          points: paquet,
        ),
      );
    } finally {
      _envoiEnCours = false;
      await _sauver();
    }
    return partis;
  }

  Future<int> _vider(
    List<_Lot> file,
    int taillePaquet,
    Future<Map<String, dynamic>> Function(_Lot, List<Map<String, dynamic>>)
        envoyer,
  ) async {
    var partis = 0;

    for (final lot in List<_Lot>.from(file)) {
      while (lot.elements.isNotEmpty) {
        final n = lot.elements.length < taillePaquet
            ? lot.elements.length
            : taillePaquet;
        final paquet = lot.elements.sublist(0, n);
        try {
          await envoyer(lot, paquet);
          lot.elements.removeRange(0, n);
          partis += n;
          // Sauvegarde après CHAQUE paquet accepté : si le réseau lâche au
          // paquet suivant, ce qui est passé ne repartira pas.
          await _sauver();
        } on ApiErreur catch (e) {
          if (e.estReseau) return partis; // On réessaiera : rien n'est perdu.

          if (e.estChronoFerme || e.estConsentementRequis) {
            // Le serveur refuse durablement ces données : chronométrage fermé,
            // ou consentement GPS non donné. Les garder ferait une file qui
            // grossit sans fin et qui repart à chaque reprise de réseau. On les
            // abandonne — c'est le serveur qui a raison, pas la file.
            lot.elements.clear();
            await _sauver();
            break;
          }
          if (e.estDeconnecte || e.estTropAncienne || e.estHorsService) {
            return partis; // Rien ne passera tant que ce n'est pas réglé.
          }

          // Refus portant sur le CONTENU (422 : horodatage sans décalage, type
          // inconnu, point hors bornes). Renvoyer à l'identique échouerait
          // pareil, indéfiniment. On écarte le paquet fautif et on continue,
          // pour que les données saines derrière lui puissent partir.
          lot.elements.removeRange(0, n);
          await _sauver();
        }
      }
      if (lot.elements.isEmpty) file.remove(lot);
    }
    return partis;
  }

  /// Vide la file sans rien envoyer. Réservé à la déconnexion : les données de
  /// course d'un compte ne doivent pas partir sous l'identité du suivant.
  Future<void> purger() async {
    _detections.clear();
    _points.clear();
    _charge = true;
    await _sauver();
  }
}

extension _Premier<E> on Iterable<E> {
  E? get firstOrNull => isEmpty ? null : first;
}
