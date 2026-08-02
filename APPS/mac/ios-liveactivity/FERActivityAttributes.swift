//
//  FERActivityAttributes.swift — Le contrat de la Live Activity.
//
//  ═══════════════════════════════════════════════════════════════════════════
//  POURQUOI CE FICHIER EST DANS LES DEUX CIBLES
//
//  Il décrit ce qu'une Live Activity affiche. L'application principale
//  (Runner) s'en sert pour la DÉMARRER ; l'extension widget s'en sert pour la
//  DESSINER. Les deux doivent donc le compiler — dans Xcode, cochez les deux
//  cibles dans l'inspecteur de fichier.
//
//  ⚠️ Une seule cible cochée donne une erreur de compilation obscure du côté
//  oublié, qui ne mentionne jamais l'appartenance de cible.
//

import ActivityKit
import Foundation

@available(iOS 16.1, *)
struct FERActivityAttributes: ActivityAttributes {

    /// Ce qui peut changer pendant la course.
    ///
    /// ⚠️ IL N'Y A PAS DE CHAMP « TEMPS ÉCOULÉ », ET C'EST VOLONTAIRE.
    /// Le compteur est animé par le système à partir de `depart`, via
    /// `Text(timerInterval:)`. Envoyer le temps chaque seconde viderait la
    /// batterie et se heurterait à la limite de mises à jour d'iOS.
    public struct ContentState: Codable, Hashable {
        /// Distance parcourue, en mètres. Mise à jour de loin en loin.
        var distanceM: Double

        /// La ligne d'arrivée a-t-elle été franchie ?
        var arrive: Bool
    }

    /// Fixé au démarrage, jamais modifié ensuite.
    var depart: Date
    var dossard: String
}
