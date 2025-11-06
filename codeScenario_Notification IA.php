<?php
/**
 * Scénario d'interrogation IA multi-provider (OpenAI, Mistral, Claude)
 * Version simplifiée utilisant la classe JeedomAssistant
 *
 * @author Franck WEHRLE
 * @version 3.00
 *
 * Tags nécessaires:
 * - #profile# : Nom de l'utilisateur (obligatoire)
 * - #msg# : Question/commande (obligatoire)
 * - #piece# : Pièce(s) concernée(s) (optionnel)
 * - #mode# : 'action' ou 'info' (optionnel, défaut: 'action')
 * - #command# : Commande de notification (optionnel)
 */

// ============================================
// CONFIGURATION
// ============================================
$notificationScenarioId = 387; // TODO ID de votre scénario de notification

require_once '/var/www/html/plugins/script/data/jeedomAssistant/jeedomAssistant.class.php';

// Exemples de configuration multi-provider :
//
// OpenAI :
// $aiApiKey = $scenario->getData('OPENAI_API_KEY'); //Token API
// $aiBaseUrl = "https://api.openai.com/v1"; //URL de base de l'API OpenAI
// $aiModel = "gpt-4o-mini";
// $aiModelVision = "gpt-4o-mini"; // 'gpt-4o-mini' ou 'gpt-4o', 'gpt-4-turbo' (OpenAI avec vision)

// Mistral :
$aiApiKey = $scenario->getData('MISTRAL_API_KEY'); //Token API
$aiBaseUrl = "https://api.mistral.ai/v1"; //URL de base de l'API Mistral
$aiModel = "mistral-small-2506"; // mistral-small-2506 : léger et rapide /magistral-small-2509 : équilibré et puissant
$aiModelVision = "mistral-small-2506"; // Vision : mistral-small-2506 (avec vision) / pixtral-12b-2409 (vision uniquement)

// Claude :
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
    
    // Pièces à inclure
    'pieces_inclus' => [
        "Maison", "Jardin", "Piscine", "Consos", "Entrée", "Salon", "Salle à manger", "Cuisine", "Garage", 
        "Demi Niveau", "Bibliothèque", "Salle de bain", "Chambre Parents", "Bureau", "Etage", "Chambre Evan", "Chambre Eliott"
    ],
  
    // Équipements à exclure
    'equipements_exclus' => [
        "Prise", "Volets", "Résumé", "Dodo", "Eteindre", "Météo Bischwiller", "Pollens", "Caméra Tablette Salon"
    ],
    
    // Catégories d'actions autorisées "light", "opening", "heating","security","energy","automatism","multimedia","default" 
    'eq_action_inclus_categories' => ["light", "opening", "heating", "security"],
    
    // Commandes à exclure
    'eq_cmd_exclus' => ["Rafraichir", "binaire", "Thumbnail"],
    
    // Debug (mettre à true pour voir les détails)
    'debug' => true,
    'debug_eq' => false,
    'debug_eq_detail' => false,
    'debug_dont_run_action' => false
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