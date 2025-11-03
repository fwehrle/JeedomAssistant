<?php
/**
 * Scénario d'interrogation d'une IA (OpenAI Assistant)
 * Version simplifiée utilisant la classe JeedomAssistant
 * 
 * @author Franck WEHRLE
 * @version 2.01
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

require_once '/var/www/html/plugins/script/data/jeedomAssistant/jeedomAssistant.class.php';

// Configuration de l'assistant
$config = [
    // OpenAI
    'openai_api_key' => $scenario->getData('OPENAI_API_KEY'), // ou directement 'sk-proj-...'
    'openai_model' => 'gpt-4o-mini', // 'gpt-4o-mini' ou 'gpt-4o', 'gpt-4-turbo' ('gpt-4o', 'gpt-4-turbo' pour vision)
    'openai_vision_model' => 'gpt-4o-mini', //'gpt-4.1-mini', 'gpt-4o-mini' ou 'gpt-4o', 'gpt-4-turbo' ('gpt-4o', 'gpt-4-turbo' pour vision)
    // Notification
    'notification_scenario_id' => 387, // TODO: ID de votre scénario de notification
    
    // Pièces à inclure
    'pieces_inclus' => [
        "Maison", "Jardin", "Piscine", "Consos", "Entrée", "Salon", "Salle à manger", "Cuisine", "Garage", 
        "Demi Niveau", "Bibliothèque", "Salle de bain", "Chambre Parents", "Bureau", "Etage", "Chambre Evan", "Chambre Eliott"
    ],
  
    // Équipements à exclure
    'equipements_exclus' => [
        "Prise", "Volets", "Résumé", "Dodo", "Eteindre", "Météo Bischwiller", "Pollens", "Caméra Tablette Salon"
    ],
    
    // Catégories d'actions autorisées "heating","security","energy","automatism","multimedia","default" 
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
    
    $scenario->setLog("📝 Question de $profile: $question");
    echo "📝 Question de $profile: $question\n";
    // Traiter la demande process($profile, $question, $pieces = null, $mode = 'action', $notificationCommand = '', $imageData = null, $filename = null) {
    $result = $assistant->process($profile, $question, $pieces, $mode, $notificationCommand, null, null);
    
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
        $errorMsg = "❌ Erreur scenario: " . $result['error'];
        echo $errorMsg."\n";
        $assistant->sendMessageNotification($profile, $errorMsg, $notificationCommand);
        $scenario->setLog($errorMsg);
    }
    
} catch (Exception $e) {
    $errorMsg = "❌ Exception scenario: " . $e->getMessage();
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
     echo "senario introuvabvle\n"; 
    }
}
echo date('[Y-m-d H:i:s] ') . "FIN de l'assistant Jeedom\n \n ";
// ============================================
// EXEMPLES D'UTILISATION AVANCÉE
// ============================================

/*
// Exemple 1: Utilisation directe sans process()
$assistant = new JeedomAssistant($config);

// Juste poser une question
$response = $assistant->ask('Franck', 'Quelle est la température du salon?');
echo $response['response'];

// Exécuter manuellement l'action
if ($response['mode'] === 'action' && $response['confidence'] === 'high') {
    $assistant->executeAction($response, 'Franck');
}

// Envoyer la notification
$assistant->sendNotification('Franck', $response['response']);


// Exemple 2: Récupérer l'historique
$history = $assistant->getHistory('Franck', 10);
foreach ($history as $msg) {
    echo "[{$msg['role']}] {$msg['content']}\n";
}


// Exemple 3: Réinitialiser le contexte
$assistant->reset();


// Exemple 4: Collecter uniquement les données sans poser de question
$jeedomJson = $assistant->collectJeedomData(['Salon', 'Cuisine'], 'info');
echo $jeedomJson;
*/

?>