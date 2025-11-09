<?php
/**
 * Scénario d'interrogation IA multi-provider (OpenAI, Mistral, Claude)
 * Version simplifiée utilisant la classe JeedomAssistant
 *
 * @author Franck WEHRLE
 * @version 3.02
 *
 * Tags nécessaires lors de l'appel du scénario:
 * - #profile# : Nom de l'utilisateur (obligatoire)
 * - #msg# : Question/commande (obligatoire)
 * - #piece# : Pièce(s) concernée(s) (optionnel)
 * - #mode# : 'action' ou 'info' (optionnel, défaut: 'action')
 * - #command# : Commande de notification retour (optionnel)
 */

// ============================================
// CONFIGURATION
// ============================================
$notificationScenarioId = 387; // TODO ID de votre scénario de notification

require_once '/var/www/html/plugins/script/data/jeedomAssistant/jeedomAssistant.class.php';

// Exemples de configuration multi-provider : (décommenter et adapter selon le provider choisi)
//La clé API est ici récupérée depuis une variable de scénario, mais peut être hardcodée si besoin (déconseillé pour la sécurité)

// OpenAI :
// $aiApiKey = $scenario->getData('OPENAI_API_KEY'); //Token API
// $aiBaseUrl = "https://api.openai.com/v1"; //URL de base de l'API OpenAI
// $aiModel = "gpt-4o-mini";
// $aiModelVision = "gpt-4o-mini"; // 'gpt-4o-mini' ou 'gpt-4o', 'gpt-4-turbo' (OpenAI avec vision)

// Mistral :
$aiApiKey = $scenario->getData('MISTRAL_API_KEY'); //Token API
$aiBaseUrl = "https://api.mistral.ai/v1"; //URL de base de l'API Mistral
$aiModel = "mistral-large-latest"; // mistral-small-2506 : léger et rapide /mistral-small-latest magistral-small-2509 : équilibré et puissant
$aiModelVision = "pixtral-large-latest"; // Vision : pixtral-12b mistral-small-2506 ou pixtral-large-latest (avec vision) / pixtral-12b-2409 (vision uniquement)

// Claude : 
// Pas de reconnaissance d'image pour Claude pour le moment
// $aiApiKey = $scenario->getData('CLAUDE_API_KEY'); //Token API
// $aiBaseUrl = "https://api.anthropic.com/v1"; //URL de base de l'API Claude
// $aiModel = "claude-3-5-sonnet-20241022"; // claude-3-5-sonnet-20241022 / claude-4-100k-20241022
// $aiModelVision = ""; // Pas de modèle avec vision pour Claude

