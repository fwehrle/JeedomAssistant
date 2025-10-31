<?php
/**
 * JeedomAssistant - Classe d'intégration OpenAI pour Jeedom
 * 
 * Permet d'interroger un assistant IA avec le contexte domotique complet
 * et d'exécuter les actions recommandées
 * 
 * @author Franck WEHRLE
 * @version 1.1
 */
/**
 * ┌─────────────────┬───────┬──────────────┬──────────────┬─────────────────────────┐
 * │ Modèle          │ Prix  │ Vitesse      │ Intelligence │ Idéal pour              │
 * ├─────────────────┼───────┼──────────────┼──────────────┼─────────────────────────┤
 * │ gpt-4o-mini     │ $     │ ⚡⚡⚡⚡    │ ⭐⭐⭐     │ RECOMMANDÉ Domotique    │
 * │                 │       │ Très rapide  │ Bon          │ Meilleur qualité/prix   │
 * │                 │       │              │              │ $0.15/1M tok (input)    │
 * │                 │       │              │              │ $0.60/1M tok (output)   │
 * ├─────────────────┼───────┼──────────────┼──────────────┼─────────────────────────┤
 * │ gpt-4o          │ $$$   │ ⚡⚡⚡      │ ⭐⭐⭐⭐   │ Meilleur choix général  │
 * │                 │       │ Rapide       │ Excellent    │ Analyses complexes      │
 * │                 │       │              │              │ $2.50/1M tok (input)    │
 * │                 │       │              │              │ $10.00/1M tok (output)  │
 * ├─────────────────┼───────┼──────────────┼──────────────┼─────────────────────────┤
 * │ gpt-4-turbo     │ $$$$  │ ⚡⚡        │ ⭐⭐⭐⭐    │ Tâches complexes        │
 * │                 │       │ Moyen        │ Excellent    │ Raisonnement avancé     │
 * ├─────────────────┼───────┼──────────────┼──────────────┼─────────────────────────┤
 * │ gpt-4           │ $$$$$ │ ⚡          │ ⭐⭐⭐⭐⭐  │ Analyses très poussées  │
 * │                 │       │ Lent         │ Top absolu   │ Budget confortable      │
 * ├─────────────────┼───────┼──────────────┼──────────────┼─────────────────────────┤
 * │ gpt-3.5-turbo   │ $     │ ⚡⚡⚡⚡   │ ⭐⭐         │ Tâches très simples     │
 * │                 │       │ Très rapide  │ Basique      │ Non recommandé          │
 * └─────────────────┴───────┴──────────────┴──────────────┴─────────────────────────┘
 * 
 * ESTIMATION COÛTS MENSUELS (50 requêtes/jour, usage typique domotique) :
 *   - gpt-4o-mini  : ~$0.50-1/mois   ← RECOMMANDÉ
 *   - gpt-4o       : ~$5-10/mois
 *   - gpt-4-turbo  : ~$15-30/mois
 * */
require_once '/var/www/html/plugins/script/data/jeedomAssistant/openAIAssistant.class.php';

class JeedomAssistant {
    
    // Configuration
    private $openaiApiKey;
    private $openaiModel;
    private $openaiVisionModel;
  
    private $configFile;
    private $notificationScenarioId;
    
    // Filtres et paramètres
    private $piecesInclus;
    private $equipementsExclus;
    private $eqActionInclusCategories;
    private $eqCmdExclus;
    
    // Debug
    private $debug;
    private $debugEq;
    private $debugEqDetail;
    private $debugDontRunAction;
    
    // Assistant OpenAI
    private $ai;
    
    // Données collectées
    private $jeedomCommands = [];
    
    /**
     * Constructeur
     */
    public function __construct($config = []) {
        // Configuration par défaut
        $defaults = [
            'openai_api_key' => '',
            'openai_model' => 'gpt-4o-mini',
          	'openai_vision_model' => 'gpt-4o', // 'gpt-4o-mini' ou 'gpt-4o', 'gpt-4-turbo' ('gpt-4o', 'gpt-4-turbo' pour vision)
    
            'config_file' => '/tmp/jeedom_openai_config.json',
            'notification_scenario_id' => 387,
            
            // Filtres
            'pieces_inclus' => [
                "Maison", "Jardin", "Piscine", "Consos", "Entrée", 
                "Salon", "Salle à manger", "Cuisine", "Garage", 
                "12 niveau", "Bibliothèque", "Salle de bain", 
                "Chambre Parents", "Bureau", "Etage", 
                "Chambre Evan", "Chambre Eliott"
            ],
            'equipements_exclus' => [
                "Prise", "Volets", "Résumé", "Dodo", 
                "Eteindre", "Météo Bischwiller", "Pollens"
            ],
            'eq_action_inclus_categories' => [
                "light", "opening", "heating"
              //"heating","security","energy","automatism","multimedia","default" //Catégories d'équipement pilotables par IA
            ],
            'eq_cmd_exclus' => [
                "Rafraichir", "binaire", "Thumbnail"
            ],
            
            // Debug
            'debug' => true,
            'debug_eq' => false,
            'debug_eq_detail' => false,
            'debug_dont_run_action' => true
        ];
        
        $config = array_merge($defaults, $config);
        
        // Assignation des propriétés
        $this->openaiApiKey = $config['openai_api_key'];
        $this->openaiModel = $config['openai_model'];
      	$this->openaiVisionModel = $config['openai_vision_model'];
        $this->configFile = $config['config_file'];
        $this->notificationScenarioId = $config['notification_scenario_id'];
        
        $this->piecesInclus = $config['pieces_inclus'];
        $this->equipementsExclus = $config['equipements_exclus'];
        $this->eqActionInclusCategories = $config['eq_action_inclus_categories'];
        $this->eqCmdExclus = $config['eq_cmd_exclus'];
        
        $this->debug = $config['debug'];
        $this->debugEq = $config['debug_eq'];
        $this->debugEqDetail = $config['debug_eq_detail'];
        $this->debugDontRunAction = $config['debug_dont_run_action'];
        
        // Initialiser l'assistant OpenAI
        if (empty($this->openaiApiKey)) {
            throw new Exception("La clé API OpenAI est requise");
        }
        
        $this->ai = new OpenAIAssistant($this->openaiApiKey, $this->debug, $this->configFile);
        
        if ($this->debug) {
            echo "JeedomAssistant initialisé en mode DEBUG avec le modèle: {$this->openaiModel}\n";
        }else{
          //echo "JeedomAssistant initialisé avec le modèle: {$this->openaiModel}\n";
        }
    }
    
