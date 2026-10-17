<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Deploy;

use RivetCore\Rmm\Authz\RmmAbility;
use RivetCore\Rmm\Job\JobType;
use RivetCore\Rmm\Job\JobTypeRegistry;

/**
 * The Phase 5 job types and their STRICT parameter allow-lists. A validator keeps only the keys it knows, in a fixed order and with fixed types; an unknown key,
 * a wrong type or a value outside its set refuses the whole job. The Go agent validates the same contract again ({@see DeployProtocol}); it does not trust this.
 *
 * `software_detect` is read only. `software_install` defaults to `dry_run`; a real run needs `mode` = `run` spelled out. Both are queued only by their own services
 * ({@see DeployService}); {@see \RivetCore\Rmm\Technician\TechnicianActions::submitJob()} refuses them. The server never lets a technician type a command, an argument
 * list, a path or a download address: every value comes from a catalog row that an administrator saved after the same checks.
 *
 * @api
 */
final class DeployJobTypes
{
    public static function register(JobTypeRegistry $r): void
    {
        $both = ['windows', 'linux'];
        $r->register(new JobType(DeployProtocol::JOB_DETECT, RmmAbility::SOFTWARE_MANAGE, false, $both, false, self::detect(...), 600, true, DeployProtocol::CAP_DETECT));
        // destructive: offered at most once and never re-sent when the acknowledgement is lost (an install must not run twice by accident).
        $r->register(new JobType(DeployProtocol::JOB_INSTALL, RmmAbility::SOFTWARE_DEPLOY, true, $both, false, self::install(...), 7500, true, DeployProtocol::CAP_INSTALL));
    }

