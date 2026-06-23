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

    public function status(Request $request, Response $response): Response
    {
        $wgStats = $this->parseWgDump();
        $dbPeers = $this->db->query("SELECT id, public_key FROM peers WHERE enabled = 1");

        $now    = time();
        $result = [];
        $connected = 0;

        foreach ($dbPeers as $peer) {
            $stat = $wgStats[$peer['public_key']] ?? null;
            $hs   = $stat ? (int) $stat['handshake'] : 0;
            $isConnected = $stat && $hs > 0 && ($now - $hs) < 180;
            if ($isConnected) $connected++;

            $result[$peer['public_key']] = [
                'connected'     => $isConnected,
                'handshake_ago' => $stat && $hs > 0 ? $this->humanAgo($hs) : null,
                'endpoint'      => $stat['endpoint'] ?? null,
                'rx'            => $stat['rx'] ?? null,
                'tx'            => $stat['tx'] ?? null,
            ];
        }

        $response->getBody()->write(json_encode([
            'connected_count' => $connected,
            'peers'           => $result,
        ]));
        return $response->withHeader('Content-Type', 'application/json');
    }

    public function index(Request $request, Response $response): Response
    {
        $totalPeers     = $this->db->queryOne("SELECT COUNT(*) as c FROM peers")['c'] ?? 0;
        $totalZones     = $this->db->queryOne("SELECT COUNT(*) as c FROM zones")['c'] ?? 0;
        $totalProfiles  = $this->db->queryOne("SELECT COUNT(*) as c FROM profiles")['c'] ?? 0;
        $enabledPeers   = $this->db->queryOne("SELECT COUNT(*) as c FROM peers WHERE enabled = 1")['c'] ?? 0;

        $dbPeers = $this->db->query(
            "SELECT p.*, z.name as zone_name FROM peers p 
             LEFT JOIN zones z ON z.id = p.zone_id 
             ORDER BY p.name"
        );

        $wgStats = $this->parseWgDump();

        $peers = array_map(function (array $peer) use ($wgStats): array {
            $stat = $wgStats[$peer['public_key']] ?? null;
            $peer['wg_endpoint']      = $stat['endpoint']   ?? null;
            $peer['wg_rx']            = $stat['rx']         ?? null;
            $peer['wg_tx']            = $stat['tx']         ?? null;
            $peer['wg_handshake_ts']  = $stat['handshake']  ?? 0;
            $peer['wg_connected']     = $stat ? ($stat['handshake'] > 0 && (time() - $stat['handshake']) < 180) : false;
            $peer['wg_handshake_ago'] = $stat && $stat['handshake'] > 0
                ? $this->humanAgo((int) $stat['handshake'])
                : null;
            return $peer;
        }, $dbPeers);

        $connectedCount = count(array_filter($peers, fn($p) => $p['wg_connected']));

        return $this->view->render($response, 'dashboard/index.twig', [
            'total_peers'     => $totalPeers,
            'total_zones'     => $totalZones,
            'total_profiles'  => $totalProfiles,
            'enabled_peers'   => $enabledPeers,
            'connected_peers' => $connectedCount,
            'peers'           => $peers,
        ]);
    }

    private function parseWgDump(): array
    {
        $out = shell_exec('wg show wg0 dump 2>/dev/null');
        if (!$out) return [];

        $result = [];
        $lines  = explode("\n", trim($out));
        array_shift($lines);

        foreach ($lines as $line) {
            if ($line === '') continue;
            $f = explode("\t", $line);
            if (count($f) < 7) continue;
            $pubkey    = $f[0];
            $endpoint  = ($f[2] !== '(none)') ? $f[2] : null;
            $handshake = (int) $f[4];
            $rx        = (int) $f[5];
            $tx        = (int) $f[6];
            $result[$pubkey] = [
                'endpoint'  => $endpoint,
                'handshake' => $handshake,
                'rx'        => $rx,
                'tx'        => $tx,
            ];
        }

        return $result;
    }

    private function humanAgo(int $ts): string
    {
        $diff = time() - $ts;
        if ($diff < 60)   return $diff . 's ago';
        if ($diff < 3600) return floor($diff / 60) . 'm ago';
        if ($diff < 86400) return floor($diff / 3600) . 'h ago';
        return floor($diff / 86400) . 'd ago';
    }

    private function humanBytes(int $bytes): string
    {
        if ($bytes < 1024)       return $bytes . ' B';
        if ($bytes < 1048576)    return round($bytes / 1024, 1) . ' KB';
        if ($bytes < 1073741824) return round($bytes / 1048576, 1) . ' MB';
        return round($bytes / 1073741824, 2) . ' GB';
    }
}
