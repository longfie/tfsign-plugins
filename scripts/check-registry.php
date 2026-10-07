<?php

declare(strict_types=1);

/*
 * 商店 PR 审核检查。由 pull_request_target 从 main 分支运行，PR 内容只作为数据读取，
 * 不检出也不执行 PR 或插件仓库中的任何代码。正式发布时平台仓库还会按核心规则再校验一次。
 */

const TIERS = ['official', 'community'];
const RISK_LABELS = ['credentials', 'account_risk', 'unofficial_api'];
const MAX_TARBALL_BYTES = 20 * 1024 * 1024;

$options = [];
foreach (array_slice($argv, 1) as $argument) {
    if (preg_match('/^--([a-z-]+)=(.*)$/sD', $argument, $match)) {
        $options[$match[1]] = $match[2];
    }
}

$errors = [];
$warnings = [];
$sections = [];
$author = strtolower((string)($options['author'] ?? ''));
$isOwner = $author !== '' && $author === strtolower((string)($options['owner'] ?? ''));

function readRegistry(string $path, array &$errors, string $label): array
{
    $data = json_decode((string)@file_get_contents($path), true);
    if (!is_array($data) || ($data['schema_version'] ?? null) !== 1 || !is_array($data['plugins'] ?? null) || !array_is_list($data['plugins'])) {
        $errors[] = "{$label} registry.json 不是有效的审核清单（需要 schema_version=1 和 plugins 列表）";
        return [];
    }
    $plugins = [];
    foreach ($data['plugins'] as $entry) {
        $code = is_array($entry) && is_string($entry['code'] ?? null) ? $entry['code'] : '';
        if (!preg_match('/^[a-z][a-z0-9_]{1,63}$/D', $code)) {
            $errors[] = "{$label} 存在无效的插件 code";
            continue;
        }
        if (isset($plugins[$code])) {
            $errors[] = "{$label} 重复登记插件 {$code}";
        }
        $plugins[$code] = $entry;
    }
    return $plugins;
}

function validatePlugin(string $code, array $entry, array &$errors): void
{
    if (!is_string($entry['repository'] ?? null) || !preg_match('/^[A-Za-z0-9_.-]{1,100}\/[A-Za-z0-9_.-]{1,100}$/D', $entry['repository'])) {
        $errors[] = "{$code}：repository 必须是 owner/name";
    }
    $path = $entry['path'] ?? '.';
    if (!is_string($path) || !preg_match('/^[A-Za-z0-9._\/-]{1,200}$/D', $path) || preg_match('/(^|\/)\.\.($|\/)/', $path) || str_starts_with($path, '/')) {
        $errors[] = "{$code}：path 必须是仓库内相对路径";
    }
    $maintainers = $entry['maintainers'] ?? null;
    if (!is_array($maintainers) || !array_is_list($maintainers) || $maintainers === [] || count($maintainers) > 10
        || array_filter($maintainers, static fn ($login) => !is_string($login) || !preg_match('/^[A-Za-z0-9](?:[A-Za-z0-9-]{0,38})$/D', $login)) !== []) {
        $errors[] = "{$code}：maintainers 必须是 1～10 个 GitHub 用户名";
    }
    if (!in_array($entry['tier'] ?? null, TIERS, true)) {
        $errors[] = "{$code}：tier 必须是 official 或 community";
    }
    $labels = $entry['risk_labels'] ?? [];
    if (!is_array($labels) || !array_is_list($labels) || array_diff($labels, RISK_LABELS) !== [] || count(array_unique($labels)) !== count($labels)) {
        $errors[] = "{$code}：risk_labels 只能使用 " . implode('、', RISK_LABELS);
    }
    $releases = $entry['releases'] ?? [];
    if (!is_array($releases) || !array_is_list($releases)) {
        $errors[] = "{$code}：releases 必须是列表";
        return;
    }
    $seen = [];
    foreach ($releases as $release) {
        $version = is_array($release) ? ($release['version'] ?? null) : null;
        if (!is_string($version) || strlen($version) > 32 || !preg_match('/^\d+\.\d+\.\d+(?:[-+][0-9A-Za-z.-]+)?$/D', $version) || isset($seen[$version])) {
            $errors[] = "{$code}：版本号无效或重复";
            continue;
        }
        $seen[$version] = true;
        if (!is_string($release['commit'] ?? null) || !preg_match('/^[a-f0-9]{40}$/D', $release['commit'])) {
            $errors[] = "{$code} v{$version}：commit 必须是 40 位小写提交 SHA";
        }
        $revoked = $release['revoked'] ?? null;
        if ($revoked !== null && (!is_string($revoked) || trim($revoked) === '' || mb_strlen($revoked) > 200)) {
            $errors[] = "{$code} v{$version}：撤回原因必须是 1～200 个字符";
        }
    }
}

