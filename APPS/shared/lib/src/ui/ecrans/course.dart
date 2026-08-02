import 'package:flutter/material.dart';

import '../../course/mesures.dart';
import '../../course/suivi_course.dart';
import '../../models/course_app.dart';
import '../portee.dart';
import '../theme.dart';
import 'profil_physique.dart';

/// « Ma course » — infos pratiques, puis suivi pendant l'épreuve.
///
/// ═════════════════════════════════════════════════════════════════════════════
/// CET ÉCRAN N'AFFICHE JAMAIS UN TEMPS OFFICIEL.
///
/// Le chrono qu'on y voit est calculé depuis l'heure de départ de l'édition,
/// pour se situer pendant la marche. Le résultat officiel vient du serveur,
/// après arbitrage entre balise et GPS, et peut en différer de quelques
/// secondes. C'est écrit à l'écran : laisser croire que ce compteur fait foi
/// garantirait une contestation.
library;

class EcranCourse extends StatelessWidget {
  const EcranCourse({super.key});

  @override
  Widget build(BuildContext context) {
    final session = PorteeSession.de(context);
    final info = session.infoCourse;
    final inscription = session.inscriptionActive;
    final suivi = session.suivi;

    return RefreshIndicator(
      onRefresh: session.rafraichirConfig,
      child: ListView(
        padding: const EdgeInsets.all(marge),
        children: <Widget>[
          if (info != null) _CarteInfos(info: info),
          if (info != null) const SizedBox(height: marge),

          // Le suivi n'apparaît que si le chronométrage est OUVERT et que
          // l'édition a tout ce qu'il lui faut. Proposer « démarrer le suivi »
          // sur une édition sans ligne d'arrivée serait promettre un temps qui
          // ne sera jamais calculé.
          if (!(info?.chronoPret ?? false))
            const CarteFer(
              titre: 'Suivi de course',
              icone: Icons.timer_off_outlined,
              enfant: Text(
                "Le suivi n'est pas ouvert pour le moment. Il le sera aux "
                'abords de la course. Vos inscriptions et votre QR code '
                'restent accessibles.',
              ),
            )
          else if (inscription == null)
            const CarteFer(
              titre: 'Suivi de course',
              icone: Icons.person_off_outlined,
              enfant: Text(
                "Aucune inscription pour l'édition en cours : il n'y a rien à "
                'suivre. Si vous êtes inscrit avec une autre adresse email, '
                'reconnectez-vous avec celle-ci.',
              ),
            )
          else
            AnimatedBuilder(
              animation: suivi,
              builder: (context, _) => _CarteSuivi(suivi: suivi),
            ),

          const SizedBox(height: marge),
          AnimatedBuilder(
            animation: suivi,
            builder: (context, _) => _CarteFile(suivi: suivi),
          ),
        ],
      ),
    );
  }
}

/* ═══════════════════════════ Infos pratiques ══════════════════════════ */

class _CarteInfos extends StatelessWidget {
  const _CarteInfos({required this.info});

  final InfoCourse info;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final avant = info.avantDepart;

