<?php
class OmadaClient {

    private $baseUrl      = 'https://127.0.0.1:8043';
    private $omadacId     = '2163d67547fcf33c287f7c12b1850bdc';
    private $siteId       = '68e3d9d4e222cf7b32f63d02';
    private $clientId     = '11299a5b0dea4ab0831098423909f842';
    private $clientSecret = '436566816e7849f880ac4220d04366c7';
    private $email        = 'tanakachakz@gmail.com';
    private $password     = 'Chipochidzawo3!';

    // ── Voucher status constants (from Omada) ─────────────────────────────────
    const VOUCHER_UNUSED  = 0;
    const VOUCHER_ACTIVE  = 1;  // "In-use" on Omada dashboard
    const VOUCHER_EXPIRED = 2;

    private $apiToken       = null;
    private $apiTokenExpiry = 0;
    private $webToken       = null;
    private $sessionId      = null;
    private $cookieFile     = null;

    public function __construct() {
        $this->cookieFile = tempnam(sys_get_temp_dir(), 'omada_');
    }

    public function __destruct() {
        if ($this->cookieFile && file_exists($this->cookieFile)) {
            @unlink($this->cookieFile);
        }
    }

    public function getWebToken() {
        $this->ensureWebSession();
        return $this->webToken;
    }

    // ─── OpenAPI Login ────────────────────────────────────────────────────────
    private function apiLogin() {
        $url  = $this->baseUrl . '/openapi/authorize/token?grant_type=client_credentials';
        $body = json_encode([
            'omadacId'      => $this->omadacId,
            'client_id'     => $this->clientId,
            'client_secret' => $this->clientSecret,
        ]);

        $res = $this->curlPost($url, $body, 'application/json', false);

        if (isset($res['result']['accessToken'])) {
            $this->apiToken       = $res['result']['accessToken'];
            $this->apiTokenExpiry = time() + ($res['result']['expiresIn'] ?? 7200);
            return true;
        }

        error_log('Omada API login failed: ' . json_encode($res));
        return false;
    }

    private function ensureApiToken() {
        if (!$this->apiToken || time() >= $this->apiTokenExpiry) {
            $this->apiLogin();
        }
    }

    // ─── Web API Login ────────────────────────────────────────────────────────
    private function webLogin() {
        $url  = $this->baseUrl . '/' . $this->omadacId . '/api/v2/login';
        $body = json_encode([
            'username' => $this->email,
            'password' => $this->password,
        ]);

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        curl_setopt($ch, CURLOPT_HEADER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_COOKIEJAR, $this->cookieFile);
        curl_setopt($ch, CURLOPT_COOKIEFILE, $this->cookieFile);

        $raw   = curl_exec($ch);
        $hSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);

        $headers = substr($raw, 0, $hSize);
        $body    = substr($raw, $hSize);
        $decoded = json_decode($body, true);

        preg_match('/TPOMADA_SESSIONID=([^;]+)/', $headers, $matches);
        $this->sessionId = $matches[1] ?? null;
        $this->webToken  = $decoded['result']['token'] ?? null;