function fetchUrl(string $url, int $maxBytes): ?string
{
    $handle = curl_init($url);
    $body = '';
    curl_setopt_array($handle, [
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_USERAGENT => 'tfsign-plugin-store-check',
        CURLOPT_WRITEFUNCTION => static function ($curl, string $chunk) use (&$body, $maxBytes): int {
            if (strlen($body) + strlen($chunk) > $maxBytes) {
                return 0;
            }
            $body .= $chunk;
            return strlen($chunk);
        },
    ]);
    $ok = curl_exec($handle) === true && curl_getinfo($handle, CURLINFO_RESPONSE_CODE) === 200;
    curl_close($handle);
    return $ok ? $body : null;
}

function hostAllowed(string $host, array $patterns): bool
{
    foreach ($patterns as $pattern) {
        if ($host === $pattern || (str_starts_with($pattern, '*.') && str_ends_with($host, substr($pattern, 1)))) {
            return true;
        }
    }
    return false;
}

/** @return array{hosts:list<string>,composer_scripts:bool} */
function scanSource(string $repository, string $commit, string $path, array &$warnings, string $label): array
{
    $tarball = fetchUrl("https://codeload.github.com/{$repository}/tar.gz/{$commit}", MAX_TARBALL_BYTES);
    if ($tarball === null) {
        $warnings[] = "{$label}：无法下载源码包，未能扫描源码中的域名";
        return ['hosts' => [], 'composer_scripts' => false];
    }
    $file = sys_get_temp_dir() . '/store-src-' . bin2hex(random_bytes(8)) . '.tar.gz';
    file_put_contents($file, $tarball);
    $hosts = [];
    $composerScripts = false;
    try {
        $archive = new PharData($file);
        $prefix = trim($path, './') === '' ? '' : trim($path, '/') . '/';
        foreach (new RecursiveIteratorIterator($archive) as $entry) {
            $relative = preg_replace('#^phar://.+?\.tar\.gz/[^/]+/#', '', $entry->getPathname());
            if (!str_starts_with($relative, $prefix) || str_contains($relative, '/vendor/') || str_starts_with(substr($relative, strlen($prefix)), 'vendor/')) {
                continue;
            }
            $inner = substr($relative, strlen($prefix));
            if ($inner === 'composer.json') {
                $composer = json_decode((string)file_get_contents($entry->getPathname()), true);
                $composerScripts = is_array($composer) && !empty($composer['scripts']);
            }
            if (!preg_match('/\.(php|js|json)$/', $inner) || $entry->getSize() > 2_000_000 || in_array($inner, ['composer.json', 'composer.lock', 'plugin.json'], true)) {
                continue;
            }
            preg_match_all('#https?://([a-z0-9.-]+\.[a-z]{2,63})#i', (string)file_get_contents($entry->getPathname()), $matches);
            foreach ($matches[1] as $host) {
                $hosts[strtolower($host)] = true;
            }
        }
    } catch (Throwable $exception) {
        $warnings[] = "{$label}：源码包解析失败（{$exception->getMessage()}），未能扫描源码中的域名";
    } finally {
        @unlink($file);
    }
    ksort($hosts);
    return ['hosts' => array_keys($hosts), 'composer_scripts' => $composerScripts];
}

