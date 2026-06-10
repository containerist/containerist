<?php
/**
 * Containerist linter.
 *
 * Mechanical checks against the eight pillars. Catches the straightforward
 * violations — the ones regex can see. The Claude skill
 * (.claude/skills/containerist-review/) handles the semantic review on top.
 *
 * Usage:
 *   php lint.php                    # lint the current directory
 *   php lint.php path/to/project    # lint a specific project root
 *
 * Exit codes:
 *   0 — clean or warnings only
 *   1 — at least one error
 *
 * Requires PHP 8.0+.
 */

$root = rtrim($argv[1] ?? '.', '/');
$modules_dir    = "$root/modules";
$stacks_dir     = "$root/stacks";
$containers_dir = "$root/containers";
$skin_dir       = "$root/skin";

$issues = [];

function issue(string $severity, string $file, int $line, string $msg): void {
  global $issues;
  $issues[] = compact('severity', 'file', 'line', 'msg');
}

function find_files(string $dir, string $pattern): array {
  if (!is_dir($dir)) return [];
  $out = [];
  $iter = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS)
  );
  foreach ($iter as $f) {
    if ($f->isFile() && fnmatch($pattern, $f->getFilename())) {
      $out[] = $f->getPathname();
    }
  }
  sort($out);
  return $out;
}

/* -----------------------------------------------------------------
 * Mod file checks (modules/** / *.php)
 * ----------------------------------------------------------------- */

function check_mod(string $path): void {
  $src = file_get_contents($path);
  $lines = explode("\n", $src);

  // 1. @in declaration (required as contract, even if empty)
  $in_decl = null;
  $in_line = 1;
  foreach ($lines as $i => $line) {
    if (preg_match('/^\s*\/\/\s*@in:\s*(.*)$/i', $line, $m)) {
      $in_decl = trim($m[1]);
      $in_line = $i + 1;
      break;
    }
  }
  if ($in_decl === null) {
    issue('warn', $path, 1,
      'no @in declaration found (use `// @in:` with an empty list if the mod takes no args)');
  }

  // 2. Parse declared inputs
  $declared = [];
  if ($in_decl !== null && $in_decl !== '') {
    foreach (explode(',', $in_decl) as $part) {
      $part = trim($part);
      if (preg_match('/^([a-zA-Z_][a-zA-Z0-9_]*)/', $part, $m)) {
        $declared[] = $m[1];
      }
    }
  }

  // 3. Forbidden realm-locking patterns
  $forbidden = [
    '/\$_GET\b/'              => 'reads $_GET directly — realm-locked (declare @in and let the realm adapter populate)',
    '/\$_POST\b/'             => 'reads $_POST directly — realm-locked',
    '/\$_SESSION\b/'          => 'reads $_SESSION — realm-locked',
    '/\$_SERVER\b/'           => 'reads $_SERVER — realm-locked',
    '/\$_FILES\b/'            => 'reads $_FILES — realm-locked (upload mods should be thin edge layers)',
    '/\bsession_start\s*\(/'  => 'calls session_start() — realm-locked',
    '/\bSTDIN\b/'             => 'reads STDIN directly — realm-locked',
    '/php:\/\/stdin/'         => 'reads php://stdin — realm-locked',
    '/\bheader\s*\(/'         => 'emits HTTP headers — realm-locked (realm adapters own headers)',
    '/\bob_start\s*\(/'       => 'calls ob_start — collides with core output capture',
    '/\bob_get_clean\s*\(/'   => 'calls ob_get_clean — collides with core output capture',
    '/\bexit\s*[;(]/'          => 'calls exit — realm adapters, not mods, control flow termination',
    '/\bdie\s*[;(]/'           => 'calls die — see exit',
  ];
  foreach ($lines as $i => $line) {
    if (preg_match('/^\s*\/\//', $line)) continue;      // skip line comments
    if (preg_match('/^\s*\*/', $line))  continue;       // skip docblock body
    foreach ($forbidden as $pat => $msg) {
      if (preg_match($pat, $line)) {
        issue('error', $path, $i + 1, $msg);
      }
    }
  }

  // 4. Variables referenced but not declared in @in and not assigned locally.
  //    Best-effort regex; false positives are acceptable for a first pass.
  $reads = [];
  foreach ($lines as $i => $line) {
    if (preg_match('/^\s*\/\//', $line)) continue;
    if (preg_match_all('/\$([a-zA-Z_][a-zA-Z0-9_]*)/', $line, $m)) {
      foreach ($m[1] as $v) {
        $reads[$v] = $reads[$v] ?? $i + 1;
      }
    }
  }

  $assigned = [
    'C' => true, 'this' => true, 'argc' => true, 'argv' => true,
    'GLOBALS' => true,
  ];
  foreach ($declared as $d) $assigned[$d] = true;

  foreach ($lines as $line) {
    // $x = ...
    if (preg_match_all('/\$([a-zA-Z_][a-zA-Z0-9_]*)\s*=[^=]/', $line, $m)) {
      foreach ($m[1] as $v) $assigned[$v] = true;
    }
    // foreach (... as $v) / foreach (... as $k => $v)
    if (preg_match_all(
      '/\bforeach\s*\(.+?\s+as\s+(?:\$([a-zA-Z_][a-zA-Z0-9_]*)\s*=>\s*)?\$([a-zA-Z_][a-zA-Z0-9_]*)/',
      $line, $m)) {
      foreach ($m[1] as $v) if ($v) $assigned[$v] = true;
      foreach ($m[2] as $v) $assigned[$v] = true;
    }
    // list($a, $b) = ...  or  [$a, $b] = ...
    if (preg_match_all('/(?:list\s*\(|\[)\s*\$([a-zA-Z_][a-zA-Z0-9_]*)/', $line, $m)) {
      foreach ($m[1] as $v) $assigned[$v] = true;
    }
    // function (...$params...)
    if (preg_match_all('/\bfunction\s*\w*\s*\(([^)]*)\)/', $line, $m)) {
      foreach ($m[1] as $params) {
        if (preg_match_all('/\$([a-zA-Z_][a-zA-Z0-9_]*)/', $params, $pm)) {
          foreach ($pm[1] as $p) $assigned[$p] = true;
        }
      }
    }
    // catch (... $e)
    if (preg_match_all('/\bcatch\s*\([^)]*\$([a-zA-Z_][a-zA-Z0-9_]*)/', $line, $m)) {
      foreach ($m[1] as $v) $assigned[$v] = true;
    }
  }

  foreach ($reads as $var => $ln) {
    if (!isset($assigned[$var])) {
      issue('warn', $path, $ln,
        "\$$var used but not declared in @in and not assigned locally");
    }
  }

  // 5. Does the mod emit CTN?
  $emits_ctn = preg_match('/["\']\s*CTN\s*:/', $src) || preg_match('/^\s*CTN\s*:/m', $src);
  if (!$emits_ctn) {
    issue('info', $path, 1,
      'no `CTN:` emission found — mod may rely on implicit-standard body output (intentional?)');
  }
}