        return ($this->webToken && $this->sessionId);
    }

    private function ensureWebSession() {
        if (!$this->webToken || !$this->sessionId) {
            $this->webLogin();
        }
    }

    // ─── CLIENTS ──────────────────────────────────────────────────────────────
    public function getActiveClients() {
        $this->ensureApiToken();
        $url = $this->baseUrl
             . '/openapi/v1/' . $this->omadacId
             . '/sites/' . $this->siteId
             . '/clients?filters.active=true&page=1&pageSize=200';

        $res = $this->curlGet($url, true);
        return $res['result']['data'] ?? [];
    }

    public function getClientByMac($mac) {
        $clients = $this->getActiveClients();
        foreach ($clients as $client) {
            if (strtolower($client['mac']) === strtolower($mac)) {
                return $client;
            }
        }
        return null;
    }

    public function disconnectClient($mac) {
        $this->ensureApiToken();
        $url = $this->baseUrl
             . '/openapi/v1/' . $this->omadacId
             . '/sites/' . $this->siteId
             . '/cmd/clients/disconnect';

        return $this->curlPost($url, json_encode(['mac' => $mac]), 'application/json', true);
    }

    // ─── VOUCHER GROUPS ───────────────────────────────────────────────────────
    public function getVoucherGroups() {
        $this->ensureWebSession();
        $url = $this->baseUrl . '/' . $this->omadacId
             . '/api/v2/hotspot/sites/' . $this->siteId
             . '/voucherGroups?currentPage=1&currentPageSize=50&token=' . $this->webToken;

        $res = $this->curlGetWeb($url);
        return $res['result']['data'] ?? [];
    }

    // ─── VOUCHER CODES ────────────────────────────────────────────────────────
    public function getUnusedVoucherFromGroup($groupId, $count = 1) {
        $this->ensureWebSession();

        $url = $this->baseUrl . '/' . $this->omadacId
             . '/api/v2/hotspot/sites/' . $this->siteId
             . '/voucherGroups/' . $groupId
             . '?currentPage=1&currentPageSize=200&token=' . $this->webToken;

        $res    = $this->curlGetWeb($url);
        $all    = $res['result']['data'] ?? [];
        $unused = [];

        foreach ($all as $v) {
            if (($v['status'] ?? -1) === self::VOUCHER_UNUSED) {
                $unused[] = [
                    'id'           => $v['id']           ?? null,
                    'code'         => $v['code']         ?? null,
                    'status'       => $v['status']       ?? 0,
                    'trafficLimit' => $v['trafficLimit'] ?? 0,
                ];
                if (count($unused) >= $count) break;
            }
        }

        return $unused;
    }

    public function claimVoucher($groupId) {
        $vouchers = $this->getUnusedVoucherFromGroup($groupId, 1);
        if (empty($vouchers)) return null;
        return $vouchers[0]['code'] ?? null;
    }

    public function getVoucherByCode($code) {
        $this->ensureWebSession();
        $url = $this->baseUrl . '/' . $this->omadacId
             . '/api/v2/hotspot/sites/' . $this->siteId
             . '/vouchers?currentPage=1&currentPageSize=1'
             . '&searchField=code&searchKey=' . urlencode($code)
             . '&token=' . $this->webToken;

        $res  = $this->curlGetWeb($url);
        $data = $res['result']['data'] ?? [];
        return $data[0] ?? null;
    }

    // ─── FIX: Use Omada's integer status field directly ───────────────────────
    // Omada returns: status=0 (unused), status=1 (in-use/active), status=2 (expired)
    // The old code used 'used' and 'valid' booleans which were unreliable.
    public function getVoucherStatus($code) {
        $voucher = $this->getVoucherByCode($code);
        if (!$voucher) return null;

        $omadaStatus = (int)($voucher['status'] ?? -1);

        // Map Omada integer to readable string
        $statusStr = match($omadaStatus) {
            self::VOUCHER_UNUSED  => 'unused',
            self::VOUCHER_ACTIVE  => 'active',   // "In-use" on Omada = active for us
            self::VOUCHER_EXPIRED => 'expired',
            default               => 'unknown',
        };

        $isActive = ($omadaStatus === self::VOUCHER_ACTIVE);
        $isUnused = ($omadaStatus === self::VOUCHER_UNUSED);

        return [
            'code'         => $voucher['code']         ?? $code,
            'status'       => $omadaStatus,             // raw integer (0/1/2)
            'statusStr'    => $statusStr,               // 'unused' / 'active' / 'expired'
            'used'         => !$isUnused,               // kept for backwards compat
            'valid'        => $isActive,                // kept for backwards compat
            'duration'     => $voucher['duration']      ?? 0,
            'trafficLimit' => $voucher['trafficLimit']  ?? 0,
            'trafficUsed'  => $voucher['trafficUsed']   ?? 0,
            'trafficLeft'  => $voucher['trafficLeft']   ?? 0,
            'startTime'    => $voucher['startTime']     ?? 0,
            'endTime'      => $voucher['endTime']       ?? 0,
            'unitPrice'    => $voucher['unitPrice']     ?? '0',
            'currency'     => $voucher['currency']      ?? 'USD',
            'portalNames'  => $voucher['portalNames']   ?? [],
            'name'         => $voucher['name']          ?? '',
        ];
    }

    // ─── SITE STATS ───────────────────────────────────────────────────────────
    public function getSiteStats() {
        $this->ensureApiToken();
        $url = $this->baseUrl
             . '/openapi/v1/' . $this->omadacId
             . '/sites/' . $this->siteId
             . '/dashboard/overallClientStats';

        return $this->curlGet($url, true);
    }

    // ─── HTTP HELPERS ─────────────────────────────────────────────────────────
    private function curlGet($url, $useApiToken = false) {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);

        $headers = ['Content-Type: application/json'];
        if ($useApiToken && $this->apiToken) {
            $headers[] = 'Authorization: AccessToken=' . $this->apiToken;
        }
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        $result = curl_exec($ch);
        $error  = curl_error($ch);
        curl_close($ch);

        if ($error) {
            error_log('Omada GET error: ' . $error);
            return ['error' => $error];
        }

        return json_decode($result, true) ?? [];
    }

    private function curlGetWeb($url) {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
        curl_setopt($ch, CURLOPT_COOKIEFILE, $this->cookieFile);
        curl_setopt($ch, CURLOPT_COOKIEJAR, $this->cookieFile);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Csrf-Token: ' . $this->webToken,
            'Cookie: TPOMADA_SESSIONID=' . $this->sessionId,
        ]);

        $result = curl_exec($ch);
        $error  = curl_error($ch);
        curl_close($ch);

        if ($error) {
            error_log('Omada Web GET error: ' . $error);
            return ['error' => $error];
        }

        return json_decode($result, true) ?? [];
    }

    private function curlPost($url, $body, $contentType = 'application/json', $auth = true) {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);

        $headers = ['Content-Type: ' . $contentType];
        if ($auth && $this->apiToken) {
            $headers[] = 'Authorization: AccessToken=' . $this->apiToken;
        }
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        $result = curl_exec($ch);
        $error  = curl_error($ch);
        curl_close($ch);

        if ($error) {
            error_log('Omada POST error: ' . $error);
            return ['error' => $error];
        }

        return json_decode($result, true) ?? [];
    }
}
?>