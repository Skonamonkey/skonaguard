<?php

declare(strict_types=1);

namespace SkonaGuard\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
use SkonaGuard\Models\Database;

class DashboardController
{
    public function __construct(
        private Twig $view,
        private Database $db
    ) {}

    public function index(Request $req, Response $res): Response
    {
        $totalPeers     = $this->db->queryOne("SELECT COUNT(*) as c FROM peers")['c'] ?? 0;
        $totalZones     = $this->db->queryOne("SELECT COUNT(*) as c FROM zones")['c'] ?? 0;
        $totalProfiles  = $this->db->queryOne("SELECT COUNT(*) as c FROM profiles")['c'] ?? 0;
        $enabledPeers   = $this->db->queryOne("SELECT COUNT(*) as c FROM peers WHERE enabled = 1")['c'] ?? 0;

        $recentPeers = $this->db->query(
            "SELECT p.*, z.name as zone_name FROM peers p 
             LEFT JOIN zones z ON z.id = p.zone_id 
             ORDER BY p.created_at DESC LIMIT 5"
        );

        return $this->view->render($res, 'dashboard/index.twig', [
            'total_peers'    => $totalPeers,
            'total_zones'    => $totalZones,
            'total_profiles' => $totalProfiles,
            'enabled_peers'  => $enabledPeers,
            'recent_peers'   => $recentPeers,
        ]);
    }
}
