# Jeedom IA Assistant

Assistant domotique intelligent pour Jeedom utilisant des modèles d'IA avancés pour le contrôle vocal et automatisé de votre maison connectée.

## 🎯 Fonctionnalités

- **Contrôle naturel** : Commandez vos équipements en langage naturel
- **Analyse contextuelle** : Comprend les demandes ambiguës grâce à l'historique des conversations
- **Vision par caméra** : Analyse les images des caméras de surveillance
- **Gestion multi-pièces** : Supporte plusieurs pièces et profils utilisateurs
- **Détection d'état** : Vérifie l'état actuel avant d'exécuter une action
- **Notifications intelligentes** : Envoie des notifications via Telegram ou autre
- **Multi-provider** : Compatible avec plusieurs fournisseurs IA (OpenAI, Mistral, Claude)
- **Optimisé coûts** : Choix du modèle selon vos besoins et budget

## 📋 Exemples d'utilisation

```
"Allume la lumière du salon"
"Quelle est la température de la chambre ?"
"Ouvre tous les volets du premier étage"
"Montre-moi ce qui se passe dans le jardin"
"Éteins tout dans la cuisine"
```

## 📁 Structure du projet

### `jeedomAssistant.class.php`
Classe principale qui orchestre la logique métier. Gère :
- Collecte automatique des équipements Jeedom (lumières, volets, capteurs, caméras)
- Parsing et validation des réponses de l'IA
- Exécution sécurisée des actions
- Gestion des notifications et images de caméras
- Filtrage intelligent des équipements (catégories, exclusions)

