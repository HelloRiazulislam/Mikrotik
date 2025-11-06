
Proxynama — Full package (UI like screenshots)
============================================
Contents:
- index.php        (two-step UI: Login -> Server Ready & Generate Config)
- lib/RouterOSRest.php (minimal REST helper)
- tmp/             (writable, stores generated .conf files)
- README.txt       (this file)

Important:
- WireGuard listen port is set to 7626 by design.
- The script uses RouterOS REST API (RouterOS v7+). Ensure REST (www-ssl or api-ssl) is enabled
  and reachable from your hosting/VPS. If hosting blocks outbound connections, use a VPS.
- PHP requirements: php-curl, php-sodium (libsodium), PHP 8+ recommended.
- For production: protect index.php with authentication or restrict by IP, and use HTTPS.

Deployment:
1) Upload all files preserving structure to webroot.
2) Ensure tmp/ is writable by web server (chmod 700 or 755).
3) Open site, login to Router (API host/port/user/pass). After successful connect, click Make Ready Server & Generate Config.
