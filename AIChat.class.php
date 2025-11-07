<?php

/* Classe d'utilisation de l'API chat conversation (multi-provider : OpenAI, Mistral, Claude)
* @author Franck WEHRLE avec l'aide de Claude.ai
* @version 3.01
*/

// ============================================
// CLASSE AI CHAT (Multi-provider)
// ============================================
class AIChat {
    private $apiKey;
    private $baseUrl = 'https://api.openai.com/v1';  // Par défaut OpenAI, modifiable
    private $configFile = '/tmp/jeedom_ai_config.json';  // Renommé pour être agnostique
    private $model = 'gpt-4o-mini'; // 'gpt-4o-mini' ou 'gpt-4o', 'gpt-4-turbo' ('gpt-4o', 'gpt-4-turbo' pour vision)
    private $modelVision = 'gpt-4o'; //'gpt-4-turbo'
    private $debug = true;
    private $conversationMaxAge = 3600; // Durée de vie max d'une conversation en secondes (1h par défaut)

    public function __construct($apiKey, $baseUrl, $model, $modelVision, $debug = true, $configFile = null) {
      if ($this->debug) echo "__construct\n";
        if (!empty($apiKey)) {
            $this->apiKey = $apiKey;
        } else {
            throw new Exception("La clé API ne peut pas être vide");
        }
        if (!empty($baseUrl)) {
            $this->baseUrl = $baseUrl;
        }else {
            throw new Exception("L'URL de base de l'API ne peut pas être vide");
        }
        if (!empty($model)) {
            $this->model = $model;
        }else {
            throw new Exception("Le modèle IA ne peut pas être vide");
        }
        if (!empty($modelVision)) {
            $this->modelVision = $modelVision;
        }
      	if (!empty($configFile)) {
            $this->configFile = $configFile;
        }

        $this->debug = $debug;
    }

    /**
     * Formater un message d'erreur API de manière user-friendly
     *
     * @param int $httpCode Code HTTP de l'erreur
     * @param string $response Réponse brute de l'API
     * @return string Message d'erreur formaté
     */
    private function formatApiError($httpCode, $response) {
        // Essayer de parser la réponse JSON
        $errorData = json_decode($response, true);

        // Messages par défaut selon le code HTTP
        $defaultMessages = [
            400 => "Requête invalide",
            401 => "Clé API invalide ou expirée",
            403 => "Accès refusé",
            404 => "Endpoint API non trouvé",
            429 => "Limite de requêtes dépassée",
            500 => "Erreur serveur API",
            503 => "Service API temporairement indisponible"
        ];

        $userMessage = $defaultMessages[$httpCode] ?? "Erreur API";

        // Si on a un JSON valide avec des détails
        if ($errorData !== null) {
            // Gestion spécifique OpenAI/Mistral
            if (isset($errorData['error'])) {
                $error = $errorData['error'];

                // Message de l'API
                if (isset($error['message'])) {
                    $apiMessage = $error['message'];

                    // Traductions et simplifications des messages courants
                    if (strpos($apiMessage, 'Service tier capacity exceeded') !== false) {
                        $userMessage = "⚠️ Quota du modèle dépassé. Veuillez patienter quelques minutes ou utiliser un autre modèle.";
                    } elseif (strpos($apiMessage, 'Rate limit') !== false || strpos($apiMessage, 'rate_limit') !== false) {
                        $userMessage = "⚠️ Trop de requêtes. Veuillez patienter quelques secondes avant de réessayer.";
                    } elseif (strpos($apiMessage, 'Invalid API key') !== false || strpos($apiMessage, 'Incorrect API key') !== false) {
                        $userMessage = "❌ Clé API invalide. Vérifiez votre configuration.";
                    } elseif (strpos($apiMessage, 'model') !== false && strpos($apiMessage, 'does not exist') !== false) {
                        $userMessage = "❌ Le modèle spécifié n'existe pas ou n'est pas accessible.";
                    } elseif (strpos($apiMessage, 'context_length_exceeded') !== false) {
                        $userMessage = "⚠️ Message trop long. Le contexte dépasse la limite du modèle.";
                    } else {
                        // Utiliser le message de l'API s'il est compréhensible
                        $userMessage = "❌ " . $apiMessage;
                    }
                }

                // Type d'erreur
                if (isset($error['type'])) {
                    $errorType = $error['type'];
                    // Ajouter le type si informatif
                    if ($errorType === 'insufficient_quota') {
                        $userMessage .= " (Quota insuffisant)";
                    }
                }

                // Code d'erreur spécifique
                if (isset($error['code'])) {
                    $errorCode = $error['code'];
                    if ($this->debug) {
                        $userMessage .= " [Code: $errorCode]";
                    }
                }
            }

            // Gestion spécifique Claude (format différent)
            if (isset($errorData['type']) && isset($errorData['message'])) {
                $userMessage = "❌ " . $errorData['message'];
            }
        }

        return $userMessage;
    }