/* -----------------------------------------------------------------
 * Stack file checks (stacks/*.txt)
 * ----------------------------------------------------------------- */

function check_stack(string $path): void {
  global $modules_dir, $containers_dir;
  $src = file_get_contents($path);
  $lines = explode("\n", $src);

  // Must start with CTN: stack (ignoring leading comments / blanks)
  $first_real = null;
  foreach ($lines as $line) {
    $t = trim($line);
    if ($t === '' || preg_match('/^#/', $t)) continue;
    $first_real = $t;
    break;
  }
  if (!preg_match('/^CTN\s*:\s*stack\b/i', $first_real ?? '')) {
    issue('error', $path, 1, 'stack file must begin with `CTN: stack`');
  }

  // No branching, no templating, no embedded code
  $forbidden = [
    '/^\s*@if\b/'        => 'stacks are flat — no @if branching',
    '/^\s*@foreach\b/'   => 'stacks are flat — no loops',
    '/\{\{.*\}\}/'       => 'stacks are flat — no template interpolation',
    '/^\s*<\?/'          => 'stacks are flat — no embedded PHP',
  ];
  foreach ($lines as $i => $line) {
    foreach ($forbidden as $pat => $msg) {
      if (preg_match($pat, $line)) {
        issue('error', $path, $i + 1, $msg);
      }
    }
  }

  // After the --- separator, each non-blank non-comment non-directive line
  // should be a container name that resolves to a mod or a static.
  $in_body = false;
  foreach ($lines as $i => $line) {
    $t = trim($line);
    if ($t === '---') { $in_body = true; continue; }
    if (!$in_body) continue;
    if ($t === '' || preg_match('/^#/', $t) || preg_match('/^@/', $t)) continue;

    if (!find_container($t)) {
      issue('warn', $path, $i + 1,
        "container `$t` not found under modules/ or containers/");
    }
  }
}

function find_container(string $name): ?string {
  global $modules_dir, $containers_dir;
  foreach (find_files($modules_dir, "$name.ctn.php") as $f) return $f;
  foreach (find_files($modules_dir, "$name.php")     as $f) return $f;
  foreach (find_files($containers_dir, "$name.txt")  as $f) return $f;
  return null;
}

/* -----------------------------------------------------------------
 * Skin coverage check
 * ----------------------------------------------------------------- */

function check_skin_coverage(): void {
  global $modules_dir, $containers_dir, $skin_dir;

  $types = ['standard' => true];  // implicit default

  $sources = array_merge(
    find_files($modules_dir,    '*.ctn.php'),
    find_files($modules_dir,    '*.php'),
    find_files($containers_dir, '*.txt')
  );
  foreach ($sources as $f) {
    $src = file_get_contents($f);
    if (preg_match_all('/CTN\s*:\s*([a-z][a-z0-9_-]*)/i', $src, $m)) {
      foreach ($m[1] as $t) $types[strtolower($t)] = true;
    }
  }

  if (!is_dir($skin_dir)) {
    issue('info', 'skin/', 0, 'no skin/ directory present — no coverage check');
    return;
  }

  foreach ($types as $type => $_) {
    if (!is_file("$skin_dir/$type.html")) {
      issue('warn', 'skin/', 0, "CTN type `$type` emitted but skin/$type.html is missing");
    }
  }
}

/* -----------------------------------------------------------------
 * Run
 * ----------------------------------------------------------------- */

foreach (find_files($modules_dir, '*.php') as $f) {
  check_mod($f);
}
foreach (find_files($stacks_dir, '*.txt') as $f) {
  check_stack($f);
}
check_skin_coverage();

if (!$issues) {
  echo "clean.\n";
  exit(0);
}

usort($issues, fn($a, $b) =>
  strcmp($a['file'], $b['file']) ?: ($a['line'] <=> $b['line']));

$counts = ['error' => 0, 'warn' => 0, 'info' => 0];
foreach ($issues as $i) {
  printf("%-5s %s:%d  %s\n",
    strtoupper($i['severity']), $i['file'], $i['line'], $i['msg']);
  $counts[$i['severity']]++;
}
printf("\n%d error(s), %d warning(s), %d info.\n",
  $counts['error'], $counts['warn'], $counts['info']);
exit($counts['error'] > 0 ? 1 : 0);
