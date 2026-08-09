/// « Modifier mes informations » — nom, prénom et adresse email.
///
/// ═════════════════════════════════════════════════════════════════════════════
/// LE MÊME POUVOIR QUE SUR LE SITE, ET LES MÊMES GARDE-FOUS.
///
/// L'espace coureur du site permet depuis toujours de corriger son identité et
/// de changer d'adresse ; l'application, elle, se contentait de les AFFICHER.
/// Quelqu'un qui remarquait une faute dans son nom devait sortir de
/// l'application et ouvrir un navigateur — pour un champ que le serveur sait
/// modifier depuis le premier jour (`PATCH /me`).
///
/// ═════════════════════════════════════════════════════════════════════════════
/// LE CHANGEMENT D'ADRESSE SE FAIT EN DEUX TEMPS, ET C'EST DÉLIBÉRÉ.
///
/// Le serveur envoie d'abord un code à la NOUVELLE adresse, qu'il faut ensuite
/// saisir. En une seule étape, une faute de frappe enfermerait le coureur
/// dehors : son adresse est son unique moyen de se reconnecter, il n'y a pas de
/// mot de passe pour rattraper.
///
/// ⚠️ L'ANCIENNE ADRESSE EST PRÉVENUE par le serveur. Ce n'est pas une
/// politesse : si le changement n'est pas de votre fait, c'est le seul signal
/// qui vous parvient.
library;

import 'package:flutter/material.dart';

import '../../api/api_erreur.dart';
import '../portee.dart';
import '../theme.dart';

class EcranModifierCompte extends StatefulWidget {
  const EcranModifierCompte({super.key});

  @override
  State<EcranModifierCompte> createState() => _EcranModifierCompteState();
}

class _EcranModifierCompteState extends State<EcranModifierCompte> {
  late final TextEditingController _prenom;
  late final TextEditingController _nom;
  final _nouvelEmail = TextEditingController();
  final _code = TextEditingController();

  bool _occupeIdentite = false;
  bool _occupeEmail = false;

  /// Adresse à laquelle le code vient d'être envoyé, `null` avant l'envoi.
  /// C'est elle qui fait passer le bloc email de la première à la seconde étape.
  String? _emailEnAttente;

  @override
  void initState() {
    super.initState();
    final p = PorteeSession.action(context).profil;
    _prenom = TextEditingController(text: p?.prenom ?? '');
    _nom = TextEditingController(text: p?.nom ?? '');
  }

  @override
  void dispose() {
    _prenom.dispose();
    _nom.dispose();
    _nouvelEmail.dispose();
    _code.dispose();
    super.dispose();
  }

  Future<void> _enregistrerIdentite() async {
    setState(() => _occupeIdentite = true);
    final session = PorteeSession.action(context);
    final messenger = ScaffoldMessenger.of(context);
    try {
      final message = await session.api.majIdentite(
        prenom: _prenom.text.trim(),
        nom: _nom.text.trim(),
      );
      // Le profil affiché ailleurs doit suivre : sans ce rechargement, l'écran
      // « Mon compte » garderait l'ancien nom jusqu'au prochain démarrage.
      await session.rafraichir();
      messenger.showSnackBar(SnackBar(content: Text(message)));
    } on ApiErreur catch (e) {
      messenger.showSnackBar(SnackBar(content: Text(e.message)));
    } finally {
      if (mounted) setState(() => _occupeIdentite = false);
    }
  }

  Future<void> _demanderCodeEmail() async {
    final cible = _nouvelEmail.text.trim();
    if (cible.isEmpty) return;
    setState(() => _occupeEmail = true);
    final messenger = ScaffoldMessenger.of(context);
    try {
      final message =
          await PorteeSession.action(context).api.demanderChangementEmail(cible);
      if (mounted) setState(() => _emailEnAttente = cible);
      messenger.showSnackBar(SnackBar(content: Text(message)));
    } on ApiErreur catch (e) {
      messenger.showSnackBar(SnackBar(content: Text(e.message)));
    } finally {
      if (mounted) setState(() => _occupeEmail = false);
    }
  }