// Configuration de l'assistant
$config = [
    'ai_api_key' => $aiApiKey,
    'ai_model' => $aiModel,
    'ai_vision_model' => $aiModelVision,
    'ai_base_url' => $aiBaseUrl,
    'notification_scenario_id' => $notificationScenarioId,

    // Instructions pour l'IA (optionnel - utilise les instructions par défaut si non spécifié)
    // Personnaliser si vous voulez modifier le comportement de l'assistant
     'ai_instructions' => "# RÔLE\n" .
        "Tu es Jarvis, un assistant domotique intelligent pour Jeedom.\n\n" .

        "# FORMAT DE RÉPONSE OBLIGATOIRE\n" .
        "Tu dois TOUJOURS répondre UNIQUEMENT avec un objet JSON valide (sans markdown, sans backticks).\n" .
        "Structure JSON obligatoire :\n" .
        "{\n" .
        "  \"question\": \"question reformulée sans le JSON des capteurs\",\n" .
        "  \"response\": \"réponse en langage naturel et amical\",\n" .
        "  \"piece\": \"nom de la/les pièce(s) concernée(s), séparées par virgules, ou vide\",\n" .
        "  \"id\": \"ID de la ou les commande(s) ou équipement(s) Jeedom si trouvée(s), séparées par virgules, ou vide\",\n" .
        "  \"mode\": \"action\" ou \"info\",\n" .
        "  \"confidence\": \"high\" ou \"medium\" ou \"low\",\n" .
        "  \"type action\": \"code du type de l'action que tu souhaite executer. OBLIGATOIRE si un id est précisé.\"\n" .
        "}\n\n" .

        "# RÈGLES DE DÉTECTION DU MODE\n" .
        "- mode = \"action\" : Pour toute demande d'action physique (allumer, éteindre, ouvrir, fermer, monter, descendre, activer, désactiver, régler, programmer)\n" .
        "- mode = \"info\" : Pour les questions d'information (quelle température, est-ce que, combien, statut, état)\n\n" .

        "# RÈGLES DE DÉTECTION DU TYPE D'ACTION\n" .
        "- type action = \"command\" : Pour toute demande d'action physique (allumer, éteindre, ouvrir, fermer, monter, descendre, activer, désactiver, régler, programmer)\n" .
        "- type action = \"camera\" : Pour toutes demandes d'information relatives à de l'analyse d'image des caméras de surveillance (obligatoire si tu renvois un ID de camera dans le champ id \n\n" .

        "# RÈGLES POUR LES ACTIONS\n" .
        "Avant d'executer une action :\n" .
        "1. Vérifie l'état actuel de l'équipement dans le JSON fourni :\n" .
        " - RÈGLE GÉNÉRALE : Pour tous les équipements (portes, volets, fenêtres, garage, vannes) :\n" .
        "   * Etat = 0 → équipement OUVERT\n" .
        "   * Etat = 1 → équipement FERMÉ\n" .
        " - Pour les lumières et équipements électriques :\n" .
        "   * Etat = 0 → équipement ÉTEINT\n" .
        "   * Etat = 1 ou valeur positive → équipement ALLUMÉ/ACTIF\n" .
        " - Pour les actions dans le JSON :\n" .
        "   * 'Ouvrir' ou 'Monter' → ouvre l'équipement (porte, volet, vanne, garage)\n" .
        "   * 'Fermer' ou 'Descendre' → ferme l'équipement\n" .
        "   * 'On' ou 'Allumer' → allume l'équipement\n" .
        "   * 'Off' ou 'Eteindre' → éteint l'équipement\n" .
        "2. Vérifie SYSTEMATIQUEMENT l'état de l'équipement dans le json envoyé\n" .
        "3. Si l'équipement est déjà dans l'état demandé, réponds : \"[Équipement] est déjà [état].\"\n" .
        "4. Si l'action est nécessaire, fournis l'ID de la commande correspondant à l'action voulue et mode=\"action\"\n" .
        "5. Si plusieurs équipements correspondent, demande de préciser ou liste les options\n\n" .
        "6. Si tu renvois \"type action\" = \"camera\", ne réponds jamais sur ce que tu vois sur une image ou une caméra si il n'y a pas d'image dans mon message. Attends de pouvoir analyser l'image \n\n" .
        "# RÈGLES DE SÉCURITÉ\n" .
        "- N'execute une action que si tu es CERTAIN de la réponse (confidence=\"high\")\n" .
        "- Si tu n'es pas sûr, indique confidence=\"medium\" ou \"low\" et explique pourquoi\n" .
        "- Si aucune question n'est posée, réponds : {\"question\":\"\",\"response\":\"Aucune question détectée.\",\"piece\":\"\",\"id\":\"\",\"mode\":\"info\",\"confidence\":\"high\"}\n" .
        "- Si l'ID de commande n'est pas trouvé dans le JSON, laisse \"id\" vide et explique dans \"response\"\n\n" .

        "# STYLE DE RÉPONSE\n" .
        "- Sois précis, naturel et concis. Fais des réponses courtes\n" .
        "- Utilise des retours à la ligne (\\n) pour les réponses multi-phrases\n" .
        "- Personnalise avec le prénom si pertinent\n" .
        "- Ajoute des emojis légers si approprié (🌡️ 💡 🚪)\n\n" .

        "# EXEMPLES DE RÉPONSES ATTENDUES\n" .
        "Question : \"Allume la lumière du salon\"\n" .
        "Si déjà allumée :\n" .
        "{\"question\":\"Allume la lumière du salon\",\"response\":\"💡 La lumière du salon est déjà allumée.\",\"piece\":\"salon\",\"id\":\"\",\"mode\":\"info\",\"confidence\":\"high\",\"type action\":\"\"}\n\n" .

        "Si éteinte :\n" .
        "{\"question\":\"Allume la lumière du salon\",\"response\":\"✅ J'allume la lumière du salon.\",\"piece\":\"salon\",\"id\":\"123\",\"mode\":\"action\",\"confidence\":\"high\",\"type action\":\"command\"}\n\n" .

        "Question : \"Quelle est la température du salon ?\"\n" .
        "{\"question\":\"Quelle est la température du salon ?\",\"response\":\"🌡️ La température du salon est actuellement de 21.5°C.\",\"piece\":\"salon\",\"id\":\"456\",\"mode\":\"info\",\"confidence\":\"high\",\"type action\":\"\"}\n\n" .

        "Question : \"Montre-moi le salon\"\n" .
        "{\"question\":\"Montre-moi le salon\",\"response\":\"Je regarde sur les caméras.\",\"piece\":\"salon\",\"id\":\"\",\"mode\":\"action\",\"confidence\":\"high\",\"type action\":\"camera\"}\n\n" .

        "# GESTION DU CONTEXTE\n" .
        "- Mémorise les préférences exprimées par chaque utilisateur\n" .
        "- Si une pièce a été mentionnée récemment, c'est probablement celle concernée par \"ici\" ou \"là\"\n",

    // Pièces à inclure dans les infos a envoyer à l'IA
    'pieces_inclus' => [
        "Maison", "Jardin", "Piscine", "Consos", "Entrée", "Salon", "Salle à manger", "Cuisine", "Garage",
        "Demi Niveau", "Bibliothèque", "Salle de bain", "Chambre Parents", "Bureau", "Etage", "Chambre Evan", "Chambre Eliott"
    ],

    // Équipements à exclure dans les infos a envoyer à l'IA
    'equipements_exclus' => [
        "Prise", "Volets", "Résumé", "Dodo", "Eteindre", "Météo Bischwiller", "Pollens", "Caméra Tablette Salon"
    ],

    // Catégories d'actions autorisées à l'IA : "light", "opening", "heating","security","energy","automatism","multimedia","default"
    'eq_action_inclus_categories' => ["light", "opening", "heating", "security"],

    // Commandes à exclure
    'eq_cmd_exclus' => ["Rafraichir", "binaire", "Thumbnail"],

    'debug' => false, //Affichage des logs de débuggage dans le log scenario_execution
    'debug_eq' => false, //Affichage de la liste des équipements chargés
    'debug_eq_detail' => false, //Affichage du détail des équipements chargés
    'debug_dont_run_action' => false //Ne pas exécuter les actions (mode test)
];

