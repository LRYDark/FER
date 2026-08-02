import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

import '../../api/api_erreur.dart';
import '../portee.dart';
import '../theme.dart';

/// Connexion par code à 6 chiffres envoyé par email.
///
/// ═════════════════════════════════════════════════════════════════════════════
/// IL N'Y A PAS DE MOT DE PASSE, ET C'EST VOULU.
///
/// Le coureur s'est inscrit avec une adresse email ; c'est la seule chose qu'il
/// est sûr de connaître le jour de la course. Lui demander de retenir un mot de
/// passe créé une fois par an, c'est garantir une file au stand d'accueil.
///
/// ⚠️ LE MAIL NE CONTIENT AUCUN LIEN, seulement le code. Un lien cliquable dans
/// un mail est exactement ce qu'imitent les tentatives d'hameçonnage : on
/// n'apprend pas aux gens à cliquer.
///
/// ⚠️ NE JAMAIS DIRE « ADRESSE INCONNUE ». Le serveur répond la même chose que
/// l'adresse soit inscrite ou non — sinon l'API deviendrait un moyen de savoir
/// qui participe à la course.
library;

class EcranConnexion extends StatefulWidget {
  const EcranConnexion({super.key});

  @override
  State<EcranConnexion> createState() => _EcranConnexionState();
}

enum _Etape { adresse, code }

class _EcranConnexionState extends State<EcranConnexion> {
  final _email = TextEditingController();
  final _code = TextEditingController();
  final _focusCode = FocusNode();

  _Etape _etape = _Etape.adresse;
  bool _occupe = false;
  String? _message;
  String? _erreur;

  @override
  void dispose() {
    _email.dispose();
    _code.dispose();
    _focusCode.dispose();
    super.dispose();
  }

  Future<void> _demander() async {
    final email = _email.text.trim();
    if (!email.contains('@') || email.length < 5) {
      setState(() => _erreur = 'Saisissez votre adresse email.');
      return;
    }
    setState(() {
      _occupe = true;
      _erreur = null;
    });
    try {
      final msg = await PorteeSession.action(context).demanderCode(email);
      if (!mounted) return;
      setState(() {
        _etape = _Etape.code;
        _message = msg;
      });
      _focusCode.requestFocus();
    } on ApiErreur catch (e) {
      if (!mounted) return;
      // Le message du serveur est écrit pour être lu : « Trop de demandes.
      // Réessayez dans quelques minutes. » n'a pas besoin d'être reformulé.
      setState(() => _erreur = e.message);
    } finally {
      if (mounted) setState(() => _occupe = false);
    }
  }