    return CarteFer(
      titre: info.libelle ?? 'Édition ${info.annee}',
      icone: Icons.event_outlined,
      enfant: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: <Widget>[
          // Le compte à rebours n'apparaît que dans la semaine qui précède :
          // « J-247 » n'aide personne et occupe la meilleure place de l'écran.
          if (avant != null && !avant.isNegative && avant.inDays <= 7) ...<Widget>[
            Text(
              avant.inHours < 24
                  ? 'Départ dans ${avant.inHours} h ${avant.inMinutes % 60} min'
                  : 'Départ dans ${avant.inDays} jour${avant.inDays > 1 ? 's' : ''}',
              style: theme.textTheme.titleLarge?.copyWith(
                color: theme.colorScheme.primary,
                fontWeight: FontWeight.w700,
              ),
            ),
            const SizedBox(height: 12),
          ],
          if (info.heureDepart != null)
            _Ligne(Icons.schedule, 'Départ',
                _dateHeure(info.heureDepart!)),
          if (info.lieu != null)
            _Ligne(Icons.place_outlined, 'Rendez-vous', info.lieu!),
          if (info.distanceKm != null)
            _Ligne(Icons.straighten, 'Distance',
                '${info.distanceKm!.toStringAsFixed(2).replaceAll('.', ',')} km'),
          if (info.horaires != null)
            _Ligne(Icons.access_time, 'Horaires', info.horaires!),
          if (info.retraitTshirt != null)
            _Ligne(Icons.checkroom_outlined, 'Dossards et T-shirts',
                info.retraitTshirt!),
          if (info.inscriptionSurPlace != null)
            _Ligne(Icons.how_to_reg_outlined, 'Sur place',
                info.inscriptionSurPlace!),
        ],
      ),
    );
  }

  static String _dateHeure(DateTime d) {
    final l = d.toLocal();
    return '${l.day.toString().padLeft(2, '0')}/'
        '${l.month.toString().padLeft(2, '0')}/${l.year} à '
        '${l.hour.toString().padLeft(2, '0')} h '
        '${l.minute.toString().padLeft(2, '0')}';
  }
}

class _Ligne extends StatelessWidget {
  const _Ligne(this.icone, this.libelle, this.valeur);

  final IconData icone;
  final String libelle;
  final String valeur;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 6),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: <Widget>[
          Icon(icone, size: 18, color: theme.colorScheme.outline),
          const SizedBox(width: 10),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: <Widget>[
                Text(libelle,
                    style: theme.textTheme.bodySmall
                        ?.copyWith(color: theme.colorScheme.outline)),
                Text(valeur, style: theme.textTheme.bodyMedium),
              ],
            ),
          ),
        ],
      ),
    );
  }
}

/* ══════════════════════════════ Suivi ═════════════════════════════════ */

class _CarteSuivi extends StatelessWidget {
  const _CarteSuivi({required this.suivi});

  final SuiviCourse suivi;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final session = PorteeSession.de(context);
    final actif = suivi.etat == EtatSuivi.actif;