// ============================================
// RÉCUPÉRATION DES TAGS
// ============================================

$tags = $scenario->getTags();

// Profile (obligatoire)
$profile = isset($tags['#profile#']) ? $tags['#profile#'] : 'Inconnu';

// Question (obligatoire)
if (!isset($tags['#msg#'])) {
    $scenario->setLog("❌ ERREUR: Tag #msg# manquant");
    exit;
}
$question = trim((string) $tags['#msg#']);

// Pièces (optionnel)
$pieces = null;
if (isset($tags['#piece#'])) {
    $tagPieces = explode(',', $tags['#piece#']);
    $pieces = array_map('trim', $tagPieces);
}else{
    $pieces = $config['pieces_inclus'];
}

// Mode (optionnel)
$mode = isset($tags['#mode#']) ? $tags['#mode#'] : 'action';

// Commande de notification (optionnel)
$notificationCommand = isset($tags['#command#']) ? $tags['#command#'] : '';

// ============================================
// TRAITEMENT
// ============================================

try {
    echo "\n\n******************************************\n";
  	echo date('[Y-m-d H:i:s] ') . "Initialisation de l'assistant Jeedom\n";
    $assistant = new JeedomAssistant($config);
    
    // Optionnel : Configurer la durée de vie des Conversations (1 heures)
    $assistant->setConversationMaxAge(3600);

    // Forcer un nouveau Conversation
    //$assistant->resetConversation("Franck");

    $scenario->setLog("📝 Question de $profile: $question");
    echo "📝 Question de $profile: $question\n";

    // ✅ Activer l'analyse automatique des pièces pour optimiser les performances
    // Si $pieces = null et $analysePieces = true, l'IA identifie d'abord les pièces concernées
    // pour ne charger que les données nécessaires (au lieu de toutes les pièces)
    $analysePieces = true; // false = charge toutes les pièces (ancien comportement)

    // Traiter la demande process($profile, $question, $pieces = null, $mode = 'action', $notificationCommand = '', $images = null, $analysePieces = false)
    $result = $assistant->process($profile, $question, $pieces, $mode, $notificationCommand, null, $analysePieces);
    
    // Vérifier le résultat
    if ($result['success']) {
        $scenario->setLog("✅ Réponse: " . $result['message']);
        
        if ($result['action_executed']) {
            $scenario->setLog("🎬 Action exécutée");
        }
        
        // Afficher les détails de la réponse
        $response = $result['response'];
        if (!empty($response['piece']))  $scenario->setLog("📍 Pièce(s): " . $response['piece']);
        if (!empty($response['id'])) $scenario->setLog("🔗 Commande ID: " . $response['id']);
        if (!empty($response['action'])) $scenario->setLog("🔗 Action: " . $response['action']);
		if (!empty($response['type action'])) $scenario->setLog("🔗 Type action: " . $response['type action']);

        $scenario->setLog("📊 Confiance: " . $response['confidence']);
        
    } else {
        $errorMsg = "❌ Erreur scénario: " . $result['error'];
        echo $errorMsg."\n";
        $assistant->sendMessageNotification($profile, $errorMsg, $notificationCommand);
        $scenario->setLog($errorMsg);
    }
    
} catch (Exception $e) {
    $errorMsg = "❌ Exception scénario: " . $e->getMessage();
    echo $errorMsg."\n";
    $scenario->setLog($errorMsg);
    
    echo "Envoyer une notification d'erreur à  la commande ".$notificationCommand." au scenario ".$config['notification_scenario_id']."\n";
    $scenario2 = scenario::byId($config['notification_scenario_id']);
    if (is_object($scenario2)) {
        $tags2 = $scenario2->getTags();
        $tags2['#profile#'] = 'Franck';
        $tags2['#msg#'] = $errorMsg;
        $tags2['#command#'] = $notificationCommand;
        $scenario2->setTags($tags2);
        $scenario2->launch();
    }else{
     echo "scénario introuvable\n"; 
    }
}
echo date('[Y-m-d H:i:s] ') . "FIN de l'assistant Jeedom\n \n ";
?>