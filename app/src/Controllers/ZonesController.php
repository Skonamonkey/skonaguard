<?php

declare(strict_types=1);

namespace SkonaGuard\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
use SkonaGuard\Models\Database;
use SkonaGuard\Services\DnsService;

class ZonesController
{
    public function __construct(
        private Twig $view,
        private Database $db,
        private DnsService $dns
    ) {}

    public function index(Request $request, Response $response): Response
    {
        $zones = $this->db->query("SELECT z.*, COUNT(p.id) as peer_count FROM zones z LEFT JOIN peers p ON p.zone_id = z.id GROUP BY z.id ORDER BY z.name");
        return $this->view->render($response, 'zones/index.twig', [
            'active_nav' => 'zones',
            'zones'      => $zones,
        ]);
    }

    public function store(Request $request, Response $response): Response
    {
        $body    = (array) $request->getParsedBody();
        $name    = trim($body['name'] ?? '');
        $subnet  = trim($body['subnet'] ?? '');
        $desc    = trim($body['description'] ?? '');
        $dnsName = strtolower(trim($body['dns_name'] ?? ''));

        if (!$name || !$subnet) {
            $_SESSION['flash_error'] = 'Name and subnet are required.';
            return $response->withHeader('Location', '/zones')->withStatus(302);
        }

        if (!$this->validCidr($subnet)) {
            $_SESSION['flash_error'] = 'Invalid subnet format. Use CIDR notation (e.g. 172.16.1.0/24).';
            return $response->withHeader('Location', '/zones')->withStatus(302);
        }

        try {
            $this->db->execute(
                "INSERT INTO zones (name, subnet, description, dns_name) VALUES (?, ?, ?, ?)",
                [$name, $subnet, $desc ?: null, $dnsName ?: null]
            );
            $_SESSION['flash_success'] = "Zone \"{$name}\" created.";
            try { $this->dns->generateHostsFile(); } catch (\Throwable) {}
        } catch (\Exception $e) {
            $_SESSION['flash_error'] = 'A zone with that name or subnet already exists.';
        }

        return $response->withHeader('Location', '/zones')->withStatus(302);
    }

    public function update(Request $request, Response $response, string $id): Response
    {
        $zone = $this->db->queryOne("SELECT * FROM zones WHERE id = ?", [$id]);
        if ($zone && !empty($zone['is_system'])) {
            $_SESSION['flash_error'] = "System zone \"{$zone['name']}\" cannot be modified.";
            return $response->withHeader('Location', '/zones')->withStatus(302);
        }

        $body    = (array) $request->getParsedBody();
        $name    = trim($body['name'] ?? '');
        $subnet  = trim($body['subnet'] ?? '');
        $desc    = trim($body['description'] ?? '');
        $dnsName = strtolower(trim($body['dns_name'] ?? ''));

        if (!$name || !$subnet) {
            $_SESSION['flash_error'] = 'Name and subnet are required.';
            return $response->withHeader('Location', '/zones')->withStatus(302);
        }

        if (!$this->validCidr($subnet)) {
            $_SESSION['flash_error'] = 'Invalid subnet format.';
            return $response->withHeader('Location', '/zones')->withStatus(302);
        }

        try {
            $this->db->execute(
                "UPDATE zones SET name = ?, subnet = ?, description = ?, dns_name = ? WHERE id = ?",
                [$name, $subnet, $desc ?: null, $dnsName ?: null, $id]
            );
            $_SESSION['flash_success'] = "Zone updated.";
            try { $this->dns->generateHostsFile(); } catch (\Throwable) {}
        } catch (\Exception $e) {
            $_SESSION['flash_error'] = 'A zone with that name or subnet already exists.';
        }

        return $response->withHeader('Location', '/zones')->withStatus(302);
    }

    public function destroy(Request $request, Response $response, string $id): Response
    {
        $zone = $this->db->queryOne("SELECT * FROM zones WHERE id = ?", [$id]);
        if (!$zone) {
            return $response->withHeader('Location', '/zones')->withStatus(302);
        }

        if (!empty($zone['is_system'])) {
            $_SESSION['flash_error'] = "System zone \"{$zone['name']}\" cannot be deleted.";
            return $response->withHeader('Location', '/zones')->withStatus(302);
        }

        $peerCount = (int) ($this->db->queryOne("SELECT COUNT(*) as c FROM peers WHERE zone_id = ?", [$id])['c'] ?? 0);
        if ($peerCount > 0) {
            $_SESSION['flash_error'] = "Cannot delete zone \"{$zone['name']}\" — it has {$peerCount} peer(s) assigned.";
            return $response->withHeader('Location', '/zones')->withStatus(302);
        }

        $this->db->execute("DELETE FROM zones WHERE id = ?", [$id]);
        $_SESSION['flash_success'] = "Zone \"{$zone['name']}\" deleted.";
        return $response->withHeader('Location', '/zones')->withStatus(302);
    }

    private function validCidr(string $cidr): bool
    {
        if (!str_contains($cidr, '/')) return false;
        [$ip, $prefix] = explode('/', $cidr, 2);
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false
            && ctype_digit($prefix)
            && (int) $prefix >= 8
            && (int) $prefix <= 30;
    }
}
