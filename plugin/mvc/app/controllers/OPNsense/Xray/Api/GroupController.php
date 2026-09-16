<?php

namespace OPNsense\Xray\Api;

use OPNsense\Base\ApiMutableModelControllerBase;
use OPNsense\Core\Config;
use OPNsense\Xray\VlessLink;

/**
 * v3.1.0: groups of VLESS servers.
 *
 * One controller serves both ArrayFields of the Group model (group, server) —
 * same pattern as OPNsense/Dnsmasq/Api/SettingsController.
 *
 * Subscription import and Refresh never touch the running service: they only
 * maintain the server list. Switching the active node is a separate,
 * deliberate action on the instance (Save + Apply).
 */
class GroupController extends ApiMutableModelControllerBase
{
    protected static $internalModelClass = '\OPNsense\Xray\Group';
    protected static $internalModelName  = 'group';

    /** hard limit on nodes per subscription — config.xml is parsed on every GUI request */
    private const MAX_NODES = 200;
    /** subscription body limit */
    private const MAX_BODY = 1048576;
    private const DEFAULT_UA = 'v2rayNG/1.9.5';

    /* ── groups ─────────────────────────────────────────────────────────── */

    public function searchGroupAction()
    {
        $response = $this->searchBase('group', ['name', 'source', 'sub_url', 'last_fetch', 'last_count']);
        if (!empty($response['rows'])) {
            $counts = [];
            foreach ($this->getModel()->server->iterateItems() as $srv) {
                $gid = (string)$srv->group;
                $counts[$gid] = ($counts[$gid] ?? 0) + 1;
            }
            foreach ($response['rows'] as &$row) {
                $row['servers'] = (string)($counts[$row['uuid']] ?? 0);
            }
            unset($row);
        }
        return $response;
    }

    public function getGroupAction($uuid = null)
    {
        return $this->getBase('group', 'group', $uuid);
    }

    public function addGroupAction()
    {
        return $this->addBase('group', 'group');
    }

    public function setGroupAction($uuid)
    {
        return $this->setBase('group', 'group', $uuid);
    }

    /**
     * Deleting a group takes its servers with it — orphaned servers would keep
     * showing up in the server grid with a dangling group reference.
     */
    public function delGroupAction($uuid)
    {
        if ($this->request->isPost()) {
            $model = $this->getModel();
            $remove = [];
            foreach ($model->server->iterateItems() as $srvUuid => $srv) {
                if ((string)$srv->group === $uuid) {
                    $remove[] = $srvUuid;
                }
            }
            foreach ($remove as $srvUuid) {
                $model->server->del($srvUuid);
            }
            if (!empty($remove)) {
                $model->serializeToConfig();
                Config::getInstance()->save();
            }
        }
        return $this->delBase('group', $uuid);
    }

    /* ── servers ────────────────────────────────────────────────────────── */

    public function searchServerAction()
    {
        $group = $this->request->getPost('group', 'string', '');
        $filter = function ($record) use ($group) {
            return $group === '' || (string)$record->group === $group;
        };
        return $this->searchBase(
            'server',
            ['group', 'name', 'address', 'port', 'stale'],
            'name',
            $filter
        );
    }

    public function getServerAction($uuid = null)
    {
        return $this->getBase('server', 'server', $uuid);
    }

    public function addServerAction()
    {
        return $this->addBase('server', 'server');
    }

    public function setServerAction($uuid)
    {
        return $this->setBase('server', 'server', $uuid);
    }

    public function delServerAction($uuid)
    {
        return $this->delBase('server', $uuid);
    }

    /* ── subscription import ────────────────────────────────────────────── */

