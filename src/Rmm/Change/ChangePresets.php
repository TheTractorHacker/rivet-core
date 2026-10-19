<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Change;

/**
 * The named watch presets of change monitoring. A preset is a code constant, not data: an administrator turns presets on and off, and the signed configuration carries
 * exactly the watches below, so what a device reads is always one of these lists or a custom watch that passed {@see \RivetCore\Rmm\Contracts\ChangeWatchPolicyInterface}.
 *
 * Watch specs (the wire form, docs/rmm/PROTOCOL.md 3.9):
 *   registry  {"id","kind":"registry","paths":[{"key","depth","values"?}]}   `depth` 0 = the key's own values, N = also subkeys down to N levels; `values` = only these value names
 *             (case-insensitive); a key starting `HKU\*\` is read once for every loaded user hive
 *   file      {"id","kind":"file","paths":[{"path","depth"?,"match"?}]}      a file, or a folder read down to `depth` levels (default 1), `match` a name filter with `*` and `?`
 *   group     {"id","kind":"group","name":"Administrators"}                  the members of a local group (Windows `net localgroup`)
 *   task      {"id","kind":"task"}                                           the Windows scheduled task definitions
 *
 * @api
 */
final class ChangePresets
{
    private const RUN = 'SOFTWARE\\Microsoft\\Windows\\CurrentVersion\\';