    /**
     * Configurer la durée de vie maximale des Conversations
     *
     * @param int $seconds Durée en secondes (3600 = 1h, 7200 = 2h, etc.)
     * @return void
     */
    public function setConversationMaxAge($seconds) {
        $this->conversationMaxAge = (int)$seconds;
        if ($this->debug) {
            $hours = round($seconds / 3600, 1);
            echo "Durée de vie des Conversations configurée à {$hours}h ({$seconds}s)\n";
        }
    }

    /**
     * Obtenir la durée de vie maximale des Conversations
     *
     * @return int Durée en secondes
     */
    public function getConversationMaxAge() {
        return $this->conversationMaxAge;
    }

    /**
     * Obtenir l'historique de conversation d'un profil depuis le JSON local
     *
     * @param string $profile Profil utilisateur
     * @return array Tableau de messages
     */
    public function getConversationHistory($profile) {
        $config = $this->loadConfig();

        if (empty($config['conversations'][$profile]['messages'])) {
            return [];
        }

        return $config['conversations'][$profile]['messages'];
    }

    /**
     * Sauvegarder l'historique de conversation d'un profil dans le JSON local
     *
     * @param string $profile Profil utilisateur
     * @param array $messages Tableau de messages
     * @return bool Succès ou échec
     */
    public function saveConversationHistory($profile, $messages) {
        $config = $this->loadConfig();
        $now = time();

        if (!isset($config['conversations'])) {
            $config['conversations'] = [];
        }

        $config['conversations'][$profile] = [
            'messages' => $messages,
            'last_used' => $now,
            'created_at' => $config['conversations'][$profile]['created_at'] ?? $now
        ];

        return $this->saveConfig($config);
    }

    /**
     * Ajouter un message à l'historique de conversation
     * Maintient automatiquement la limite de 20 messages
     *
     * @param string $profile Profil utilisateur
     * @param string $role Role du message ('user' ou 'assistant')
     * @param string $content Contenu du message
     * @return bool Succès ou échec
     */
    public function addMessageToHistory($profile, $role, $content) {
        $messages = $this->getConversationHistory($profile);
        $now = time();

        // Ajouter le nouveau message
        $messages[] = [
            'role' => $role,
            'content' => $content,
            'timestamp' => $now
        ];

        // Garder seulement les 20 derniers messages
        if (count($messages) > 20) {
            $messages = array_slice($messages, -20);
        }

        return $this->saveConversationHistory($profile, $messages);
    }

    /**
     * Purger les conversations trop anciennes (> conversationMaxAge)
     *
     * @return int Nombre de conversations supprimées
     */
    public function pruneOldConversations() {
        $config = $this->loadConfig();
        $now = time();
        $deletedCount = 0;

        if (empty($config['conversations'])) {
            return 0;
        }

        foreach ($config['conversations'] as $profile => $conversationData) {
            $lastUsed = $conversationData['last_used'] ?? 0;
            $age = $now - $lastUsed;

            if ($age > $this->conversationMaxAge) {
                unset($config['conversations'][$profile]);
                $deletedCount++;

                if ($this->debug) {
                    $ageHours = round($age / 3600, 1);
                    echo "Conversation supprimée pour $profile ({$ageHours}h d'inactivité)\n";
                }
            }
        }

        if ($deletedCount > 0) {
            $this->saveConfig($config);
        }

        return $deletedCount;
    }

