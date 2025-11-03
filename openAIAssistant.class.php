<?php

/* Classe d'utilisation de l'API assistant d'OpenAI
* @author Franck WEHRLE avec l'aide de Claude.ai qui m'a conseillé chatGPT parce qu'elle n'avait pas de gestion de thread ;)
* @version 2.01
*/

// ============================================
// CLASSE OPENAI ASSISTANT
// ============================================
class OpenAIAssistant {
    private $apiKey;
    private $baseUrl = 'https://api.openai.com/v1';
    private $configFile = '/tmp/jeedom_openai_config.json';
    private $model = 'gpt-4o-mini'; // 'gpt-4o-mini' ou 'gpt-4o', 'gpt-4-turbo' ('gpt-4o', 'gpt-4-turbo' pour vision)
    private $modelVision = 'gpt-4o'; //'gpt-4-turbo'
    private $debug;

    public function __construct($apiKey, $debug = false, $configFile = null) {
      if ($this->debug) echo "__construct\n";
        if (empty($apiKey)) {
            throw new Exception("La clé API ne peut pas être vide");
        }
      	if (!empty($configFile)) {
            $this->configFile = $configFile;
        }
      	//$this->configFile = $configFile;
        $this->apiKey = $apiKey;
        $this->debug = $debug;
    }
    
