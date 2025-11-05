# Gestion automatique des Threads avec expiration

## Vue d'ensemble

Les threads OpenAI stockent l'historique des conversations. Pour éviter d'accumuler trop d'historique et garder le contexte récent, les threads expirent automatiquement après **1 heure** d'inactivité.

## Fonctionnement

### Nouveau format de stockage

**Ancien format** (compatibilité maintenue) :
```json
{
  "assistant_id": "asst_...",
  "threads": {
    "Franck": "thread_abc123"
  }
}
```

**Nouveau format** (avec timestamps) :
```json
{
  "assistant_id": "asst_...",
  "threads": {
    "Franck": {
      "id": "thread_abc123",
      "last_used": 1730800800,
      "created_at": 1730797200
    }
  }
}
```

### Logique d'expiration

```
┌─────────────────────────────────────────────────────────────┐
│ Utilisateur pose une question                               │
└───────────────────────────┬─────────────────────────────────┘
                            │
                            ▼
                ┌───────────────────────┐
                │ Thread existe pour    │
                │ ce profile ?          │
                └───────┬───────────────┘
                        │
            ┌───────────┴───────────┐
            │                       │
           OUI                     NON
            │                       │
            ▼                       ▼
┌───────────────────────┐   ┌─────────────────┐
│ Vérifier âge du       │   │ Créer nouveau   │
│ dernier usage         │   │ thread          │
└───────┬───────────────┘   └─────────────────┘
        │
    ┌───┴────┐
    │        │
  < 1h     > 1h
    │        │
    ▼        ▼
┌───────┐ ┌─────────────────┐
│Réutil.│ │Créer nouveau    │
│thread │ │thread (purge)   │
└───┬───┘ └────────┬────────┘
    │              │
    └──────┬───────┘
           ▼
┌──────────────────────┐
│Mettre à jour         │
│last_used = now()     │
└──────────────────────┘
```

## Configuration

### Durée de vie par défaut : 1 heure

```php
$ai = new OpenAIAssistant(OPENAI_API_KEY, true, CONFIG_FILE);

// La durée par défaut est 3600s (1h)
echo $ai->getThreadMaxAge(); // 3600
```

### Personnaliser la durée de vie

```php
// 2 heures
$ai->setThreadMaxAge(7200);

// 30 minutes
$ai->setThreadMaxAge(1800);

// 24 heures
$ai->setThreadMaxAge(86400);
```

## Cas d'usage

### Scénario 1 : Conversations récentes

```
10h00 : "Allume la lumière du salon"
        → Nouveau thread créé (thread_A)

10h15 : "Quelle est la température ?"
        → Réutilise thread_A (15 min < 1h)

10h45 : "Éteins la lumière"
        → Réutilise thread_A (45 min < 1h)
        → L'IA se souvient du contexte (salon)
```

### Scénario 2 : Conversation après pause

```
10h00 : "Allume la lumière du salon"
        → Nouveau thread créé (thread_A)

11h30 : "Quelle est la température ?"
        → Nouveau thread créé (thread_B)
        → 1h30 > 1h → thread_A expiré
        → Contexte réinitialisé (l'IA ne se souvient plus du salon)
```

### Scénario 3 : Utilisateurs multiples

```
Profile "Franck":
  10h00 → thread_franck_1
  10h30 → Réutilise thread_franck_1

Profile "Marie":
  10h15 → thread_marie_1
  10h45 → Réutilise thread_marie_1

Chaque utilisateur a son propre thread et son propre historique
```

## Migration automatique

Le code détecte et migre automatiquement l'ancien format :

```php
// Ancien format détecté
if (is_string($threadData)) {
    echo "Migration ancien format de thread pour $profile\n";
    $threadId = $threadData;
    $lastUsed = $now; // Considéré comme récent
}

// Converti en nouveau format au prochain appel
$config['threads'][$profile] = [
    'id' => $threadId,
    'last_used' => $now,
    'created_at' => $now
];
```

**Aucune action requise** : La migration se fait automatiquement au prochain appel.

## Réinitialisation manuelle

### Méthode 1 : resetThread()

```php
// Forcer la création d'un nouveau thread pour un utilisateur
$ai->resetThread("Franck");

// Utile pour :
// - Commande "oublie tout"
// - Démarrer une nouvelle session
// - Purger l'historique manuellement
```

### Méthode 2 : Supprimer le fichier de config

```bash
rm /tmp/jeedom_openai_config.json
```

→ Tous les threads seront recréés au prochain appel

## Debug et monitoring