    /**
     * Réinitialiser l'historique de conversation d'un profil
     *
     * @param string $profile Profil utilisateur
     * @return bool Succès ou échec
     */
    public function resetConversation($profile) {
        $config = $this->loadConfig();
        $now = time();

        $config['conversations'][$profile] = [
            'messages' => [],
            'last_used' => $now,
            'created_at' => $now
        ];

        $result = $this->saveConfig($config);

        if ($this->debug) echo "Conversation réinitialisée pour $profile\n";

        return $result;
    }

    /**
     * Appel API générique
     */
    private function apiCall($method, $endpoint, $data = null, $retryCount = 0, $maxRetries = 3) {
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
             'Authorization: Bearer ' . $this->apiKey
            // ,'OpenAI-Beta: assistants=v2'
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

        // ✅ Gestion des erreurs temporaires avec retry
        if ($httpCode >= 500 && $httpCode < 600) {
            // Erreurs serveur (500, 502, 503, 504...)
            if ($retryCount < $maxRetries) {
                $waitTime = pow(2, $retryCount); // Backoff exponentiel : 1s, 2s, 4s
                if ($this->debug) {
                    echo "⚠️ Erreur $httpCode (tentative " . ($retryCount + 1) . "/$maxRetries), retry dans {$waitTime}s...\n";
                }
                sleep($waitTime);
                return $this->apiCall($method, $endpoint, $data, $retryCount + 1, $maxRetries);
            }
        }

        if ($httpCode >= 400) {
            $errorMessage = $this->formatApiError($httpCode, $response);
            if ($this->debug) {
                echo "Détails erreur API ($httpCode): " . substr($response, 0, 300) . "\n";
            }
            throw new Exception($errorMessage);
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
            return $config ?: ['assistant_id' => null, 'threads' => []]; //Assistant et threads OBSOLETE?
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
     * Poser une question (méthode principale)
     * Utilise Chat Completion avec historique local JSON
     */
    public function ask($profile, $message, $assistantConfig = null, $modelOverride = null, $messageForHistory = null) {
        $startTime = microtime(true);

        // Configuration par défaut de l'assistant
        if ($assistantConfig === null) {
          	if ($this->debug) echo "Config Assistant par defaut\n";
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
                'model' => $this->model
            ];
        }

        $model = $modelOverride ?? ($assistantConfig['model'] ?? $this->model);
    	if ($this->debug) echo "Utilisation du modèle: $model\n";

        // Récupérer l'historique de conversation pour ce profil
        $conversationHistory = $this->getConversationHistory($profile);

        // Purger les anciennes conversations (> 1h d'inactivité)
        $this->pruneOldConversations();

        // Construire le tableau de messages pour Chat Completion
        $messages = [];

        // 1. Message système (instructions)
        $messages[] = [
            'role' => 'system',
            'content' => $assistantConfig['instructions']
        ];

        // 2. Ajouter l'historique existant (sans les timestamps)
        foreach ($conversationHistory as $historyMsg) {
            $messages[] = [
                'role' => $historyMsg['role'],
                'content' => $historyMsg['content']
            ];
        }

        // 3. Ajouter le nouveau message utilisateur
        $messages[] = [
            'role' => 'user',
            'content' => $message
        ];

        if ($this->debug) {
            echo "Historique: " . count($conversationHistory) . " messages\n";
            echo "Total messages envoyés: " . count($messages) . " (system + historique + nouveau)\n";
        }

        try {
            // Appel à Chat Completion avec l'historique
            $requestData = [
                'model' => $model,
                'messages' => $messages,
                'temperature' => 0.7,
                'max_tokens' => 2000,
                'response_format' => ['type' => 'json_object']  // Force JSON valide
            ];

            if ($this->debug) {
                echo "Requête Chat Completion pour profil: $profile\n";
            }

            $url = $this->baseUrl . '/chat/completions';

            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($requestData));
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $this->apiKey
            ]);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_close($ch);

