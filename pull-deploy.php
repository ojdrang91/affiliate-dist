<?php
// Henter ferdigbygd side fra det offentlige repoet ojdrang91/affiliate-dist og pakker ut i public_html.
// Ligger i hjemmemappen på hvert webhotell (ikke i public_html) og kjøres av cron hvert 15. minutt:
//   /usr/local/bin/php $HOME/pull-deploy.php <domene>
// Trenger ingen pålogging: dist-repoet inneholder bare det som uansett ligger offentlig på nettsiden.
// Filer på serveren slettes aldri – bare nye/endrede filer skrives. Sjekksum verifiseres før utpakking.
// Kildekode: tools/pull-deploy.php i ojdrang91/affiliate-sites (installeres via cPanel).

$domain = $argv[1] ?? '';
if (!preg_match('/^[a-z0-9.-]+$/', $domain)) { fwrite(STDERR, "Ugyldig domene\n"); exit(1); }

$home = getenv('HOME') ?: dirname(__FILE__);
$base = 'https://raw.githubusercontent.com/ojdrang91/affiliate-dist/main/';
$state = "$home/.pull-deploy-$domain.sha";
$log = "$home/pull-deploy.log";
$target = "$home/public_html";

function logg($msg) { global $log; file_put_contents($log, date('c') . " $msg\n", FILE_APPEND); }
function hent($url) {
    $ctx = stream_context_create(['http' => ['timeout' => 60, 'header' => "Cache-Control: no-cache\r\nUser-Agent: affiliate-pull-deploy\r\n"]]);
    return @file_get_contents($url . '?t=' . time(), false, $ctx);
}

$manifest = json_decode((string) hent($base . 'manifest.json'), true);
if (!is_array($manifest) || empty($manifest[$domain]['sha256'])) exit(0); // ingenting publisert ennå
$want = $manifest[$domain]['sha256'];
if (is_file($state) && trim(file_get_contents($state)) === $want) exit(0); // allerede oppdatert

$zipData = hent($base . rawurlencode($domain) . '.zip');
if ($zipData === false || hash('sha256', $zipData) !== $want) { logg("$domain: nedlasting feilet eller feil sjekksum"); exit(1); }

$tmp = "$home/.pull-deploy-$domain.zip";
file_put_contents($tmp, $zipData);
$zip = new ZipArchive();
if ($zip->open($tmp) !== true) { logg("$domain: kunne ikke åpne zip"); exit(1); }
for ($i = 0; $i < $zip->numFiles; $i++) {           // stopp stier som prøver å gå ut av public_html
    $name = $zip->getNameIndex($i);
    if (strpos($name, '..') !== false || $name[0] === '/') { logg("$domain: ugyldig sti $name"); exit(1); }
}
$ok = $zip->extractTo($target);
$zip->close();
unlink($tmp);
if (!$ok) { logg("$domain: utpakking feilet"); exit(1); }
file_put_contents($state, $want);
logg("$domain: publisert " . substr($want, 0, 12) . " (" . ($manifest[$domain]['commit'] ?? '?') . ")");
