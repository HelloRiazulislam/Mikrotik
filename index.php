<?php
// index.php - Proxynama full UI
require __DIR__ . '/lib/RouterOSRest.php';
function b64(string $bin): string { return rtrim(base64_encode($bin), '='); }
function genKeypair(): array {
    if (!function_exists('sodium_crypto_box_keypair')) {
        throw new Exception('libsodium extension not found. Enable PHP sodium.');
    }
    $kp = sodium_crypto_box_keypair();
    $sk = sodium_crypto_box_secretkey($kp);
    $pk = sodium_crypto_box_publickey($kp);
    return [b64($sk), b64($pk)];
}
function json_response($ok, $data=[], $msg='') {
    header('Content-Type: application/json');
    echo json_encode(['ok'=>$ok,'msg'=>$msg,'data'=>$data]);
    exit;
}
// Fixed WireGuard listen port as requested
$WG_LISTEN_PORT = 7626;
if(($_POST['action'] ?? '') === 'connect') {
    // Try simple connect: read /system/resource to verify credentials
    $host = trim($_POST['ip'] ?? '');
    $port = (int)($_POST['port'] ?? 443);
    $user = trim($_POST['user'] ?? '');
    $pass = (string)($_POST['pass'] ?? '');
    if(!$host || !$user) json_response(false, [], 'Missing host or user');
    try {
        $api = new RouterOSRest($host, $user, $pass, $port, true, 8);
        // test endpoint
        $res = $api->get('/system/resource');
        json_response(true, ['resource'=>$res, 'listen_port'=>$WG_LISTEN_PORT], 'Connected to MikroTik successfully!');
    } catch(Throwable $e) {
        json_response(false, ['error'=>$e->getMessage()], 'Failed: ' . $e->getMessage());
    }
}
if(($_POST['action'] ?? '') === 'setup') {
    $host = trim($_POST['ip'] ?? '');
    $port = (int)($_POST['port'] ?? 443);
    $user = trim($_POST['user'] ?? '');
    $pass = (string)($_POST['pass'] ?? '');
    $wgName = trim($_POST['wg_name'] ?? 'wg0');
    $listen = $WG_LISTEN_PORT; // enforce 7626
    $srvAddr = trim($_POST['server_address'] ?? '10.0.0.1/24');
    $subnet = trim($_POST['subnet'] ?? '10.0.0.0/24');
    $clientIP = trim($_POST['client_ip'] ?? '10.0.0.2/32');
    $dns = trim($_POST['dns'] ?? '1.1.1.1');
    $endpoint = trim($_POST['endpoint'] ?? ($host . ':' . $listen));
    if(!$host || !$user) json_response(false, [], 'Missing router credentials');
    try {
        $api = new RouterOSRest($host, $user, $pass, $port, true, 10);
        // 1) create interface if missing
        $ifc = $api->get('/interface/wireguard', ['name'=>$wgName]);
        if(empty($ifc)) {
            [$sk,$pk] = genKeypair();
            $api->post('/interface/wireguard', [
                "name"=>$wgName,
                "listen-port"=>$listen,
                "private-key"=>$sk,
                "mtu"=>1420,
                "comment"=>"proxynama auto wg"
            ]);
            $ifc = $api->get('/interface/wireguard', ['name'=>$wgName]);
        } else {
            $rowid = $ifc[0]['.id'] ?? null;
            if($rowid && ((int)($ifc[0]['listen-port'] ?? 0) !== $listen)) {
                $api->patch('/interface/wireguard/' . rawurlencode($rowid), ["listen-port"=>$listen]);
            }
        }
        if(empty($ifc)) throw new Exception('Failed to create/find WireGuard interface');
        $serverPub = $ifc[0]['public-key'] ?? '';
        // 2) assign address
        $addrExists = $api->get('/ip/address', ['interface'=>$wgName, 'address'=>$srvAddr]);
        if(empty($addrExists)) {
            $api->post('/ip/address', ["address"=>$srvAddr,"interface"=>$wgName,"comment"=>"WG server address"]);
        }
        // 3) add firewall allow for udp 7626 (WireGuard)
        $allow = $api->get('/ip/firewall/filter', ['comment'=>'Allow WireGuard UDP 7626']);
        if(empty($allow)) {
            $api->post('/ip/firewall/filter', [
                "chain"=>"input",
                "protocol"=>"udp",
                "dst-port"=> (string)$listen,
                "action"=>"accept",
                "comment"=>"Allow WireGuard UDP 7626"
            ]);
        }
        // 4) NAT masquerade for subnet if missing
        $nat = $api->get('/ip/firewall/nat', ['comment'=>'WG Masquerade']);
        if(empty($nat)) {
            $api->post('/ip/firewall/nat', [
                "chain"=>"srcnat",
                "action"=>"masquerade",
                "src-address"=>$subnet,
                "comment"=>"WG Masquerade"
            ]);
        }
        // 5) create client peer
        [$clientPriv,$clientPub] = genKeypair();
        $api->post('/interface/wireguard/peers',[
            "interface"=>$wgName,
            "public-key"=>$clientPub,
            "allowed-address"=>$clientIP,
            "persistent-keepalive"=>"25",
            "comment"=>"proxynama-client-1"
        ]);
        // 6) prepare config and save
        $cfg = "[Interface]\nAddress = {$clientIP}\nDNS = {$dns}\nPrivateKey = {$clientPriv}\n\n[Peer]\nAllowedIPs = 0.0.0.0/0, ::/0\nEndpoint = {$endpoint}\nPublicKey = {$serverPub}\nPersistentKeepalive = 25\n";
        $fname = 'wg-client-' . preg_replace('/[^a-zA-Z0-9_-]/','_', $wgName) . '.conf';
        $path = __DIR__ . '/tmp';
        if(!is_dir($path)) mkdir($path, 0700, true);
        file_put_contents($path . '/' . $fname, $cfg);
        json_response(true, ['config'=>$cfg,'config_url'=>'tmp/'.$fname,'server_public'=>$serverPub], 'WireGuard config generated successfully!');
    } catch(Throwable $e) {
        json_response(false, ['error'=>$e->getMessage()], 'Failed: ' . $e->getMessage());
    }
}
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8"/>
<meta name="viewport" content="width=device-width,initial-scale=1"/>
<title>Proxynama — WireGuard Manager</title>
<style>
:root{--bg1:#2f4ea6;--bg2:#0c243e;--card:#122236;--muted:#a8b3c7;--accent:#6b5cff;}
*{box-sizing:border-box;font-family:Inter,system-ui,Arial,sans-serif}
body{margin:0;background:linear-gradient(160deg,var(--bg1),var(--bg2));min-height:100vh;color:#e6eef8}
.wrap{max-width:760px;margin:20px auto;padding:18px}
.card{background:linear-gradient(180deg, rgba(255,255,255,0.04), rgba(0,0,0,0.06));border-radius:18px;padding:22px;box-shadow:0 12px 30px rgba(0,0,0,.35)}
h1{margin:0 0 6px;font-size:28px}
.muted{color:var(--muted);margin-bottom:12px}
.grid{display:grid;grid-template-columns:1fr 1fr;gap:12px}
.row3{display:grid;grid-template-columns:repeat(3,1fr);gap:12px;margin-top:8px}
label{display:block;font-size:13px;color:#bfd4ff;margin-bottom:6px}
input{width:100%;padding:14px;border-radius:18px;border:0;background:#071026;color:#e6eef8;box-shadow:inset 0 0 0 2px rgba(255,255,255,0.03)}
.bigbtn{display:inline-block;padding:14px 18px;border-radius:14px;background:var(--accent);color:white;font-weight:700;border:0;cursor:pointer;margin-top:16px}
.alert{border-radius:12px;padding:12px;margin-top:12px}
.err{background:rgba(239,68,68,0.12);border:1px solid rgba(239,68,68,0.25);color:#ffd6d6}
.ok{background:rgba(16,185,129,0.08);border:1px solid rgba(16,185,129,0.18);color:#c7f7df}
textarea{width:100%;height:200px;padding:12px;border-radius:12px;background:#071026;color:#e6eef8;border:0;margin-top:12px}
.small{font-size:13px;color:var(--muted)}
.copybtn{float:right;background:#4750a8;color:white;padding:8px 10px;border-radius:10px;border:0;cursor:pointer}
.footer{margin-top:12px;color:var(--muted);font-size:13px}
</style>
</head>
<body>
<div class="wrap">
  <div class="card">
    <h1>🔒 Proxynama — WireGuard Manager</h1>
    <p class="muted">Login via REST → create WG → IP/Firewall/NAT → add peer → download config.</p>
    <div id="step1">
      <form id="loginForm">
        <div class="grid">
          <div><label>MikroTik IP / Host</label><input name="ip" id="ip" placeholder="203.0.113.10" required></div>
          <div><label>REST Port</label><input name="port" id="port" value="443"></div>
        </div>
        <div class="grid" style="margin-top:12px">
          <div><label>Username</label><input name="user" id="user" required></div>
          <div><label>Password</label><input name="pass" id="pass" type="password" required></div>
        </div>
        <button class="bigbtn" id="connectBtn" type="submit">Connect to MikroTik</button>
      </form>
      <div id="loginMsg"></div>
    </div>
    <div id="step2" style="display:none">
      <div class="ok alert" id="connectedBox">Connected to MikroTik successfully!</div>
      <form id="setupForm">
        <div class="row3" style="margin-top:10px">
          <div><label>WG Name</label><input name="wg_name" id="wg_name" value="wg0"></div>
          <div><label>Listen Port</label><input id="listen" value="7626" readonly></div>
          <div><label>Server WG Address</label><input name="server_address" id="server_address" value="10.0.0.1/24"></div>
        </div>
        <div class="grid" style="margin-top:10px">
          <div><label>WG Subnet</label><input name="subnet" id="subnet" value="10.0.0.0/24"></div>
          <div><label>Client IP</label><input name="client_ip" id="client_ip" value="10.0.0.2/32"></div>
        </div>
        <div class="grid" style="margin-top:10px">
          <div><label>DNS for Client</label><input name="dns" id="dns" value="1.1.1.1"></div>
          <div><label>Endpoint (publicIP:port)</label><input name="endpoint" id="endpoint" placeholder="203.0.113.10:7626"></div>
        </div>
        <input type="hidden" name="ip" id="hid_ip"><input type="hidden" name="port" id="hid_port"><input type="hidden" name="user" id="hid_user"><input type="hidden" name="pass" id="hid_pass">
        <button class="bigbtn" id="makeBtn" type="submit">Make Ready Server & Generate Config</button>
      </form>
      <div id="setupMsg"></div>
      <div id="result" style="display:none">
        <div class="ok alert">Config ready to use! Copy or download.</div>
        <button class="copybtn" id="copyBtn">Copy</button>
        <textarea id="cfg" readonly></textarea>
        <div class="footer"><a id="dl" class="small" href="#" download>⬇️ Download .conf</a></div>
      </div>
    </div>
  </div>
</div>
<script>
const loginForm = document.getElementById('loginForm');
const loginMsg = document.getElementById('loginMsg');
const step1 = document.getElementById('step1');
const step2 = document.getElementById('step2');
const setupForm = document.getElementById('setupForm');
const setupMsg = document.getElementById('setupMsg');
const cfg = document.getElementById('cfg');
const dl = document.getElementById('dl');
const copyBtn = document.getElementById('copyBtn');
loginForm.addEventListener('submit', async (e)=>{
  e.preventDefault();
  loginMsg.innerHTML='';
  const form = new FormData(loginForm);
  form.set('action','connect');
  const r = await fetch('', {method:'POST', body:form});
  const j = await r.json();
  if(!j.ok){ loginMsg.innerHTML = `<div class="alert err">${j.msg}</div>`; return; }
  // show step2
  step1.style.display='none';
  step2.style.display='block';
  document.getElementById('hid_ip').value = document.getElementById('ip').value;
  document.getElementById('hid_port').value = document.getElementById('port').value;
  document.getElementById('hid_user').value = document.getElementById('user').value;
  document.getElementById('hid_pass').value = document.getElementById('pass').value;
  document.getElementById('endpoint').value = document.getElementById('ip').value + ':7626';
});
setupForm.addEventListener('submit', async (e)=>{
  e.preventDefault();
  setupMsg.innerHTML='';
  const form = new FormData(setupForm);
  form.set('action','setup');
  // include login hidden fields
  form.set('ip', document.getElementById('hid_ip').value);
  form.set('port', document.getElementById('hid_port').value);
  form.set('user', document.getElementById('hid_user').value);
  form.set('pass', document.getElementById('hid_pass').value);
  const r = await fetch('', {method:'POST', body:form});
  const j = await r.json();
  if(!j.ok){ setupMsg.innerHTML = `<div class="alert err">${j.msg}</div>`; return; }
  setupMsg.innerHTML = `<div class="alert ok">${j.msg}</div>`;
  cfg.value = j.data.config;
  dl.href = j.data.config_url;
  dl.download = j.data.config_url.split('/').pop();
  document.getElementById('result').style.display = 'block';
});
copyBtn.addEventListener('click', ()=>{
  navigator.clipboard.writeText(cfg.value);
  copyBtn.textContent = 'Copied';
  setTimeout(()=>copyBtn.textContent='Copy',1500);
});
</script>
</body>
</html>
