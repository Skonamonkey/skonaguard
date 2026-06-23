<?php

declare(strict_types=1);

namespace SkonaGuard\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
use SkonaGuard\Models\Database;
use SkonaGuard\Services\WireGuardService;

class AclController
{
    private const RULE_TYPES = [
        'full'        => 'Full Access',
        'established' => 'Inbound Only',
        'icmp_only'   => 'ICMP / Ping Only',
        'deny'        => 'Deny All',
    ];

    public function __construct(
        private Twig $view,
        private Database $db,
        private WireGuardService $wg
    ) {}

    public function index(Request $request, Response $response): Response
    {
        $rules = $this->db->query("
            SELECT r.*,
                   sz.name as src_zone_name,
                   dz.name as dst_zone_name
            FROM acl_rules r
            LEFT JOIN zones sz ON sz.id = r.src_zone_id
            LEFT JOIN zones dz ON dz.id = r.dst_zone_id
            ORDER BY r.priority ASC, r.id ASC
        ");

        $zones = $this->db->query("SELECT * FROM zones ORDER BY name");

        return $this->view->render($response, 'acls/index.twig', [
            'active_nav' => 'acls',
            'rules'      => $rules,
            'zones'      => $zones,
            'rule_types' => self::RULE_TYPES,
        ]);
    }

    public function store(Request $request, Response $response): Response
    {
        $body       = (array) $request->getParsedBody();
        $name       = trim($body['name'] ?? '');
        $srcZoneId  = ($body['src_zone_id'] ?? '') !== '' ? (int) $body['src_zone_id'] : null;
        $dstZoneId  = ($body['dst_zone_id'] ?? '') !== '' ? (int) $body['dst_zone_id'] : null;
        $srcIp      = trim($body['src_ip_override'] ?? '');
        $dstIp      = trim($body['dst_ip_override'] ?? '');
        $ruleType   = array_key_exists($body['rule_type'] ?? '', self::RULE_TYPES) ? $body['rule_type'] : 'full';
        $action     = $ruleType === 'deny' ? 'DROP' : 'ACCEPT';
        $priority   = max(1, min(999, (int) ($body['priority'] ?? 100)));

        if (!$name) {
            $_SESSION['flash_error'] = 'Rule name is required.';
            return $response->withHeader('Location', '/acls')->withStatus(302);
        }

        $this->db->execute("
            INSERT INTO acl_rules (name, src_zone_id, dst_zone_id, src_ip_override, dst_ip_override, action, rule_type, priority)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ", [$name, $srcZoneId, $dstZoneId, $srcIp ?: null, $dstIp ?: null, $action, $ruleType, $priority]);

        $this->wg->syncAcl();
        $_SESSION['flash_success'] = "Rule \"{$name}\" created.";
        return $response->withHeader('Location', '/acls')->withStatus(302);
    }

    public function update(Request $request, Response $response, string $id): Response
    {
        $body       = (array) $request->getParsedBody();
        $name       = trim($body['name'] ?? '');
        $srcZoneId  = ($body['src_zone_id'] ?? '') !== '' ? (int) $body['src_zone_id'] : null;
        $dstZoneId  = ($body['dst_zone_id'] ?? '') !== '' ? (int) $body['dst_zone_id'] : null;
        $srcIp      = trim($body['src_ip_override'] ?? '');
        $dstIp      = trim($body['dst_ip_override'] ?? '');
        $ruleType   = array_key_exists($body['rule_type'] ?? '', self::RULE_TYPES) ? $body['rule_type'] : 'full';
        $action     = $ruleType === 'deny' ? 'DROP' : 'ACCEPT';
        $priority   = max(1, min(999, (int) ($body['priority'] ?? 100)));

        if (!$name) {
            $_SESSION['flash_error'] = 'Rule name is required.';
            return $response->withHeader('Location', '/acls')->withStatus(302);
        }

        $this->db->execute("
            UPDATE acl_rules SET name = ?, src_zone_id = ?, dst_zone_id = ?, src_ip_override = ?, dst_ip_override = ?, action = ?, rule_type = ?, priority = ?
            WHERE id = ?
        ", [$name, $srcZoneId, $dstZoneId, $srcIp ?: null, $dstIp ?: null, $action, $ruleType, $priority, (int) $id]);

        $this->wg->syncAcl();
        $_SESSION['flash_success'] = "Rule updated.";
        return $response->withHeader('Location', '/acls')->withStatus(302);
    }

    public function destroy(Request $request, Response $response, string $id): Response
    {
        $rule = $this->db->queryOne("SELECT * FROM acl_rules WHERE id = ?", [(int) $id]);
        if ($rule) {
            $this->db->execute("DELETE FROM acl_rules WHERE id = ?", [(int) $id]);
            $this->wg->syncAcl();
            $_SESSION['flash_success'] = "Rule \"{$rule['name']}\" deleted.";
        }
        return $response->withHeader('Location', '/acls')->withStatus(302);
    }
}