### `AIChat.class.php`
Wrapper universel pour les APIs d'IA. Fournit :
- Support multi-provider (OpenAI, Mistral, Claude)
- Gestion des conversations par utilisateur avec historique local
- Support de la vision (analyse d'images)
- Gestion automatique du contexte et des modèles
- Gestion d'erreurs intelligente avec messages user-friendly

### `codeScenario_Notification IA.php`
Script d'intégration pour les scénarios Jeedom. Permet :
- Configuration simple via tags de scénario
- Filtrage des pièces et équipements
- Mode debug complet
- Gestion des erreurs et notifications

## 🚀 Installation

1. **Copier les fichiers** dans `/var/www/html/plugins/script/data/jeedomAssistant/`

2. **Créer un scénario Jeedom** avec les tags suivants :
```php
#profile#  // Nom de l'utilisateur (ex: "Franck")
#msg#      // Votre question/commande
#piece#    // Pièce(s) concernée(s) (optionnel)
#mode#     // 'action' ou 'info' (optionnel)
#command#  // Commande de notification (optionnel)
```

3. **Configurer dans `codeScenario_Notification IA.php`** :

#### Configuration avec OpenAI
```php
$aiApiKey = $scenario->getData('OPENAI_API_KEY');
$aiBaseUrl = "https://api.openai.com/v1";
$aiModel = "gpt-4o-mini";
$aiModelVision = "gpt-4o-mini";
```

#### Configuration avec Mistral
```php
$aiApiKey = $scenario->getData('MISTRAL_API_KEY');
$aiBaseUrl = "https://api.mistral.ai/v1";
$aiModel = "mistral-large-2-1-24-11";
$aiModelVision = "mistral-small-3-2-25-06";
```

#### Configuration avec Claude
```php
$aiApiKey = $scenario->getData('CLAUDE_API_KEY');
$aiBaseUrl = "https://api.anthropic.com/v1";
$aiModel = "claude-3-5-sonnet-20241022";
$aiModelVision = "claude-3-5-sonnet-20241022";
```

#### Configuration générale
```php
$config = [
    'ai_api_key' => $aiApiKey,
    'ai_model' => $aiModel,
    'ai_vision_model' => $aiModelVision,
    'ai_base_url' => $aiBaseUrl,
    'notification_scenario_id' => 387,
    'pieces_inclus' => ["Salon", "Cuisine", ...],
    'debug' => true
];
```

## 🔧 Configuration avancée

### Filtres d'équipements
```php
'pieces_inclus' => ["Maison", "Jardin", "Salon", ...],
'equipements_exclus' => ["Prise", "Volets", "Résumé", ...],
'eq_action_inclus_categories' => ["light", "opening", "heating", "security"],
'eq_cmd_exclus' => ["Rafraichir", "binaire", "Thumbnail"]
```

### Gestion de l'historique
```php
// Durée de vie des conversations (en secondes)
$assistant->setConversationMaxAge(3600); // 1 heure

// Réinitialiser l'historique d'un utilisateur
$assistant->resetConversation("Franck");
```

### Analyse automatique des pièces
```php
// Active l'analyse préliminaire pour identifier les pièces concernées
// Permet d'optimiser les performances en ne chargeant que les données nécessaires
$analysePieces = true;
$result = $assistant->process($profile, $question, $pieces, $mode,
                              $notificationCommand, $images, $analysePieces);
```

### Mode debug
```php
'debug' => true,                  // Affiche les logs détaillés
'debug_eq' => true,               // Affiche les équipements collectés
'debug_eq_detail' => true,        // Détails des commandes
'debug_dont_run_action' => true   // Simule sans exécuter
```

## 🎬 Cas d'usage

### Contrôle vocal simple
```php
$assistant->process('Franck', 'Allume le salon', null, 'action', 'telegram');
```

### Interrogation multi-pièces
```php
$assistant->process('Marie', 'Quelle température dans les chambres ?',
    ['Chambre Parents', 'Chambre Evan'], 'info');
```

### Analyse de caméra
```php
$assistant->process('Franck', 'Montre-moi le jardin', ['Jardin'], 'action');
```

## 📝 Format de réponse JSON

L'IA retourne toujours un JSON structuré :
```json
{
  "question": "Allume la lumière du salon",
  "response": "✅ J'allume la lumière du salon.",
  "piece": "Salon",
  "id": "123",
  "mode": "action",
  "confidence": "high",
  "type action": "command"
}
```

## 🌍 Comparaison des providers

| Provider | Avantages | Modèles recommandés | Coût estimé |
|----------|-----------|-------------------|-------------|
| **OpenAI** | Mature, stable, excellente documentation | gpt-4o-mini, gpt-4o | ~€1-2/mois |
| **Mistral** | Meilleur rapport qualité/prix, européen | mistral-large, mistral-small | ~€0.50-1/mois |
| **Claude** | Excellent raisonnement, moins cher | claude-3-5-sonnet | ~€0.80-1.50/mois |

## 🔒 Sécurité

- Validation systématique des profils utilisateurs
- Vérification de l'état avant exécution
- Niveau de confiance (high/medium/low)
- Confirmation pour actions sensibles
- Mode simulation pour tests
- Gestion d'erreurs avec messages clairs

## 🆕 Nouveautés v3.00

### Support multi-provider
- Architecture agnostique compatible avec plusieurs fournisseurs IA
- Changement de provider en modifiant simplement la configuration
- Optimisation des coûts selon les tarifs

### Gestion d'erreurs améliorée
- Messages d'erreur traduits en français
- Suggestions de solutions automatiques
- Mode debug intelligent

### Historique local
- Conversations stockées localement (20 messages max)
- Expiration automatique après 1h
- Meilleur contrôle et confidentialité

## 📊 Performance

- **Temps de réponse** : 0.5-2s selon le provider et le modèle
- **Taille du contexte** : Optimisé automatiquement (max 28KB de données Jeedom)
- **Limite historique** : 20 messages (10 échanges) par profil
- **Expiration** : Conversations purgées après 1h d'inactivité

## 🤝 Contribution

Les contributions sont les bienvenues ! N'hésitez pas à ouvrir une issue ou soumettre une pull request.

## 📄 Licence

Ce projet est sous licence MIT.

## 👨‍💻 Auteur

**Franck WEHRLE**
Avec l'aide de Claude.ai (Anthropic)

## 📚 Documentation

- [CHANGELOG.md](CHANGELOG.md) - Historique des versions
- [MIGRATION_AGNOSTIQUE.md](MIGRATION_AGNOSTIQUE.md) - Guide de migration v2.x → v3.00

---

⭐ Si ce projet vous est utile, n'hésitez pas à mettre une étoile !