function checkRelease(array $entry, array $release, ?array $previous, array &$errors, array &$warnings): string
{
    $code = $entry['code'];
    $label = "{$code} v{$release['version']}";
    $repository = $entry['repository'];
    $commit = $release['commit'];
    $path = trim((string)($entry['path'] ?? '.'), '/');
    $manifestPath = ($path === '' || $path === '.') ? 'plugin.json' : "{$path}/plugin.json";
    $lines = ["### {$label}", '', "- 源码：https://github.com/{$repository}/tree/{$commit}"];
    if ($previous !== null) {
        $lines[] = "- 与上个版本 v{$previous['version']} 的差异：https://github.com/{$repository}/compare/{$previous['commit']}...{$commit}";
    } else {
        $lines[] = '- 首个版本，请完整审阅源码';
    }
    $manifest = json_decode((string)fetchUrl("https://raw.githubusercontent.com/{$repository}/{$commit}/{$manifestPath}", 1_048_576), true);
    if (!is_array($manifest)) {
        $errors[] = "{$label}：无法读取该提交的 {$manifestPath}，请确认仓库公开且提交存在";
        return implode("\n", $lines);
    }
    if (($manifest['code'] ?? null) !== $code || ($manifest['version'] ?? null) !== $release['version']) {
        $errors[] = "{$label}：plugin.json 是 " . json_encode([$manifest['code'] ?? null, $manifest['version'] ?? null], JSON_UNESCAPED_UNICODE) . '，与登记不一致';
    }
    if (($manifest['runtime']['mode'] ?? null) !== 'runner') {
        $errors[] = "{$label}：商店插件必须声明 runtime.mode=runner";
    }
    $hosts = $manifest['network_hosts'] ?? [];
    $validHosts = is_array($hosts) && array_is_list($hosts) && $hosts !== [] && count($hosts) <= 50
        && array_filter($hosts, static fn ($host) => !is_string($host) || !preg_match('/^(?:\*\.)?(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/D', $host)) === [];
    if (!$validHosts) {
        $errors[] = "{$label}：plugin.json 必须用 network_hosts 声明会访问的域名（小写域名，可用 *. 前缀）";
        $hosts = [];
    }
    if (!is_string($manifest['changelog'] ?? null) || trim($manifest['changelog']) === '') {
        $warnings[] = "{$label}：建议在 plugin.json 的 changelog 中写明本次更新内容";
    }
    $credentialFields = is_array($manifest['credential_labels'] ?? null) ? array_keys($manifest['credential_labels']) : [];
    $lines[] = '- 接入方式：' . (is_array($manifest['connection_methods'] ?? null) ? implode('、', $manifest['connection_methods']) : '未声明');
    $lines[] = '- 凭据字段：' . ($credentialFields === [] ? '无' : implode('、', $credentialFields));
    $lines[] = '- 动作：' . (is_array($manifest['actions'] ?? null) ? implode('、', array_map(
        static fn ($key, $action) => is_array($action) && is_string($action['label'] ?? null) ? "{$action['label']}（{$key}）" : (string)$key,
        array_keys($manifest['actions']),
        $manifest['actions'],
    )) : '未声明');
    $lines[] = '- 声明访问的域名：' . ($hosts === [] ? '无' : implode('、', $hosts));

    $scan = scanSource($repository, $commit, $path, $warnings, $label);
    $undeclared = array_values(array_filter($scan['hosts'], static fn (string $host) => !hostAllowed($host, $hosts)));
    $lines[] = '- 源码中出现但未声明的域名：' . ($undeclared === [] ? '无' : implode('、', $undeclared));
    if ($undeclared !== []) {
        $warnings[] = "{$label}：源码中出现未声明的域名 " . implode('、', $undeclared) . '，请确认是否会被访问';
    }
    if ($scan['composer_scripts']) {
        $warnings[] = "{$label}：composer.json 定义了 scripts，商店构建时不会执行这些脚本";
    }
    return implode("\n", $lines);
}

$base = readRegistry((string)($options['base'] ?? ''), $errors, '目标分支的');
$head = readRegistry((string)($options['head'] ?? ''), $errors, 'PR 中的');
foreach ($head as $code => $entry) {
    validatePlugin($code, $entry, $errors);
}

$changed = array_values(array_filter(array_map('trim', file((string)($options['changed'] ?? ''), FILE_IGNORE_NEW_LINES) ?: [])));
if (!$isOwner && array_diff($changed, ['registry.json']) !== []) {
    $errors[] = '非商店维护者的 PR 只能修改 registry.json，当前还修改了：' . implode('、', array_diff($changed, ['registry.json']));
}

foreach ($base as $code => $entry) {
    if (!isset($head[$code]) && !$isOwner) {
        $errors[] = "{$code}：只有商店维护者可以删除插件登记";
    }
}