    return CarteFer(
      titre: 'Suivi de course',
      icone: Icons.directions_walk,
      action: actif
          ? const Pastille('en cours', couleur: Colors.green, icone: Icons.circle)
          : null,
      enfant: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: <Widget>[
          if (actif) ...<Widget>[
            Center(
              child: Text(
                formaterDuree(suivi.chronoCourant),
                style: theme.textTheme.displayMedium?.copyWith(
                  fontWeight: FontWeight.w700,
                  fontFeatures: chiffresFixes.fontFeatures,
                ),
              ),
            ),
            const SizedBox(height: 4),
            // ⚠️ LA MISE EN GARDE ACCOMPAGNE LE COMPTEUR, TOUJOURS. Sans elle,
            // ce chiffre serait pris pour le temps officiel.
            Center(
              child: Text(
                session.infoCourse?.partie ?? false
                    ? 'Depuis le départ officiel — votre temps final est calculé '
                        "par l'organisation, à partir de la balise et du GPS."
                    : "Le départ n'a pas encore été donné : ce compteur part de "
                        "l'heure prévue et sera recalé.",
                textAlign: TextAlign.center,
                style: theme.textTheme.bodySmall
                    ?.copyWith(color: theme.colorScheme.outline),
              ),
            ),
            const SizedBox(height: 16),
            Row(
              children: <Widget>[
                Expanded(child: _Stat('Distance', formaterDistance(suivi.distanceM))),
                Expanded(child: _Stat('Allure', suivi.allureMoyenne != null
                    ? '${suivi.allureMoyenne} /km' : '—')),
              ],
            ),
            const SizedBox(height: 8),
            Row(
              children: <Widget>[
                Expanded(child: _Stat('Dénivelé +',
                    '${suivi.denivelePositifM.round()} m')),
                Expanded(
                  child: suivi.calories.disponible
                      // La réserve accompagne le chiffre ici comme partout : ce
                      // n'est pas une mesure, et ça doit se lire.
                      ? _Stat('Calories', suivi.calories.libelle,
                          note: Calories.mention)
                      : _StatProfil(),
                ),
              ],
            ),
            if (suivi.kilometres.isNotEmpty) ...<Widget>[
              const SizedBox(height: 12),
              _TempsAuKm(tours: suivi.kilometres),
            ],
            const SizedBox(height: 12),
            Row(
              children: <Widget>[
                Icon(
                  suivi.baliseActive
                      ? Icons.bluetooth_connected
                      : Icons.bluetooth_disabled,
                  size: 16,
                  color: suivi.baliseActive
                      ? theme.colorScheme.primary
                      : theme.colorScheme.outline,
                ),
                const SizedBox(width: 6),
                Text(
                  suivi.baliseActive
                      ? 'Balise à l\'écoute'
                      : 'Balise inactive — le GPS prend le relais',
                  style: theme.textTheme.bodySmall,
                ),
              ],
            ),
            const SizedBox(height: 16),
            // Boutons de secours : si la balise n'a rien vu et que le GPS a
            // dérivé, il reste ce geste. Sans lui, quelqu'un franchit la ligne
            // et n'a aucun moyen de le signaler.
            Row(
              children: <Widget>[
                Expanded(
                  child: OutlinedButton.icon(
                    onPressed: suivi.aFranchi('depart')
                        ? null
                        : () => suivi.declarerPassage('depart'),
                    icon: const Icon(Icons.flag_outlined),
                    label: const Text('Je pars'),
                  ),
                ),
                const SizedBox(width: 8),
                Expanded(
                  child: FilledButton.icon(
                    onPressed: suivi.aFranchi('arrivee')
                        ? null
                        : () => suivi.declarerPassage('arrivee'),
                    icon: const Icon(Icons.sports_score),
                    label: const Text("J'arrive"),
                  ),
                ),
              ],
            ),
            const SizedBox(height: 8),
            TextButton.icon(
              onPressed: () async {
                await suivi.arreter();
                await session.rafraichirResultats();
              },
              icon: const Icon(Icons.stop_circle_outlined),
              label: const Text('Arrêter le suivi'),
            ),
          ] else ...<Widget>[
            Text(
              'Le suivi enregistre votre position et écoute la balise posée sur '
              'les lignes. Les deux sont envoyées à l\'organisation, qui en '
              'déduit votre temps.',
              style: theme.textTheme.bodyMedium,
            ),
            if (suivi.messageErreur != null) ...<Widget>[
              const SizedBox(height: 12),
              Text(
                suivi.messageErreur!,
                style: TextStyle(color: theme.colorScheme.error),
              ),
            ],
            const SizedBox(height: 16),
            FilledButton.icon(
              onPressed: suivi.etat == EtatSuivi.demarrage
                  ? null
                  : () => suivi.demarrer(
                        inscription: session.inscriptionActive!,
                        edition: session.editionActive,
                        // Le top réel s'il a été donné, la prévision sinon.
                        departEffectif: session.infoCourse?.departEffectif,
                      ),
              icon: const Icon(Icons.play_arrow),
              label: const Text('Démarrer le suivi'),
            ),
          ],
        ],
      ),
    );
  }
}

class _Stat extends StatelessWidget {
  const _Stat(this.libelle, this.valeur, {this.note});

  final String libelle;
  final String valeur;

  /// Réserve affichée sous la valeur — pour tout ce qui est estimé.
  final String? note;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: <Widget>[
        Text(libelle,
            style: theme.textTheme.bodySmall
                ?.copyWith(color: theme.colorScheme.outline)),
        Text(valeur,
            style: theme.textTheme.titleMedium
                ?.copyWith(fontWeight: FontWeight.w600)),
        if (note != null)
          Text(note!,
              style: theme.textTheme.bodySmall?.copyWith(
                  color: theme.colorScheme.outline, fontSize: 10)),
      ],
    );
  }
}

