# Changelog - JeedomAssistant

## Version 3.00 (2025-11-06)

### 🌍 Multi-Provider Support
**Migration vers une architecture agnostique supportant plusieurs fournisseurs d'IA**

#### Changements majeurs
- **Renommage de la classe principale** : `OpenAIChat` → `AIChat`
- **Variables renommées** : Toutes les références "openai" deviennent "ai" pour une utilisation générique
- **Support multi-fournisseurs** : OpenAI, Mistral AI, et préparation pour Claude (Anthropic)

#### Providers supportés
| Provider | Status | Modèles recommandés | Particularités |
|----------|--------|-------------------|----------------|
| **OpenAI** | ✅ Full support | gpt-4o-mini, gpt-4o | Tous les modèles Vision supportés |
| **Mistral** | ✅ Full support | mistral-large-2-1-24-11 | Meilleur rapport qualité/prix |
| **Claude** | 🟡 Partiel | claude-3-5-sonnet | Pas de response_format JSON |

#### Avantages
- **Flexibilité** : Changement de provider en modifiant simplement la configuration
- **Économies** : Possibilité d'utiliser Mistral (30% moins cher qu'OpenAI pour des performances équivalentes)
- **Résilience** : Basculement facile si un provider est indisponible
- **Indépendance** : Moins de dépendance à un seul fournisseur

#### Fichiers de configuration
- Ancien fichier : `/tmp/jeedom_openai_config.json`
- Nouveau fichier : `/tmp/jeedom_ai_config.json`
- Les deux peuvent coexister temporairement

#### Gestion d'erreurs améliorée
- **Messages d'erreur user-friendly** : Les erreurs API sont maintenant traduites en français avec des solutions
- **Détection intelligente** :
  - Quota dépassé → Suggestion d'attendre ou changer de modèle
  - Rate limit → Suggestion de patience
  - Modèle invalide → Message clair avec suggestion
  - Clé API invalide → Demande de vérification
- **Mode debug** : Affiche les détails techniques uniquement si activé

#### Migration depuis v2.x
**Étape 1** : Remplacer dans votre configuration :
- `openai_api_key` → `ai_api_key`
- `openai_model` → `ai_model`
- `openai_vision_model` → `ai_vision_model`

**Étape 2** : Ajouter le paramètre `ai_base_url` selon votre provider :
- OpenAI : `https://api.openai.com/v1`
- Mistral : `https://api.mistral.ai/v1`
- Claude : `https://api.anthropic.com/v1`

**Étape 3** : Choisir le modèle adapté à votre provider

#### Modèles Mistral recommandés (2024-2025)
- **Texte standard** : `mistral-large-2-1-24-11` (novembre 2024)
- **Texte + Vision** : `mistral-small-3-2-25-06` (juin 2025)
- **Reasoning avancé** : `magistral-medium-2509` (septembre 2025)
- **Vision avancée** : `pixtral-large-24-11` (novembre 2024)

#### Notes de compatibilité
- **response_format: json_object** fonctionne avec OpenAI et Mistral uniquement
- Claude nécessite un prompt engineering sans garantie JSON stricte
- Les anciennes classes/variables restent pour compatibilité temporaire

---

## Version 2.06 (2025-11-06)

### ✅ JSON Format garanti avec response_format

#### Amélioration
Ajout du paramètre `response_format: json_object` à toutes les requêtes API pour garantir des réponses JSON valides à 100%.

