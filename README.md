# Jeedom OpenAI Assistant

Assistant domotique intelligent pour Jeedom utilisant l'API OpenAI GPT-4 pour le contrôle vocal et automatisé de votre maison connectée.

## 🎯 Fonctionnalités

- **Contrôle naturel** : Commandez vos équipements en langage naturel
- **Analyse contextuelle** : Comprend les demandes ambiguës grâce à l'historique des conversations
- **Vision par caméra** : Analyse les images des caméras de surveillance
- **Gestion multi-pièces** : Supporte plusieurs pièces et profils utilisateurs
- **Détection d'état** : Vérifie l'état actuel avant d'exécuter une action
- **Notifications intelligentes** : Envoie des notifications via Telegram ou autre
- **Optimisé coûts** : Utilise GPT-4o-mini par défaut (~$0.50-1/mois)

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
Classe principale qui fait le pont entre Jeedom et OpenAI. Gère :
- Collecte automatique des équipements Jeedom (lumières, volets, capteurs, caméras)
- Parsing et validation des réponses de l'IA
- Exécution sécurisée des actions
- Gestion des notifications et images de caméras
- Filtrage intelligent des équipements (catégories, exclusions)

### `openAIAssistant.class.php`
Wrapper de l'API OpenAI Assistant. Fournit :
- Gestion des threads de conversation par utilisateur
- Support de la vision (analyse d'images)
- Upload de fichiers vers OpenAI
- Historique des conversations
- Gestion des modèles GPT (4o, 4o-mini, 4-turbo)

### `codeScenario.php`
Script d'intégration pour les scénarios Jeedom. Permet :
- Configuration simple via tags de scénario
- Filtrage des pièces et équipements
- Mode debug complet
- Gestion des erreurs et notifications

## 🚀 Installation

1. **Copier les fichiers** dans `/var/www/html/plugins/script/data/`

2. **Créer un scénario Jeedom** avec les tags suivants :
```php
#profile#  // Nom de l'utilisateur (ex: "Franck")
#msg#      // Votre question/commande
#piece#    // Pièce(s) concernée(s) (optionnel)
#mode#     // 'action' ou 'info' (optionnel)
#command#  // Commande de notification (optionnel)
```

3. **Configurer dans `codeScenario.php`** :
```php
$config = [
    'openai_api_key' => 'sk-proj-...', // Votre clé API OpenAI
    'openai_model' => 'gpt-4o-mini',
    'notification_scenario_id' => 387, // ID de votre scénario de notification
    'pieces_inclus' => ["Salon", "Cuisine", ...],
    'debug' => true
];
```

## 🔧 Configuration avancée

### Filtres d'équipements
```php
'pieces_inclus' => ["Maison", "Jardin", "Salon", ...],
'equipements_exclus' => ["Prise", "Volets", "Résumé", ...],
'eq_action_inclus_categories' => ["light", "opening", "heating"],
'eq_cmd_exclus' => ["Rafraichir", "binaire", "Thumbnail"]
```

### Mode debug
```php
'debug' => true,              // Affiche les logs détaillés
'debug_eq' => true,           // Affiche les équipements collectés
'debug_eq_detail' => true,    // Détails des commandes
'debug_dont_run_action' => true  // Simule sans exécuter
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

## 🔒 Sécurité

- Validation systématique des profils utilisateurs
- Vérification de l'état avant exécution
- Niveau de confiance (high/medium/low)
- Confirmation pour actions sensibles
- Mode simulation pour tests


## 🤝 Contribution

Les contributions sont les bienvenues ! N'hésitez pas à ouvrir une issue ou soumettre une pull request.

## 📄 Licence

Ce projet est sous licence MIT.

## 👨‍💻 Auteur

**Franck WEHRLE**

---

⭐ Si ce projet vous est utile, n'hésitez pas à mettre une étoile !