    /**
     * Collecter les informations des équipements Jeedom
     * 
     * @param array $pieces Liste des pièces à inclure (null = toutes)
     * @param string $mode Mode de collecte: 'info' ou 'action'
     * @return string JSON des commandes
     */
    public function collectJeedomData($pieces = null, $mode = 'action') {
        if ($this->debug) echo "Collecte des données Jeedom (mode: $mode)\n";
        
        // Utiliser toutes les pièces si non spécifié
        if ($pieces === null) {
            $pieces = $this->piecesInclus;
        } else {
            // Filtrer les pièces valides
            $pieces = array_filter($pieces, function($piece) {
                return in_array($piece, $this->piecesInclus);
            });
        }
        
        $this->jeedomCommands = [];
        $eqLogics = [];
        
        // Récupérer les équipements de chaque pièce
        foreach ($pieces as $piece) {
            $object = jeeObject::byName($piece);
            if (is_object($object) && $object->getIsVisible() == 1) {
                $eqLogics = array_merge($eqLogics, $object->getEqLogic(true));
            }
        }
        
        $piecePrev = "";
        
        foreach ($eqLogics as $eqLogic) {
            if ($eqLogic->getIsEnable() != 1 || $eqLogic->getIsVisible() != 1) {
                continue;
            }
            
            $piece = $eqLogic->getObject()->getName();
            if ($piece != $piecePrev) {
                $piecePrev = $piece;
            }
            
            $eqName = $eqLogic->getName();
            
            // Vérifier si l'équipement doit être exclu
            if ($this->isEquipmentExcluded($eqName)) {
                continue;
            }
            
            if ($this->debugEqDetail) {
                echo "| - $eqName\n";
            }
            
            // Vérifier si c'est une caméra
            $eqType = $eqLogic->getEqType_name();
            if ($eqType === 'camera') {
                // Pour les caméras, ajouter uniquement l'ID de l'équipement
                $this->jeedomCommands[$piece][$eqName] = [
                    'id' => $eqLogic->getId()
                ];

                if ($this->debugEqDetail) {
                    echo "|  Type: Camera (ID: " . $eqLogic->getId() . ")\n";
                }

                continue; // Passer à l'équipement suivant
            }
            // Vérifier si l'équipement est dans une catégorie autorisée pour les actions
            $isAuthorizedCatAction = $this->isAuthorizedCategory($eqLogic);
            
            // Collecter les commandes
            $eqCmds = $this->collectEquipmentCommands(
                $eqLogic, 
                $eqName, 
                $mode, 
                $isAuthorizedCatAction
            );
            
            if (!empty($eqCmds)) {
                $this->jeedomCommands[$piece][$eqName] = $eqCmds;
            }
        }
        
        $json = json_encode($this->jeedomCommands, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        
        if ($this->debugEq) {
            echo "Équipements collectés:\n$json\n\n";
        }
        
        return $json;
    }
    
    /**
     * Vérifier si un équipement doit être exclu
     */
    private function isEquipmentExcluded($eqName) {
        foreach ($this->equipementsExclus as $exclusion) {
            if (strpos($eqName, $exclusion) !== false) {
                return true;
            }
        }
        return false;
    }
    
    /**
     * Vérifier si un équipement est dans une catégorie autorisée
     */
    private function isAuthorizedCategory($eqLogic) {
        foreach ($this->eqActionInclusCategories as $category) {
            if ($eqLogic->getCategory($category) == 1) {
                if ($this->debugEqDetail) {
                    echo "|  Cat $category : OK\n";
                }
                return true;
            }
        }
        return false;
    }
    
    /**
     * Collecter les commandes d'un équipement
     */
    private function collectEquipmentCommands($eqLogic, $eqName, $mode, $isAuthorizedCatAction) {
        $eqCmds = [];
        $cmds = $eqLogic->getCmd();
        
        foreach ($cmds as $cmd) {
            $cmdName = $cmd->getName();
            
            // Vérifier si la commande doit être exclue
            if ($this->isCommandExcluded($cmdName)) {
                continue;
            }
            
            if ($cmd->getIsVisible() != 1 && $cmdName != "Etat") {
                continue;
            }
            
            $cmdType = $cmd->getType();
            
            // Collecter les actions
            if ($mode === 'action' && $cmdType === 'action' && $isAuthorizedCatAction) {
                $eqCmds = $this->addActionCommand($eqCmds, $cmd, $eqLogic, $cmdName);
            }
            
            // Collecter les infos
            if ($cmdType === 'info') {
                $eqCmds = $this->addInfoCommand($eqCmds, $cmd, $eqLogic, $cmdName, $eqName);
            }
        }
        
        return $eqCmds;
    }
    
    /**
     * Vérifier si une commande doit être exclue
     */
    private function isCommandExcluded($cmdName) {
        foreach ($this->eqCmdExclus as $exclusion) {
            if (strpos($cmdName, $exclusion) !== false) {
                return true;
            }
        }
        return false;
    }
    
    /**
     * Ajouter une commande action
     */
    private function addActionCommand($eqCmds, $cmd, $eqLogic, $cmdName) {
        // Adapter les noms pour les lumières
        if ($eqLogic->getCategory('light') == 1) {
            $cmdName = str_replace(["On", "Off"], ["Allumer", "Eteindre"], $cmdName);
        }
        
        if ($this->debugEqDetail) {
            echo "|  - action {$cmd->getHumanName()} $cmdName\n";
        }
        
        $eqCmds[$cmdName] = [
            'id' => $cmd->getId()
        ];
        
        return $eqCmds;
    }
    
    /**
     * Ajouter une commande info
     */
    private function addInfoCommand($eqCmds, $cmd, $eqLogic, $cmdName, $eqName) {
        $cmdValue = $cmd->execCmd();
        
        if ($cmdValue === "") {
            if ($this->debugEqDetail) {
                echo "|  - info $cmdName : vide\n";
            }
            return $eqCmds;
        }
        
        if ($this->debugEqDetail) {
            echo "|  - info {$cmd->getHumanName()} : $cmdValue";
        }
        
        // Formater la valeur selon le type d'équipement
        $cmdValue = $this->formatCommandValue($eqLogic, $eqName, $cmdName, $cmdValue);
        
        // Ajouter l'unité
        if ($cmd->getUnite() !== "") {
            $cmdValue = $cmdValue . $cmd->getUnite();
        }
        
        if ($this->debugEqDetail) {
            echo " ($cmdValue : OK)\n";
        }
        
        $eqCmds[$cmdName] = $cmdValue;
        
        return $eqCmds;
    }
    
    /**
     * Formater la valeur d'une commande selon son type
     */
    private function formatCommandValue($eqLogic, $eqName, $cmdName, $cmdValue) {
        if ($cmdName !== "Etat") {
            return $cmdValue;
        }
        /*
        // Lumières
        if ($eqLogic->getCategory('light') == 1) {
            return str_replace([255, 0, 1], ["Allumée", "Eteinte", "Allumée"], $cmdValue);
        }
        
        // Ouvertures
        if ($eqLogic->getCategory('opening') == 1) {
            if (strpos($eqName, "Fenêtre") !== false) {
                return str_replace([0, 1], ["Fermée", "Ouverte"], $cmdValue);
            }
            if (strpos($eqName, "Porte") !== false) {
                return str_replace([0, 1], ["Ouverte", "Fermée"], $cmdValue);
            }
            if (strpos($eqName, "Volet") !== false) {
                return str_replace([0, 1], ["Ouvert", "Fermé"], $cmdValue);
            }
        }
        
        // Chauffage
        if ($eqLogic->getCategory('heating') == 1) {
            return str_replace([255, 0, 1], ["Allumé", "Eteint", "Allumé"], $cmdValue);
        }
        
        // Vannes
        if (strpos($eqName, "Vanne") !== false) {
            return str_replace([0, 1], ["Ouverte", "Fermée"], $cmdValue);
        }
        */
        return $cmdValue;
    }
    
    /**
     * Créer la configuration de l'assistant
     */
    public function createAssistantConfig($profile) {
        return [
            'name' => 'Assistant Domotique Jeedom',
            'instructions' => 
                "# RÔLE\n" .
                "Tu es Jarvis, un assistant domotique intelligent pour Jeedom. Je m'appelle $profile.\n\n" .
                
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
          		"  \"type action\": \"code du type de l'action que tu souhaite executer\"\n" .
                "}\n\n" .
                
                "# RÈGLES DE DÉTECTION DU MODE\n" .
                "- mode = \"action\" : Pour toute demande d'action physique (allumer, éteindre, ouvrir, fermer, monter, descendre, activer, désactiver, régler, programmer)\n" .
                "- mode = \"info\" : Pour les questions d'information (quelle température, est-ce que, combien, statut, état)\n\n" .
                
                "# RÈGLES DE DÉTECTION DU TYPE D'ACTION\n" .
                "- type action = \"command\" : Pour toute demande d'action physique (allumer, éteindre, ouvrir, fermer, monter, descendre, activer, désactiver, régler, programmer)\n" .
                "- type action = \"camera\" : Pour toutes demandes d'information relatives à de l'analyse d'image des caméras de surveillance (obligatoire si tu renvois un ID de camera dans le champ id \n\n" .
                          
                "# RÈGLES POUR LES ACTIONS\n" .
                "Avant de proposer une action :\n" .
                "1. Vérifie l'état actuel de l'équipement dans le JSON fourni :\n" .
          " - Pour les équipements volets, portes et vannes : le champs 'Etat' vaut : 0 si l'équipement est ouvert et 1 si l'équipement est fermé\n" .
          " - Pour les équipements fenêtre : le champs 'Etat' vaut : 0 si l'équipement est fermé, et 1 si l'équipement est ouvert\n" .
          " - Pour les équipements lumière : le champs 'Etat' vaut : 0 si l'équipement est éteind, et 1 ou un valeur positive si l'équipement est allumé\n" .
          " - Pour les autres équipements : le champs 'Etat' vaut : 0 si l'équipement est éteind, arrêté ou inactif, et 1 ou une valeur positive si l'équipement est allumé, en marche ou actif\n" .
          " - Pour les actions  : On veut dire allumer, Off veut dire éteindre. Monter veut dire ouvrir, et descendre veut dire fermer\n" .
          		"2. Vérifie SYSTEMATIQUEMENT l'état de l'équipement dans le json envoyé\n" .
                "3. Si l'équipement est déjà dans l'état demandé, réponds : \"[Équipement] est déjà [état].\"\n" .
                "4. Si l'action est nécessaire, fournis l'ID de la ou les commandes et mode=\"action\"\n" .
                "5. Si plusieurs équipements correspondent, demande de préciser ou liste les options\n\n" .
                
                "# RÈGLES DE SÉCURITÉ\n" .
                "- Ne réponds que si tu es CERTAIN de la réponse (confidence=\"high\")\n" .
                "- Si tu n'es pas sûr, indique confidence=\"medium\" ou \"low\" et explique pourquoi\n" .
                "- Si aucune question n'est posée, réponds : {\"question\":\"\",\"response\":\"Aucune question détectée.\",\"piece\":\"\",\"id\":\"\",\"mode\":\"info\",\"confidence\":\"high\"}\n" .
                "- Si l'ID de commande n'est pas trouvé dans le JSON, laisse \"id\" vide et explique dans \"response\"\n\n" .
                
                "# RÈGLES AVANCÉES\n" .
                //"- Si plusieurs actions sont demandées, retourne un tableau 'actions' : [{\"id\":\"123\",\"action\":\"on\"},{\"id\":\"456\",\"action\":\"off\"}]\n" .
                "- Pour les températures, précise l'unité (°C)\n" .
                "- Pour les pourcentages (volets, luminosité), indique la valeur actuelle et cible\n" .
                "- Si une action risque d'être gênante (éteindre toutes les lumières la nuit) ou dangereuse (ouvrir le garage, ouvrir la piscine), demande confirmation\n\n" .
                
                "# STYLE DE RÉPONSE\n" .
                "- Sois naturel, amical et concis\n" .
                "- Utilise des retours à la ligne (\\n) pour les réponses multi-phrases\n" .
                "- Personnalise avec le prénom $profile si pertinent\n" .
                "- Ajoute des emojis légers si approprié (🌡️ 💡 🚪)\n\n" .
                
                "# EXEMPLES DE RÉPONSES ATTENDUES\n" .
                "Question : \"Allume la lumière du salon\"\n" .
                "Si déjà allumée :\n" .
                "{\"question\":\"Allume la lumière du salon\",\"response\":\"💡 La lumière du salon est déjà allumée.\",\"piece\":\"salon\",\"id\":\"\",\"mode\":\"info\",\"confidence\":\"high\",\"type action\":\"\"}\n\n" .
                
                "Si éteinte :\n" .
                "{\"question\":\"Allume la lumière du salon\",\"response\":\"✅ J'allume la lumière du salon.\",\"piece\":\"salon\",\"id\":\"123\",\"mode\":\"action\",\"confidence\":\"high\",\"type action\":\"command\"}\n\n" .
                
                "Question : \"Quelle est la température du salon ?\"\n" .
                "{\"question\":\"Quelle est la température du salon ?\",\"response\":\"🌡️ La température du salon est actuellement de 21.5°C.\",\"piece\":\"salon\",\"id\":\"456\",\"mode\":\"info\",\"confidence\":\"high\",\"type action\":\"\"}\n\n" .
                
                "Question ambiguë : \"Allume la lumière\"\n" .
                "{\"question\":\"Allume la lumière\",\"response\":\"J'ai trouvé plusieurs lumières : salon, cuisine, chambre.\\nQuelle lumière veux-tu allumer ?\",\"piece\":\"\",\"id\":\"\",\"mode\":\"info\",\"confidence\":\"low\",\"type action\":\"\"}\n\n" .
                
                "Question : \"Montre-moi le salon\"\n" .
                "{\"question\":\"Montre-moi le salon\",\"response\":\"Je regarde sur les caméras.\",\"piece\":\"salon\",\"id\":\"\",\"mode\":\"action\",\"confidence\":\"high\",\"type action\":\"camera\"}\n\n" .
          
                "# GESTION DU CONTEXTE\n" .
                "- Utilise l'historique de la conversation pour comprendre les références implicites (\"et dans la cuisine aussi?\", \"éteins-la\") mais PAS pour déduire les états des équiepements. Récupère les toujurs dans le json fournis à chaque question\n" .
                "- Mémorise les préférences exprimées par $profile\n" .
                "- Si une pièce a été mentionnée récemment, c'est probablement celle concernée par \"ici\" ou \"là\"\n",
            
            'model' => $this->openaiModel
        ];
    }
    
    /**
     * Poser une question à l'assistant
     * 
     * @param string $profile Nom du profil utilisateur
     * @param string $question Question à poser
     * @param array $pieces Pièces concernées (null = toutes)
     * @param string $mode Mode 'action' ou 'info'
     * @return array Réponse parsée
     */
    public function ask($profile, $question, $pieces = null, $mode = 'action',$sendJeedomData = true, $imageData = null, $filename = 'image.jpg') {
        if ($this->debug) echo "jeedomAssistant ask : ".$question."\n";
        $startTime = microtime(true);

        // Gestion des commandes spéciales
        //TODO : ajouter une action executable par l'assistant?
        if (strtolower($question) === 'reset' || strtolower($question) === 'init' || strtolower($question) === 'oubliette' || strtolower($question) === 'raz') {
            if ($this->debug) echo "RÉINITIALISATION DU CONTEXTE\n";

            $this->ai->resetConfig();
            return [
                'question' => $question,
                'response' => "✅ J'ai réinitialisé le contexte de notre discussion.",
                'piece' => '',
                'id' => '',
                'mode' => 'info',
                'confidence' => 'high',
                'type action' => ''
            ];
        }
        
        $message = $question;
        if ($this->debug) echo "📝 Taille de la question initiale: " . strlen($question) . " octets\n";
        
        if($sendJeedomData === true) {
            // Collecter les données Jeedom
            $jeedomJson = $this->collectJeedomData($pieces, $mode);
            
            // ⚠️ AJOUT: Vérifier la taille du JSON
            $jsonSize = strlen($jeedomJson);
            if ($this->debug) echo "📊 Taille du JSON Jeedom: " . number_format($jsonSize) . " octets (" . round($jsonSize / 1024, 2) . " KB)\n";
            
            // Limiter à 10000 caractères environ (ajustez selon vos besoins)
            if ($jsonSize > 30000) {
                echo "⚠️ WARNING: JSON très volumineux (". round($jsonSize / 1024, 2) . " KB), cela peut causer des erreurs\n";
                // TODO : Tronquer ou filtrer davantage
            }
            
            $message = $message . "\n" . 
                    "Voici les valeurs actuelles des capteurs de la domotique : " . 
                    $jeedomJson;
        }
        
        // Afficher la taille totale du message final
        $totalSize = strlen($message);
        if ($this->debug) echo "📦 Taille TOTALE du contexte envoyé: " . number_format($totalSize) . " octets (" . round($totalSize / 1024, 2) . " KB)\n";
        if ($this->debug) echo "🔤 Estimation tokens (~4 chars/token): " . round($totalSize / 4) . " tokens\n";
    
           
        // Configuration de l'assistant
        $assistantConfig = $this->createAssistantConfig($profile);
        
        // Interroger l'assistant
        if ($this->debug) echo "Interrogation de l'assistant IA\n";
        
        if (!empty($imageData) && $imageData !== false) {
            if ($this->debug) echo "Analyse d'image\n";
			$response = $this->ai->askWithImage($profile, $message, $assistantConfig, $imageData, $filename, $this->openaiVisionModel);
    	}else{
             $response = $this->ai->ask($profile, $message, $assistantConfig, $this->openaiModel);
        }
        
        if ($this->debug) echo "Réponse BRUTE : $response\n";
        
        // Parser la réponse
        $return = $this->parseResponse($response, $profile);
        $endTime = microtime(true); // 🕒 Stoppe le chronomètre
        $duration = round($endTime - $startTime, 3); // Temps en secondes
        if ($this->debug) echo "⏱️ Temps d'exécution jeedomAssistant ask : {$duration}s\n";

        return $return;
    }
    
    /**
     * Parser et traiter la réponse de l'IA
     */
    private function parseResponse($response, $profile) {
        try {
            // Nettoyer la réponse (retirer les backticks markdown si présents)
            $response = preg_replace('/^```json\s*|\s*```$/m', '', $response);
            
            // Décoder le JSON
            $responseData = json_decode($response, true);
            
            // Vérifier les erreurs JSON
            if ($responseData === null && json_last_error() !== JSON_ERROR_NONE) {
                if ($this->debug) {
                    echo "Erreur JSON: " . json_last_error_msg() . "\n";
                }
                throw new Exception("JSON invalide: " . json_last_error_msg());
            }
            
            if ($responseData === null) {
                throw new Exception("Réponse JSON vide");
            }
            
            // Assurer que tous les champs existent
            $defaults = [
                'question' => '',
                'response' => '',
                'piece' => '',
                'id' => '',
                'mode' => 'info',
                'confidence' => 'medium',
                'type action' => ''
            ];
            
            return array_merge($defaults, $responseData);
            
        } catch (Exception $e) {
            if ($this->debug) {
                echo "Erreur parseResponse: " . $e->getMessage() . "\n";
                echo "Réponse brute: " . substr($response, 0, 500) . "\n";
            }
            
            // Retourner une structure par défaut avec la réponse brute
            return [
                'question' => '',
                'response' => $response,
                'piece' => '',
                'id' => '',
                'mode' => 'info',
                'confidence' => 'low',
                'type action' => '',
                'raw' => $response,
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Verifie la possibilité d'execution d' une action 
     * 
     * @param array $response Réponse parsée
     * @param string $profile Profil utilisateur
     * @return string Raison
     */
    public function isExecutableAction($response, $profile) {
        if ($response['mode'] !== 'action') {
            return "Le mode n'est pas action (".$response['mode'].")";
        }
        
      	if ($response['type action'] !== 'command' && $response['type action'] !== 'camera') {
            return "Type d'action non géré: ".$response['type action'];
        }
      
        if ($response['type action'] === 'command' && (empty($response['id']) || trim($response['id'])==='') || !($response['id'])) {
            return "Aucun ID de commande fourni pour l'action ".$response['type action'];
        }
        
        if ($profile === 'Inconnu' || $profile === '') {
            return "Action non autorisée pour le profil: $profile";
        }
        if ($this->debugDontRunAction) {
            return "Action non autorisée";
        }
        $cmdId = $response['id'];
        $cmdAction = cmd::byId($cmdId);
        if (!is_object($cmdAction)) {
            return "Commande ID $cmdId non trouvée pour l'action ".$response['type action'];
        }
        
        if ($cmdAction->getType() !== 'action') {
            return "La commande ID '$cmdId' n'est pas une action";
        }
        
        $cmdActionName = $cmdAction->getHumanName();
        //if ($this->debug) {
        //    return "EXÉCUTION ACTION ==> $cmdActionName\n";
        //}
        return "";
    }
  
    /**
     * Exécuter une action si nécessaire
     * 
     * @param array $response Réponse parsée
     * @param string $profile Profil utilisateur
     * @return bool Action exécutée ou non
     */
    public function executeActions($response, $profile) {
        if ($response['mode'] !== 'action') {
            return false;
        }

        if ($profile === 'Inconnu' || $profile === '' || $this->debugDontRunAction) {
            if ($this->debug) {
                echo "Action non autorisée pour le profil: $profile\n";
            }
            return false;
        }

        switch ($response['type action']) {
          case 'command':
              $cmdId = $response['id'];
              if (!empty($cmdId)) {
                  // Sépare les ID multiples (ex: "123,456,789") et supprime les espaces inutiles
                  $cmdIds = array_map('trim', explode(',', $cmdId));

                  // Boucle sur chaque ID et exécute la commande correspondante
                  foreach ($cmdIds as $id) {
                      if (!empty($id)) {
                          $this->executeCommand($id);
                      }
                  }
              }
              return true;
              break;
        //   case 'camera':
        //       $cmdId = $response['id'];
        //       if (!empty($cmdId)) {
        //           // Sépare les ID multiples (ex: "123,456,789") et supprime les espaces inutiles
        //           $cmdIds = array_map('trim', explode(',', $cmdId));

        //           // Boucle sur chaque ID et exécute la commande correspondante
        //           foreach ($cmdIds as $id) {
        //               if (!empty($id)) {
        //                   return $this->executeCamera($response, $profile, $id); //TODO : retour mono type? pas object ou bool?
        //               }
        //           }
        //       }
        //       return true;
        //       break;
          default:
              echo "Type d'Action ".$response['type action']." non géré\n";
              return false;
              break;
        }
        return true;
    }
       
    /**
     * Exécuter une commande camera jeedom
     * 
     * @param array $response Réponse parsée
     * @param string $profile Profil utilisateur
     * @return bool Action exécutée ou non
     */
    public function executeCamera($response = null, $profile = null, $eqLogicId) {
		echo "executeCamera\n";
        $startTime = microtime(true); // 🕒 Démarre le chronomètre

        //TODO : recuperation et renvoi de l'image a l'assistant + dans une notif?
      
        if (empty($eqLogicId)) {
            return false;
        }
 
        $eqLogic = eqLogic::byId($eqLogicId);
        
        if (!is_object($eqLogic)) {
            if ($this->debug) echo "Equipement ID $eqLogicId non trouvée\n";
            return false;
        }
        
        if ($eqLogic->getEqType_name() !== 'camera') {
            if ($this->debug) echo "L'equipement $eqLogicId n'est pas une camera\n";
            return false;
        }
        
        $eqLogicName = $eqLogic->getHumanName();
        if ($this->debug) echo "Analyse CAMERA ==> $eqLogicName\n";
        
        // Récupération du flux d'une camera
        $imageData = $this->getCameraImage($eqLogicId);
        //$imageData2='';
        if ($imageData !== false) {
            echo "Image recupérée (".strlen($imageData)." octets)\n";;
        }

        //process($profile, $question, $pieces = null, $mode = 'action', $notificationCommand = '', $imageData = null, $filename = null) {
        //$result = $this->process($profile, $question, $pieces, $mode, $notificationCommand, $imageData2, 'cam.jpg');
        if($response['question'] === null || empty($response['question'])){
          $question = "Analyse l'image de la caméra de surveillance";
        }else{
          $question = "Réponds à la question en analysant l'image de la caméra de surveillance: ".$response['question'];
        }
        $response2 = $this->ask($profile, $question, $response['piece'], null, false, $imageData, null); //TODO : préciser un mode? et un nom de fichier?
        
        $endTime = microtime(true); // 🕒 Stoppe le chronomètre
        $duration = round($endTime - $startTime, 3); // Temps en secondes
        echo "⏱️ Temps d'exécution executeCamera : {$duration}s\n";

        return $response2; //TODO : retour mono type? pas object ou bool?
    }
  
    /**
     * Exécuter une commande jeedom
     * 
     * @param array $response Réponse parsée
     * @param string $profile Profil utilisateur
     * @return bool Action exécutée ou non
     */
    public function executeCommand($cmdId) {

        if (empty($cmdId)) {
            return false;
        }
 
        $cmdAction = cmd::byId($cmdId);
        
        if (!is_object($cmdAction)) {
            if ($this->debug) {
                echo "Commande ID $cmdId non trouvée\n";
            }
            return false;
        }
        
        if ($cmdAction->getType() !== 'action') {
            if ($this->debug) {
                echo "La commande ID $cmdId n'est pas une action\n";
            }
            return false;
        }
        
        $cmdActionName = $cmdAction->getHumanName();
        if ($this->debug) {
            echo "EXÉCUTION ACTION ==> $cmdActionName\n";
        }
        $cmdAction->execCmd();
        
        return true;
    }
  
    /**
     * Envoyer une notification
     * 
     * @param string $profile Destinataire
     * @param string $message Message à envoyer
     * @param string $command Commande de notification (optionnel)
     */
    public function sendMessageNotification($profile, $message, $command = '') {
        if ($this->debug) echo "Envoi notification à $profile: $message\n";
        
        $scenario = scenario::byId($this->notificationScenarioId);
        
        if (!is_object($scenario)) {
            throw new Exception("Scénario de notification ID {$this->notificationScenarioId} non trouvé");
        }
        
        $tags = $scenario->getTags();
        $tags['#profile#'] = $profile;
        $tags['#msg#'] = $message;
        $tags['#command#'] = $command;
        
        $scenario->setTags($tags);
        $scenario->launch();
    }
    
    /**
     * Envoyer une notification avec support optionnel d'images de caméras
     * 
     * @param string $profile Destinataire de la notification
     * @param string $message Message à envoyer
     * @param string $command Commande de notification (optionnel)
     * @param string|int $eqLogicId_Camera ID(s) de l'équipement caméra Jeedom (optionnel, peut être une liste séparée par des virgules)
     * @return bool Succès de l'envoi
     * 
     * @example
     * // Notification simple sans image
     * $this->sendNotification('Franck', 'Mouvement détecté');
     * 
     * // Notification avec image d'une caméra
     * $this->sendNotification('Franck', 'Analyse de la caméra', 'telegram', 123);
     * 
     * // Notification avec images de plusieurs caméras
     * $this->sendNotification('Franck', 'Vue des caméras', 'telegram', '123,456,789');
     */
    public function sendCameraNotification($profile, $message, $command = '', $eqLogicId_Camera = null) {
        if ($this->debug) echo "Envoi notification à $profile: $message\n";
        
        // === ENVOI DES IMAGES DE CAMÉRAS (SI DEMANDÉ) ===
        if (!empty($eqLogicId_Camera)) {
            if ($this->debug) echo "Traitement des caméras pour envoi d'images\n";
            
            // Séparer les IDs multiples (ex: "123,456,789") et supprimer les espaces inutiles
            $cameraIds = array_map('trim', explode(',', (string)$eqLogicId_Camera));
            
            // Boucler sur chaque caméra
            foreach ($cameraIds as $cameraId) {
                if (empty($cameraId)) {
                    continue;
                }
                
                try {
                    // Récupérer l'équipement caméra
                    $eqLogic = eqLogic::byId($cameraId);
                    
                    if (!is_object($eqLogic)) {
                        echo "Équipement caméra ID $cameraId non trouvé\n";
                        continue;
                    }
                    
                    if ($eqLogic->getEqType_name() !== 'camera') {
                        echo "L'équipement ID $cameraId n'est pas une caméra\n";
                        continue;
                    }
                    
                    $cameraName = $eqLogic->getHumanName();
                    //if ($this->debug) echo "Envoi snapshot de la caméra: $cameraName\n";
                    
                    // Rechercher la commande avec logicalId = 'sendSnapshot'
                    $sendSnapshotCmd = null;
                    foreach ($eqLogic->getCmd() as $cmd) {
                        if ($cmd->getLogicalId() === 'sendSnapshot') {
                            $sendSnapshotCmd = $cmd;
                            break;
                        }
                    }
                    
                    if (!is_object($sendSnapshotCmd)) {
                        echo "Commande 'sendSnapshot' non trouvée pour la caméra ID $cameraId\n";
                        continue;
                    }
                    
                    // Vérifier que c'est bien une commande action
                    if ($sendSnapshotCmd->getType() !== 'action') {
                        echo "La commande 'sendSnapshot' n'est pas une action pour la caméra ID $cameraId\n";
                        continue;
                    }
                    
                    $messageNotif = (empty($message)?$cameraName:$message);
                    // Options : nombre de captures, message, désactiver les notifications internes, ne pas envoyer la première capture
                    $options = [
                        'nbSnap' => 1,
                        'message' => $messageNotif,
                        'disable_notify' => 1,
                        'sendFirstSnap' => 0
                    ];
                    
                    // Construire la chaîne d'options
                    $optionsString = "nbSnap={$options['nbSnap']} message='{$options['message']}' disable_notify={$options['disable_notify']} sendFirstSnap={$options['sendFirstSnap']}";
                    
                    // Paramètres de la commande
                    $execParams = [
                        'title' => $optionsString,  // Nombre de captures et options
                        'message' => $command          // Commande d'envoi
                    ];
                    
                    if ($this->debug) {
                        echo "Exécution sendSnapshot avec:\n";
                        echo "  - sendSnapshotCmd: ".$sendSnapshotCmd->getId()."\n";
                        echo "  - Options: $optionsString\n";
                        echo "  - Message/Commande: $command\n";
                    }
                    
                    // Exécuter la commande sendSnapshot
                    $sendSnapshotCmd->execCmd($execParams);
                    
                    if ($this->debug) echo "Snapshot envoyé avec succès pour la caméra: $cameraName\n";
                    
                } catch (Exception $e) {
                    if ($this->debug) echo "Erreur lors de l'envoi du snapshot pour la caméra ID $cameraId: " . $e->getMessage() . "\n";
                    // Continuer avec les autres caméras même en cas d'erreur
                    continue;
                }
            }
        }else{
            // === ENVOI DE LA NOTIFICATION TEXTE (SCÉNARIO) ===
            try {
                $scenario = scenario::byId($this->notificationScenarioId);
                
                if (!is_object($scenario)) {
                    throw new Exception("Scénario de notification ID {$this->notificationScenarioId} non trouvé");
                }
                
                $tags = $scenario->getTags();
                $tags['#profile#'] = $profile;
                $tags['#msg#'] = $message;
                $tags['#command#'] = $command;
                
                $scenario->setTags($tags);
                $scenario->launch();
                
                if ($this->debug) echo "Notification texte envoyée avec succès via le scénario : ".$message."\n";
                
                return true;
                
            } catch (Exception $e) {
                if ($this->debug) echo "Erreur lors de l'envoi de la notification: " . $e->getMessage() . "\n";
                throw $e;
            }

        }
        
        
    }

    /**
     * Traiter une demande complète (ask + execute + notify)
     * 
     * @param string $profile Profil utilisateur
     * @param string $question Question
     * @param array $pieces Pièces concernées
     * @param string $mode Mode
     * @param string $notificationCommand Commande de notification
     * @return array Résultat complet
     */
    public function process($profile, $question, $pieces = null, $mode = 'action', $notificationCommand = '', $imageData = null, $filename = null) {
        
        try {
          	$notificationProfile = ($profile !== 'Inconnu' && $profile !== '') ? $profile : 'Franck';
            if ($this->debug) echo "PROCESS question : ".$profile." (".$notificationProfile.")\n";
			
            // Poser la question
           if (!empty($imageData)) {
            if ($this->debug) echo "PROCESS CAMERA : pieces:$pieces mode:$mode \n";
			$response = $this->ask($profile, $question, $pieces, $mode, false, $imageData, $filename);
    	   }else{
            if ($this->debug) echo "PROCESS MESSAGE : pieces:$pieces mode:$mode \n";
             $response = $this->ask($profile, $question, $pieces, $mode, true, null, null);
           }
         	
            // Exécuter l'action si nécessaire
            $equipmentNames = "";
          	if ($response['mode'] === 'action') { /************************************************************************************ */
               $actionResponse = $this->isExecutableAction($response, $profile);
               switch ($response['type action']) {
                  case 'command': /************** COMMAND ********************************************************************************* */
                      $actionExecuted = $this->executeActions($response, $profile);
                      $equipmentNames = $this->getHumanName($response['id'], "cmd");
                      if ($this->debug) echo "COMMANDS : $equipmentNames\n";
                      break;
                  case 'camera': //************* CAMERA *********************************************************************************** */
                   
                      $cmdId = $response['id'];
                      if (!empty($cmdId)) {
                          //$equipmentNames = $this->getHumanName($response['id'], "eqlogic");
                          $this->sendMessageNotification($notificationProfile, $response['response'], $notificationCommand);
                          //$this->sendMessageNotification($notificationProfile, "Voici les images des caméras", $notificationCommand);
                          // Sépare les ID multiples (ex: "123,456,789") et supprime les espaces inutiles
                          $cmdIds = array_map('trim', explode(',', $cmdId));
                          // Boucle sur chaque ID et envoi snapshot des camera avant analyse
                          foreach ($cmdIds as $id) {
                              if (!empty($id)) {
                                  $equipmentName = $this->getHumanName($id, "eqlogic");
                                  if ($this->debug) echo "CAMERA : $equipmentName\n";
                                  $equipmentNames = $equipmentName."\n";
                                  $this->sendCameraNotification($notificationProfile, $equipmentName, $notificationCommand, $id);
                              }
                          }

                          // Boucle sur chaque ID et exécute la commande correspondante
                          $responseCameras = "";
                          foreach ($cmdIds as $id) {
                              if (!empty($id)) {
                                  $equipmentName = $this->getHumanName($id, "eqlogic");
                                  if ($this->debug) echo "CAMERA : $equipmentName\n";
                                  //$equipmentNames = $equipmentName."\n";
                                  $this->sendCameraNotification($notificationProfile, $equipmentName, $notificationCommand, $id);
                                  $responseCamera = $this->executeCamera($response, $profile, $id); //TODO : retour mono type? pas object ou bool?
                                  if(!($responseCamera===false)){
                                    $responseCameras .= $responseCamera['response']."\n";
                                  }
                              }
                          }
                          $response['response']=$responseCameras; //TODO : que la réponse?     
                      }
                      break;
                  default:
                      echo "Type d'Action ".$response['type action']." non géré\n";
                   	  $actionResponse = "Type d'Action ".$response['type action']." non géré";
                      break;
                }
            }else{
              $actionExecuted = false;
            }
          
            // Préparer le message de notification
            $notificationMsg = $response['response'];
            if ($response['mode'] === 'action' && $actionExecuted===false) {
				$notificationMsg .= "\n Je n'ai pas pu executer la commande : ".$actionResponse.".\n";
            }
            
          	//DEBUG : Recuperation des noms des equipements ou commandes
            if(!empty($equipmentNames)) $notificationMsg .= " \n".$equipmentNames;
                       
            // Envoyer la notification
            $this->sendMessageNotification($notificationProfile, $notificationMsg, $notificationCommand);
            
            // Retourner le résultat complet
            return [
                'success' => true,
                'response' => $response,
                'action_executed' => $actionExecuted,
                'notification_sent' => true,
                'message' => $notificationMsg
            ];
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'response' => null,
                'action_executed' => false,
                'notification_sent' => false
            ];
        }
    }
    
    /**
     * Réinitialiser la configuration de l'assistant
     */
    public function reset() {
        return $this->ai->resetConfig();
    }
    
    /**
     * Obtenir l'historique d'une conversation
     */
    public function getHistory($profile, $limit = 20) {
        return $this->ai->getThreadHistory($profile, $limit);
    }
  
/**
   * Récupère l'image JPEG d'une caméra Jeedom
   * 
   * @param int $eqLogicId L'ID de l'équipement caméra
   * @return string|false Le contenu de l'image JPEG ou false en cas d'erreur
   */
  function getCameraImage($eqLogicId) {
      try {
          echo "getCameraImage($eqLogicId)\n";
          // Récupération de l'équipement
          $eqLogic = eqLogic::byId($eqLogicId);

          if (!is_object($eqLogic)) {
              if ($this->debug) echo "Équipement introuvable : ID $eqLogicId";
              return false;
          }

          // Recherche de la commande avec le LogicalId "urlFlux"
          $cmd = null;
          foreach ($eqLogic->getCmd() as $command) {
              if ($command->getLogicalId() == 'urlFlux') {
                  $cmd = $command;
                  break;
              }
          }

          if (!is_object($cmd)) {
              if ($this->debug) echo "Commande 'urlFlux' introuvable pour l'équipement ID $eqLogicId";
              return false;
          }

          // Récupération de l'URL du flux
          $urlFlux = $cmd->execCmd();
          if (empty($urlFlux)) {
              if ($this->debug) echo "URL du flux vide pour l'équipement ID $eqLogicId";
              return false;
          }

          //Recupération du host local
          $internalAddr = config::byKey('internalAddr', 'core', 'localhost');
          if (empty($internalAddr)) {
              if ($this->debug) echo "Adresse réseau interne Jeedom vide";
              return false;
          }
          $internalPort = config::byKey('internalPort', 'core', '80');
          if (empty($internalPort)) {
              if ($this->debug) echo "Port réseau interne Jeedom vide";
              return false;
          }
        
          $urlFlux = "http://".$internalAddr.":".$internalPort."/".$urlFlux;
        
          // Récupération de l'image depuis l'URL
          $context = stream_context_create([
              'http' => [
                  'timeout' => 10, // Timeout de 10 secondes
                  'ignore_errors' => true
              ]
          ]);

          $imageData = @file_get_contents($urlFlux, false, $context);

          if ($imageData === false) {
              if ($this->debug) echo "Impossible de récupérer l'image depuis l'URL : $urlFlux";
              return false;
          }

          // Vérification que c'est bien une image JPEG
          if (strpos($imageData, "\xFF\xD8\xFF") !== 0) {
              if ($this->debug) echo "Le contenu récupéré ne semble pas être une image JPEG valide";
          }

          if ($this->debug) echo "Image récupérée avec succès depuis l'équipement ID $eqLogicId (" . strlen($imageData) . " octets)";

          return $imageData;

      } catch (Exception $e) {
          if ($this->debug) echo "Erreur lors de la récupération de l'image : " . $e->getMessage();
          return false;
      }
  }

    /**
     * Récupère les noms lisibles (HumanName) d'une liste d'IDs de commandes ou d'équipements
     * 
     * @param string $ids Liste d'IDs séparés par des virgules (ex: "123,456,789")
     * @param string $objectType Type d'objet: "cmd" pour commandes, "eqLogic" pour équipements
     * @return string Noms des objets séparés par des retours à la ligne, ou chaîne vide si aucun trouvé
     */
    function getHumanName($ids, $objectType) {
        // Vérifier que les paramètres sont valides
        if (empty($ids) || empty($objectType)) {
            return "";
        }
        
        // Normaliser le type d'objet
        $objectType = strtolower(trim($objectType));
        
        // Valider le type d'objet
        if (!in_array($objectType, ['cmd', 'eqlogic'])) {
            throw new InvalidArgumentException("Type d'objet invalide. Utilisez 'cmd' ou 'eqLogic'.");
        }
        
        $names = [];  // Utiliser un tableau au lieu d'une chaîne
            
        // Séparer les IDs multiples et supprimer les espaces inutiles
        $idList = array_map('trim', explode(',', $ids));

        // Boucler sur chaque ID
        foreach ($idList as $id) {
            // Vérifier que l'ID n'est pas vide
            if (empty($id)) {
                continue;
            }
            
            // Récupérer l'objet selon son type
            try {
                switch ($objectType) {
                    case 'cmd':
                        $object = cmd::byId($id);
                        break;
                        
                    case 'eqlogic':
                        $object = eqLogic::byId($id);
                        break;
                }
                
                // Ajouter le nom si l'objet existe
                if (is_object($object)) {
                    $names[] = $object->getHumanName();
                }
                
            } catch (Exception $e) {
                // Ignorer silencieusement les erreurs pour cet ID et continuer
                continue;
            }
        }
        
        // Formater la sortie selon le nombre d'éléments
        if (empty($names)) {
            return "";
        } elseif (count($names) === 1) {
            // Un seul élément : retourner sans retour à la ligne
            return $names[0];
        } else {
            // Plusieurs éléments : séparer par des retours à la ligne
            return implode("\n", $names);
        }
    }

}

?>