### Messages de debug

Avec `$debug = true` :

```
# Nouveau thread
Nouveau thread créé: thread_abc123 pour Franck

# Réutilisation
Réutilisation du thread thread_abc123 pour Franck (dernier usage: 15.3 min)

# Expiration
Thread trop ancien pour Franck (1.5h), création d'un nouveau thread
Nouveau thread créé: thread_xyz789 pour Franck

# Migration
Migration ancien format de thread pour Franck
```

### Vérifier l'état du fichier

```bash
cat /tmp/jeedom_openai_config.json | jq
```

```json
{
  "assistant_id": "asst_...",
  "threads": {
    "Franck": {
      "id": "thread_abc123",
      "last_used": 1730800800,
      "created_at": 1730797200
    }
  }
}
```

## Avantages

### 1. Contexte récent
- ✅ Conversations cohérentes sur une courte période
- ✅ L'IA se souvient des références récentes ("éteins-la", "dans la même pièce")

### 2. Économie de tokens
- ✅ Historique limité → moins de tokens consommés
- ✅ Pas d'accumulation infinie de messages

### 3. Pertinence des réponses
- ✅ Pas de confusion avec des contextes obsolètes
- ✅ État actuel de la maison toujours basé sur les données récentes

### 4. Isolation par utilisateur
- ✅ Chaque utilisateur a son propre historique
- ✅ Pas de mélange de contextes entre utilisateurs

## Exemples d'intégration

### Dans un scénario Jeedom

```php
require_once '/var/www/html/plugins/script/data/openAIAssistant.class.php';

$ai = new OpenAIAssistant(OPENAI_API_KEY, true, CONFIG_FILE);

// Configurer la durée de vie (optionnel)
$ai->setThreadMaxAge(3600); // 1h (valeur par défaut)

$config = [...];
$assistant = new JeedomAssistant($config);

// Utilisation normale - la gestion des threads est transparente
$result = $assistant->process($profile, $question, $pieces, $mode, $notificationCommand);
```

### Avec réinitialisation sur commande

```php
// Détecter la commande "oublie tout"
if (strtolower($question) === 'oublie tout' || strtolower($question) === 'reset') {
    $ai->resetThread($profile);
    echo "✅ Historique réinitialisé pour $profile\n";
}
```

## Format des timestamps

Les timestamps sont stockés en **Unix timestamp** (secondes depuis le 01/01/1970) :

```php
$timestamp = 1730800800;

// Conversion en date lisible
echo date('Y-m-d H:i:s', $timestamp);
// 2024-11-05 10:00:00

// Calcul de l'âge
$age = time() - $timestamp;
$ageMinutes = round($age / 60, 1);
echo "Âge: $ageMinutes minutes";
```

## Troubleshooting

### Thread toujours recréé

**Symptôme** : Nouveau thread à chaque appel, même rapprochés

**Causes possibles** :
1. Fichier de config non accessible en écriture
2. Erreur lors de la sauvegarde
3. Fichier effacé entre deux appels

**Solution** :
```bash
# Vérifier les permissions
ls -la /tmp/jeedom_openai_config.json

# Doit être accessible en écriture
chmod 644 /tmp/jeedom_openai_config.json

# Vérifier le contenu
cat /tmp/jeedom_openai_config.json
```

### Thread jamais réinitialisé

**Symptôme** : Même thread utilisé pendant des jours

**Causes possibles** :
1. `last_used` non mis à jour
2. Durée de vie configurée trop longue

**Solution** :
```php
// Vérifier la durée configurée
echo $ai->getThreadMaxAge(); // Devrait être 3600 (1h)

// Forcer la réinitialisation
$ai->resetThread($profile);
```

### Erreur "Undefined variable '$threadData'"

**Cause** : Ancien code avec nouveau format

**Solution** : Mettre à jour `openAIAssistant.class.php` avec la nouvelle version

## Compatibilité

- ✅ **Rétrocompatible** : Ancien format détecté et migré automatiquement
- ✅ **Pas de perte de données** : Les threads existants continuent de fonctionner
- ✅ **Migration transparente** : Aucune action manuelle requise

## Conclusion

La gestion automatique des threads avec expiration :
- ✅ Améliore la pertinence des réponses
- ✅ Réduit les coûts (moins de tokens)
- ✅ Maintient un contexte récent et cohérent
- ✅ Fonctionne de manière transparente

**Configuration recommandée** : Garder la valeur par défaut de 1 heure, ajuster si nécessaire selon vos cas d'usage.