      /**
     * Récupérer l'historique d'un thread
     * 
     * @param string $profile Profil utilisateur
     * @param int $limit Nombre de messages à récupérer (max 100)
     * @return array Tableau de messages
     */
    public function getThreadHistory($profile, $limit = 20) {
        // Limiter entre 1 et 100
        $limit = max(1, min(100, $limit));

        // Récupérer l'ID du thread pour ce profil
        $config = $this->loadConfig();

        if (empty($config['threads'][$profile])) {
            return [
                'success' => false,
                'message' => "Aucun thread trouvé pour le profil: $profile",
                'messages' => []
            ];
        }

        $threadId = $config['threads'][$profile];

        try {
            // Récupérer les messages
            $response = $this->apiCall('GET', "/threads/$threadId/messages?limit=$limit&order=desc");

            $messages = [];
            foreach ($response['data'] as $msg) {
                $messages[] = [
                    'id' => $msg['id'],
                    'role' => $msg['role'],
                    'content' => $msg['content'][0]['text']['value'] ?? '',
                    'created_at' => $msg['created_at'],
                    'date' => date('Y-m-d H:i:s', $msg['created_at'])
                ];
            }

            return [
                'success' => true,
                'thread_id' => $threadId,
                'profile' => $profile,
                'count' => count($messages),
                'messages' => $messages
            ];

        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => "Erreur lors de la récupération de l'historique: " . $e->getMessage(),
                'messages' => []
            ];
        }
    }

    /**
     * Afficher l'historique formaté d'un thread
     * 
     * @param string $profile Profil utilisateur
     * @param int $limit Nombre de messages à récupérer
     * @return string Historique formaté en texte
     */
    public function displayThreadHistory($profile, $limit = 20) {
        $history = $this->getThreadHistory($profile, $limit);

        if (!$history['success']) {
            return $history['message'];
        }

        $output = "=== HISTORIQUE CONVERSATION: $profile ===\n";
        $output .= "Thread ID: {$history['thread_id']}\n";
        $output .= "Nombre de messages: {$history['count']}\n";
        $output .= str_repeat("=", 50) . "\n\n";

        foreach ($history['messages'] as $msg) {
            $role = $msg['role'] === 'user' ? '👤 USER' : '🤖 ASSISTANT';
            $output .= "[{$msg['date']}] $role:\n";
            $output .= $msg['content'] . "\n";
            $output .= str_repeat("-", 50) . "\n\n";
        }

        return $output;
    }

    /**
     * Supprimer un thread (et son historique)
     * 
     * @param string $profile Profil utilisateur
     * @return bool Succès ou échec
     */
    public function deleteThread($profile) {
        $config = $this->loadConfig();

        if (empty($config['threads'][$profile])) {
            echo "Aucun thread à supprimer pour le profil: $profile\n";
            return false;
        }

        $threadId = $config['threads'][$profile];

        try {
            // Supprimer le thread via l'API OpenAI
            $this->apiCall('DELETE', "/threads/$threadId");

            // Retirer de la configuration
            unset($config['threads'][$profile]);
            $this->saveConfig($config);

            if ($this->debug) echo "Thread supprimé avec succès pour le profil: $profile\n";
            return true;

        } catch (Exception $e) {
            echo "Erreur lors de la suppression du thread: " . $e->getMessage() . "\n";
            return false;
        }
    }

    /**
     * Lister tous les threads enregistrés
     * 
     * @return array Liste des profils et leurs thread IDs
     */
    public function listThreads() {
        $config = $this->loadConfig();

        if (empty($config['threads'])) {
            return [
                'count' => 0,
                'threads' => []
            ];
        }

        $threads = [];
        foreach ($config['threads'] as $profile => $threadId) {
            $threads[] = [
                'profile' => $profile,
                'thread_id' => $threadId
            ];
        }

        return [
            'count' => count($threads),
            'threads' => $threads
        ];
    }

  
    /**
     * Appel API générique
     */
    private function apiCall($method, $endpoint, $data = null) {
        if (empty($endpoint)) {
            throw new Exception("Endpoint vide");
        }

        $url = $this->baseUrl . $endpoint;

        $ch = curl_init();
        if ($ch === false) {
            throw new Exception("Impossible d'initialiser cURL");
        }

        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $this->apiKey,
            'OpenAI-Beta: assistants=v2'
        ]);

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            if ($data) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
            }
        } elseif ($method === 'DELETE') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
        }
        // GET est le défaut, pas besoin de configuration spéciale

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            throw new Exception("Erreur cURL: $error");
        }

        if ($httpCode >= 400) {
            throw new Exception("Erreur API ($httpCode): " . substr($response, 0, 300));
        }

        $decoded = json_decode($response, true);
        if ($decoded === null) {
            throw new Exception("Réponse JSON invalide: " . substr($response, 0, 200));
        }

        return $decoded;
    }
    
    /**
     * Charger la configuration
     */
    private function loadConfig() {
        if (file_exists($this->configFile)) {
            $content = file_get_contents($this->configFile);
            $config = json_decode($content, true);
            return $config ?: ['assistant_id' => null, 'threads' => []];
        }
        return ['assistant_id' => null, 'threads' => []];
    }
    
    /**
     * Sauvegarder la configuration
     */
    private function saveConfig($config) {
        if (empty($this->configFile)) {
            throw new Exception("Chemin du fichier de config vide");
        }
        
        // Créer le dossier parent si nécessaire
        $dir = dirname($this->configFile);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        
        $json = json_encode($config, JSON_PRETTY_PRINT);
        $result = file_put_contents($this->configFile, $json);
        
        if ($result === false) {
            throw new Exception("Impossible d'écrire dans: " . $this->configFile);
        }
        
        return true;
    }
    
    /**
     * Créer un assistant
     */
    public function createAssistant($name, $instructions, $model = null) {
      if ($model === null) {
        $model = $this->model;
      }  
      return $this->apiCall('POST', '/assistants', [
            'name' => $name,
            'instructions' => $instructions,
            'model' => $model
        ]);
    }
    
    /**
     * Créer un thread
     */
    public function createThread() {
        return $this->apiCall('POST', '/threads');
    }
    
    /**
     * Ajouter un message à un thread
     */
    public function addMessage($threadId, $content) {
      //echo "addMessage\n"; 
      $startTime = microtime(true); // 🕒 Démarre le chronomètre

      $return = $this->apiCall('POST', "/threads/$threadId/messages", [
            'role' => 'user',
            'content' => $content
        ]);
      $endTime = microtime(true); // 🕒 Stoppe le chronomètre
      $duration = round($endTime - $startTime, 3); // Temps en secondes
      if ($this->debug) echo "⏱️ Temps d'exécution addMessage : {$duration}s\n";
      
      return $return ;
    }
    
    /**
     * Exécuter l'assistant
     */
    public function runAssistant($threadId, $assistantId, $modelOverride = null) {
        if ($this->debug) echo "runAssistant";
        $startTime = microtime(true);
        $runData = ['assistant_id' => $assistantId];

        // Override du modèle si spécifié
        if ($modelOverride !== null) {
            // Valider que le modèle est compatible
            $validModels = ['gpt-4.1','gpt-4.1-mini', 'gpt-4o', 'gpt-4o-mini', 'gpt-4-turbo', 'gpt-4', 'gpt-3.5-turbo'];
            if (!in_array($modelOverride, $validModels)) {
                echo " WARNING: Modèle potentiellement invalide: $modelOverride\n";
            }
            
            $runData['model'] = $modelOverride;
            if ($this->debug) echo " with overrided modèle: ".$modelOverride."\n";
        } else {
            if ($this->debug) echo " with default model\n";
        }

        try {
            // Afficher les données envoyées pour debug
            if ($this->debug) echo "Run data: " . json_encode($runData) . "\n";
            
            $run = $this->apiCall('POST', "/threads/$threadId/runs", $runData);
            
            // Vérifier que le run a bien été créé
            if (!isset($run['id'])) {
                throw new Exception("Run créé mais ID manquant: " . json_encode($run));
            }
            
            if ($this->debug) echo "Run créé avec ID: " . $run['id'] . " (status: " . $run['status'] . ")\n";

            //TODO : remonter l'erreur pour prévenir l'utilisateur par retour de notification ?
            $return = $this->waitForRunCompletion($threadId, $run['id']);
            
            if($return['isError']){
              if ($this->debug) echo "Run échoué: " . json_encode($return) . "\n";
              if ($this->debug) echo "ERREUR de run : ".  $return['last_error']['code'] . "-" . $return['last_error']['code'] . "\n";
              if($return['last_error']['code'] == 'rate_limit_exceeded'){
                //TODO 	// gérer l'erreur et relancer? ici ou plus haut
              }
            }

            $endTime = microtime(true);
            $duration = round($endTime - $startTime, 3);
            if ($this->debug) echo "⏱️ Temps d'exécution runAssistant : {$duration}s\n";
            
            return $return;
            
        } catch (Exception $e) {
            $endTime = microtime(true);
            $duration = round($endTime - $startTime, 3);
            echo "❌ Échec runAssistant après {$duration}s\n";
            //TODO : remonter l'erreur pour prévenir l'utilisateur par retour de notification ?
            throw $e; // Re-lancer l'exception ?
        }
    }
    
    /**
     * Attendre la fin de l'exécution
     */
    private function waitForRunCompletion($threadId, $runId, $maxAttempts = 30) {
        $delays = [0.5, 0.5, 1, 1, 2, 2, 3, 3, 3]; // Délais progressifs
        //usleep(500000);
        for ($i = 0; $i < $maxAttempts; $i++) {
            $delay = $delays[min($i, count($delays) - 1)];
            usleep($delay * 1000000);
        
            $run = $this->apiCall('GET', "/threads/$threadId/runs/$runId");
            
            if ($run['status'] === 'completed') {
                if ($this->debug) echo "run complete after ".$i." attempts\n";
                $run['isError']= false;
                return $run;
            }
            
            if (in_array($run['status'], ['failed', 'cancelled', 'expired'])) {
                // Afficher le détail complet de l'erreur
                $errorMsg = "Run échoué: " . $run['status'];
                
                $run['isError']= true;
                // Récupérer les détails de l'erreur si disponibles
                if (isset($run['last_error'])) {
                    $errorMsg .= "\nCode: " . ($run['last_error']['code'] ?? 'unknown');
                    $errorMsg .= "\nMessage: " . ($run['last_error']['message'] ?? 'no message');
                }
                
                // Afficher la structure complète pour debug
                echo "Structure complète du run en échec:\n";
                echo json_encode($run, JSON_PRETTY_PRINT) . "\n";
                
                return $run;
            }
            
            // Afficher le statut actuel pour debug
            if ($this->debug) echo "Run status: " . $run['status'] . " (attempt " . ($i + 1) . ")\n";
            
            //sleep(1);
        }
        
        $run['isError']= true;
        $errorMsg = "Run échoué: Timeout en attendant la réponse";
        return $run;
        //throw new Exception("Timeout en attendant la réponse");
    }
    
    /**
     * Récupérer les messages
     */
    public function getMessages($threadId, $limit = 1, $sentMessageId = null) {
        if(empty($sentMessageId)){
            $response = $this->apiCall('GET', "/threads/$threadId/messages?limit=$limit");
        }else{
            $response = $this->apiCall('GET', "/threads/$threadId/messages?after=$sentMessageId&limit=3&order=asc");
        }

        if ($this->debug) {
            echo "Messages après $sentMessageId : " . count($response['data']) . " message(s)\n";
        }
        
        // Trouver le premier message assistant
        $return = null;
        foreach ($response['data'] as $msg) {
            if ($msg['role'] === 'assistant') {
                $return = $msg; //['content'][0]['text']['value'];
                
                if ($this->debug) {
                    echo "✅ Réponse trouvée (ID: {$msg['id']})\n";
                }
                break;
            }
        }
        
        // Fallback si rien trouvé
        if ($return === null) {
            if ($this->debug) echo "⚠️ Fallback: utilisation du dernier message\n";
            $response = $this->apiCall('GET', "/threads/$threadId/messages?limit=$limit");
        }
        
        return $response['data'];
    }
    
    /**
     * Obtenir ou créer l'assistant
     */
    public function getOrCreateAssistant($name, $instructions, $model = null) {
        
        if ($model === null) {
          $model = $this->model;
        } 
        $config = $this->loadConfig();
        
        if (!empty($config['assistant_id'])) {
          if ($this->debug) echo "get existing assistant ".$config['assistant_id']."\n";
            return $config['assistant_id'];
        }
        
        $assistant = $this->createAssistant($name, $instructions, $model);
        $config['assistant_id'] = $assistant['id'];
      
        $this->saveConfig($config);
        if ($this->debug) echo "get new assistant ".$assistant['id']."\n";
        return $assistant['id'];
    }
    
    /**
     * Obtenir ou créer un thread pour une pièce
     */
    public function getOrCreateThread($profile) {
        $config = $this->loadConfig();
        
        if (!empty($config['threads'][$profile])) {
            return $config['threads'][$profile];
        }
        
        $thread = $this->createThread();
        $config['threads'][$profile] = $thread['id'];
        $this->saveConfig($config);
        
        return $thread['id'];
    }
    
    /**
     * Poser une question (méthode principale)
     */
    public function ask($profile, $message, $assistantConfig = null, $modelOverride = null) {
        // Configuration par défaut de l'assistant
        if ($assistantConfig === null) {
          	if ($this->debug) echo "Config Assistant par defaut";
            $assistantConfig = [
                'name' => 'Assistant Domotique Jeedom',
                'instructions' => 'Tu es un assistant domotique intelligent pour Jeedom. 
                    La maison contient :
                    - Lumières dans le salon, cuisine, chambre, bureau, entrée
                    - Volets dans chaque pièce
                    - Capteurs de température et mouvement dans chaque pièce
                    - Caméras de surveillance
                    Tu dois aider à automatiser et contrôler ces équipements de manière intelligente.
                    Réponds de façon concise et pratique.',
                'model' => $this->model //'gpt-4-turbo'
            ];
        }else{
         //if ($this->debug) echo "Config Assistant personnalisée\n"; 
        }
      
        $model = $modelOverride ?? ($assistantConfig['model'] ?? $this->model);
    	echo "Utilisation du modèle: $model\n";
      
        $assistantId = $this->getOrCreateAssistant(
            $assistantConfig['name'],
            $assistantConfig['instructions'],
            $model
        );
        //if ($this->debug) echo "ask assistantConfig Name :".$assistantConfig['name']."\n";
        //if ($this->debug) echo "ask assistantConfig Name :".$assistantConfig['model']."\n";
        //if ($this->debug) echo "ask assistantConfig Name :".$assistantConfig['instructions']."\n";
      
      	$threadId = $this->getOrCreateThread($profile);
        
        // ✅ Sauvegarder l'ID du message envoyé
        $sentMessage = $this->addMessage($threadId, $message);
        $sentMessageId = $sentMessage['id'];
        
        if ($this->debug) {
            echo "Message utilisateur envoyé avec ID: $sentMessageId\n";
        }
        // Exécuter l'assistant        
        $run = $this->runAssistant($threadId, $assistantId, $modelOverride);
        if($run['isError']){
            //if ($this->debug) echo "Run échoué: " . json_encode($run) . "\n";
            $response = $run['last_error']['message']." (".$run['last_error']['code'].")";   
            if ($this->debug) echo "ERREUR de run : ".  $response . "\n";
            if($run['last_error']['code'] == 'rate_limit_exceeded'){
              //TODO 	// gérer l'erreur et relancer?
              $response = "Veuillez réessayer plus tard, limite de taux dépassée ($response).";
            }
            $return = [
                'question' => $message,
                'response' => $response,
                'piece' => '',
                'id' => '',
                'mode' => 'info',
                'confidence' => 'high',
                'type action' => ''
            ];
        }else{
            // Récupérer la réponse
            $messages = $this->getMessages($threadId, 1);
            $return = $messages[0]['content'][0]['text']['value'];
        }        
        
        // Petit délai pour permettre la synchronisation du thread
        // Important si vous faites plusieurs appels successifs au même thread
        usleep(500000); // 0.5 seconde

        return $return;
    }
    
    /**
     * Réinitialiser la configuration (utile pour debug)
     */
    public function resetConfig() {
        if (file_exists($this->configFile)) {
            unlink($this->configFile);
            return true;
        }
        return false;
    }

    /**
     * Uploader un fichier image vers OpenAI
     * @param string $imageData Données binaires de l'image
     * @param string $filename Nom du fichier (ex: "image.jpg")
     * @return array Réponse de l'API avec l'ID du fichier
     */
    public function uploadImage($imageData, $filename = 'image.jpg') {
        $url = $this->baseUrl . '/files';
        
        // Déterminer le MIME type basé sur l'extension
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $mimeTypes = [
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'webp' => 'image/webp'
        ];

        $mimeType = isset($mimeTypes[$extension]) ? $mimeTypes[$extension] : 'image/jpeg';

        // S'assurer que le filename a une extension valide
        if (!in_array($extension, ['jpg', 'jpeg', 'png', 'gif', 'webp'])) {
            $filename = 'image.jpg';
            $mimeType = 'image/jpeg';
        }

        // Créer un fichier temporaire avec la bonne extension
        $tempFile = tempnam(sys_get_temp_dir(), 'openai_') . '.' . $extension;
        file_put_contents($tempFile, $imageData);

        if ($this->debug) echo "Upload fichier: $filename (MIME: $mimeType, Size: " . strlen($imageData) . " octets)\n";

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $this->apiKey
            // Ne PAS inclure OpenAI-Beta pour l'upload de fichiers
        ]);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, [
            'file' => new CURLFile($tempFile, $mimeType, $filename),
            'purpose' => 'vision'
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        // Supprimer le fichier temporaire
        if (file_exists($tempFile)) {
            unlink($tempFile);
        }

        if ($error) {
            throw new Exception("Erreur cURL upload: $error");
        }

        if ($httpCode >= 400) {
            throw new Exception("Erreur API upload ($httpCode): $response");
        }

        $decoded = json_decode($response, true);
        if ($decoded === null) {
            throw new Exception("Réponse JSON invalide: " . substr($response, 0, 200));
        }

        if ($this->debug) echo "Fichier uploadé avec succès: ID = " . $decoded['id'] . "\n";

        return $decoded;
    }

