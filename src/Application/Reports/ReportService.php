<?php

declare(strict_types=1);

namespace App\Application\Reports;

use DateTimeImmutable;
use DateTimeZone;
use PDO;

final class ReportService
{
    public function __construct(private PDO $pdo) {}

    public function build(array $filters): array
    {
        $timezone = new DateTimeZone('Africa/Dakar');
        $start = new DateTimeImmutable((string)($filters['date_debut'] ?? 'today'), $timezone);
        $endInput = (string)($filters['date_fin'] ?? $start->format('Y-m-d'));
        $end = new DateTimeImmutable($endInput, $timezone);
        $from = $start->setTime(0, 0, 0);
        $to = $end->setTime(23, 59, 59);

        $where = ['bl.date_production >= ?', 'bl.date_production < ?', "bl.statut = 'livre'"];
        $params = [$from->format('Y-m-d'), $to->modify('+1 second')->format('Y-m-d')];
        foreach (['client_id' => 'cmd.client_id', 'chantier_id' => 'cmd.chantier_id', 'chauffeur_id' => 'bl.chauffeur_id', 'commande_id' => 'bl.commande_id'] as $key => $column) {
            if (isset($filters[$key]) && $filters[$key] !== '') {
                $where[] = $column . ' = ?';
                $params[] = (int)$filters[$key];
            }
        }

        $sql = "SELECT bl.id, bl.reference bon_reference, bl.heure_depart, bl.quantite_chargee,
                       cmd.id commande_id, cmd.reference commande_reference, cmd.quantite_commandee,
                       cl.id client_id, COALESCE(NULLIF(cl.entreprise,''), CONCAT_WS(' ',cl.prenom,cl.nom)) client,
                       ch.id chantier_id, ch.nom_chantier chantier,
                       chf.id chauffeur_id, CONCAT_WS(' ',chf.prenom,chf.nom) chauffeur
                FROM bons_livraison bl
                JOIN commandes cmd ON cmd.id=bl.commande_id
                JOIN clients cl ON cl.id=cmd.client_id
                LEFT JOIN chantiers ch ON ch.id=cmd.chantier_id
                LEFT JOIN chauffeurs chf ON chf.id=bl.chauffeur_id
                WHERE " . implode(' AND ', $where) . " ORDER BY bl.heure_depart ASC, bl.id ASC";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        $orders = $clients = $sites = $drivers = [];
        $delivered = 0.0;
        foreach ($rows as $row) {
            $delivered += (float)$row['quantite_chargee'];
            $orders[(int)$row['commande_id']] = (float)$row['quantite_commandee'];
            $clients[(int)$row['client_id']] = $row['client'];
            if ($row['chantier_id']) $sites[(int)$row['chantier_id']] = $row['chantier'];
            $driverId = (int)($row['chauffeur_id'] ?? 0);
            $drivers[$driverId] ??= ['nom' => $row['chauffeur'] ?: 'Non renseigné', 'rotations' => 0, 'quantite' => 0.0];
            $drivers[$driverId]['rotations']++;
            $drivers[$driverId]['quantite'] += (float)$row['quantite_chargee'];
        }
        $ordered = array_sum($orders);
        $remaining = 0.0;
        if ($orders) {
            $placeholders = implode(',', array_fill(0, count($orders), '?'));
            $totals = $this->pdo->prepare(
                "SELECT cmd.id, cmd.quantite_commandee,
                        COALESCE(SUM(CASE WHEN bl.statut='livre' THEN bl.quantite_chargee ELSE 0 END),0) quantite_livree
                 FROM commandes cmd LEFT JOIN bons_livraison bl ON bl.commande_id=cmd.id
                 WHERE cmd.id IN ($placeholders) GROUP BY cmd.id, cmd.quantite_commandee"
            );
            $totals->execute(array_keys($orders));
            foreach ($totals->fetchAll() as $total) {
                $remaining += max(0, (float)$total['quantite_commandee'] - (float)$total['quantite_livree']);
            }
        }

        return [
            'periode' => ['debut' => $from->format(DATE_ATOM), 'fin' => $to->format(DATE_ATOM)],
            'indicateurs' => [
                'nombre_commandes' => count($orders), 'nombre_livraisons' => count($rows),
                'nombre_clients' => count($clients), 'quantite_commandee' => round($ordered, 2),
                'quantite_livree' => round($delivered, 2), 'quantite_restante' => round($remaining, 2),
            ],
            'clients' => array_values($clients), 'chantiers' => array_values($sites),
            'chauffeurs' => array_values($drivers), 'livraisons' => $rows,
        ];
    }
}
