<?php

namespace OPNsense\Xray\Api;

use OPNsense\Base\ApiControllerBase;
use OPNsense\Xray\VlessLink;

class ImportController extends ApiControllerBase
{
    public function parseAction()
    {
        if (!$this->request->isPost()) {
            return ['status' => 'error', 'message' => 'POST required'];
        }

        $requestData = $this->extractRequestData();
        $link = $requestData['link'];

        if (empty($link)) {
            return ['status' => 'error', 'message' => 'No VLESS link provided'];
        }

        if (strlen($link) > 2048) {
            return ['status' => 'error', 'message' => 'Link too long'];
        }

        $data = VlessLink::parse($link);
        if (isset($data['error'])) {
            return ['status' => 'error', 'message' => $data['error']];
        }

        $data['status'] = 'ok';
        return $data;
    }

    /**
     * Extracts the link from request body (JSON with link_b64, or plain POST).
     * @return array{link: string}
     */
    private function extractRequestData(): array
    {
        $result = ['link' => ''];

        // JSON body ($.ajax contentType: application/json)
        $rawBody = file_get_contents('php://input');
        if (!empty($rawBody)) {
            $json = json_decode($rawBody, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                if (!empty($json['link_b64'])) {
                    $decoded = base64_decode($json['link_b64'], true);
                    if ($decoded !== false) {
                        $result['link'] = trim($decoded);
                    }
                } elseif (!empty($json['link'])) {
                    $result['link'] = trim($json['link']);
                }
                return $result;
            }
        }

        // POST param link_b64 (base64-safe, & in link is not a problem)
        $b64 = $this->request->getPost('link_b64', 'string', '');
        if (!empty($b64)) {
            $decoded = base64_decode($b64, true);
            if ($decoded !== false) {
                $result['link'] = trim($decoded);
            }
        }

        // Plain POST link
        if (empty($result['link'])) {
            $link = $this->request->getPost('link', 'string', '');
            if (!empty($link)) {
                $result['link'] = trim($link);
            }
        }

        return $result;
    }
}
