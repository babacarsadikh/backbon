<?php
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use App\Application\Reports\ReportExporter;

$rows = [];
for ($i = 1; $i <= 55; $i++) {
    $rows[] = ['heure_depart'=>'2026-09-04 '.sprintf('%02d:%02d:00', 6+($i%12), $i%60),'bon_reference'=>'BL-'.str_pad((string)$i,5,'0',STR_PAD_LEFT),'commande_reference'=>'CMD-20260904-'.sprintf('%03d',$i%9),'client'=>'Client béton '.(($i%8)+1),'chantier'=>'Chantier '.(($i%12)+1).' - Dakar','chauffeur'=>'Chauffeur '.(($i%7)+1),'quantite_chargee'=>10.5];
}
$report = ['periode'=>['debut'=>'2026-09-04T00:00:00+00:00','fin'=>'2026-09-04T23:59:59+00:00'],'indicateurs'=>['nombre_commandes'=>9,'nombre_livraisons'=>55,'nombre_clients'=>8,'quantite_commandee'=>650,'quantite_livree'=>577.5,'quantite_restante'=>72.5],'livraisons'=>$rows];
(new ReportExporter())->export($report, dirname(__DIR__, 2).'/tmp/pdfs', 'rapport-journalier-fixture');