  Future<void> _verifier() async {
    final code = _code.text.replaceAll(RegExp(r'\D'), '');
    if (code.length != 6) {
      setState(() => _erreur = 'Le code compte 6 chiffres.');
      return;
    }
    setState(() {
      _occupe = true;
      _erreur = null;
    });
    try {
      await PorteeSession.action(context)
          .verifierCode(_email.text.trim(), code);
      // Aucun `Navigator` ici : c'est le changement d'état de la session qui
      // fait basculer l'application. Un écran qui se pousse lui-même laisserait
      // la connexion accessible par le bouton retour.
    } on ApiErreur catch (e) {
      if (!mounted) return;
      setState(() {
        _erreur = e.message;
        _code.clear();
      });
    } finally {
      if (mounted) setState(() => _occupe = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final session = PorteeSession.de(context);
    final theme = Theme.of(context);
    final ttl = session.config?.codeTtlMinutes ?? 15;
    final aide = session.config?.aideConnexion ??
        "Saisissez l'adresse email utilisée lors de votre inscription. "
            'Un code à 6 chiffres vous sera envoyé.';

    return Scaffold(
      body: SafeArea(
        child: Center(
          child: SingleChildScrollView(
            padding: const EdgeInsets.all(24),
            child: ConstrainedBox(
              // Sur tablette, un formulaire étiré sur 1000 px est illisible :
              // l'œil perd la ligne entre le libellé et le champ.
              constraints: const BoxConstraints(maxWidth: 420),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: <Widget>[
                  Icon(Icons.favorite,
                      size: 56, color: theme.colorScheme.primary),
                  const SizedBox(height: 16),
                  Text('Forbach en Rose',
                      textAlign: TextAlign.center,
                      style: theme.textTheme.headlineSmall
                          ?.copyWith(fontWeight: FontWeight.w700)),
                  const SizedBox(height: 4),
                  Text('Espace coureur',
                      textAlign: TextAlign.center,
                      style: theme.textTheme.bodyMedium
                          ?.copyWith(color: theme.colorScheme.outline)),
                  const SizedBox(height: 32),
                  if (_etape == _Etape.adresse)
                    ..._formulaireAdresse(theme, aide)
                  else
                    ..._formulaireCode(theme, ttl),
                  if (_erreur != null) ...<Widget>[
                    const SizedBox(height: 16),
                    _Bandeau(
                      texte: _erreur!,
                      couleur: theme.colorScheme.error,
                      icone: Icons.error_outline,
                    ),
                  ],
                ],
              ),
            ),
          ),
        ),
      ),
    );
  }

  List<Widget> _formulaireAdresse(ThemeData theme, String aide) => <Widget>[
        Text(aide,
            style: theme.textTheme.bodyMedium
                ?.copyWith(color: theme.colorScheme.outline)),
        const SizedBox(height: 20),
        TextField(
          controller: _email,
          enabled: !_occupe,
          keyboardType: TextInputType.emailAddress,
          autocorrect: false,
          autofillHints: const <String>[AutofillHints.email],
          textInputAction: TextInputAction.go,
          onSubmitted: (_) => _demander(),
          decoration: const InputDecoration(
            labelText: 'Adresse email',
            prefixIcon: Icon(Icons.mail_outline),
          ),
        ),
        const SizedBox(height: 16),
        FilledButton(
          onPressed: _occupe ? null : _demander,
          child: _occupe
              ? const _Rond()
              : const Text('Recevoir mon code'),
        ),
      ];

  List<Widget> _formulaireCode(ThemeData theme, int ttl) => <Widget>[
        if (_message != null)
          _Bandeau(
            texte: _message!,
            couleur: theme.colorScheme.primary,
            icone: Icons.mark_email_read_outlined,
          ),
        const SizedBox(height: 20),
        TextField(
          controller: _code,
          focusNode: _focusCode,
          enabled: !_occupe,
          keyboardType: TextInputType.number,
          inputFormatters: <TextInputFormatter>[
            FilteringTextInputFormatter.digitsOnly,
            LengthLimitingTextInputFormatter(6),
          ],
          // Le code arrive par mail sur le même appareil : la saisie
          // automatique du système évite un aller-retour entre deux
          // applications, code en tête.
          autofillHints: const <String>[AutofillHints.oneTimeCode],
          textAlign: TextAlign.center,
          style: theme.textTheme.headlineMedium
              ?.copyWith(letterSpacing: 12, fontFeatures: chiffresFixes.fontFeatures),
          decoration: const InputDecoration(
            labelText: 'Code à 6 chiffres',
            counterText: '',
          ),
          onChanged: (v) {
            // Validation dès le sixième chiffre : personne n'a envie de
            // chercher un bouton après avoir tapé un code.
            if (v.length == 6 && !_occupe) _verifier();
          },
        ),
        const SizedBox(height: 8),
        Text(
          'Le code est valable $ttl minutes. Le mail ne contient aucun lien : '
          'seul ce code sert à vous connecter.',
          style: theme.textTheme.bodySmall
              ?.copyWith(color: theme.colorScheme.outline),
        ),
        const SizedBox(height: 16),
        FilledButton(
          onPressed: _occupe ? null : _verifier,
          child: _occupe ? const _Rond() : const Text('Me connecter'),
        ),
        TextButton(
          onPressed: _occupe
              ? null
              : () => setState(() {
                    _etape = _Etape.adresse;
                    _code.clear();
                    _erreur = null;
                    _message = null;
                  }),
          child: const Text("Changer d'adresse"),
        ),
      ];
}

class _Bandeau extends StatelessWidget {
  const _Bandeau({
    required this.texte,
    required this.couleur,
    required this.icone,
  });

  final String texte;
  final Color couleur;
  final IconData icone;

  @override
  Widget build(BuildContext context) => Container(
        padding: const EdgeInsets.all(12),
        decoration: BoxDecoration(
          // ignore: deprecated_member_use
          color: couleur.withOpacity(0.10),
          borderRadius: BorderRadius.circular(12),
        ),
        child: Row(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: <Widget>[
            Icon(icone, color: couleur, size: 20),
            const SizedBox(width: 10),
            Expanded(
              child: Text(texte, style: TextStyle(color: couleur)),
            ),
          ],
        ),
      );
}

class _Rond extends StatelessWidget {
  const _Rond();

  @override
  Widget build(BuildContext context) => const SizedBox(
        height: 20,
        width: 20,
        child: CircularProgressIndicator(strokeWidth: 2),
      );
}