    /**
     * The only parameter is `rules`: a JSON text (a job parameter must be a flat string, number or boolean) holding a list of at most
     * {@see DeployProtocol::DETECT_ITEMS_MAX} rules, each with exactly `key`, `kind`, `value` and `min`. Build it with {@see self::encodeRules()}.
     *
     * @param array<array-key,mixed> $p
     * @return array{0:array<string,mixed>,1:?string}
     */
    public static function detect(array $p): array
    {
        if (array_keys($p) !== ['rules'] || !is_string($p['rules']) || strlen($p['rules']) > DeployProtocol::RULES_MAX_CHARS) {
            return [$p, 'Only rules is accepted: a JSON list of at most ' . DeployProtocol::RULES_MAX_CHARS . ' characters.'];
        }
        try {
            $items = json_decode($p['rules'], true, 4, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [$p, 'rules is not valid JSON.'];
        }
        if (!is_array($items) || !array_is_list($items) || $items === [] || count($items) > DeployProtocol::DETECT_ITEMS_MAX) {
            return [$p, 'rules must be a list of 1 to ' . DeployProtocol::DETECT_ITEMS_MAX . ' detection rules.'];
        }
        $out = [];
        $keys = [];
        foreach ($items as $it) {
            if (!is_array($it) || array_diff(array_keys($it), ['key', 'kind', 'value', 'min']) !== [] || array_diff(['key', 'kind', 'value', 'min'], array_keys($it)) !== []) {
                return [$p, 'A detection rule has exactly key, kind, value and min.'];
            }
            $key = $it['key'];
            $kind = $it['kind'];
            if (!is_string($key) || preg_match(DeployProtocol::KEY_RE, $key) !== 1 || isset($keys[$key]) || !is_string($kind)) {
                return [$p, 'A detection key is not valid or is used twice.'];
            }
            // The operating system is not known here: the kind decides it (package = Linux; the other two = Windows).
            $os = $kind === 'package' ? 'linux' : 'windows';
            if (!DeployProtocol::detectOk($kind, $it['value'], $it['min'], $os)) {
                return [$p, 'A detection rule is not valid.'];
            }
            $keys[$key] = true;
            $out[] = ['key' => $key, 'kind' => $kind, 'value' => $it['value'], 'min' => $it['min']];
        }
        $text = json_encode($out, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return [['rules' => $text === false ? $p['rules'] : $text], null];
    }

    /**
     * The `rules` parameter for a list of rules.
     *
     * @param list<array{key:string,kind:string,value:string,min:string}> $rules
     */
    public static function encodeRules(array $rules): string
    {
        return (string) json_encode(array_map(static fn (array $r): array => ['key' => $r['key'], 'kind' => $r['kind'], 'value' => $r['value'], 'min' => $r['min']], $rules), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * @param array<array-key,mixed> $p
     * @return array{0:array<string,mixed>,1:?string}
     */
    public static function install(array $p): array
    {
        $allowed = ['action', 'mode', 'fmt', 'pkg', 'version', 'url', 'sha256', 'size', 'args', 'product_code', 'detect_kind', 'detect_value', 'detect_min', 'max_minutes'];
        if (array_diff(array_keys($p), $allowed) !== []) {
            return [$p, 'An unknown parameter was given.'];
        }
        $bad = static fn (string $why): array => [$p, $why];
        $action = $p['action'] ?? null;
        $fmt = $p['fmt'] ?? null;
        $mode = $p['mode'] ?? 'dry_run';
        if (!is_string($action) || !in_array($action, DeployProtocol::ACTIONS, true)) {
            return $bad('action must be install or uninstall.');
        }
        if (!is_string($fmt) || !in_array($fmt, DeployProtocol::FORMATS, true)) {
            return $bad('fmt must be apt, dnf, msi or exe.');
        }
        if (!is_string($mode) || !in_array($mode, DeployProtocol::MODES, true)) {
            return $bad('mode must be dry_run or run.');
        }
        $mins = $p['max_minutes'] ?? DeployProtocol::MAX_MINUTES_DEFAULT;
        if (!is_int($mins) || $mins < 1 || $mins > DeployProtocol::MAX_MINUTES_MAX) {
            return $bad('max_minutes must be 1 to ' . DeployProtocol::MAX_MINUTES_MAX . '.');
        }
        $linux = $fmt === 'apt' || $fmt === 'dnf';
        $out = ['action' => $action, 'mode' => $mode, 'fmt' => $fmt];
        // Linux package: a name, optionally a version to pin (install only); never a url, hash, size, args or product code.
        if ($linux) {
            $pkg = $p['pkg'] ?? null;
            if (!is_string($pkg) || preg_match(DeployProtocol::PACKAGE_RE, $pkg) !== 1) {
                return $bad('pkg is not a valid package name.');
            }
            foreach (['url', 'sha256', 'size', 'args', 'product_code', 'detect_kind', 'detect_value', 'detect_min'] as $k) {
                if (array_key_exists($k, $p)) {
                    return $bad("$k does not apply to a package manager install.");
                }
            }
            $out['pkg'] = $pkg;
            if (array_key_exists('version', $p)) {
                $v = $p['version'];
                if ($action !== 'install' || !is_string($v) || preg_match(DeployProtocol::PIN_RE, $v) !== 1) {
                    return $bad('version is only for an install and must be a plain version.');
                }
                $out['version'] = $v;
            }
            $out['max_minutes'] = $mins;

            return [$out, null];
        }
        // Windows: msi or exe.
        foreach (['pkg', 'version'] as $k) {
            if (array_key_exists($k, $p)) {
                return $bad("$k does not apply to a Windows installer.");
            }
        }
        $code = $p['product_code'] ?? null;
        if ($fmt === 'exe' && ($code !== null || $action === 'uninstall')) {
            return $bad('An exe installer is install only and has no product code.');
        }
        if ($code !== null && (!is_string($code) || preg_match(DeployProtocol::PRODUCT_CODE_RE, $code) !== 1)) {
            return $bad('product_code is not valid.');
        }
        if ($action === 'uninstall') {
            if ($code === null) {
                return $bad('Removing an msi needs its product_code.');
            }
            foreach (['url', 'sha256', 'size', 'args', 'detect_kind', 'detect_value', 'detect_min'] as $k) {
                if (array_key_exists($k, $p)) {
                    return $bad("$k does not apply to an uninstall.");
                }
            }
            $out['product_code'] = $code;
            $out['max_minutes'] = $mins;

            return [$out, null];
        }
        $url = $p['url'] ?? null;
        $sha = $p['sha256'] ?? null;
        $size = $p['size'] ?? null;
        if (SourceUrl::host($url) === null || $url !== SourceUrl::normalise($url)) {
            return $bad('url must be a plain https address (lower-case host name, no user information, default port).');
        }
        if (!is_string($sha) || preg_match(DeployProtocol::SHA256_RE, $sha) !== 1) {
            return $bad('sha256 must be 64 lower-case hex digits.');
        }
        if (!is_int($size) || $size < 1 || $size > DeployProtocol::SIZE_MAX) {
            return $bad('size must be 1 to ' . DeployProtocol::SIZE_MAX . ' bytes.');
        }
        $args = $p['args'] ?? '';
        if ($fmt === 'msi' && array_key_exists('args', $p)) {
            return $bad('An msi is installed with fixed options; args is for an exe.');
        }
        if (!is_string($args) || !DeployProtocol::argsOk($args)) {
            return $bad('args holds characters or tokens that are not allowed.');
        }
        $out += ['url' => $url, 'sha256' => $sha, 'size' => $size];
        if ($fmt === 'exe') {
            $out['args'] = $args;
        }
        if ($code !== null) {
            $out['product_code'] = $code;
        }
        // Detection. An msi is detected by its product code (so it carries at most a minimum version and never a second rule); an exe needs a rule of its own: all of kind, value and min.
        $dk = $p['detect_kind'] ?? null;
        if ($code !== null) {
            if ($dk !== null || array_key_exists('detect_value', $p)) {
                return $bad('An msi is detected by its product code; it carries no other detection rule.');
            }
            if (array_key_exists('detect_min', $p)) {
                $dm = $p['detect_min'];
                if (!is_string($dm) || ($dm !== '' && preg_match(DeployProtocol::MIN_RE, $dm) !== 1)) {
                    return $bad('detect_min is not a valid version.');
                }
                $out['detect_min'] = $dm;
            }
        } else {
            if (!is_string($dk) || !array_key_exists('detect_value', $p) || !DeployProtocol::detectOk($dk, $p['detect_value'], $p['detect_min'] ?? '', 'windows')) {
                return $bad('An exe installer needs a valid detection rule.');
            }
            $out += ['detect_kind' => $dk, 'detect_value' => $p['detect_value'], 'detect_min' => $p['detect_min'] ?? ''];
        }
        $out['max_minutes'] = $mins;

        return [$out, null];
    }
}
