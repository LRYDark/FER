//
//  FERWatchApp.swift — Forbach en Rose, application Apple Watch.
//
//  ═══════════════════════════════════════════════════════════════════════════
//  POURQUOI CE FICHIER N'EST PAS EN DART
//
//  Flutter ne compile pas pour watchOS. Ce n'est pas une limite de ce projet :
//  aucune version de Flutter n'a jamais produit de binaire watchOS, et rien
//  n'annonce le contraire. Une application Apple Watch s'écrit en SwiftUI, dans
//  une cible Xcode distincte de l'application iPhone.
//
//  Ce fichier parle donc DIRECTEMENT à l'API `/api/v1`, avec le même contrat
//  que le client Dart : mêmes routes, même en-tête X-App-Version, même jeton
//  personnel. Voir ../../shared/lib/src/api/api_client.dart pour la référence.
//
//  ═══════════════════════════════════════════════════════════════════════════
//  CE QUE FAIT LA MONTRE — ET CE QU'ELLE NE FAIT PAS
//
//  Elle est un COMPAGNON DE COURSE : chrono, dossard, et surtout le bouton
//  « je passe la ligne ». Elle n'affiche pas les inscriptions, ne gère pas les
//  transferts, ne change pas d'adresse email — tout cela se fait sur le
//  téléphone, où l'on voit ce qu'on tape.
//
//  ⚠️ LA MONTRE N'EST PAS UNE SOURCE DE TEMPS. Comme le téléphone, elle envoie
//  des OBSERVATIONS horodatées ; le temps est calculé par le serveur. Un
//  appareil qui enverrait « j'ai fait 42 minutes » ferait une déclaration, pas
//  une mesure.
//

import SwiftUI

@main
struct FERWatchApp: App {
    @StateObject private var session = SessionMontre()

    var body: some Scene {
        WindowGroup {
            NavigationStack {
                if session.jetonAppareil == nil {
                    VueConnexionRequise()
                } else {
                    VueCourse()
                }
            }
            .environmentObject(session)
            .task { await session.demarrer() }
        }
    }
}

/// Écran affiché tant que la montre n'a pas de jeton.
///
/// ⚠️ ON NE SAISIT PAS SON ADRESSE EMAIL SUR UNE MONTRE. Le jeton d'appareil
/// arrive du téléphone par WatchConnectivity (voir `SessionMontre`). Proposer
/// un clavier ici serait imposer vingt caractères sur un écran de 40 mm.
struct VueConnexionRequise: View {
    var body: some View {
        VStack(spacing: 12) {
            Image(systemName: "iphone.and.arrow.forward")
                .font(.largeTitle)
                .foregroundStyle(.pink)
            Text("Ouvrez l'application sur votre iPhone")
                .font(.headline)
                .multilineTextAlignment(.center)
            Text("La montre reprend votre connexion automatiquement.")
                .font(.caption2)
                .foregroundStyle(.secondary)
                .multilineTextAlignment(.center)
        }
        .padding()
    }
}