/// Invitation à renseigner son poids, à la place des calories.
///
/// ⚠️ ON N'INVENTE PAS UN POIDS MOYEN pour afficher un chiffre. Un « ~400 kcal »
/// calculé sur 70 kg par défaut serait faux pour presque tout le monde, et rien
/// ne le dirait.
class _StatProfil extends StatelessWidget {
  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    return InkWell(
      onTap: () => Navigator.of(context).push(
        MaterialPageRoute<void>(builder: (_) => const EcranProfilPhysique()),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: <Widget>[
          Text('Calories',
              style: theme.textTheme.bodySmall
                  ?.copyWith(color: theme.colorScheme.outline)),
          Row(
            children: <Widget>[
              Icon(Icons.add_circle_outline,
                  size: 16, color: theme.colorScheme.primary),
              const SizedBox(width: 4),
              Text('Votre poids',
                  style: theme.textTheme.bodyMedium
                      ?.copyWith(color: theme.colorScheme.primary)),
            ],
          ),
          Text('reste sur le téléphone',
              style: theme.textTheme.bodySmall
                  ?.copyWith(color: theme.colorScheme.outline, fontSize: 10)),
        ],
      ),
    );
  }
}

/// Le temps de chaque kilomètre, comme sur une montre de sport.
class _TempsAuKm extends StatelessWidget {
  const _TempsAuKm({required this.tours});

  final List<TempsAuKm> tours;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: <Widget>[
        Text('Par kilomètre',
            style: theme.textTheme.bodySmall
                ?.copyWith(color: theme.colorScheme.outline)),
        const SizedBox(height: 4),
        // Les derniers d'abord : c'est le kilomètre qu'on vient de finir qu'on
        // regarde, pas le premier.
        for (final t in tours.reversed.take(5))
          Padding(
            padding: const EdgeInsets.symmetric(vertical: 2),
            child: Row(
              children: <Widget>[
                SizedBox(
                  width: 34,
                  child: Text('km ${t.km}', style: theme.textTheme.bodySmall),
                ),
                Text(t.allure,
                    style: theme.textTheme.bodyMedium?.copyWith(
                        fontWeight: FontWeight.w600,
                        fontFeatures: chiffresFixes.fontFeatures)),
                if (t.deniveleM >= 5) ...<Widget>[
                  const SizedBox(width: 8),
                  Icon(Icons.trending_up, size: 13, color: theme.colorScheme.outline),
                  Text(' ${t.deniveleM.round()} m',
                      style: theme.textTheme.bodySmall
                          ?.copyWith(color: theme.colorScheme.outline)),
                ],
              ],
            ),
          ),
      ],
    );
  }
}

/// Ce qui attend d'être envoyé.
///
/// Affiché EXPRÈS, et pas caché comme un détail technique : voir « 340 points
/// en attente » pendant une coupure de réseau, c'est comprendre que rien n'est
/// perdu. Sans cette carte, l'absence de retour ressemble à une panne.
class _CarteFile extends StatelessWidget {
  const _CarteFile({required this.suivi});

  final SuiviCourse suivi;

  @override
  Widget build(BuildContext context) {
    final total = suivi.pointsEnAttente + suivi.detectionsEnAttente;
    if (total == 0) return const SizedBox.shrink();

    final theme = Theme.of(context);
    return CarteFer(
      titre: 'En attente d\'envoi',
      icone: Icons.cloud_upload_outlined,
      enfant: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: <Widget>[
          Text(
            '${suivi.detectionsEnAttente} passage(s) de ligne et '
            '${suivi.pointsEnAttente} point(s) de parcours.',
            style: theme.textTheme.bodyMedium,
          ),
          const SizedBox(height: 6),
          Text(
            'Tout est enregistré sur votre téléphone et repartira dès le retour '
            'du réseau. Rien n\'est perdu, même si vous fermez l\'application.',
            style: theme.textTheme.bodySmall
                ?.copyWith(color: theme.colorScheme.outline),
          ),
        ],
      ),
    );
  }
}