/**
 * Ajouter un message avec image à un thread (format Vision API)
 * @param string $threadId ID du thread
 * @param string $textContent Texte du message
 * @param string $fileId ID du fichier uploadé
 * @return array Réponse de l'API
 */
    public function addMessageWithImage($threadId, $textContent, $fileId) {
        if ($this->debug) echo "addMessageWithImage\n";
        $startTime = microtime(true); // 🕒 Démarre le chronomètre

        $messageData = [
            'role' => 'user',
            'content' => [
                [
                    'type' => 'text',
                    'text' => $textContent
                ],
                [
                    'type' => 'image_file',
                    'image_file' => [
                        'file_id' => $fileId
                    ]
                ]
            ]
        ];
		//echo "Message data: " . json_encode($messageData, JSON_PRETTY_PRINT) . "\n";
    
        $return = $this->apiCall('POST', "/threads/$threadId/messages", $messageData);
        $endTime = microtime(true); // 🕒 Stoppe le chronomètre
        $duration = round($endTime - $startTime, 3); // Temps en secondes
        if ($this->debug) echo "⏱️ Temps d'exécution addMessageWithImage : {$duration}s\n";

        return $return;
    }

    /**
     * Poser une question avec une image
     * @param string $profile Profil utilisateur
     * @param string $message Texte de la question
     * @param string|null $imageData Données binaires de l'image (optionnel)
     * @param string $filename Nom du fichier image
     * @param array|null $assistantConfig Configuration de l'assistant
     * @return string Réponse de l'assistant
     */
    public function askWithImage($profile, $message, $assistantConfig = null, $imageData = null, $filename = 'image.jpg', $modelOverride = null) {
        // Configuration par défaut de l'assistant avec support vision
        if ($assistantConfig === null) {
            if ($this->debug) echo "Config Assistant par defaut avec support vision";
            $assistantConfig = [
                'name' => 'Assistant Domotique Jeedom avec Vision',
                'instructions' => 'Tu es un assistant domotique intelligent pour Jeedom avec capacité de vision.
                    La maison contient :
                    - Lumières dans le salon, cuisine, chambre, bureau, entrée
                    - Volets dans chaque pièce
                    - Capteurs de température et mouvement dans chaque pièce
                    - Caméras de surveillance
                    Tu peux analyser des images de caméras de surveillance.
                    Réponds de façon concise et pratique.',
                'model' => $this->modelVision // gpt-4o ou gpt-4-turbo pour vision
            ];
        }
        
      	$model = $modelOverride ?? ($assistantConfig['model'] ?? $this->modelVision);
    	if ($this->debug) echo "Utilisation du modèle: $model\n";
    
        $assistantId = $this->getOrCreateAssistant(
            $assistantConfig['name'],
            $assistantConfig['instructions'],
            $model
        );
        
        $threadId = $this->getOrCreateThread($profile);
        
        // Si une image est fournie, l'uploader d'abord
        $fileId = null;
        if ($imageData !== null) {
            if ($this->debug) echo "Upload de l'image ".$filename."...\n";
            $fileResponse = $this->uploadImage($imageData, $filename);
            $fileId = $fileResponse['id'];
            if ($this->debug) echo "Image uploadée avec ID: $fileId\n";
            
            // Ajouter le message avec l'image
            $this->addMessageWithImage($threadId, $message, $fileId);
        } else {
            // Message texte simple
            $this->addMessage($threadId, $message);
        }
        
        // Exécuter l'assistant
        $run = $this->runAssistant($threadId, $assistantId, $model);
        if($run['isError']){
            //if ($this->debug) echo "Run échoué: " . json_encode($run) . "\n";
            $response = $run['last_error']['message']." (".$run['last_error']['code'].")";   
            if ($this->debug) echo "ERREUR de run : ".  $response . "\n";
            if($run['last_error']['code'] == 'rate_limit_exceeded'){
                //TODO 	// gérer l'erreur et relancer?
                $response = "Veuillez réessayer plus tard, limite de taux dépassée ($response).";
            }
            $return = [
                'question' => $message,
                'response' => $response,
                'piece' => '',
                'id' => '',
                'mode' => 'info',
                'confidence' => 'high',
                'type action' => ''
            ];
        }else{
            // Récupérer la réponse
            $messages = $this->getMessages($threadId, 1);
            $return = $messages[0]['content'][0]['text']['value'];
        }        
        
        usleep(500000); // 0.5 seconde
        
        return $return;
    }

}

/*
//Utilisation : 
	require_once '/var/www/html/plugins/script/data/openAIAssistant.class.php';


    // Initialiser l'assistant
    $ai = new OpenAIAssistant(OPENAI_API_KEY, CONFIG_FILE);
       
    // Exemple 2 : Depuis une variable de scénario
    // $profile = $scenario->getData('profile');
    // $message = $scenario->getData('user_message');
    
    // Exemple 3 : Détection de mouvement
    // $profile = 'Madame';
    // $message = "Mouvement détecté. Dois-je allumer la lumière ?";

    // Exemple 1 : Température du salon
    $profile = 'Monsieur';
    $temperature = 22; // ou récupérez depuis une commande: cmd::byId(123)->execCmd()
    $message = "La température actuelle du salon est de {$temperature}°C";
  
    $response = $ai->ask($profile, $message);
    $scenario->setLog("Question: ($profile) $message\n");
    $scenario->setLog("Réponse: $response\n");
    
     // Stocker dans une variable
    #$scenario->setData('ai_response', $response);
    
    // Vous pouvez aussi parser la réponse pour déclencher des actions
    // if (strpos($response, 'allumer') !== false) {
    //     cmd::byId(456)->execCmd(); // Allumer lumière
    // }
*/

?>