    /**
     * @return array<string,array<string,mixed>> id => {platform, kind, title, description, category, recommended, rule (null or the recommended alert rule), source?, watch}
     */
    public static function catalog(): array
    {
        $r = self::RUN;
        $reg = static fn (string $key, int $depth = 0, ?array $values = null): array => ['key' => $key, 'depth' => $depth] + ($values === null ? [] : ['values' => $values]);
        $file = static fn (string $path, int $depth = 0, ?string $match = null): array => ['path' => $path] + ($depth > 0 ? ['depth' => $depth] : []) + ($match === null ? [] : ['match' => $match]);
        $any = ['rule' => 'any'];

        $p = [];
        $p['win.startup'] = ['platform' => 'windows', 'kind' => 'registry', 'category' => 'persistence', 'recommended' => true, 'rule' => $any,
            'title' => 'Startup entries', 'description' => 'Run and RunOnce keys (machine, 32-bit and every loaded user), the Winlogon shell and userinit, AppInit DLLs and Image File Execution Options debuggers: the places a program is made to start at logon or boot.',
            'watch' => ['kind' => 'registry', 'paths' => [
                $reg("HKLM\\{$r}Run"), $reg("HKLM\\{$r}RunOnce"), $reg("HKLM\\{$r}RunOnceEx", 1), $reg("HKLM\\{$r}Policies\\Explorer\\Run"),
                $reg("HKLM\\SOFTWARE\\WOW6432Node\\Microsoft\\Windows\\CurrentVersion\\Run"), $reg("HKLM\\SOFTWARE\\WOW6432Node\\Microsoft\\Windows\\CurrentVersion\\RunOnce"),
                $reg("HKU\\*\\{$r}Run"), $reg("HKU\\*\\{$r}RunOnce"),
                $reg('HKLM\\SOFTWARE\\Microsoft\\Windows NT\\CurrentVersion\\Winlogon', 0, ['Shell', 'Userinit', 'Taskman', 'VMApplet', 'AppSetup', 'GinaDLL']),
                $reg('HKLM\\SOFTWARE\\Microsoft\\Windows NT\\CurrentVersion\\Windows', 0, ['AppInit_DLLs', 'LoadAppInit_DLLs', 'RequireSignedAppInit_DLLs']),
                $reg('HKLM\\SOFTWARE\\Microsoft\\Windows NT\\CurrentVersion\\Image File Execution Options', 1, ['Debugger', 'GlobalFlag', 'VerifierDlls']),
            ]]];
        $p['win.services'] = ['platform' => 'windows', 'kind' => 'registry', 'category' => 'persistence', 'recommended' => true, 'rule' => null,
            'title' => 'Services configuration', 'description' => 'For every service: start type, program (ImagePath), account (ObjectName), type and ServiceDll. A new service key appears as an added item.',
            'watch' => ['kind' => 'registry', 'paths' => [$reg('HKLM\\SYSTEM\\CurrentControlSet\\Services', 2, ['Start', 'ImagePath', 'ObjectName', 'Type', 'DelayedAutostart', 'ServiceDll'])]]];
        $p['win.defender'] = ['platform' => 'windows', 'kind' => 'registry', 'category' => 'security_policy', 'recommended' => true, 'rule' => $any,
            'title' => 'Windows Defender exclusions and policy', 'description' => 'Excluded paths, extensions and processes, the Defender group policy, real-time protection and tamper protection settings.',
            'watch' => ['kind' => 'registry', 'paths' => [
                $reg('HKLM\\SOFTWARE\\Microsoft\\Windows Defender\\Exclusions', 2), $reg('HKLM\\SOFTWARE\\Policies\\Microsoft\\Windows Defender', 4),
                $reg('HKLM\\SOFTWARE\\Microsoft\\Windows Defender\\Real-Time Protection'), $reg('HKLM\\SOFTWARE\\Microsoft\\Windows Defender\\Features', 0, ['TamperProtection', 'TamperProtectionSource']),
                $reg('HKLM\\SOFTWARE\\Microsoft\\Windows Defender', 0, ['DisableAntiSpyware', 'DisableAntiVirus', 'PUAProtection']), $reg('HKLM\\SOFTWARE\\Microsoft\\Windows Defender\\Spynet'),
            ]]];
        $p['win.firewall'] = ['platform' => 'windows', 'kind' => 'registry', 'category' => 'security_policy', 'recommended' => true, 'rule' => null,
            'title' => 'Windows Firewall policy', 'description' => 'The firewall profiles (on or off, default actions) and every rule, local and from group policy.',
            'watch' => ['kind' => 'registry', 'paths' => [$reg('HKLM\\SYSTEM\\CurrentControlSet\\Services\\SharedAccess\\Parameters\\FirewallPolicy', 3), $reg('HKLM\\SOFTWARE\\Policies\\Microsoft\\WindowsFirewall', 3)]]];
        $p['win.rdp'] = ['platform' => 'windows', 'kind' => 'registry', 'category' => 'security_policy', 'recommended' => true, 'rule' => $any,
            'title' => 'Remote Desktop settings', 'description' => 'Whether Remote Desktop is on, its port, network level authentication and security layer, and the Remote Desktop group policy.',
            'watch' => ['kind' => 'registry', 'paths' => [
                $reg('HKLM\\SYSTEM\\CurrentControlSet\\Control\\Terminal Server', 0, ['fDenyTSConnections', 'fSingleSessionPerUser', 'TSUserEnabled', 'AllowRemoteRPC']),
                $reg('HKLM\\SYSTEM\\CurrentControlSet\\Control\\Terminal Server\\WinStations\\RDP-Tcp', 0, ['PortNumber', 'UserAuthentication', 'SecurityLayer', 'MinEncryptionLevel', 'fEnableWinStation']),
                $reg('HKLM\\SOFTWARE\\Policies\\Microsoft\\Windows NT\\Terminal Services', 1),
            ]]];
        $p['win.uac'] = ['platform' => 'windows', 'kind' => 'registry', 'category' => 'security_policy', 'recommended' => true, 'rule' => null,
            'title' => 'User Account Control settings', 'description' => 'EnableLUA, the consent prompts, the secure desktop and the remote token filter policy.',
            'watch' => ['kind' => 'registry', 'paths' => [$reg("HKLM\\{$r}Policies\\System", 0, ['EnableLUA', 'ConsentPromptBehaviorAdmin', 'ConsentPromptBehaviorUser', 'PromptOnSecureDesktop',
                'LocalAccountTokenFilterPolicy', 'FilterAdministratorToken', 'EnableInstallerDetection', 'EnableVirtualization', 'ValidateAdminCodeSignatures', 'DisableCAD', 'DontDisplayLastUserName'])]]];
        $p['win.update_policy'] = ['platform' => 'windows', 'kind' => 'registry', 'category' => 'security_policy', 'recommended' => false, 'rule' => null,
            'title' => 'Windows Update policy', 'description' => 'The Windows Update group policy (update source, deferral, automatic update behaviour).',
            'watch' => ['kind' => 'registry', 'paths' => [$reg('HKLM\\SOFTWARE\\Policies\\Microsoft\\Windows\\WindowsUpdate', 1), $reg("HKLM\\{$r}WindowsUpdate\\Auto Update")]]];
        $p['win.local_admins'] = ['platform' => 'windows', 'kind' => 'group', 'category' => 'accounts', 'recommended' => true, 'rule' => 'added',
            'title' => 'Local administrators', 'description' => 'The members of the local Administrators group, read with net localgroup (not from the registry).',
            'watch' => ['kind' => 'group', 'name' => 'Administrators']];
        $p['win.tasks'] = ['platform' => 'windows', 'kind' => 'task', 'category' => 'persistence', 'recommended' => true, 'rule' => null,
            'title' => 'Scheduled tasks', 'description' => 'Every scheduled task definition: its program and arguments, the account it runs as and whether it is enabled. Read from the task definition files.',
            'watch' => ['kind' => 'task']];
        $p['win.hosts'] = ['platform' => 'windows', 'kind' => 'file', 'category' => 'network', 'recommended' => true, 'rule' => null,
            'title' => 'hosts file', 'description' => 'The Windows hosts file (a changed hash and the lines that were added or removed).',
            'watch' => ['kind' => 'file', 'paths' => [$file('C:\\Windows\\System32\\drivers\\etc\\hosts')]]];
        $p['win.software'] = ['platform' => 'windows', 'kind' => 'inventory', 'category' => 'software', 'recommended' => true, 'rule' => null, 'source' => 'inventory',
            'title' => 'Installed software', 'description' => 'Not read again: installs, removals and version changes come from the software inventory (the inventory_software switch) and are merged into the timeline.',
            'watch' => null];

        $p['lnx.ssh'] = ['platform' => 'linux', 'kind' => 'file', 'category' => 'security_policy', 'recommended' => true, 'rule' => $any,
            'title' => 'SSH server and root keys', 'description' => 'sshd_config and its drop-ins, and root\'s authorized_keys.',
            'watch' => ['kind' => 'file', 'paths' => [$file('/etc/ssh/sshd_config'), $file('/etc/ssh/sshd_config.d', 1, '*.conf'), $file('/root/.ssh/authorized_keys')]]];
        $p['lnx.sudo'] = ['platform' => 'linux', 'kind' => 'file', 'category' => 'accounts', 'recommended' => true, 'rule' => $any,
            'title' => 'sudo rules', 'description' => '/etc/sudoers and /etc/sudoers.d.',
            'watch' => ['kind' => 'file', 'paths' => [$file('/etc/sudoers'), $file('/etc/sudoers.d', 1)]]];
        $p['lnx.accounts'] = ['platform' => 'linux', 'kind' => 'file', 'category' => 'accounts', 'recommended' => true, 'rule' => $any,
            'title' => 'Users and groups', 'description' => '/etc/passwd and /etc/group (never /etc/shadow). The password field of a passwd line is never kept.',
            'watch' => ['kind' => 'file', 'paths' => [$file('/etc/passwd'), $file('/etc/group')]]];
        $p['lnx.cron'] = ['platform' => 'linux', 'kind' => 'file', 'category' => 'persistence', 'recommended' => true, 'rule' => null,
            'title' => 'Cron jobs', 'description' => '/etc/crontab, /etc/cron.d, the hourly, daily, weekly and monthly folders and the user crontabs.',
            'watch' => ['kind' => 'file', 'paths' => [$file('/etc/crontab'), $file('/etc/cron.d', 1), $file('/etc/cron.hourly', 1), $file('/etc/cron.daily', 1), $file('/etc/cron.weekly', 1), $file('/etc/cron.monthly', 1), $file('/var/spool/cron', 2)]]];
        $p['lnx.systemd'] = ['platform' => 'linux', 'kind' => 'file', 'category' => 'persistence', 'recommended' => true, 'rule' => null,
            'title' => 'systemd units', 'description' => 'Unit files, drop-ins and enable links under /etc/systemd/system and /usr/local/lib/systemd/system (not the package-managed unit folders).',
            'watch' => ['kind' => 'file', 'paths' => [$file('/etc/systemd/system', 2), $file('/usr/local/lib/systemd/system', 1)]]];
        $p['lnx.startup'] = ['platform' => 'linux', 'kind' => 'file', 'category' => 'persistence', 'recommended' => false, 'rule' => null,
            'title' => 'Shell and boot hooks', 'description' => '/etc/ld.so.preload, rc.local, /etc/profile, /etc/profile.d and /etc/environment.',
            'watch' => ['kind' => 'file', 'paths' => [$file('/etc/ld.so.preload'), $file('/etc/rc.local'), $file('/etc/profile'), $file('/etc/profile.d', 1), $file('/etc/environment')]]];
        $p['lnx.hosts'] = ['platform' => 'linux', 'kind' => 'file', 'category' => 'network', 'recommended' => false, 'rule' => null,
            'title' => 'Name resolution and TCP wrappers', 'description' => '/etc/hosts, nsswitch.conf, hosts.allow and hosts.deny.',
            'watch' => ['kind' => 'file', 'paths' => [$file('/etc/hosts'), $file('/etc/nsswitch.conf'), $file('/etc/hosts.allow'), $file('/etc/hosts.deny')]]];
        $p['lnx.pam'] = ['platform' => 'linux', 'kind' => 'file', 'category' => 'security_policy', 'recommended' => false, 'rule' => null,
            'title' => 'PAM configuration', 'description' => '/etc/pam.d.',
            'watch' => ['kind' => 'file', 'paths' => [$file('/etc/pam.d', 1)]]];
        $p['lnx.software'] = ['platform' => 'linux', 'kind' => 'inventory', 'category' => 'software', 'recommended' => true, 'rule' => null, 'source' => 'inventory',
            'title' => 'Installed software', 'description' => 'Not read again: package installs, removals and version changes come from the software inventory (the inventory_software switch) and are merged into the timeline.',
            'watch' => null];

        return $p;
    }

    /** @return list<string> */
    public static function ids(?string $platform = null): array
    {
        $out = [];
        foreach (self::catalog() as $id => $p) {
            if ($platform === null || $p['platform'] === $platform) {
                $out[] = $id;
            }
        }

        return $out;
    }

    /** Presets that put a watch in the signed configuration (the inventory ones do not). */
    public static function isWatch(string $id): bool
    {
        $c = self::catalog()[$id] ?? null;

        return $c !== null && $c['watch'] !== null;
    }

    /**
     * The watch specs for a platform, from the enabled preset ids.
     *
     * @param list<string> $enabled
     * @return list<array<string,mixed>>
     */
    public static function watchesFor(string $platform, array $enabled): array
    {
        $out = [];
        foreach (self::catalog() as $id => $p) {
            if ($p['platform'] === $platform && $p['watch'] !== null && in_array($id, $enabled, true)) {
                $out[] = ['id' => $id] + $p['watch'];
            }
        }

        return $out;
    }
}