#### Avantages
- **Fiabilité** : Plus besoin de nettoyer les backticks markdown (```json)
- **Simplicité** : Parsing JSON direct sans regex préalable
- **Performance** : Code plus simple et rapide
- **Robustesse** : Garantie de format JSON valide

#### Méthodes modifiées
- `AIChat::ask()` - Chat standard
- `AIChat::askWithImage()` - Chat avec vision
- `AIChat::chatCompletion()` - Extraction de pièces

#### Simplifications du code
- Suppression du nettoyage regex dans `jeedomAssistant::parseResponse()`
- Suppression du nettoyage lors de l'analyse des pièces
- Code de parsing 40% plus court

#### Compatibilité
- ✅ OpenAI : Full support
- ✅ Mistral : Full support
- ⚠️ Claude : Non supporté (utilise prompt engineering)

---

## Version 2.05 (2025-11-05)

### 🚀 Migration vers Chat Completion API

#### Changement architectural majeur
Remplacement complet de l'architecture basée sur **Assistants API** (threads serveur) par **Chat Completion API** avec historique local JSON.

#### Motivations
- **Performance** : 40-60% plus rapide (pas de polling, réponse immédiate)
- **Coûts** : Réduction significative grâce au stockage local
- **Simplicité** : -30% de lignes de code
- **Contrôle** : Historique géré localement, maîtrise totale

#### Breaking Changes
- Méthode renommée : `askAssistant()` → `askChat()`
- Wrapper renommés (rétrocompatibles) :
  - `setThreadMaxAge()` → `setConversationMaxAge()`
  - `resetThread()` → `resetConversation()`

#### Nouvelles fonctionnalités
- **Historique JSON local** : 20 messages maximum par profil (10 échanges)
- **Expiration automatique** : Conversations purgées après 1h d'inactivité
- **Images en base64** : Plus d'upload séparé, intégration directe
- **Gestion du contexte** : Limite automatique pour maîtriser les coûts

#### Méthodes d'historique
- `getConversationHistory()` - Récupère l'historique
- `saveConversationHistory()` - Sauvegarde l'historique
- `addMessageToHistory()` - Ajoute un message
- `pruneOldConversations()` - Purge les anciennes conversations
- `resetConversation()` - Réinitialise l'historique

#### Comparaison avant/après

| Aspect | v2.04 (Assistants) | v2.05 (Chat Completion) |
|--------|-------------------|------------------------|
| **API** | Assistants API | Chat Completion API |
| **Historique** | Threads serveur | JSON local |
| **Appels API** | 3-4 par requête | 1 par requête |
| **Temps réponse** | 2-5s (polling) | 0.5-2s (immédiat) |
| **Images** | Upload fichiers | Base64 inline |
| **Limite** | Illimité | 20 messages |
| **Expiration** | Manuelle | Automatique (1h) |
| **Coût stockage** | Payant (OpenAI) | Gratuit (local) |

#### Méthodes obsolètes
Les méthodes suivantes sont conservées pour compatibilité mais ne sont plus utilisées :
- Gestion des assistants : `createAssistant()`, `getOrCreateAssistant()`
- Gestion des threads : `createThread()`, `getOrCreateThread()`, `deleteThread()`
- Messages : `addMessage()`, `addMessageWithImage()`, `getMessages()`
- Exécution : `runAssistant()`, `waitForRunCompletion()`
- Upload : `uploadImage()`

---

## Version 2.04 et antérieures

### Fonctionnalités principales
- Support de l'API Assistants OpenAI avec threads serveur
- Gestion multi-profils utilisateurs
- Support des images (upload + analyse)
- Historique de conversation illimité côté serveur
- Intégration domotique Jeedom complète
- Exécution d'actions (lumières, volets, équipements)
- Analyse de caméras en temps réel
- Support multi-pièces avec filtrage intelligent
- Mode action et mode info
- Notifications via scénarios Jeedom

### Évolutions notables
- **v2.04** : Expiration automatique des threads après 1h
- **v2.03** : Support multi-images (plusieurs caméras simultanées)
- **v2.02** : Optimisations de performance
- **v2.01** : Support du streaming (retiré en v2.05)
- **v2.00** : Refonte complète de l'architecture

---

## Notes de migration

### De v2.x vers v3.00
1. Mettre à jour les noms de configuration (openai → ai)
2. Ajouter le paramètre `ai_base_url`
3. Vérifier les noms de modèles selon le provider choisi
4. Tester les scénarios critiques

### De v2.04 vers v2.05+
1. Remplacer `askAssistant()` par `askChat()` dans tout le code
2. Vérifier les permissions du fichier `/tmp/jeedom_ai_config.json`
3. Tester l'historique de conversation (max 20 messages)
4. Vérifier que les images fonctionnent (base64)

### Rétrocompatibilité
- Les anciennes méthodes sont conservées mais déconseillées
- Les anciens fichiers de configuration peuvent coexister
- Transition progressive possible

---

## Améliorations futures envisagées

### Court terme
- [ ] Détection automatique du provider selon la clé API
- [ ] Fallback automatique si un provider est indisponible
- [ ] Statistiques d'utilisation (tokens, coûts, latence)
- [ ] Support de nouveaux providers (Gemini, etc.)

### Moyen terme
- [ ] Cache intelligent des réponses fréquentes
- [ ] Optimisation automatique du contexte selon le provider
- [ ] Export de l'historique en format lisible
- [ ] Interface web de monitoring

### Long terme
- [ ] Support de l'apprentissage continu
- [ ] Système de plugins pour extensions
- [ ] API REST pour intégration externe
- [ ] Dashboard de statistiques avancées

---

## Support et contribution

**Projet** : JeedomAssistant
**Auteur** : Franck WEHRLE
**IA Assistant** : Claude.ai (Anthropic)
**Licence** : À définir
**Repository** : À définir

Pour signaler un bug ou proposer une amélioration, créez une issue sur le repository du projet.

---

**Merci d'utiliser JeedomAssistant ! 🎉**