  Future<void> _confirmerEmail() async {
    final cible = _emailEnAttente;
    if (cible == null) return;
    setState(() => _occupeEmail = true);
    final session = PorteeSession.action(context);
    final messenger = ScaffoldMessenger.of(context);
    try {
      final message = await session.api.confirmerChangementEmail(
        cible,
        _code.text.replaceAll(RegExp(r'\D'), ''),
      );
      await session.rafraichir();
      if (mounted) {
        setState(() {
          _emailEnAttente = null;
          _nouvelEmail.clear();
          _code.clear();
        });
      }
      messenger.showSnackBar(SnackBar(content: Text(message)));
    } on ApiErreur catch (e) {
      messenger.showSnackBar(SnackBar(content: Text(e.message)));
    } finally {
      if (mounted) setState(() => _occupeEmail = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final profil = PorteeSession.de(context).profil;

    return Scaffold(
      appBar: AppBar(title: const Text('Mes informations')),
      body: ListView(
        padding: const EdgeInsets.fromLTRB(marge, marge, marge, margeBasListe),
        children: <Widget>[
          /* ── Identité ───────────────────────────────────────────────── */
          CarteFer(
            titre: 'Mon identité',
            icone: Icons.badge_outlined,
            enfant: Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: <Widget>[
                _Etiquette('Prénom'),
                TextField(
                  controller: _prenom,
                  enabled: !_occupeIdentite,
                  textCapitalization: TextCapitalization.words,
                  decoration: const InputDecoration(hintText: 'Joris'),
                ),
                const SizedBox(height: 14),
                _Etiquette('Nom'),
                TextField(
                  controller: _nom,
                  enabled: !_occupeIdentite,
                  textCapitalization: TextCapitalization.characters,
                  decoration: const InputDecoration(hintText: 'REINERT'),
                ),
                const SizedBox(height: 18),
                FilledButton(
                  onPressed: _occupeIdentite ? null : _enregistrerIdentite,
                  child: _occupeIdentite
                      ? const SizedBox(
                          height: 20,
                          width: 20,
                          child: CircularProgressIndicator(strokeWidth: 2))
                      : const Text('Enregistrer'),
                ),
                const SizedBox(height: 10),
                // ⚠️ DIT AVANT, PAS APRÈS. La correction se répercute sur
                // l'inscription de l'édition en cours : c'est ce nom-là qui
                // figurera sur la liste de départ.
                Text(
                  "La correction est reportée sur votre inscription de l'édition "
                  'en cours : c\'est ce nom qui figurera sur la liste de départ. '
                  'Les éditions passées ne changent pas.',
                  style: theme.textTheme.bodySmall
                      ?.copyWith(color: theme.colorScheme.onSurfaceVariant),
                ),
              ],
            ),
          ),

          const SizedBox(height: marge),

          /* ── Adresse email ──────────────────────────────────────────── */
          CarteFer(
            titre: 'Mon adresse email',
            icone: Icons.alternate_email,
            enfant: Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: <Widget>[
                LigneFer('Adresse actuelle', profil?.email ?? '—'),
                const SizedBox(height: 8),

                if (_emailEnAttente == null) ...<Widget>[
                  _Etiquette('Nouvelle adresse'),
                  TextField(
                    controller: _nouvelEmail,
                    enabled: !_occupeEmail,
                    keyboardType: TextInputType.emailAddress,
                    autocorrect: false,
                    decoration:
                        const InputDecoration(hintText: 'vous@exemple.fr'),
                  ),
                  const SizedBox(height: 16),
                  OutlinedButton.icon(
                    onPressed: _occupeEmail ? null : _demanderCodeEmail,
                    icon: const Icon(Icons.send_outlined, size: 18),
                    label: const Text('Envoyer le code'),
                  ),
                  const SizedBox(height: 10),
                  Text(
                    'Un code de confirmation sera envoyé à la NOUVELLE adresse. '
                    "Le changement ne prend effet qu'une fois ce code saisi — "
                    'une faute de frappe ne peut donc pas vous enfermer dehors.',
                    style: theme.textTheme.bodySmall
                        ?.copyWith(color: theme.colorScheme.onSurfaceVariant),
                  ),
                ] else ...<Widget>[
                  BlocAccent(
                    icone: Icons.mark_email_unread_outlined,
                    enfant: Text(
                      'Un code à 6 chiffres vient d\'être envoyé à '
                      '$_emailEnAttente.',
                      style: theme.textTheme.bodyMedium,
                    ),
                  ),
                  const SizedBox(height: 16),
                  _Etiquette('Code reçu'),
                  TextField(
                    controller: _code,
                    enabled: !_occupeEmail,
                    keyboardType: TextInputType.number,
                    style: const TextStyle(
                        fontSize: 22, letterSpacing: 8, fontWeight: FontWeight.w700),
                    textAlign: TextAlign.center,
                    decoration: const InputDecoration(hintText: '000000'),
                  ),
                  const SizedBox(height: 16),
                  FilledButton(
                    onPressed: _occupeEmail ? null : _confirmerEmail,
                    child: const Text("Confirmer l'adresse"),
                  ),
                  TextButton(
                    onPressed: _occupeEmail
                        ? null
                        : () => setState(() {
                              _emailEnAttente = null;
                              _code.clear();
                            }),
                    child: const Text('Annuler le changement'),
                  ),
                ],

                const SizedBox(height: 10),
                Text(
                  "Votre ancienne adresse est prévenue du changement : si ce "
                  "n'est pas vous, vous le saurez. Vos appareils connectés, eux, "
                  'ne sont pas déconnectés.',
                  style: theme.textTheme.bodySmall
                      ?.copyWith(color: theme.colorScheme.onSurfaceVariant),
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }
}

/// Étiquette de champ — le thème pose des champs sans contour, il faut donc
/// nommer chacun au-dessus. Un `labelText` flottant se confondrait avec la
/// valeur saisie.
class _Etiquette extends StatelessWidget {
  const _Etiquette(this.texte);

  final String texte;

  @override
  Widget build(BuildContext context) => Padding(
        padding: const EdgeInsets.only(bottom: 8),
        child: Text(
          texte,
          style: Theme.of(context).textTheme.bodySmall?.copyWith(
              color: Theme.of(context).colorScheme.onSurfaceVariant),
        ),
      );
}
