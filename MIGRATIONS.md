# Systeme de migrations — Forbach en Rose

## Regle absolue

**JAMAIS d'auto-migration dans le code applicatif.**

Les fichiers dans `/inc/`, `/config/`, `/public/` ne doivent JAMAIS contenir de `ALTER TABLE` ou `CREATE TABLE IF NOT EXISTS` pour des migrations.

- `install.php` : contient les `CREATE TABLE` pour une installation neuve — c'est normal.
- Le code applicatif doit toujours gerer le cas ou une colonne/table n'existe pas encore (try/catch + fallback).
- Les migrations se font UNIQUEMENT via le bouton "Mise a jour BDD" dans la sidebar admin.

## Comment ca marche

1. **Creer le dossier `/migrations/`** a la racine du projet.

2. **Creer un fichier SQL** dans ce dossier avec un nom prefixe par un numero :
   ```
   migrations/001_description.sql
   migrations/002_autre_changement.sql
   ```

3. **Placer le fichier `update.php`** a la racine du projet.

4. **Un bouton jaune "Mise a jour BDD"** apparait automatiquement dans la sidebar admin quand `update.php` existe a la racine.

5. **L'admin clique** sur le bouton → les migrations s'executent dans l'ordre.

6. **Auto-suppression** : si tout se passe bien, `update.php` et le dossier `migrations/` sont supprimes automatiquement. Le bouton disparait de la sidebar.

## Format des fichiers SQL

- Un fichier `.sql` par lot de migrations
- Plusieurs statements separes par `;`
- Les erreurs "colonne/table deja existante" sont ignorees automatiquement
- Utiliser `ALTER TABLE ... ADD COLUMN` pour les nouvelles colonnes
- Utiliser `CREATE TABLE IF NOT EXISTS` pour les nouvelles tables

## Quand un developpeur ajoute une fonctionnalite qui necessite un changement BDD

1. Modifier `install.php` → `getCreateTableStatements()` pour inclure la nouvelle structure (nouvelles installations)
2. Creer `migrations/XXX_description.sql` avec les ALTER TABLE / CREATE TABLE
3. Placer `update.php` a la racine
4. Dans le code applicatif, TOUJOURS gerer le fallback si la colonne/table n'existe pas :
   ```php
   $migrationDone = false;
   try { $pdo->query("SELECT new_column FROM table LIMIT 0"); $migrationDone = true; } catch (PDOException $e) {}

   if ($migrationDone) {
       // Nouveau comportement
   } else {
       // Ancien comportement (fallback)
   }
   ```
5. Afficher un message d'avertissement si la migration n'a pas ete faite :
   ```php
   <?php if (!$migrationDone): ?>
   <div class="alert alert-warning">Veuillez executer la mise a jour BDD pour activer toutes les fonctionnalites.</div>
   <?php endif; ?>
   ```

## Processus de mise a jour pour l'administrateur

1. Deployer les nouveaux fichiers sur le serveur
2. Se connecter a l'administration
3. Si le bouton jaune "Mise a jour BDD" apparait dans la sidebar → cliquer dessus
4. Verifier que tout est OK (vert)
5. Le fichier se supprime automatiquement
6. C'est fini

## Template update.php

Si vous devez recreer le fichier `update.php`, voir le fichier actuel ou le copier depuis une sauvegarde. Le fichier :
- Requiert une authentification admin
- Lit les fichiers `.sql` dans `/migrations/`
- Les execute dans l'ordre alphabetique
- Affiche les resultats
- Se supprime automatiquement (ainsi que le dossier migrations/) si tout est OK
