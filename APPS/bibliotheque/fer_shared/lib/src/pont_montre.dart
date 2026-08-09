/// Pont vers l'Apple Watch : jeton d'appareil et profil physique.
///
/// ═════════════════════════════════════════════════════════════════════════════
/// POURQUOI CE FICHIER EXISTE
///
/// Flutter ne compile pas pour watchOS — aucune version ne l'a jamais fait.
/// L'application de la montre est donc écrite en SwiftUI, dans une cible Xcode
/// distincte (`mac/watchos/`), et elle parle directement à `/api/mobile`.
///
/// Deux choses ne peuvent pas venir de l'API, et doivent donc transiter par ici.
///
/// **Le jeton d'appareil.** La montre ne peut pas se connecter seule : la
/// connexion se fait par code reçu par email, et taper une adresse sur un écran
/// de 40 mm est hors de question.
///
/// **Le poids et la taille.** Ils ne sont *jamais* envoyés au serveur — c'est
/// une règle du projet, pas une commodité. Ils vivent dans les préférences du
/// téléphone. Sans eux, la montre ne peut pas estimer les calories, puisque
/// l'équation ACSM part du poids. Ils passent donc d'appareil à appareil, et
/// ne quittent jamais la paire.
///
/// ⚠️ ON NE TRANSMET PAS LE JETON D'ACCÈS, ni l'adresse email. Le jeton
/// d'appareil est un secret de longue durée que la montre range dans son propre
/// trousseau ; le jeton d'accès se rachète en un appel à `/auth/refresh` et n'a
/// aucune raison de circuler entre deux appareils.
///
/// ⚠️ UN SEUL APPEL PORTE TOUT, ET C'EST OBLIGATOIRE.
/// `updateApplicationContext` REMPLACE le contexte, il ne le fusionne pas.
/// Deux appels séparés — un pour le jeton, un pour le profil — feraient que le
/// second efface le premier, et la montre se retrouverait déconnectée dès qu'on
/// modifie son poids.
///
/// ⚠️ CE PONT NE DOIT JAMAIS FAIRE ÉCHOUER QUOI QUE CE SOIT. Se connecter,
/// se déconnecter et ouvrir l'application doivent marcher à l'identique sans
/// montre appairée, sur Android, et sur un simulateur où `WCSession` n'existe
/// pas. Tout est donc avalé en silence : l'absence de montre n'est pas une
/// erreur, c'est le cas courant.
library;

import 'package:flutter/foundation.dart';
import 'package:flutter/services.dart';

import 'course/mesures.dart';

/// Canal de plateforme partagé avec `ios/Runner/AppDelegate.swift`.
///
/// ⚠️ CE NOM EST UN CONTRAT. Le modifier ici sans le modifier dans
/// l'`AppDelegate` casse le pont sans le moindre message : les appels partent
/// dans le vide et `MissingPluginException` est justement ce qu'on avale.
const MethodChannel _canal = MethodChannel('fr.forbachenrose/montre');

/// Envoie à la montre tout ce dont elle a besoin, en une fois.
///
/// [jeton] à `null` signifie « déconnecté » : la montre efface le sien et
/// revient à son écran d'attente. Sans cet appel, quelqu'un qui se déconnecte
/// du téléphone resterait connecté au poignet — et la montre continuerait
/// d'envoyer des passages de ligne sous une identité qu'on croyait fermée.
///
/// [profil] à `null` efface aussi le profil côté montre : c'est ce qui doit se
/// produire quand on exerce son droit à l'effacement depuis le téléphone.
Future<void> synchroniserMontre({
  String? jeton,
  ProfilPhysique? profil,
}) async {
  // Ni Android ni le web n'ont d'Apple Watch. Sortir tout de suite évite une
  // exception attendue à chaque connexion, qui polluerait les journaux.
  if (kIsWeb || defaultTargetPlatform != TargetPlatform.iOS) return;
  try {
    await _canal.invokeMethod<void>('synchroniser', <String, dynamic>{
      if (jeton != null) 'device_token': jeton,
      if (profil?.poidsKg != null) 'poids_kg': profil!.poidsKg,
      if (profil?.tailleCm != null) 'taille_cm': profil!.tailleCm,
      if (profil?.age != null) 'age': profil!.age,
      if (profil?.sexe != null) 'sexe': profil!.sexe,
    });
  } catch (_) {
    // Pas de montre appairée, `WCSession` indisponible, simulateur, canal non
    // enregistré : aucun de ces cas n'est un problème pour le téléphone.
  }
}
