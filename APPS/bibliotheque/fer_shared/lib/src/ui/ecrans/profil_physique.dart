import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

import '../../course/mesures.dart';
import '../theme.dart';

/// Poids et mensurations, pour l'estimation des calories.
///
/// ═════════════════════════════════════════════════════════════════════════════
/// ⚠️ CES DONNÉES NE QUITTENT JAMAIS LE TÉLÉPHONE.
///
/// En contexte sportif, le poids peut relever de la donnée de santé au sens du
/// RGPD : ce serait une base de plus à protéger, à purger et à justifier dans la
/// politique de confidentialité — pour un calcul qui se fait très bien sur
/// l'appareil. Le serveur ne les voit pas, ne les stocke pas, et n'a aucun moyen
/// de les demander.
///
/// C'est écrit à l'écran, et le bouton d'effacement est là : une donnée qu'on ne
/// peut pas retirer n'aurait pas dû être demandée.
library;

class EcranProfilPhysique extends StatefulWidget {
  const EcranProfilPhysique({super.key});

  @override
  State<EcranProfilPhysique> createState() => _EcranProfilPhysiqueState();
}

class _EcranProfilPhysiqueState extends State<EcranProfilPhysique> {
  final _poids = TextEditingController();
  final _taille = TextEditingController();
  final _age = TextEditingController();
  String? _sexe;
  bool _charge = false;

  @override
  void initState() {
    super.initState();
    _lire();
  }

  Future<void> _lire() async {
    final p = await ProfilPhysique.charger();
    if (!mounted) return;
    setState(() {
      _poids.text = p.poidsKg?.toStringAsFixed(0) ?? '';
      _taille.text = p.tailleCm?.toString() ?? '';
      _age.text = p.age?.toString() ?? '';
      _sexe = p.sexe;
      _charge = true;
    });
  }

  @override
  void dispose() {
    _poids.dispose();
    _taille.dispose();
    _age.dispose();
    super.dispose();
  }

  Future<void> _enregistrer() async {
    final messenger = ScaffoldMessenger.of(context);
    await ProfilPhysique(
      poidsKg: double.tryParse(_poids.text.replaceAll(',', '.')),
      tailleCm: int.tryParse(_taille.text),
      age: int.tryParse(_age.text),
      sexe: _sexe,
    ).enregistrer();
    if (!mounted) return;
    messenger.showSnackBar(
      const SnackBar(content: Text('Enregistré sur cet appareil.')),
    );
    Navigator.of(context).pop();
  }

  Future<void> _effacer() async {
    await ProfilPhysique.effacer();
    if (!mounted) return;
    setState(() {
      _poids.clear();
      _taille.clear();
      _age.clear();
      _sexe = null;
    });
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    if (!_charge) {
      return const Scaffold(body: Center(child: CircularProgressIndicator()));
    }

    return Scaffold(
      appBar: AppBar(title: const Text('Estimation des calories')),
      body: ListView(
        padding: const EdgeInsets.all(marge),
        children: <Widget>[
          CarteFer(
            titre: 'Vos données',
            icone: Icons.monitor_weight_outlined,
            enfant: Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: <Widget>[
                TextField(
                  controller: _poids,
                  keyboardType: const TextInputType.numberWithOptions(decimal: true),
                  inputFormatters: <TextInputFormatter>[
                    FilteringTextInputFormatter.allow(RegExp(r'[0-9.,]')),
                  ],
                  decoration: const InputDecoration(
                    labelText: 'Poids',
                    suffixText: 'kg',
                    helperText: 'Le seul champ qui compte vraiment.',
                  ),
                ),
                const SizedBox(height: 12),
                Row(
                  children: <Widget>[
                    Expanded(
                      child: TextField(
                        controller: _taille,
                        keyboardType: TextInputType.number,
                        decoration: const InputDecoration(
                          labelText: 'Taille',
                          suffixText: 'cm',
                        ),
                      ),
                    ),
                    const SizedBox(width: 12),
                    Expanded(
                      child: TextField(
                        controller: _age,
                        keyboardType: TextInputType.number,
                        decoration: const InputDecoration(labelText: 'Âge'),
                      ),
                    ),
                  ],
                ),
                const SizedBox(height: 12),
                SegmentedButton<String>(
                  segments: const <ButtonSegment<String>>[
                    ButtonSegment<String>(value: 'F', label: Text('Femme')),
                    ButtonSegment<String>(value: 'H', label: Text('Homme')),
                  ],
                  selected: _sexe == null ? <String>{} : <String>{_sexe!},
                  emptySelectionAllowed: true,
                  onSelectionChanged: (s) =>
                      setState(() => _sexe = s.isEmpty ? null : s.first),
                ),
                const SizedBox(height: 8),
                // ⚠️ On dit franchement que la taille ne change presque rien.
                // Demander une donnée en laissant croire qu'elle est
                // déterminante, c'est en demander plus que nécessaire.
                Text(
                  'Taille, âge et sexe sont facultatifs : ils ne changent presque '
                  'rien au calcul. Ce qui compte, c\'est le poids, la vitesse et '
                  'la pente.',
                  style: theme.textTheme.bodySmall
                      ?.copyWith(color: theme.colorScheme.outline),
                ),
              ],
            ),
          ),
          const SizedBox(height: marge),

          CarteFer(
            titre: 'Ce qu\'on en fait',
            icone: Icons.lock_outline,
            enfant: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: <Widget>[
                Text(
                  'Ces informations restent sur votre téléphone. Elles ne sont '
                  'jamais envoyées à l\'organisation, qui n\'a aucun moyen de les '
                  'demander. Le calcul des calories se fait ici, sur l\'appareil.',
                  style: theme.textTheme.bodyMedium,
                ),
                const SizedBox(height: 12),
                Text(
                  'Le résultat est une estimation à ±20 % environ. Deux montres '
                  'de marques différentes donnent deux chiffres différents pour '
                  'la même marche ; aucune n\'a tort.',
                  style: theme.textTheme.bodySmall
                      ?.copyWith(color: theme.colorScheme.outline),
                ),
              ],
            ),
          ),
          const SizedBox(height: marge),

          FilledButton(
            onPressed: _enregistrer,
            child: const Text('Enregistrer'),
          ),
          TextButton.icon(
            onPressed: _effacer,
            icon: const Icon(Icons.delete_outline),
            label: const Text('Effacer ces données'),
          ),
        ],
      ),
    );
  }
}