    public function importSubscriptionAction()
    {
        if (!$this->request->isPost()) {
            return ['status' => 'error', 'message' => 'POST required'];
        }

        $data   = $this->jsonBody();
        $name   = trim((string)($data['name'] ?? ''));
        $subUrl = trim((string)($data['sub_url'] ?? ''));
        $subUa  = trim((string)($data['sub_ua'] ?? ''));
        $bodyB64 = (string)($data['body_b64'] ?? '');

        if ($name === '') {
            return ['status' => 'error', 'message' => 'Group name is required'];
        }

        if ($bodyB64 !== '') {
            $body = base64_decode($bodyB64, true);
            if ($body === false) {
                return ['status' => 'error', 'message' => 'Pasted body is not valid base64 payload'];
            }
            $source = $subUrl !== '' ? 'subscription' : 'manual';
        } else {
            if ($subUrl === '') {
                return ['status' => 'error', 'message' => 'Either a subscription URL or a pasted body is required'];
            }
            $fetched = $this->fetchSubscription($subUrl, $subUa);
            if (isset($fetched['error'])) {
                return ['status' => 'error', 'message' => $fetched['error']];
            }
            $body   = $fetched['body'];
            $source = 'subscription';
        }

        $parsed = $this->parseSubscriptionBody($body);
        if (isset($parsed['error'])) {
            return ['status' => 'error', 'message' => $parsed['error']];
        }
        if (empty($parsed['servers'])) {
            $reason = !empty($parsed['skipped'])
                ? ('no usable vless:// nodes found; first problem: line '
                    . $parsed['skipped'][0]['line'] . ': ' . $parsed['skipped'][0]['reason'])
                : 'no usable vless:// nodes found';
            return ['status' => 'error', 'message' => $reason];
        }

        $model = $this->getModel();
        $group = $model->group->add();
        $group->name       = $name;
        $group->source     = $source;
        $group->sub_url    = $subUrl;
        $group->sub_ua     = $subUa !== '' ? $subUa : self::DEFAULT_UA;
        $group->last_fetch = date('Y-m-d H:i:s');
        $group->last_count = (string)count($parsed['servers']);
        $groupUuid = $group->getAttributes()['uuid'];

        foreach ($parsed['servers'] as $node) {
            $srv = $model->server->add();
            $srv->group           = $groupUuid;
            $srv->name            = $node['name'];
            $srv->outbound_config = $node['outbound_config'];
            $srv->address         = $node['address'];
            $srv->port            = (string)$node['port'];
            $srv->node_key        = $node['node_key'];
            $srv->stale           = '0';
        }

        $valMsgs = $model->performValidation();
        if (count($valMsgs) > 0) {
            $messages = [];
            foreach ($valMsgs as $msg) {
                $messages[] = $msg->getField() . ': ' . $msg->getMessage();
            }
            return ['status' => 'error', 'message' => 'Validation failed — ' . implode('; ', $messages)];
        }

        $model->serializeToConfig();
        Config::getInstance()->save();

        return [
            'status'  => 'ok',
            'uuid'    => $groupUuid,
            'added'   => count($parsed['servers']),
            'skipped' => $parsed['skipped'],
        ];
    }

    /**
     * Re-fetches the subscription and reconciles the server list by node_key.
     * Never restarts the service and never changes the instance's active server.
     */
    public function refreshAction($uuid = null)
    {
        if (!$this->request->isPost()) {
            return ['status' => 'error', 'message' => 'POST required'];
        }

        $model = $this->getModel();
        $group = null;
        foreach ($model->group->iterateItems() as $gUuid => $candidate) {
            if ($gUuid === $uuid) {
                $group = $candidate;
                break;
            }
        }
        if ($group === null) {
            return ['status' => 'error', 'message' => 'Group not found'];
        }
        if ((string)$group->source !== 'subscription' || trim((string)$group->sub_url) === '') {
            return [
                'status'  => 'error',
                'message' => 'Group has no subscription URL — Refresh is available for imported groups only',
            ];
        }

        $fetched = $this->fetchSubscription((string)$group->sub_url, (string)$group->sub_ua);
        if (isset($fetched['error'])) {
            return ['status' => 'error', 'message' => $fetched['error']];
        }
        $parsed = $this->parseSubscriptionBody($fetched['body']);
        if (isset($parsed['error'])) {
            return ['status' => 'error', 'message' => $parsed['error']];
        }
        if (empty($parsed['servers'])) {
            return ['status' => 'error', 'message' => 'Subscription returned no usable vless:// nodes'];
        }

        $usedServers = $this->activeServerUuids();

        $existing = [];
        foreach ($model->server->iterateItems() as $srvUuid => $srv) {
            if ((string)$srv->group === $uuid) {
                $existing[$srvUuid] = $srv;
            }
        }

        $added = 0;
        $updated = 0;
        $seen = [];
        foreach ($parsed['servers'] as $node) {
            $match = null;
            foreach ($existing as $srvUuid => $srv) {
                if ((string)$srv->node_key === $node['node_key']) {
                    $match = $srvUuid;
                    break;
                }
            }
            if ($match === null) {
                $srv = $model->server->add();
                $srv->group           = $uuid;
                $srv->name            = $node['name'];
                $srv->outbound_config = $node['outbound_config'];
                $srv->address         = $node['address'];
                $srv->port            = (string)$node['port'];
                $srv->node_key        = $node['node_key'];
                $srv->stale           = '0';
                $added++;
            } else {
                $srv = $existing[$match];
                $srv->name            = $node['name'];
                $srv->outbound_config = $node['outbound_config'];
                $srv->address         = $node['address'];
                $srv->port            = (string)$node['port'];
                $srv->stale           = '0';
                $seen[$match] = true;
                $updated++;
            }
        }

        $removed = 0;
        $stale = 0;
        foreach ($existing as $srvUuid => $srv) {
            if (isset($seen[$srvUuid])) {
                continue;
            }
            if (isset($usedServers[$srvUuid])) {
                // selected by an instance — keep it, just mark it
                $srv->stale = '1';
                $stale++;
            } else {
                $model->server->del($srvUuid);
                $removed++;
            }
        }

        $group->last_fetch = date('Y-m-d H:i:s');
        $group->last_count = (string)count($parsed['servers']);

        $valMsgs = $model->performValidation();
        if (count($valMsgs) > 0) {
            $messages = [];
            foreach ($valMsgs as $msg) {
                $messages[] = $msg->getField() . ': ' . $msg->getMessage();
            }
            return ['status' => 'error', 'message' => 'Validation failed — ' . implode('; ', $messages)];
        }

        $model->serializeToConfig();
        Config::getInstance()->save();

        return [
            'status'  => 'ok',
            'added'   => $added,
            'updated' => $updated,
            'removed' => $removed,
            'stale'   => $stale,
            'skipped' => $parsed['skipped'],
        ];
    }