foreach ($head as $code => $entry) {
    $before = $base[$code] ?? null;
    $maintainers = array_map('strtolower', is_array(($before ?? $entry)['maintainers'] ?? null) ? ($before ?? $entry)['maintainers'] : []);
    if (!$isOwner && !in_array($author, $maintainers, true)) {
        if ($before === null || $before !== $entry) {
            $errors[] = "{$code}：PR 作者 {$author} 不是该插件登记的维护者";
        }
        continue;
    }
    if ($before === null) {
        if (!$isOwner && ($entry['tier'] ?? null) !== 'community') {
            $errors[] = "{$code}：新插件的 tier 只能是 community，官方分级由商店维护者设置";
        }
    } elseif (!$isOwner) {
        foreach (['repository', 'path', 'maintainers', 'tier', 'risk_labels'] as $field) {
            if (($before[$field] ?? null) !== ($entry[$field] ?? null)) {
                $errors[] = "{$code}：{$field} 只能由商店维护者修改";
            }
        }
    }
    $beforeReleases = [];
    foreach (is_array($before['releases'] ?? null) ? $before['releases'] : [] as $release) {
        if (is_array($release) && is_string($release['version'] ?? null)) {
            $beforeReleases[$release['version']] = $release;
        }
    }
    $previous = null;
    foreach ($beforeReleases as $release) {
        if ($previous === null || version_compare($release['version'], $previous['version'], '>')) {
            $previous = $release;
        }
    }
    $headVersions = [];
    foreach (is_array($entry['releases'] ?? null) ? $entry['releases'] : [] as $release) {
        if (!is_array($release) || !is_string($release['version'] ?? null) || !is_string($release['commit'] ?? null)) {
            continue;
        }
        $headVersions[$release['version']] = true;
        $old = $beforeReleases[$release['version']] ?? null;
        if ($old !== null) {
            if (($old['commit'] ?? null) !== $release['commit']) {
                $errors[] = "{$code} v{$release['version']}：已审核版本的提交不能修改，请发布新版本号";
            }
            if (!$isOwner && ($old['revoked'] ?? null) !== ($release['revoked'] ?? null)) {
                $errors[] = "{$code} v{$release['version']}：撤回状态只能由商店维护者修改";
            }
            continue;
        }
        if (isset($release['revoked'])) {
            $errors[] = "{$code} v{$release['version']}：新版本不能直接标记撤回";
        }
        if ($previous !== null && version_compare($release['version'], $previous['version'], '<=')) {
            $errors[] = "{$code} v{$release['version']}：新版本号必须大于已登记的 v{$previous['version']}";
        }
        $sections[] = checkRelease(['code' => $code] + $entry, $release, $previous, $errors, $warnings);
        $previous = $release;
    }
    foreach (array_keys($beforeReleases) as $version) {
        if (!isset($headVersions[$version]) && !$isOwner) {
            $errors[] = "{$code} v{$version}：已审核版本不能删除，如需下架请联系商店维护者撤回";
        }
    }
}

$report = ['## 插件商店审核检查', ''];
$report[] = $errors === [] ? '自动检查通过，等待商店维护者人工审核。' : '自动检查未通过，请按下列问题修改后再提交。';
if ($errors !== []) {
    $report[] = '';
    $report[] = '**问题**';
    foreach ($errors as $error) {
        $report[] = "- {$error}";
    }
}
if ($warnings !== []) {
    $report[] = '';
    $report[] = '**需要审核时留意**';
    foreach ($warnings as $warning) {
        $report[] = "- {$warning}";
    }
}
if ($sections === [] && $errors === []) {
    $report[] = '';
    $report[] = '本次没有新增版本。';
}
foreach ($sections as $section) {
    $report[] = '';
    $report[] = $section;
}
$report[] = '';
$report[] = '审核要点见 [上架规则](https://github.com/' . (getenv('GITHUB_REPOSITORY') ?: 'longfie/tfsign-plugins') . '/blob/main/POLICY.md)。合并后平台仓库的发布工作流会在 2 小时内构建、签名并上架；维护者也可以手动触发。';
file_put_contents((string)($options['report'] ?? 'php://stdout'), implode("\n", $report) . "\n");
exit($errors === [] ? 0 : 1);
