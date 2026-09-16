<?php

namespace OPNsense\Xray;

/**
 * Parser for vless:// links, shared by the single-link import and by
 * subscription import / refresh in the group controller.
 */
class VlessLink
{
    /**
     * Parses a single vless:// link.
     *
     * @return array{name:string,outbound_config:string,address:string,port:int,node_key:string}|array{error:string}
     */
    public static function parse(string $link): array
    {
        // Убираем лишние пробелы и кавычки
        $link = trim($link, " \t\n\r\0\x0B\"'");

        if (strpos($link, 'vless://') !== 0) {
            return ['error' => 'Link must start with vless://'];
        }

        // Убираем схему
        $rest = substr($link, 8); // после "vless://"

        // Отделяем #name в конце
        $name = '';
        if (($hashPos = strrpos($rest, '#')) !== false) {
            $name = htmlspecialchars(urldecode(substr($rest, $hashPos + 1)), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $rest = substr($rest, 0, $hashPos);
        }

        // Отделяем ?query
        $query = '';
        if (($qPos = strpos($rest, '?')) !== false) {
            $query = substr($rest, $qPos + 1);
            $rest  = substr($rest, 0, $qPos);
        }

        // Отделяем UUID@host:port
        // UUID — всё до последнего @ (на случай если @ есть в UUID — маловероятно, но надёжно)
        $atPos = strrpos($rest, '@');
        if ($atPos === false) {
            return ['error' => 'Missing @ separator between UUID and host'];
        }

        $uuid    = substr($rest, 0, $atPos);
        $hostport = substr($rest, $atPos + 1);

        // Разбираем host:port (с учётом IPv6 [::1]:port)
        if (substr($hostport, 0, 1) === '[') {
            // IPv6
            $closeBracket = strpos($hostport, ']');
            if ($closeBracket === false) {
                return ['error' => 'Invalid IPv6 address format'];
            }
            $host = substr($hostport, 1, $closeBracket - 1);
            $portStr = ltrim(substr($hostport, $closeBracket + 1), ':');
        } else {
            $lastColon = strrpos($hostport, ':');
            if ($lastColon === false) {
                return ['error' => 'Missing port in host:port'];
            }
            $host    = substr($hostport, 0, $lastColon);
            $portStr = substr($hostport, $lastColon + 1);
        }

        $port = (int)$portStr;
        if ($port <= 0 || $port > 65535) {
            return ['error' => 'Invalid port: ' . $portStr];
        }

        if (empty($uuid)) {
            return ['error' => 'UUID is empty'];
        }
        // BUG-10 FIX: валидация формата UUID до попадания в ответ.
        // Без этой проверки пользователь получал ошибку только в момент Apply через XML Mask —
        // непонятно далеко от точки ввода. Теперь ошибка сразу при парсинге.
        // \z вместо $ — строгий конец строки, не допускает трейлинг \n (баг PHP PCRE: $ совпадает перед \n)
        if (!preg_match('/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}\z/', $uuid)) {
            return ['error' => 'Invalid UUID format (expected xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx)'];
        }
        if (empty($host)) {
            return ['error' => 'Host is empty'];
        }

        // Парсим query string
        parse_str($query, $params);

        $type     = $params['type']     ?? 'tcp';
        $security = $params['security'] ?? 'reality';

        $flow = $params['flow'] ?? '';
        if (empty($flow)) {
            $flow = 'xtls-rprx-vision';
        }
        if ($type !== 'tcp') {
            $flow = '';
        }

        $fp = $params['fp'] ?? '';
        if (empty($fp)) {
            $fp = 'chrome';
        }

        // flow and fp whitelisted to known values (RCE/injection guard)
        $allowedFlow = ['xtls-rprx-vision', 'none', ''];
        $allowedFp   = ['chrome', 'firefox', 'safari', 'edge', 'random'];
        $flow = in_array($flow, $allowedFlow, true) ? $flow : 'xtls-rprx-vision';
        $fp   = in_array($fp,   $allowedFp,   true) ? $fp   : 'chrome';

        // Build outbound object — raw values (json_encode handles escaping)
        $outbound = [
            'tag'      => 'proxy',
            'protocol' => 'vless',
            'settings' => [
                'vnext' => [[
                    'address' => $host,
                    'port'    => $port,
                    'users'   => [[
                        'id'         => $uuid,
                        'encryption' => $params['encryption'] ?? 'none',
                        'flow'       => $flow,
                    ]],
                ]],
            ],
            'streamSettings' => self::buildStreamSettings($type, $security, $params),
        ];

        return [
            'name'            => $name,
            'outbound_config' => json_encode($outbound, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'address'         => $host,
            'port'            => $port,
            'node_key'        => sha1($host . ':' . $port . ':' . $uuid),
        ];
    }

    /**
     * Собирает streamSettings для xray-core конфига на основе transport type и security.
     */
    public static function buildStreamSettings(string $type, string $security, array $params): array
    {
        $ss = [
            'network'  => $type,
            'security' => $security,
        ];

        // ── Security settings ────────────────────────────────────────────
        if ($security === 'reality') {
            $ss['realitySettings'] = [
                'serverName'  => $params['sni'] ?? '',
                'fingerprint' => $params['fp']  ?? 'chrome',
                'show'        => false,
                'publicKey'   => $params['pbk'] ?? '',
                'shortId'     => $params['sid'] ?? '',
                'spiderX'     => $params['spx'] ?? '',
            ];
        } elseif ($security === 'tls') {
            $tls = [
                'serverName'  => $params['sni'] ?? '',
                'fingerprint' => $params['fp']  ?? 'chrome',
            ];
            if (!empty($params['alpn'])) {
                $tls['alpn'] = explode(',', $params['alpn']);
            }
            $ss['tlsSettings'] = $tls;
        }

        // ── Transport settings ───────────────────────────────────────────
        switch ($type) {
            case 'xhttp':
                $xhttp = [];
                if (!empty($params['path'])) $xhttp['path'] = $params['path'];
                if (!empty($params['host'])) $xhttp['host'] = $params['host'];
                if (!empty($params['mode'])) $xhttp['mode'] = $params['mode'];
                if (!empty($xhttp)) $ss['xhttpSettings'] = $xhttp;
                break;

            case 'ws':
                $ws = [];
                if (!empty($params['path'])) $ws['path'] = $params['path'];
                if (!empty($params['host'])) $ws['headers'] = ['Host' => $params['host']];
                if (!empty($ws)) $ss['wsSettings'] = $ws;
                break;

            case 'grpc':
                $grpc = [];
                if (!empty($params['serviceName'])) $grpc['serviceName'] = $params['serviceName'];
                if (!empty($params['mode']))        $grpc['multiMode']   = ($params['mode'] === 'multi');
                if (!empty($grpc)) $ss['grpcSettings'] = $grpc;
                break;

            case 'h2':
            case 'http':
                $h2 = [];
                if (!empty($params['path'])) $h2['path'] = $params['path'];
                if (!empty($params['host'])) $h2['host'] = [$params['host']];
                if (!empty($h2)) $ss['httpSettings'] = $h2;
                break;

            case 'kcp':
                $kcp = [];
                if (!empty($params['headerType'])) $kcp['header'] = ['type' => $params['headerType']];
                if (!empty($params['seed']))       $kcp['seed']   = $params['seed'];
                if (!empty($kcp)) $ss['kcpSettings'] = $kcp;
                break;

            case 'tcp':
                if (!empty($params['headerType']) && $params['headerType'] === 'http') {
                    $tcp = ['header' => ['type' => 'http']];
                    if (!empty($params['path'])) {
                        $tcp['header']['request'] = ['path' => explode(',', $params['path'])];
                    }
                    if (!empty($params['host'])) {
                        if (!isset($tcp['header']['request'])) $tcp['header']['request'] = [];
                        $tcp['header']['request']['headers'] = ['Host' => explode(',', $params['host'])];
                    }
                    $ss['tcpSettings'] = $tcp;
                }
                break;
        }

        return $ss;
    }
}
