//
//  VueCourse.swift — L'écran de course sur Apple Watch.
//
//  ═══════════════════════════════════════════════════════════════════════════
//  UN ÉCRAN DE 40 mm SE LIT EN COURANT, D'UN COUP D'ŒIL.
//
//  D'où trois règles tenues ici :
//    • Le chrono occupe le tiers supérieur, en chiffres à chasse fixe. Sans
//      chasse fixe, le compteur change de largeur à chaque seconde et l'œil
//      suit un texte qui bouge au lieu de lire l'heure.
//    • Les boutons de passage de ligne font toute la largeur : on les touche
//      en marchant, avec un doigt qui tremble.
//    • Rien d'autre. Pas d'inscriptions, pas de réglages — ils sont sur le
//      téléphone, où l'on voit ce qu'on fait.
//

import SwiftUI

struct VueCourse: View {
    @EnvironmentObject private var session: SessionMontre

    /// Rafraîchit l'affichage chaque seconde. Personne ne lit les dixièmes, et
    /// plus rapide ne ferait que consommer de la batterie un jour où elle doit
    /// tenir toute la matinée.
    @State private var horloge = Timer.publish(every: 1, on: .main, in: .common)
        .autoconnect()
    @State private var maintenant = Date()

    var body: some View {
        ScrollView {
            VStack(spacing: 10) {

                // ── Chrono ────────────────────────────────────────────────
                Text(chronoFormate)
                    .font(.system(size: 34, weight: .semibold, design: .rounded))
                    .monospacedDigit()
                    .minimumScaleFactor(0.6)
                    .lineLimit(1)

                // ⚠️ LA MISE EN GARDE ACCOMPAGNE LE COMPTEUR. Sans elle, ce
                // chiffre serait pris pour le temps officiel — et la première
                // contestation serait indéfendable.
                Text("indicatif")
                    .font(.caption2)
                    .foregroundStyle(.secondary)

                if let dossard = session.dossard {
                    Text(dossard)
                        .font(.footnote.weight(.medium))
                        .foregroundStyle(.pink)
                }

                Divider()

                // ── Passages de ligne ─────────────────────────────────────
                Button {
                    Task { await session.declarerPassage("depart") }
                } label: {
                    Label("Je pars", systemImage: "flag")
                        .frame(maxWidth: .infinity)
                }
                .buttonStyle(.bordered)
                .disabled(session.enCourse || session.dossard == nil)

                Button {
                    Task { await session.declarerPassage("arrivee") }
                } label: {
                    Label("J'arrive", systemImage: "flag.checkered")
                        .frame(maxWidth: .infinity)
                }
                .buttonStyle(.borderedProminent)
                .tint(.pink)
                .disabled(session.dossard == nil)

                // ── File d'attente ────────────────────────────────────────
                // Affichée EXPRÈS : voir « 3 en attente » pendant une coupure,
                // c'est comprendre que rien n'est perdu. L'absence de retour
                // ressemblerait à une panne.
                if session.enAttente > 0 {
                    HStack(spacing: 4) {
                        Image(systemName: "arrow.up.circle")
                        Text("\(session.enAttente) en attente")
                    }
                    .font(.caption2)
                    .foregroundStyle(.orange)
                }

                if let message = session.message {
                    Text(message)
                        .font(.caption2)
                        .foregroundStyle(.secondary)
                        .multilineTextAlignment(.center)
                }
            }
            .padding(.horizontal, 4)
        }
        .navigationTitle("Ma course")
        .onReceive(horloge) { maintenant = $0 }
        .task {
            // Le retour du réseau n'est pas notifié ici : on retente à chaque
            // ouverture de l'écran, ce qui suffit sur une montre.
            await session.viderFile()
        }
    }

    private var chronoFormate: String {
        guard let t = session.chrono else { return "--:--" }
        let s = Int(t)
        return String(format: "%d:%02d:%02d", s / 3600, (s % 3600) / 60, s % 60)
    }
}
