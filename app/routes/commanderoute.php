<?php

declare(strict_types=1);

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Interfaces\RouteCollectorProxyInterface as Group;

return function (Group $group) {
    // Lire tous les bons de commande avec pagination
    $group->get('', function (Request $request, Response $response) {
        $pdo = $this->get(PDO::class);
        $queryParams = $request->getQueryParams();
        
        $page = max(1, (int)($queryParams['page'] ?? 1));
        $limit = min(100, max(1, (int)($queryParams['limit'] ?? 20)));
        $offset = ($page - 1) * $limit;
        
        // Compter le total
        $stmt = $pdo->query("SELECT COUNT(*) as total FROM commandes");
        $total = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
        
        // Récupérer les commandes avec les informations liées
        $stmt = $pdo->prepare("
            SELECT 
                cmd.*,
                cl.nom as client_nom, 
                cl.prenom as client_prenom,
                cl.entreprise as client_entreprise,
                ch.nom_chantier,
                ch.adresse as chantier_adresse,
                fb.nom as formule_nom,
                fb.prix_metre_cube as formule_prix,
                op.nom as operateur_nom,
                op.prenom as operateur_prenom
            FROM commandes cmd
            INNER JOIN clients cl ON cmd.client_id = cl.id
            INNER JOIN chantiers ch ON cmd.chantier_id = ch.id
            INNER JOIN formules_beton fb ON cmd.formule_beton_id = fb.id
            INNER JOIN operateurs op ON cmd.operateur_id = op.id
            ORDER BY cmd.date_commande DESC
            LIMIT ? OFFSET ?
        ");
        $stmt->execute([$limit, $offset]);
        $commandes = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $response->getBody()->write(json_encode([
            'commandes' => $commandes,
            'pagination' => [
                'page' => $page,
                'limit' => $limit,
                'total' => (int)$total,
                'pages' => ceil($total / $limit)
            ]
        ]));
        return $response->withHeader('Content-Type', 'application/json');
    });

    // Lire les commandes d'un client spécifique
    $group->get('/client/{client_id}', function (Request $request, Response $response, array $args) {
        $pdo = $this->get(PDO::class);
        
        $stmt = $pdo->prepare("
            SELECT 
                cmd.*,
                ch.nom_chantier,
                ch.adresse as chantier_adresse,
                fb.nom as formule_nom,
                fb.prix_metre_cube as formule_prix,
                op.nom as operateur_nom,
                op.prenom as operateur_prenom
            FROM commandes cmd
            INNER JOIN chantiers ch ON cmd.chantier_id = ch.id
            INNER JOIN formules_beton fb ON cmd.formule_beton_id = fb.id
            INNER JOIN operateurs op ON cmd.operateur_id = op.id
            WHERE cmd.client_id = ?
            ORDER BY cmd.date_commande DESC
        ");
        $stmt->execute([$args['client_id']]);
        $commandes = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $response->getBody()->write(json_encode($commandes));
        return $response->withHeader('Content-Type', 'application/json');
    });

    // Lire une commande spécifique
    $group->get('/{id}', function (Request $request, Response $response, array $args) {
        $pdo = $this->get(PDO::class);
        
        $stmt = $pdo->prepare("
            SELECT 
                cmd.*,
                cl.nom as client_nom, 
                cl.prenom as client_prenom,
                cl.entreprise as client_entreprise,
                cl.telephone as client_telephone,
                cl.email as client_email,
                ch.nom_chantier,
                ch.adresse as chantier_adresse,
                ch.ville as chantier_ville,
                ch.code_postal as chantier_code_postal,
                fb.nom as formule_nom,
                fb.description as formule_description,
                fb.prix_metre_cube as formule_prix,
                op.nom as operateur_nom,
                op.prenom as operateur_prenom
            FROM commandes cmd
            INNER JOIN clients cl ON cmd.client_id = cl.id
            INNER JOIN chantiers ch ON cmd.chantier_id = ch.id
            INNER JOIN formules_beton fb ON cmd.formule_beton_id = fb.id
            INNER JOIN operateurs op ON cmd.operateur_id = op.id
            WHERE cmd.id = ?
        ");
        $stmt->execute([$args['id']]);
        $commande = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$commande) {
            $response->getBody()->write(json_encode(['error' => 'Commande non trouvée']));
            return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
        }

        // Récupérer les livraisons associées à cette commande
        $stmt = $pdo->prepare("
            SELECT 
                bl.*,
                chf.nom as chauffeur_nom,
                chf.prenom as chauffeur_prenom,
                cam.plaque_immatriculation
            FROM bons_livraison bl
            LEFT JOIN chauffeurs chf ON bl.chauffeur_id = chf.id
            LEFT JOIN camions cam ON bl.camion_id = cam.id
            WHERE bl.commande_id = ?
            ORDER BY bl.date_livraison DESC
        ");
        $stmt->execute([$args['id']]);
        $livraisons = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $commande['livraisons'] = $livraisons;

        // Calculer la quantité livrée et restante
        $quantite_livree = 0;
        foreach ($livraisons as $livraison) {
            if ($livraison['statut'] === 'livre') {
                $quantite_livree += (float)$livraison['quantite_chargee'];
            }
        }
        
        $commande['quantite_livree'] = $quantite_livree;
        $commande['quantite_restante'] = (float)$commande['quantite_commandee'] - $quantite_livree;
        $commande['pourcentage_livre'] = $commande['quantite_commandee'] > 0 ? 
            round(($quantite_livree / $commande['quantite_commandee']) * 100, 2) : 0;

        $response->getBody()->write(json_encode($commande));
        return $response->withHeader('Content-Type', 'application/json');
    });

    // Ajouter un bon de commande
    $group->post('', function (Request $request, Response $response) {
        $pdo = $this->get(PDO::class);
        $params = (array) $request->getParsedBody();

        // Validation des champs obligatoires
        $requiredFields = ['client_id', 'formule_beton_id', 'quantite_commandee'];
        foreach ($requiredFields as $field) {
            if (empty($params[$field])) {
                $response->getBody()->write(json_encode(['error' => "Le champ $field est obligatoire"]));
                return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
            }
        }

       // Dans la route POST /commandes
try {
    $pdo->beginTransaction();

    // Générer une référence unique basée sur la date et l'ID
    $reference = 'CMD-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -6));

    // Insérer la commande
    $stmt = $pdo->prepare("
        INSERT INTO commandes 
        (client_id, chantier_id, formule_beton_id, operateur_id, reference, 
         quantite_commandee, quantite_restante, date_livraison_prevue, statut, notes) 
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    
    $quantite_restante = $params['quantite_commandee'];
    
    $stmt->execute([
        $params['client_id'],
        $params['chantier_id'],
        $params['formule_beton_id'],
        $params['operateur_id'],
        $reference,
        $params['quantite_commandee'],
        $quantite_restante,
        $params['date_livraison_prevue'] ?? null,
        $params['statut'] ?? 'en_attente',
        $params['notes'] ?? null
    ]);

    $commandeId = $pdo->lastInsertId();

    $pdo->commit();

    $response->getBody()->write(json_encode([
        'message' => 'Bon de commande créé avec succès',
        'id' => $commandeId,
        'reference' => $reference
    ]));
    return $response->withStatus(201)->withHeader('Content-Type', 'application/json');

} catch (PDOException $e) {
    $pdo->rollBack();
    $response->getBody()->write(json_encode(['error' => 'Erreur lors de la création de la commande: ' . $e->getMessage()]));
    return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
}
    });

    // Modifier un bon de commande
    $group->put('/{id}', function (Request $request, Response $response, array $args) {
        $pdo = $this->get(PDO::class);
        $params = (array) $request->getParsedBody();

        // Vérifier si la commande existe
        $stmt = $pdo->prepare("SELECT id, statut FROM commandes WHERE id = ?");
        $stmt->execute([$args['id']]);
        $commande = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$commande) {
            $response->getBody()->write(json_encode(['error' => 'Commande non trouvée']));
            return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
        }

        // Ne pas permettre la modification si des livraisons sont en cours
        if ($commande['statut'] === 'en_cours') {
            $response->getBody()->write(json_encode([
                'error' => 'Impossible de modifier une commande en cours de livraison'
            ]));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }

        try {
            $stmt = $pdo->prepare("
                UPDATE commandes 
                SET date_livraison_prevue = ?, statut = ?, notes = ?
                WHERE id = ?
            ");
            $stmt->execute([
                $params['date_livraison_prevue'] ?? null,
                $params['statut'] ?? 'en_attente',
                $params['notes'] ?? null,
                $args['id']
            ]);

            $response->getBody()->write(json_encode(['message' => 'Commande mise à jour avec succès']));
            return $response->withHeader('Content-Type', 'application/json');

        } catch (PDOException $e) {
            $response->getBody()->write(json_encode(['error' => 'Erreur lors de la mise à jour: ' . $e->getMessage()]));
            return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
        }
    });

    // Changer le statut d'une commande
    $group->patch('/{id}/statut', function (Request $request, Response $response, array $args) {
        $pdo = $this->get(PDO::class);
        $params = (array) $request->getParsedBody();

        if (empty($params['statut'])) {
            $response->getBody()->write(json_encode(['error' => 'Le champ statut est obligatoire']));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }

        $statutsValides = ['en_attente', 'en_cours', 'livree', 'annulee'];
        if (!in_array($params['statut'], $statutsValides)) {
            $response->getBody()->write(json_encode(['error' => 'Statut invalide']));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }

        try {
            $stmt = $pdo->prepare("UPDATE commandes SET statut = ? WHERE id = ?");
            $stmt->execute([$params['statut'], $args['id']]);

            if ($stmt->rowCount() === 0) {
                $response->getBody()->write(json_encode(['error' => 'Commande non trouvée']));
                return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
            }

            $response->getBody()->write(json_encode(['message' => 'Statut de la commande mis à jour avec succès']));
            return $response->withHeader('Content-Type', 'application/json');

        } catch (PDOException $e) {
            $response->getBody()->write(json_encode(['error' => 'Erreur lors de la mise à jour: ' . $e->getMessage()]));
            return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
        }
    });

    // Rechercher des commandes
    $group->get('/search/{term}', function (Request $request, Response $response, array $args) {
        $pdo = $this->get(PDO::class);
        $term = '%' . $args['term'] . '%';
        
        $stmt = $pdo->prepare("
            SELECT 
                cmd.*,
                cl.nom as client_nom, 
                cl.prenom as client_prenom,
                cl.entreprise as client_entreprise,
                ch.nom_chantier,
                fb.nom as formule_nom
            FROM commandes cmd
            INNER JOIN clients cl ON cmd.client_id = cl.id
            INNER JOIN chantiers ch ON cmd.chantier_id = ch.id
            INNER JOIN formules_beton fb ON cmd.formule_beton_id = fb.id
            WHERE cmd.reference LIKE ? OR cl.nom LIKE ? OR cl.prenom LIKE ? OR cl.entreprise LIKE ?
            ORDER BY cmd.date_commande DESC
        ");
        $stmt->execute([$term, $term, $term, $term]);
        $commandes = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $response->getBody()->write(json_encode($commandes));
        return $response->withHeader('Content-Type', 'application/json');
    });

    // Statistiques des commandes
    $group->get('/stats/{periode}', function (Request $request, Response $response, array $args) {
        $pdo = $this->get(PDO::class);
        $periode = $args['periode']; // 'jour', 'semaine', 'mois', 'annee'
        
        $sql = "
            SELECT 
                DATE(date_commande) as date,
                COUNT(*) as nombre_commandes,
                SUM(quantite_commandee) as total_metres_cubes,
                AVG(quantite_commandee) as moyenne_metres_cubes
            FROM commandes
            WHERE date_commande >= ?
            GROUP BY DATE(date_commande)
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