    /* ── helpers ────────────────────────────────────────────────────────── */

    /**
     * Server UUIDs currently selected as the active node by some instance.
     * Read straight from config.xml: instances live in another model.
     *
     * @return array<string,bool>
     */
    private function activeServerUuids(): array
    {
        $result = [];
        $cfg = Config::getInstance()->object();
        $ins = $cfg->OPNsense->xray->instances ?? null;
        if ($ins) {
            foreach ($ins->instance as $inst) {
                $srv = (string)($inst->server ?? '');
                if ($srv !== '') {
                    $result[$srv] = true;
                }
            }
        }
        return $result;
    }

    /**
     * Request body as an array — accepts a JSON body or plain POST fields.
     */
    private function jsonBody(): array
    {
        $rawBody = file_get_contents('php://input');
        if (!empty($rawBody)) {
            $json = json_decode($rawBody, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($json)) {
                return $json;
            }
        }
        return [
            'name'     => $this->request->getPost('name', 'string', ''),
            'sub_url'  => $this->request->getPost('sub_url', 'string', ''),
            'sub_ua'   => $this->request->getPost('sub_ua', 'string', ''),
            'body_b64' => $this->request->getPost('body_b64', 'string', ''),
        ];
    }

    /**
     * @return array{body:string}|array{error:string}
     */
    private function fetchSubscription(string $url, string $ua): array
    {
        $parts = parse_url($url);
        if (!is_array($parts)) {
            return ['error' => 'Only http(s) subscription URLs are supported'];
        }
        $scheme = strtolower($parts['scheme'] ?? '');
        if (!in_array($scheme, ['http', 'https'], true) || empty($parts['host'])) {
            return ['error' => 'Only http(s) subscription URLs are supported'];
        }

        $body = '';
        $overflow = false;
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_USERAGENT      => $ua !== '' ? $ua : self::DEFAULT_UA,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_PROTOCOLS      => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_WRITEFUNCTION  => function ($ch, $chunk) use (&$body, &$overflow) {
                $len = strlen($chunk);
                if (strlen($body) + $len > self::MAX_BODY) {
                    $overflow = true;
                    return 0; // aborts the transfer
                }
                $body .= $chunk;
                return $len;
            },
        ]);
        $ok   = curl_exec($ch);
        $err  = curl_error($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($overflow) {
            return ['error' => 'Subscription body exceeds 1 MiB limit'];
        }
        if ($ok === false) {
            return ['error' => 'Subscription fetch failed: ' . ($err !== '' ? $err : 'unknown curl error')];
        }
        if ($code < 200 || $code > 299) {
            return ['error' => 'Subscription returned HTTP ' . $code];
        }

        return ['body' => $body];
    }

    /**
     * @return array{servers:array,skipped:array}|array{error:string}
     */
    private function parseSubscriptionBody(string $body): array
    {
        $body = trim($body);
        if ($body === '') {
            return ['error' => 'Subscription body is empty'];
        }

        $formatError = 'Unsupported subscription format (looks like xray-json/clash/HTML); '
            . 'only a list of vless:// links is supported — check the subscription User-Agent';

        $first = substr($body, 0, 1);
        if ($first === '[' || $first === '{' || $first === '<' || strpos($body, 'proxies:') === 0) {
            return ['error' => $formatError];
        }

        if (strpos($body, 'vless://') !== 0) {
            $compact = preg_replace('/\s+/', '', $body);
            $decoded = base64_decode($compact, true);
            if ($decoded !== false && strpos($decoded, 'vless://') !== false) {
                $body = trim($decoded);
            } else {
                return ['error' => $formatError];
            }
        }

        $servers = [];
        $skipped = [];
        $keys = [];
        // NB: не опираемся на завершающий перевод строки — в реальном теле
        // 12 ссылок при 11 переводах строки.
        $lines = preg_split('/\r?\n/', $body);
        foreach ($lines as $idx => $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $lineNo = $idx + 1;
            $node = VlessLink::parse($line);
            if (isset($node['error'])) {
                $skipped[] = ['line' => $lineNo, 'reason' => $node['error']];
                continue;
            }
            if (isset($keys[$node['node_key']])) {
                $skipped[] = ['line' => $lineNo, 'reason' => 'duplicate node'];
                continue;
            }
            $keys[$node['node_key']] = true;
            $servers[] = $node;
            if (count($servers) > self::MAX_NODES) {
                return ['error' => 'Subscription contains more than ' . self::MAX_NODES . ' nodes'];
            }
        }

        return ['servers' => $servers, 'skipped' => $skipped];
    }
}