            if ($error) {
                throw new Exception("Erreur cURL: $error");
            }

            if ($httpCode >= 400) {
                $errorMessage = $this->formatApiError($httpCode, $response);
                if ($this->debug) {
                    echo "Détails erreur API ($httpCode): " . substr($response, 0, 300) . "\n";
                }
                throw new Exception($errorMessage);
            }

            $responseData = json_decode($response, true);

            if ($responseData === null) {
                throw new Exception("Réponse JSON invalide: " . substr($response, 0, 200));
            }

            if (!isset($responseData['choices'][0]['message']['content'])) {
                throw new Exception("Format de réponse invalide: " . json_encode($responseData));
            }

            $assistantResponse = $responseData['choices'][0]['message']['content'];

            // Sauvegarder les deux messages dans l'historique
            // Si $messageForHistory est fourni, l'utiliser à la place du message complet (pour éviter de stocker le JSON des capteurs)
            $messageToStore = ($messageForHistory !== null) ? $messageForHistory : $message;
            $this->addMessageToHistory($profile, 'user', $messageToStore);
            $this->addMessageToHistory($profile, 'assistant', $assistantResponse);

            $endTime = microtime(true);
            $duration = round($endTime - $startTime, 3);

            if ($this->debug) {
                echo "⏱️ Temps d'exécution ask : {$duration}s\n";
                echo "📊 Tokens utilisés: " . ($responseData['usage']['total_tokens'] ?? 'N/A') . "\n";
            }

