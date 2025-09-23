<?php

declare(strict_types=1);

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Interfaces\RouteCollectorProxyInterface as Group;

return function (Group $group) {
    // Lire tous les bons de livraison avec pagination
    $group->get('', function (Request $request, Response $response) {
        $pdo = $this->get(PDO::class);
        $queryParams = $request->getQueryParams();
        
        $page = max(1, (int)($queryParams['page'] ?? 1));
        $limit = min(100, max(1, (int)($queryParams['limit'] ?? 10)));
        $offset = ($page - 1) * $limit;
        
        // Compter le total
        $stmt = $pdo->query("SELECT COUNT(*) as total FROM bons_livraison");
        $total = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
        
        // Récupérer les livraisons avec les informations liées
        $stmt = $pdo->prepare("
            SELECT 
                bl.*,
                cmd.reference as commande_reference,
                cmd.quantite_commandee,
                cl.nom as client_nom, 
                cl.prenom as client_prenom,
                cl.entreprise as client_entreprise,
                ch.nom_chantier,
                ch.adresse as chantier_adresse,
                fb.nom as formule_nom,
                chf.nom as chauffeur_nom,
                chf.prenom as chauffeur_prenom,
                cam.plaque_immatriculation,
                cam.modele as camion_modele,
                op.nom as operateur_nom,
                op.prenom as operateur_prenom
            FROM bons_livraison bl
            INNER JOIN commandes cmd ON bl.commande_id = cmd.id
            INNER JOIN clients cl ON cmd.client_id = cl.id
            INNER JOIN chantiers ch ON cmd.chantier_id = ch.id
            INNER JOIN formules_beton fb ON cmd.formule_beton_id = fb.id
            LEFT JOIN chauffeurs chf ON bl.chauffeur_id = chf.id
            LEFT JOIN camions cam ON bl.camion_id = cam.id
            INNER JOIN operateurs op ON bl.operateur_id = op.id
            ORDER BY bl.date_production DESC, bl.heure_depart DESC
            LIMIT ? OFFSET ?
        ");
        $stmt->execute([$limit, $offset]);
        $livraisons = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $response->getBody()->write(json_encode([
            'livraisons' => $livraisons,
            'pagination' => [
                'page' => $page,
                'limit' => $limit,
                'total' => (int)$total,
                'pages' => ceil($total / $limit)
            ]
        ]));
        return $response->withHeader('Content-Type', 'application/json');
    });

    // Lire les livraisons d'une commande spécifique
    $group->get('/commande/{commande_id}', function (Request $request, Response $response, array $args) {
        $pdo = $this->get(PDO::class);
        
     $stmt = $pdo->prepare("
    SELECT 
        bl.*,
        cmd.reference as commande_reference,
        cmd.quantite_commandee,
        cl.nom as client_nom, 
        cl.prenom as client_prenom,
        cl.entreprise as client_entreprise,
        ch.nom_chantier,
        ch.adresse as chantier_adresse,
        fb.nom as formule_nom,
        fb.prix_metre_cube as formule_prix,
        chf.nom as chauffeur_nom,
        chf.prenom as chauffeur_prenom,
        cam.plaque_immatriculation,
        cam.modele as camion_modele,
        op.nom as operateur_nom,
        op.prenom as operateur_prenom
    FROM bons_livraison bl
    INNER JOIN commandes cmd ON bl.commande_id = cmd.id
    INNER JOIN clients cl ON cmd.client_id = cl.id
    INNER JOIN chantiers ch ON cmd.chantier_id = ch.id
    INNER JOIN formules_beton fb ON bl.formule_beton_id = fb.id
    LEFT JOIN chauffeurs chf ON bl.chauffeur_id = chf.id
    LEFT JOIN camions cam ON bl.camion_id = cam.id
    INNER JOIN operateurs op ON bl.operateur_id = op.id
    ORDER BY bl.date_livraison DESC, bl.heure_depart DESC
    LIMIT ? OFFSET ?
");
        $stmt->execute([$args['commande_id']]);
        $livraisons = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $response->getBody()->write(json_encode($livraisons));
        return $response->withHeader('Content-Type', 'application/json');
    });

    // Lire les livraisons d'un chauffeur spécifique
    $group->get('/chauffeur/{chauffeur_id}', function (Request $request, Response $response, array $args) {
        $pdo = $this->get(PDO::class);
        
        $stmt = $pdo->prepare("
            SELECT 
                bl.*,
                cmd.reference as commande_reference,
                cl.nom as client_nom, 
                cl.prenom as client_prenom,
                cl.entreprise as client_entreprise,
                ch.nom_chantier,
                ch.adresse as chantier_adresse,
                cam.plaque_immatriculation
            FROM bons_livraison bl
            INNER JOIN commandes cmd ON bl.commande_id = cmd.id
            INNER JOIN clients cl ON cmd.client_id = cl.id
            INNER JOIN chantiers ch ON cmd.chantier_id = ch.id
            LEFT JOIN camions cam ON bl.camion_id = cam.id
            WHERE bl.chauffeur_id = ?
            ORDER BY bl.date_livraison DESC, bl.heure_depart DESC
        ");
        $stmt->execute([$args['chauffeur_id']]);
        $livraisons = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $response->getBody()->write(json_encode($livraisons));
        return $response->withHeader('Content-Type', 'application/json');
    });
  // Livraisons du jour
    $group->get('/aujourdhui', function (Request $request, Response $response) {
        $pdo = $this->get(PDO::class);
        
        $stmt = $pdo->prepare("
            SELECT 
                bl.*,
                cmd.reference as commande_reference,
                cl.nom as client_nom, 
                cl.prenom as client_prenom,
                ch.nom_chantier,
                ch.adresse as chantier_adresse,
                chf.nom as chauffeur_nom,
                chf.prenom as chauffeur_prenom,
                cam.plaque_immatriculation
            FROM bons_livraison bl
            INNER JOIN commandes cmd ON bl.commande_id = cmd.id
            INNER JOIN clients cl ON cmd.client_id = cl.id
            INNER JOIN chantiers ch ON cmd.chantier_id = ch.id
            LEFT JOIN chauffeurs chf ON bl.chauffeur_id = chf.id
            LEFT JOIN camions cam ON bl.camion_id = cam.id
            WHERE DATE(bl.date_production) = CURDATE()
            ORDER BY bl.heure_depart ASC
        ");
        $stmt->execute();
        $livraisons = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $response->getBody()->write(json_encode($livraisons));
        return $response->withHeader('Content-Type', 'application/json');
    });
    // Lire un bon de livraison spécifique
    $group->get('/{id}', function (Request $request, Response $response, array $args) {
        $pdo = $this->get(PDO::class);
        
        $stmt = $pdo->prepare("
    SELECT 
        bl.*,
        cmd.reference as commande_reference,
        cmd.quantite_commandee,
        cmd.quantite_restante,
        cl.nom as client_nom, 
        cl.prenom as client_prenom,
        cl.entreprise as client_entreprise,
        cl.telephone as client_telephone,
        ch.nom_chantier,
        ch.adresse as chantier_adresse,
        ch.ville as chantier_ville,
        ch.code_postal as chantier_code_postal,
        fb.id as formule_id,
        fb.nom as formule_nom,
        fb.description as formule_description,
        fb.prix_metre_cube as formule_prix,
        chf.nom as chauffeur_nom,
        chf.prenom as chauffeur_prenom,
        chf.telephone as chauffeur_telephone,
        cam.plaque_immatriculation,
        cam.modele as camion_modele,
        cam.capacite as camion_capacite,
        op.nom as operateur_nom,
        op.prenom as operateur_prenom
    FROM bons_livraison bl
    INNER JOIN commandes cmd ON bl.commande_id = cmd.id
    INNER JOIN clients cl ON cmd.client_id = cl.id
    INNER JOIN chantiers ch ON cmd.chantier_id = ch.id
    INNER JOIN formules_beton fb ON bl.formule_beton_id = fb.id
    LEFT JOIN chauffeurs chf ON bl.chauffeur_id = chf.id
    LEFT JOIN camions cam ON bl.camion_id = cam.id
    INNER JOIN operateurs op ON bl.operateur_id = op.id
    WHERE bl.id = ?
");
        $stmt->execute([$args['id']]);
        $livraison = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$livraison) {
            $response->getBody()->write(json_encode(['error' => 'Bon de livraison non trouvé']));
            return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
        }

        $response->getBody()->write(json_encode($livraison));
        return $response->withHeader('Content-Type', 'application/json');
    });

   // Ajouter un bon de livraison
$group->post('', function (Request $request, Response $response) {
    $pdo = $this->get(PDO::class);
    $params = (array) $request->getParsedBody();

    // Validation des champs obligatoires
    $requiredFields = ['commande_id', 'chauffeur_id', 'camion_id', 'operateur_id', 'formule_beton_id', 'quantite_chargee', 'date_production'];
    foreach ($requiredFields as $field) {
        if (empty($params[$field])) {
            $response->getBody()->write(json_encode(['error' => "Le champ $field est obligatoire"]));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }
    }

    try {
        $pdo->beginTransaction();

        // Vérifier si la commande existe et a assez de quantité restante
        $stmt = $pdo->prepare("
            SELECT c.quantite_restante, fb.id as formule_id, fb.nom as formule_nom
            FROM commandes c
            INNER JOIN formules_beton fb ON c.formule_beton_id = fb.id
            WHERE c.id = ?
        ");
        $stmt->execute([$params['commande_id']]);
        $commande = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$commande) {
            $response->getBody()->write(json_encode(['error' => 'Commande non trouvée']));
            return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
        }

        // Vérifier si la formule sélectionnée correspond à celle de la commande
        if ($commande['formule_id'] != $params['formule_beton_id']) {
            $response->getBody()->write(json_encode([
                'error' => 'La formule de béton ne correspond pas à celle de la commande',
                'formule_commande' => $commande['formule_nom'],
                'formule_commande_id' => $commande['formule_id']
            ]));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }

        if ($commande['quantite_restante'] < $params['quantite_chargee']) {
            $response->getBody()->write(json_encode([
                'error' => 'Quantité insuffisante dans la commande',
                'quantite_restante' => $commande['quantite_restante'],
                'quantite_demandee' => $params['quantite_chargee']
            ]));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }

        // Calculer la quantité totale livrée pour cette commande
        $stmt = $pdo->prepare("
            SELECT COALESCE(SUM(quantite_chargee), 0) as total_livre 
            FROM bons_livraison 
            WHERE commande_id = ? AND statut = 'livre'
        ");
        $stmt->execute([$params['commande_id']]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $quantite_total_livree = $result['total_livre'] + $params['quantite_chargee'];

        // Générer une référence unique
        $reference = 'BL-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -6));

        // Insérer le bon de livraison
        $stmt = $pdo->prepare("
            INSERT INTO bons_livraison 
            (commande_id, chauffeur_id, camion_id, operateur_id, formule_beton_id, reference, 
             quantite_chargee, quantite_total_livree, date_production, 
             heure_depart, heure_arrivee, statut, notes) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        
        $stmt->execute([
            $params['commande_id'],
            $params['chauffeur_id'],
            $params['camion_id'],
            $params['operateur_id'],
            $params['formule_beton_id'],
            $reference,
            $params['quantite_chargee'],
            $quantite_total_livree,
            $params['date_production'],
            $params['heure_depart'] ?? null,
            $params['heure_arrivee'] ?? null,
            $params['statut'] ?? 'planifie',
            $params['notes'] ?? null
        ]);

        $livraisonId = $pdo->lastInsertId();

        // Mettre à jour la quantité restante de la commande
        $nouvelle_quantite_restante = $commande['quantite_restante'] - $params['quantite_chargee'];
        $stmt = $pdo->prepare("UPDATE commandes SET quantite_restante = ? WHERE id = ?");
        $stmt->execute([$nouvelle_quantite_restante, $params['commande_id']]);

        // Mettre à jour le statut de la commande si nécessaire
        if ($nouvelle_quantite_restante <= 0) {
            $stmt = $pdo->prepare("UPDATE commandes SET statut = 'livree' WHERE id = ?");
            $stmt->execute([$params['commande_id']]);
        } else {
            $stmt = $pdo->prepare("UPDATE commandes SET statut = 'en_cours' WHERE id = ? AND statut = 'en_attente'");
            $stmt->execute([$params['commande_id']]);
        }

        // Mettre à jour l'état du camion
        $stmt = $pdo->prepare("UPDATE camions SET etat = 'en_livraison' WHERE id = ?");
        $stmt->execute([$params['camion_id']]);

        $pdo->commit();

        $response->getBody()->write(json_encode([
            'message' => 'Bon de livraison créé avec succès',
            'id' => $livraisonId,
            'reference' => $reference,
            'quantite_restante' => $nouvelle_quantite_restante,
            'formule_beton_id' => $params['formule_beton_id']
        ]));
        return $response->withStatus(201)->withHeader('Content-Type', 'application/json');

    } catch (PDOException $e) {
        $pdo->rollBack();
        $response->getBody()->write(json_encode(['error' => 'Erreur lors de la création du bon de livraison: ' . $e->getMessage()]));
        return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
    }
});

    // Modifier un bon de livraison
    $group->put('/{id}', function (Request $request, Response $response, array $args) {
        $pdo = $this->get(PDO::class);
        $params = (array) $request->getParsedBody();

        // Vérifier si le bon de livraison existe
        $stmt = $pdo->prepare("SELECT * FROM bons_livraison WHERE id = ?");
        $stmt->execute([$args['id']]);
        $livraison = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$livraison) {
            $response->getBody()->write(json_encode(['error' => 'Bon de livraison non trouvé']));
            return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
        }

        try {
            $stmt = $pdo->prepare("
                UPDATE bons_livraison 
                SET heure_depart = ?, heure_arrivee = ?, statut = ?, notes = ?
                WHERE id = ?
            ");
            $stmt->execute([
                $params['heure_depart'] ?? $livraison['heure_depart'],
                $params['heure_arrivee'] ?? $livraison['heure_arrivee'],
                $params['statut'] ?? $livraison['statut'],
                $params['notes'] ?? $livraison['notes'],
                $args['id']
            ]);

            // Si le statut est passé à 'livre', mettre à jour l'état du camion
            if (($params['statut'] ?? $livraison['statut']) === 'livre') {
                $stmt = $pdo->prepare("UPDATE camions SET etat = 'disponible' WHERE id = ?");
                $stmt->execute([$livraison['camion_id']]);
            }

            $response->getBody()->write(json_encode(['message' => 'Bon de livraison mis à jour avec succès']));
            return $response->withHeader('Content-Type', 'application/json');

        } catch (PDOException $e) {
            $response->getBody()->write(json_encode(['error' => 'Erreur lors de la mise à jour: ' . $e->getMessage()]));
            return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
        }
    });

    // Changer le statut d'un bon de livraison
    $group->patch('/{id}/statut', function (Request $request, Response $response, array $args) {
        $pdo = $this->get(PDO::class);
        $params = (array) $request->getParsedBody();

        if (empty($params['statut'])) {
            $response->getBody()->write(json_encode(['error' => 'Le champ statut est obligatoire']));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }

        $statutsValides = ['planifie', 'en_route', 'livre', 'annule'];
        if (!in_array($params['statut'], $statutsValides)) {
            $response->getBody()->write(json_encode(['error' => 'Statut invalide']));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }

        try {
            $stmt = $pdo->prepare("SELECT camion_id FROM bons_livraison WHERE id = ?");
            $stmt->execute([$args['id']]);
            $livraison = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$livraison) {
                $response->getBody()->write(json_encode(['error' => 'Bon de livraison non trouvé']));
                return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
            }

            $stmt = $pdo->prepare("UPDATE bons_livraison SET statut = ? WHERE id = ?");
            $stmt->execute([$params['statut'], $args['id']]);

            // Mettre à jour l'état du camion
            $nouvel_etat = $params['statut'] === 'livre' ? 'disponible' : 'en_livraison';
            $stmt = $pdo->prepare("UPDATE camions SET etat = ? WHERE id = ?");
            $stmt->execute([$nouvel_etat, $livraison['camion_id']]);

            $response->getBody()->write(json_encode(['message' => 'Statut du bon de livraison mis à jour avec succès']));
            return $response->withHeader('Content-Type', 'application/json');

        } catch (PDOException $e) {
            $response->getBody()->write(json_encode(['error' => 'Erreur lors de la mise à jour: ' . $e->getMessage()]));
            return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
        }
    });

    // Rechercher des bons de livraison
    $group->get('/search/{term}', function (Request $request, Response $response, array $args) {
        $pdo = $this->get(PDO::class);
        $term = '%' . $args['term'] . '%';
        
        $stmt = $pdo->prepare("
            SELECT 
                bl.*,
                cmd.reference as commande_reference,
                cl.nom as client_nom, 
                cl.prenom as client_prenom,
                ch.nom_chantier,
                chf.nom as chauffeur_nom,
                chf.prenom as chauffeur_prenom
            FROM bons_livraison bl
            INNER JOIN commandes cmd ON bl.commande_id = cmd.id
            INNER JOIN clients cl ON cmd.client_id = cl.id
            INNER JOIN chantiers ch ON cmd.chantier_id = ch.id
            LEFT JOIN chauffeurs chf ON bl.chauffeur_id = chf.id
            WHERE bl.reference LIKE ? OR cl.nom LIKE ? OR cl.prenom LIKE ? OR chf.nom LIKE ? OR chf.prenom LIKE ?
            ORDER BY bl.date_livraison DESC
        ");
        $stmt->execute([$term, $term, $term, $term, $term]);
        $livraisons = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $response->getBody()->write(json_encode($livraisons));
        return $response->withHeader('Content-Type', 'application/json');
    });

   

    // Statistiques des livraisons
    $group->get('/stats/{periode}', function (Request $request, Response $response, array $args) {
        $pdo = $this->get(PDO::class);
        $periode = $args['periode']; // 'jour', 'semaine', 'mois', 'annee'
        
        $sql = "
            SELECT 
                DATE(date_production) as date,
                COUNT(*) as nombre_livraisons,
                SUM(quantite_chargee) as total_metres_cubes,
                AVG(quantite_chargee) as moyenne_metres_cubes
            FROM bons_livraison
            WHERE date_production >= ? AND statut = 'livre'
            GROUP BY DATE(date_production)
            ORDER BY date DESC
        ";
        
        $dateDebut = '';
        switch ($periode) {
            case 'jour':
                $dateDebut = date('Y-m-d');
                break;
            case 'semaine':
                $dateDebut = date('Y-m-d', strtotime('-1 week'));
                break;
            case 'mois':
                $dateDebut = date('Y-m-d', strtotime('-1 month'));
                break;
            case 'annee':
                $dateDebut = date('Y-m-d', strtotime('-1 year'));
                break;
            default:
                $response->getBody()->write(json_encode(['error' => 'Période invalide']));
                return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$dateDebut]);
        $stats = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $response->getBody()->write(json_encode($stats));
        return $response->withHeader('Content-Type', 'application/json');
    });
};