            return $assistantResponse;

        } catch (Exception $e) {
            $endTime = microtime(true);
            $duration = round($endTime - $startTime, 3);
            echo "❌ Échec ask après {$duration}s: " . $e->getMessage() . "\n";

            // Retourner un JSON d'erreur compatible avec l'ancien format
            $errorResponse = json_encode([
                'question' => $message,
                'response' => "Erreur: " . $e->getMessage(),
                'piece' => '',
                'id' => '',
                'mode' => 'info',
                'confidence' => 'low',
                'type action' => ''
            ]);

            return $errorResponse;
        }
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
     * Uploader un fichier image vers l'IA
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
        $tempFile = tempnam(sys_get_temp_dir(), 'ai_') . '.' . $extension;
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
     * Poser une question avec une ou plusieurs images
     * Utilise Chat Completion avec historique local JSON et support Vision
     * @param string $profile Profil utilisateur
     * @param string $message Texte de la question
     * @param array|null $assistantConfig Configuration de l'assistant
     * @param array|null $images Tableau d'images avec format:
     *                           [
     *                             ['data' => $imageData1, 'filename' => 'image1.jpg'],
     *                             ['data' => $imageData2, 'filename' => 'image2.jpg'],
     *                             ...
     *                           ]
     * @param string|null $modelOverride Modèle à utiliser
     * @return string Réponse de l'assistant
     */
    public function askWithImage($profile, $message, $assistantConfig = null, $images = null, $modelOverride = null, $messageForHistory = null) {
        $startTime = microtime(true);

        // Configuration par défaut de l'assistant avec support vision
        if ($assistantConfig === null) {
            if ($this->debug) echo "Config Assistant par defaut avec support vision\n";
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

        // Récupérer l'historique de conversation pour ce profil
        $conversationHistory = $this->getConversationHistory($profile);

        // Purger les anciennes conversations (> 1h d'inactivité)
        $this->pruneOldConversations();

        // Construire le tableau de messages pour Chat Completion
        $messages = [];

        // 1. Message système (instructions)
        $messages[] = [
            'role' => 'system',
            'content' => $assistantConfig['instructions']
        ];

        // 2. Ajouter l'historique existant (sans les timestamps)
        foreach ($conversationHistory as $historyMsg) {
            $messages[] = [
                'role' => $historyMsg['role'],
                'content' => $historyMsg['content']
            ];
        }

        // 3. Construire le message utilisateur avec image(s)
        $userMessageContent = [];

        // Texte du message
        $userMessageContent[] = [
            'type' => 'text',
            'text' => $message
        ];

        // Ajouter les images en base64
        if ($images !== null && is_array($images)) {
            if ($this->debug) echo "Préparation de " . count($images) . " image(s) en base64...\n";

            foreach ($images as $idx => $image) {
                $imageData = $image['data'] ?? null;
                $imageName = $image['filename'] ?? "image_{$idx}.jpg";

                if ($imageData !== null) {
                    // Déterminer le type MIME
                    $extension = strtolower(pathinfo($imageName, PATHINFO_EXTENSION));
                    $mimeTypes = [
                        'jpg' => 'image/jpeg',
                        'jpeg' => 'image/jpeg',
                        'png' => 'image/png',
                        'gif' => 'image/gif',
                        'webp' => 'image/webp'
                    ];
                    $mimeType = $mimeTypes[$extension] ?? 'image/jpeg';

                    // Convertir en base64
                    $base64Image = base64_encode($imageData);
                    $dataUrl = "data:{$mimeType};base64,{$base64Image}";

                    $userMessageContent[] = [
                        'type' => 'image_url',
                        'image_url' => [
                            'url' => $dataUrl
                        ]
                    ];

                    if ($this->debug) echo "  - Image {$imageName} encodée en base64\n";
                }
            }
        }

        // Ajouter le message utilisateur complet
        $messages[] = [
            'role' => 'user',
            'content' => $userMessageContent
        ];

        if ($this->debug) {
            echo "Historique: " . count($conversationHistory) . " messages\n";
            echo "Total messages envoyés: " . count($messages) . " (system + historique + nouveau avec images)\n";
        }

        try {
            // Appel à Chat Completion avec l'historique et les images
            $requestData = [
                'model' => $model,
                'messages' => $messages,
                'temperature' => 0.7,
                'max_tokens' => 2000,
                'response_format' => ['type' => 'json_object']  // Force JSON valide
            ];

            if ($this->debug) {
                echo "Requête Chat Completion Vision pour profil: $profile\n";
            }

            $url = $this->baseUrl . '/chat/completions';

            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($requestData));
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $this->apiKey
            ]);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_close($ch);

            if ($error) {
                throw new Exception("Erreur cURL: $error");
            }

            if ($httpCode >= 400) {
                $errorMessage = $this->formatApiError($httpCode, $response);
                if ($this->debug) {
                    echo "Détails erreur API ($httpCode): " . substr($response, 0, 300) . "\n";
                }
                throw new Exception($errorMessage);
            }

            $responseData = json_decode($response, true);

            if ($responseData === null) {
                throw new Exception("Réponse JSON invalide: " . substr($response, 0, 200));
            }

            if (!isset($responseData['choices'][0]['message']['content'])) {
                throw new Exception("Format de réponse invalide: " . json_encode($responseData));
            }

            $assistantResponse = $responseData['choices'][0]['message']['content'];

            // Sauvegarder les deux messages dans l'historique
            // Note: Pour l'historique, on stocke juste le texte, pas les images (pour économiser l'espace)
            // Si $messageForHistory est fourni, l'utiliser à la place du message complet (pour éviter de stocker le JSON des capteurs)
            $messageToStore = ($messageForHistory !== null) ? $messageForHistory : $message;
            $this->addMessageToHistory($profile, 'user', $messageToStore . " [avec image(s)]");
            $this->addMessageToHistory($profile, 'assistant', $assistantResponse);

            $endTime = microtime(true);
            $duration = round($endTime - $startTime, 3);

            if ($this->debug) {
                echo "⏱️ Temps d'exécution askWithImage : {$duration}s\n";
                echo "📊 Tokens utilisés: " . ($responseData['usage']['total_tokens'] ?? 'N/A') . "\n";
            }

            return $assistantResponse;

        } catch (Exception $e) {
            $endTime = microtime(true);
            $duration = round($endTime - $startTime, 3);
            echo "❌ Échec askWithImage après {$duration}s: " . $e->getMessage() . "\n";

            // Retourner un JSON d'erreur compatible avec l'ancien format
            $errorResponse = json_encode([
                'question' => $message,
                'response' => "Erreur: " . $e->getMessage(),
                'piece' => '',
                'id' => '',
                'mode' => 'info',
                'confidence' => 'low',
                'type action' => ''
            ]);

            return $errorResponse;
        }
    }

    /**
     * Appel direct à l'API Chat Completion (sans assistant/thread)
     * Méthode rapide pour des requêtes simples sans historique
     *
     * @param string $systemPrompt Instructions système pour l'IA
     * @param string $userMessage Message de l'utilisateur
     * @param string $model Modèle à utiliser (défaut: gpt-4o-mini)
     * @return string Réponse de l'IA en texte brut
     */
    public function chatCompletion($systemPrompt, $userMessage, $model = 'gpt-4o-mini') {
        if ($this->debug) echo "chatCompletion avec modèle: $model\n";
        $startTime = microtime(true);

        try {
            // Construction de la requête pour l'API Chat Completion
            $requestData = [
                'model' => $model,
                'messages' => [
                    [
                        'role' => 'system',
                        'content' => $systemPrompt
                    ],
                    [
                        'role' => 'user',
                        'content' => $userMessage
                    ]
                ],
                'temperature' => 0.7,
                'max_tokens' => 500, // Limite pour réponses courtes (extraction de pièces)
                'response_format' => ['type' => 'json_object']  // Force JSON valide
            ];

            if ($this->debug) {
                echo "Requête Chat Completion: " . json_encode($requestData, JSON_PRETTY_PRINT) . "\n";
            }

            // Appel direct à l'API Chat Completion
            $url = $this->baseUrl.'/chat/completions';

            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($requestData));
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $this->apiKey
            ]);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_close($ch);

            if ($error) {
                throw new Exception("Erreur cURL: $error");
            }

            if ($httpCode >= 400) {
                $errorMessage = $this->formatApiError($httpCode, $response);
                if ($this->debug) {
                    echo "Détails erreur API ($httpCode): " . substr($response, 0, 300) . "\n";
                }
                throw new Exception($errorMessage);
            }

            $responseData = json_decode($response, true);

            if ($responseData === null) {
                throw new Exception("Réponse JSON invalide: " . substr($response, 0, 200));
            }

            // Extraire le contenu de la réponse
            if (!isset($responseData['choices'][0]['message']['content'])) {
                throw new Exception("Format de réponse invalide: " . json_encode($responseData));
            }

            $content = $responseData['choices'][0]['message']['content'];

            $endTime = microtime(true);
            $duration = round($endTime - $startTime, 3);

            if ($this->debug) {
                echo "⏱️ Temps d'exécution chatCompletion : {$duration}s\n";
                echo "📊 Tokens utilisés: " . ($responseData['usage']['total_tokens'] ?? 'N/A') . "\n";
            }

            return $content;

        } catch (Exception $e) {
            $endTime = microtime(true);
            $duration = round($endTime - $startTime, 3);
            echo "❌ Échec chatCompletion après {$duration}s: " . $e->getMessage() . "\n";

            // Retourner un JSON d'erreur
            return json_encode(['error' => $e->getMessage()]);
        }
    }

}

/*
//Utilisation : 
	require_once '/var/www/html/plugins/script/data/AIChat.class.php';


    // Initialiser l'assistant
    $ai = new AIChat(API_KEY, CONFIG_FILE);
       
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